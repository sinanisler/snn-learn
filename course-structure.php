<?php
/**
 * SNN Learn — Course Structure
 *
 * Courses, chapters and lessons are one hierarchical post type; depth decides
 * the role:
 *   course  = post_parent 0
 *   chapter = child of a course
 *   lesson  = child of a chapter
 *
 * This file owns everything that follows from that rule:
 *   1. role detection
 *   2. the cached course structure (ordered ids, counts, duration, modified)
 *   3. the two-level depth guard
 *   4. chapter → first lesson redirect
 *   5. guest content gating in REST and feeds
 *   6. the admin list screen (courses only, structure column, builder links)
 *   7. start / resume / certificate URLs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const SNN_LEARN_STRUCTURE_META    = '_snn_learn_structure';
const SNN_LEARN_STRUCTURE_VERSION = 1;

// ============================================================
// 1. ROLES
// ============================================================

function snn_learn_course_pt() {
    return snn_learn_get( 'course_post_type' ) ?: 'course';
}

/**
 * The structural role of a post: 'course', 'chapter', 'lesson', or '' when it
 * is not a course post or sits deeper than the structure allows.
 */
function snn_learn_post_role( $post ) {
    $post = get_post( $post );
    if ( ! $post || $post->post_type !== snn_learn_course_pt() ) {
        return '';
    }

    if ( ! $post->post_parent ) {
        return 'course';
    }

    $parent = get_post( $post->post_parent );
    if ( ! $parent ) {
        return '';
    }
    if ( ! $parent->post_parent ) {
        return 'chapter';
    }

    $grandparent = get_post( $parent->post_parent );

    return ( $grandparent && ! $grandparent->post_parent ) ? 'lesson' : '';
}

/**
 * Meta keys the structure reads from lessons. Filterable so a site with its
 * own field slugs does not have to fork the plugin.
 */
function snn_learn_lesson_keys() {
    return apply_filters( 'snn_learn_lesson_keys', [
        'video'        => snn_learn_get( 'video_field' ) ?: 'video_url',
        'duration'     => 'video_length',
        'subtitles'    => 'subtitles',
        'free_preview' => 'free_preview',
    ] );
}

// ============================================================
// 2. DURATIONS
// ============================================================

/**
 * Seconds from a hand-typed length.
 *
 * Accepts "12:34", "1:02:03", "1h 5m", "12m 30s", "90s". A bare number is
 * read as minutes — people type "12" meaning twelve minutes, not seconds.
 */
function snn_learn_parse_duration( $value ) {
    $value = strtolower( trim( (string) $value ) );
    if ( '' === $value ) {
        return 0;
    }

    if ( preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
        return (int) round( (float) $value * 60 );
    }

    if ( preg_match( '/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $value, $m ) ) {
        return isset( $m[3] )
            ? (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]
            : (int) $m[1] * 60 + (int) $m[2];
    }

    $seconds = 0;
    if ( preg_match( '/(\d+(?:\.\d+)?)\s*h/', $value, $m ) ) {
        $seconds += (float) $m[1] * 3600;
    }
    if ( preg_match( '/(\d+(?:\.\d+)?)\s*m(?!s)/', $value, $m ) ) {
        $seconds += (float) $m[1] * 60;
    }
    if ( preg_match( '/(\d+(?:\.\d+)?)\s*s/', $value, $m ) ) {
        $seconds += (float) $m[1];
    }

    return (int) round( $seconds );
}

/**
 * "2h 15m", "15m", "45s" ('short') or "2:15:03" / "15:03" ('clock').
 */
function snn_learn_format_duration( $seconds, $style = 'short' ) {
    $seconds = max( 0, (int) $seconds );
    $h       = intdiv( $seconds, 3600 );
    $m       = intdiv( $seconds % 3600, 60 );
    $s       = $seconds % 60;

    if ( 'clock' === $style ) {
        return $h
            ? sprintf( '%d:%02d:%02d', $h, $m, $s )
            : sprintf( '%d:%02d', $m, $s );
    }

    if ( $h ) {
        return $m ? "{$h}h {$m}m" : "{$h}h";
    }
    if ( $m ) {
        return "{$m}m";
    }
    return $seconds ? "{$s}s" : '';
}

/**
 * A lesson's length in seconds: the media library's measured duration for its
 * video when there is one, otherwise the hand-typed length field.
 */
function snn_learn_lesson_duration( $lesson_id ) {
    $keys = snn_learn_lesson_keys();
    $url  = (string) get_post_meta( $lesson_id, $keys['video'], true );

    if ( '' !== $url && function_exists( 'snn_media_find_by_url' ) ) {
        $row = snn_media_find_by_url( $url );
        if ( $row && $row->duration ) {
            return (int) round( (float) $row->duration );
        }
    }

    return snn_learn_parse_duration( get_post_meta( $lesson_id, $keys['duration'], true ) );
}

// ============================================================
// 3. CACHED COURSE STRUCTURE
// ============================================================

/**
 * Builds and stores the published structure of one course.
 *
 * Shape:
 *   v             schema version
 *   chapters      [ { id, title, lessons: [ { id, title, duration } ] } ]
 *   lesson_ids    flat ordered lesson ids
 *   chapter_count / lesson_count
 *   duration      total seconds
 *   modified      newest post_modified_gmt across the course and its children (unix)
 */
function snn_learn_build_structure( $course_id ) {
    $course_id = (int) $course_id;
    $pt        = snn_learn_course_pt();
    $course    = get_post( $course_id );

    $out = [
        'v'             => SNN_LEARN_STRUCTURE_VERSION,
        'chapters'      => [],
        'lesson_ids'    => [],
        'chapter_count' => 0,
        'lesson_count'  => 0,
        'duration'      => 0,
        'modified'      => $course ? (int) strtotime( $course->post_modified_gmt . ' UTC' ) : 0,
    ];

    if ( ! $course || $course->post_type !== $pt || $course->post_parent ) {
        return $out;
    }

    $chapters = get_posts( [
        'post_type'              => $pt,
        'post_parent'            => $course_id,
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'orderby'                => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
        'suppress_filters'       => true,
        'update_post_term_cache' => false,
    ] );

    $lessons_by_chapter = [];
    if ( $chapters ) {
        $lessons = get_posts( [
            'post_type'              => $pt,
            'post_parent__in'        => wp_list_pluck( $chapters, 'ID' ),
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'orderby'                => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
            'suppress_filters'       => true,
            'update_post_term_cache' => false,
        ] );
        foreach ( $lessons as $lesson ) {
            $lessons_by_chapter[ $lesson->post_parent ][] = $lesson;
        }
    }

    foreach ( $chapters as $chapter ) {
        $out['modified'] = max( $out['modified'], (int) strtotime( $chapter->post_modified_gmt . ' UTC' ) );

        $node = [
            'id'      => (int) $chapter->ID,
            'title'   => $chapter->post_title,
            'lessons' => [],
        ];

        foreach ( $lessons_by_chapter[ $chapter->ID ] ?? [] as $lesson ) {
            $duration = snn_learn_lesson_duration( $lesson->ID );

            $node['lessons'][] = [
                'id'       => (int) $lesson->ID,
                'title'    => $lesson->post_title,
                'duration' => $duration,
            ];
            $out['lesson_ids'][] = (int) $lesson->ID;
            $out['duration']    += $duration;
            $out['modified']     = max( $out['modified'], (int) strtotime( $lesson->post_modified_gmt . ' UTC' ) );
        }

        $out['chapters'][] = $node;
    }

    $out['chapter_count'] = count( $out['chapters'] );
    $out['lesson_count']  = count( $out['lesson_ids'] );

    update_post_meta( $course_id, SNN_LEARN_STRUCTURE_META, $out );

    return $out;
}

/** The published structure of a course, rebuilt on demand when stale. */
function snn_learn_course_structure( $course_id ) {
    global $snn_learn_structure_cache;

    $course_id = (int) $course_id;
    if ( ! is_array( $snn_learn_structure_cache ) ) {
        $snn_learn_structure_cache = [];
    }
    if ( isset( $snn_learn_structure_cache[ $course_id ] ) ) {
        return $snn_learn_structure_cache[ $course_id ];
    }

    $stored = get_post_meta( $course_id, SNN_LEARN_STRUCTURE_META, true );
    if ( ! is_array( $stored ) || ( $stored['v'] ?? 0 ) !== SNN_LEARN_STRUCTURE_VERSION ) {
        $stored = snn_learn_build_structure( $course_id );
    }

    return $snn_learn_structure_cache[ $course_id ] = $stored;
}

/**
 * Marks a course's stored structure stale. The next read rebuilds it, so a
 * batch of fifty reorders costs one rebuild, not fifty.
 */
function snn_learn_invalidate_structure( $course_id ) {
    global $snn_learn_structure_cache;

    $course_id = (int) $course_id;
    if ( ! $course_id ) {
        return;
    }

    unset( $snn_learn_structure_cache[ $course_id ] );
    delete_post_meta( $course_id, SNN_LEARN_STRUCTURE_META );

    do_action( 'snn_learn_structure_changed', $course_id );
}

/** Invalidates the course a post belongs to, if it is a course post. */
function snn_learn_invalidate_for_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== snn_learn_course_pt() || wp_is_post_revision( $post ) ) {
        return;
    }
    snn_learn_invalidate_structure( snn_learn_get_course_id( $post->ID ) );
}

// Before an update: the course the post is leaving.
add_action( 'pre_post_update', 'snn_learn_invalidate_for_post', 10, 1 );
// After a save: the course the post now belongs to.
add_action( 'save_post', 'snn_learn_invalidate_for_post', 10, 1 );
add_action( 'wp_trash_post', 'snn_learn_invalidate_for_post', 10, 1 );
add_action( 'untrashed_post', 'snn_learn_invalidate_for_post', 10, 1 );
add_action( 'before_delete_post', 'snn_learn_invalidate_for_post', 10, 1 );

// A lesson's duration comes from its meta, so the meta it is read from counts too.
foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $snn_learn_meta_hook ) {
    add_action( $snn_learn_meta_hook, function ( $meta_id, $object_id, $meta_key ) {
        $keys = snn_learn_lesson_keys();
        if ( $meta_key === $keys['video'] || $meta_key === $keys['duration'] ) {
            snn_learn_invalidate_for_post( $object_id );
        }
    }, 10, 3 );
}
unset( $snn_learn_meta_hook );

// ============================================================
// 4. TWO-LEVEL DEPTH GUARD
// ============================================================

/** How many levels hang below a post: 0 (none), 1 (children), 2 (grandchildren). */
function snn_learn_subtree_height( $post_id ) {
    if ( ! $post_id ) {
        return 0;
    }

    global $wpdb;
    $children = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')",
        (int) $post_id, snn_learn_course_pt()
    ) );
    if ( ! $children ) {
        return 0;
    }

    $ids = implode( ',', array_map( 'intval', $children ) );
    $grandchild = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($ids) AND post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL -- ids are cast to int above.
        snn_learn_course_pt()
    ) );

    return $grandchild ? 2 : 1;
}

/**
 * True when putting $post_id under $parent_id keeps everything within
 * course → chapter → lesson.
 */
function snn_learn_parent_is_valid( $post_id, $parent_id ) {
    $parent_id = (int) $parent_id;
    if ( ! $parent_id ) {
        return true;
    }

    $parent = get_post( $parent_id );
    if ( ! $parent || $parent->post_type !== snn_learn_course_pt() ) {
        return false;
    }

    $parent_depth = count( get_post_ancestors( $parent ) );

    return $parent_depth + 1 + snn_learn_subtree_height( (int) $post_id ) <= 2;
}

add_filter( 'wp_insert_post_data', function ( $data, $postarr ) {
    if ( ( $data['post_type'] ?? '' ) !== snn_learn_course_pt() || empty( $data['post_parent'] ) ) {
        return $data;
    }

    $post_id = (int) ( $postarr['ID'] ?? 0 );
    if ( snn_learn_parent_is_valid( $post_id, $data['post_parent'] ) ) {
        return $data;
    }

    $old = $post_id ? get_post( $post_id ) : null;
    $data['post_parent'] = ( $old && snn_learn_parent_is_valid( $post_id, $old->post_parent ) ) ? (int) $old->post_parent : 0;

    if ( get_current_user_id() ) {
        set_transient( 'snn_learn_depth_notice_' . get_current_user_id(), 1, 60 );
    }

    return $data;
}, 10, 2 );

add_action( 'admin_notices', function () {
    $key = 'snn_learn_depth_notice_' . get_current_user_id();
    if ( ! get_transient( $key ) ) {
        return;
    }
    delete_transient( $key );
    echo '<div class="notice notice-warning is-dismissible"><p><strong>SNN Learn:</strong> '
        . 'That parent was not saved. Courses hold chapters and chapters hold lessons &mdash; '
        . 'nothing can go deeper than a lesson.</p></div>';
} );

// Only courses and chapters are sensible parents, so hide lessons from the dropdowns.
$snn_learn_limit_parent_dropdown = function ( $args, $post = null ) {
    $post_type = $args['post_type'] ?? ( $post ? $post->post_type : '' );
    if ( $post_type === snn_learn_course_pt() ) {
        $args['depth'] = 2;
    }
    return $args;
};
add_filter( 'page_attributes_dropdown_pages_args', $snn_learn_limit_parent_dropdown, 10, 2 );
add_filter( 'quick_edit_dropdown_pages_args', $snn_learn_limit_parent_dropdown, 10, 1 );
unset( $snn_learn_limit_parent_dropdown );

// ============================================================
// 5. CHAPTER → FIRST LESSON REDIRECT
// ============================================================

/**
 * Chapters are structure, not pages: send visitors on to the chapter's first
 * published lesson, or back to the course when the chapter has none yet.
 */
add_action( 'template_redirect', function () {
    if ( ! is_singular( snn_learn_course_pt() ) ) {
        return;
    }
    // Leave page builders and previews alone so chapters stay editable.
    if ( isset( $_GET['bricks'] ) || is_preview() || is_customize_preview() ) { // phpcs:ignore WordPress.Security.NonceVerification
        return;
    }

    $post = get_queried_object();
    if ( ! $post instanceof WP_Post || 'chapter' !== snn_learn_post_role( $post ) ) {
        return;
    }

    $target = snn_learn_chapter_first_lesson_url( $post->ID );
    if ( ! $target ) {
        $course = get_post( $post->post_parent );
        $target = ( $course && 'publish' === $course->post_status ) ? get_permalink( $course ) : '';
    }

    if ( $target ) {
        wp_safe_redirect( $target, 302 );
        exit;
    }
} );

function snn_learn_chapter_first_lesson_url( $chapter_id ) {
    $chapter = get_post( $chapter_id );
    if ( ! $chapter ) {
        return '';
    }

    foreach ( snn_learn_course_structure( $chapter->post_parent )['chapters'] as $node ) {
        if ( $node['id'] === (int) $chapter->ID ) {
            return $node['lessons'] ? get_permalink( $node['lessons'][0]['id'] ) : '';
        }
    }

    // Not in the published structure (a private chapter, say) — ask directly.
    $first = get_posts( [
        'post_type'      => snn_learn_course_pt(),
        'post_parent'    => (int) $chapter->ID,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
        'fields'         => 'ids',
    ] );

    return $first ? get_permalink( $first[0] ) : '';
}

// ============================================================
// 6. GUEST CONTENT GATING
// ============================================================

/**
 * Whether the current visitor may read a post's full content.
 *
 * Mirrors the front-end template: logged-in users see every lesson, guests
 * only lessons flagged as free preview. Courses and chapters are always open.
 */
function snn_learn_can_view_content( $post ) {
    $post    = get_post( $post );
    $allowed = true;

    if ( $post && 'lesson' === snn_learn_post_role( $post ) && ! is_user_logged_in() ) {
        $allowed = (bool) get_post_meta( $post->ID, snn_learn_lesson_keys()['free_preview'], true );
    }

    return (bool) apply_filters( 'snn_learn_can_view_content', $allowed, $post );
}

// The front end hides lesson content with template conditions, but the REST
// API and feeds would otherwise serve it to anyone.
add_action( 'rest_api_init', function () {
    add_filter( 'rest_prepare_' . snn_learn_course_pt(), function ( $response, $post, $request ) {
        if ( 'edit' === $request->get_param( 'context' ) || snn_learn_can_view_content( $post ) ) {
            return $response;
        }

        $data = $response->get_data();
        if ( isset( $data['content'] ) ) {
            $data['content']['rendered']  = '';
            $data['content']['protected'] = true;
        }
        $response->set_data( $data );

        return $response;
    }, 10, 3 );
} );

add_filter( 'the_content_feed', function ( $content ) {
    return snn_learn_can_view_content( get_post() ) ? $content : '';
} );

// ============================================================
// 7. ADMIN LIST SCREEN
// ============================================================

function snn_learn_builder_url( $course_id = 0, $focus_id = 0 ) {
    $args = [ 'page' => 'snn-learn-builder' ];
    if ( $course_id ) {
        $args['course'] = (int) $course_id;
    }
    if ( $focus_id && (int) $focus_id !== (int) $course_id ) {
        $args['focus'] = (int) $focus_id;
    }
    return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * The list shows courses only unless "Chapters & lessons too" is picked, a
 * search is running, or it is the trash (trashed lessons must stay restorable).
 */
function snn_learn_list_shows_all() {
    // phpcs:disable WordPress.Security.NonceVerification -- read-only view switch.
    return ! empty( $_GET['snn_all'] )
        || ! empty( $_GET['s'] )
        || isset( $_GET['post_parent'] )
        || ( isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] );
    // phpcs:enable
}

add_action( 'pre_get_posts', function ( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'edit' !== $screen->base || $screen->post_type !== snn_learn_course_pt() ) {
        return;
    }
    if ( ! snn_learn_list_shows_all() ) {
        $query->set( 'post_parent', 0 );
    }
} );

add_action( 'admin_init', function () {
    $pt = snn_learn_course_pt();

    add_filter( 'views_edit-' . $pt, function ( $views ) {
        $base = remove_query_arg( [ 'snn_all', 'paged' ] );
        $all  = snn_learn_list_shows_all();

        $views['snn_courses'] = sprintf(
            '<a href="%s" class="%s">Courses only</a>',
            esc_url( $base ), $all ? '' : 'current'
        );
        $views['snn_all'] = sprintf(
            '<a href="%s" class="%s">Chapters &amp; lessons too</a>',
            esc_url( add_query_arg( 'snn_all', 1, $base ) ), $all ? 'current' : ''
        );
        $views['snn_builder'] = sprintf(
            '<a href="%s"><strong>Open Course Builder &rarr;</strong></a>',
            esc_url( snn_learn_builder_url() )
        );
        return $views;
    } );

    add_filter( 'manage_' . $pt . '_posts_columns', function ( $columns ) {
        $out = [];
        foreach ( $columns as $key => $label ) {
            $out[ $key ] = $label;
            if ( 'title' === $key ) {
                $out['snn_structure'] = 'Structure';
            }
        }
        return $out;
    } );

    add_action( 'manage_' . $pt . '_posts_custom_column', function ( $column, $post_id ) {
        if ( 'snn_structure' !== $column ) {
            return;
        }
        $role = snn_learn_post_role( $post_id );

        if ( 'course' === $role ) {
            $s     = snn_learn_course_structure( $post_id );
            $parts = [
                $s['chapter_count'] . ' ' . _n( 'chapter', 'chapters', $s['chapter_count'] ),
                $s['lesson_count'] . ' ' . _n( 'lesson', 'lessons', $s['lesson_count'] ),
            ];
            if ( $s['duration'] ) {
                $parts[] = snn_learn_format_duration( $s['duration'] );
            }
            echo esc_html( implode( ' · ', $parts ) );
        } else {
            echo '<span style="color:#787c82">' . esc_html( $role ? ucfirst( $role ) : '—' ) . '</span>';
        }
    }, 10, 2 );
} );

add_filter( 'page_row_actions', 'snn_learn_builder_row_action', 10, 2 );
add_filter( 'post_row_actions', 'snn_learn_builder_row_action', 10, 2 );
function snn_learn_builder_row_action( $actions, $post ) {
    if ( $post->post_type !== snn_learn_course_pt() || ! current_user_can( 'edit_post', $post->ID ) ) {
        return $actions;
    }
    $course_id = snn_learn_get_course_id( $post->ID );
    if ( $course_id ) {
        $actions = [ 'snn_builder' => sprintf(
            '<a href="%s"><strong>Open in Builder</strong></a>',
            esc_url( snn_learn_builder_url( $course_id, $post->ID ) )
        ) ] + $actions;
    }
    return $actions;
}

// ============================================================
// 8. START / RESUME / CERTIFICATE URLS
// ============================================================

/** The first published lesson of a course, or the course itself. */
function snn_learn_course_start_url( $course_id ) {
    $s = snn_learn_course_structure( $course_id );
    return $s['lesson_ids'] ? get_permalink( $s['lesson_ids'][0] ) : get_permalink( $course_id );
}

/**
 * Where a learner should pick up: the first lesson in course order they have
 * not completed. Guests, and learners who finished everything, start at the top.
 */
function snn_learn_course_resume_url( $course_id, $user_id = 0 ) {
    $user_id = $user_id ?: get_current_user_id();
    $s       = snn_learn_course_structure( $course_id );

    if ( ! $user_id || ! $s['lesson_ids'] ) {
        return snn_learn_course_start_url( $course_id );
    }

    global $wpdb;
    $t    = $wpdb->prefix . 'snn_learn_enrollments';
    $done = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM $t WHERE user_id = %d AND course_id = %d AND completed_at IS NOT NULL",
        (int) $user_id, (int) $course_id
    ) ) );

    foreach ( $s['lesson_ids'] as $lesson_id ) {
        if ( ! in_array( $lesson_id, $done, true ) ) {
            return get_permalink( $lesson_id );
        }
    }

    return snn_learn_course_start_url( $course_id );
}

/** The date a user finished a course: the course row, else their latest lesson. */
function snn_learn_course_completed_timestamp( $course_id, $user_id = 0 ) {
    $user_id = $user_id ?: get_current_user_id();
    if ( ! $user_id ) {
        return 0;
    }

    global $wpdb;
    $t  = $wpdb->prefix . 'snn_learn_enrollments';
    $ts = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT completed_at FROM $t WHERE user_id = %d AND post_id = %d AND is_course = 1",
        (int) $user_id, (int) $course_id
    ) );

    if ( ! $ts ) {
        $ts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(completed_at) FROM $t WHERE user_id = %d AND course_id = %d AND is_course = 0",
            (int) $user_id, (int) $course_id
        ) );
    }

    return $ts;
}

/**
 * The certificate page URL for a user and course, on the user's profile base
 * (/user/ or /instructor/, as configured under User Permalinks).
 */
function snn_learn_certificate_url( $course_id, $user_id = 0 ) {
    $user = get_userdata( $user_id ?: get_current_user_id() );
    if ( ! $user || ! $course_id ) {
        return '';
    }

    $is_instructor = (bool) array_intersect( (array) snn_learn_get( 'user_permalink_instr_roles' ), (array) $user->roles );
    $base          = $is_instructor
        ? ( sanitize_title( snn_learn_get( 'user_permalink_instr_base' ) ) ?: 'instructor' )
        : ( sanitize_title( snn_learn_get( 'user_permalink_normal_base' ) ) ?: 'user' );

    $ts = snn_learn_course_completed_timestamp( $course_id, $user->ID );

    return add_query_arg( [
        'cid'             => (int) $course_id,
        'user'            => rawurlencode( trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name ),
        'completion_date' => rawurlencode( $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '' ),
        'certificate_id'  => snn_learn_cert_hash( $user->ID, $course_id ),
    ], home_url( '/' . $base . '/' . (int) $user->ID . '/' ) );
}
