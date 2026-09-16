<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// BRICKS BUILDER DYNAMIC TAGS
// Only loaded when the Bricks theme is active (checked in snn-learn.php).
//
// Progress & completion
//   {snn_learn_progress}                           0–100 for the current user
//   {snn_learn_progress:bool}                      "true" once past 1%
//   {snn_learn_completed_date}                     date of the latest completed lesson
//   {current_user_current_course_certificate_hash} certificate hash
//
// Structure (all resolve the course from any course / chapter / lesson)
//   {snn_learn_type}               course | chapter | lesson
//   {snn_learn_course_id}          the course ID
//   {snn_learn_course_url}         the course landing page URL
//   {snn_learn_chapter_count}      published chapters
//   {snn_learn_lesson_count}       published lessons
//   {snn_learn_duration}           total length, "2h 15m"
//   {snn_learn_duration:clock}     total length, "2:15:03"
//   {snn_learn_duration:seconds}   total length in seconds
//   {snn_learn_lesson_duration}    this lesson's length, "12m" (also :clock / :seconds)
//   {snn_learn_modified}           newest modified date across the course and its children
//   {snn_learn_objectives}         this post's Course Objectives as a <ul> list
//   {snn_learn_objectives:count}   number of objectives
//
// Navigation
//   {snn_learn_start_url}          first lesson
//   {snn_learn_resume_url}         next unfinished lesson for the current user
//   {snn_learn_certificate_url}    certificate page URL for the current user
// ============================================================

/**
 * Every SNN Learn tag: name => [ label, callback( $post_id, $arg ) ].
 * The callback receives the post the tag renders for and the text after ":".
 */
function snn_learn_bricks_tags() {
    $course = function ( $post_id ) {
        return snn_learn_get_course_id( $post_id );
    };

    $format_duration = function ( $seconds, $arg ) {
        if ( 'seconds' === $arg ) {
            return (string) (int) $seconds;
        }
        return snn_learn_format_duration( $seconds, 'clock' === $arg ? 'clock' : 'short' );
    };

    return [
        'snn_learn_progress' => [
            'label'    => 'Course Progress (%)',
            'callback' => function ( $post_id, $arg ) {
                return snn_learn_bricks_get_progress_value( 'bool' === $arg ? 'snn_learn_progress:bool' : 'snn_learn_progress', $post_id );
            },
            'variants' => [ 'bool' => 'Course Progress (bool)' ],
        ],
        'snn_learn_completed_date' => [
            'label'    => 'Last Completed Lesson Date',
            'callback' => function ( $post_id ) {
                return snn_learn_bricks_get_completed_date( $post_id );
            },
        ],
        'current_user_current_course_certificate_hash' => [
            'label'    => 'Course Certificate Hash',
            'callback' => function ( $post_id ) {
                return snn_learn_bricks_get_certificate_hash( $post_id );
            },
        ],
        'snn_learn_type' => [
            'label'    => 'Structure Type (course / chapter / lesson)',
            'callback' => function ( $post_id ) {
                return snn_learn_post_role( $post_id );
            },
        ],
        'snn_learn_course_id' => [
            'label'    => 'Course ID',
            'callback' => function ( $post_id ) use ( $course ) {
                return (string) $course( $post_id );
            },
        ],
        'snn_learn_course_url' => [
            'label'    => 'Course URL',
            'callback' => function ( $post_id ) use ( $course ) {
                $id = $course( $post_id );
                return $id ? (string) get_permalink( $id ) : '';
            },
        ],
        'snn_learn_chapter_count' => [
            'label'    => 'Chapter Count',
            'callback' => function ( $post_id ) use ( $course ) {
                $id = $course( $post_id );
                return $id ? (string) snn_learn_course_structure( $id )['chapter_count'] : '0';
            },
        ],
        'snn_learn_lesson_count' => [
            'label'    => 'Lesson Count',
            'callback' => function ( $post_id ) use ( $course ) {
                $id = $course( $post_id );
                return $id ? (string) snn_learn_course_structure( $id )['lesson_count'] : '0';
            },
        ],
        'snn_learn_duration' => [
            'label'    => 'Course Duration',
            'callback' => function ( $post_id, $arg ) use ( $course, $format_duration ) {
                $id = $course( $post_id );
                return $id ? $format_duration( snn_learn_course_structure( $id )['duration'], $arg ) : '';
            },
            'variants' => [ 'clock' => 'Course Duration (h:mm:ss)', 'seconds' => 'Course Duration (seconds)' ],
        ],
        'snn_learn_lesson_duration' => [
            'label'    => 'Lesson Duration',
            'callback' => function ( $post_id, $arg ) use ( $format_duration ) {
                return 'lesson' === snn_learn_post_role( $post_id )
                    ? $format_duration( snn_learn_lesson_duration( $post_id ), $arg )
                    : '';
            },
            'variants' => [ 'clock' => 'Lesson Duration (m:ss)' ],
        ],
        'snn_learn_modified' => [
            'label'    => 'Course Last Updated (incl. lessons)',
            'callback' => function ( $post_id ) use ( $course ) {
                $id = $course( $post_id );
                $ts = $id ? snn_learn_course_structure( $id )['modified'] : 0;
                return $ts ? wp_date( get_option( 'date_format' ), $ts ) : '';
            },
        ],
        'snn_learn_objectives' => [
            'label'    => 'Course Objectives (list)',
            'callback' => function ( $post_id, $arg ) {
                return snn_learn_bricks_get_objectives( $post_id, $arg );
            },
            'variants' => [ 'count' => 'Course Objectives (count)' ],
        ],
        'snn_learn_start_url' => [
            'label'    => 'Start Course URL (first lesson)',
            'callback' => function ( $post_id ) use ( $course ) {
                $id = $course( $post_id );
                return $id ? (string) snn_learn_course_start_url( $id ) : '';
            },
        ],
        'snn_learn_resume_url' => [
            'label'    => 'Continue Learning URL (next unfinished lesson)',
            'callback' => function ( $post_id ) use ( $course ) {
                $id = $course( $post_id );
                return $id ? (string) snn_learn_course_resume_url( $id ) : '';
            },
        ],
        'snn_learn_certificate_url' => [
            'label'    => 'Certificate URL',
            'callback' => function ( $post_id ) use ( $course ) {
                $id = $course( $post_id );
                return $id ? snn_learn_certificate_url( $id ) : '';
            },
        ],
    ];
}

/** Resolves "{name}" or "{name:arg}" to a value, or null when it is not ours. */
function snn_learn_bricks_resolve( $clean, $post ) {
    $parts = explode( ':', $clean, 2 );
    $tags  = snn_learn_bricks_tags();

    if ( ! isset( $tags[ $parts[0] ] ) ) {
        return null;
    }

    $post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
    if ( ! $post_id ) {
        $post_id = (int) get_the_ID();
    }

    return (string) call_user_func( $tags[ $parts[0] ]['callback'], $post_id, $parts[1] ?? '' );
}

// Step 1: Register tags in the Bricks builder UI
add_filter( 'bricks/dynamic_tags_list', function ( $tags ) {
    foreach ( snn_learn_bricks_tags() as $name => $def ) {
        $tags[] = [ 'name' => '{' . $name . '}', 'label' => $def['label'], 'group' => 'SNN Learn' ];
        foreach ( $def['variants'] ?? [] as $arg => $label ) {
            $tags[] = [ 'name' => '{' . $name . ':' . $arg . '}', 'label' => $label, 'group' => 'SNN Learn' ];
        }
    }
    return $tags;
} );

// Step 2a: Render a single tag when Bricks calls render_tag()
add_filter( 'bricks/dynamic_data/render_tag', 'snn_learn_bricks_render_tag', 20, 3 );
function snn_learn_bricks_render_tag( $tag, $post, $context = 'text' ) {
    if ( ! is_string( $tag ) ) {
        return $tag;
    }

    $value = snn_learn_bricks_resolve( trim( $tag, '{}' ), $post );

    return null === $value ? $tag : $value;
}

// Step 2b: Replace tags inside larger content strings
add_filter( 'bricks/dynamic_data/render_content', 'snn_learn_bricks_render_content', 20, 3 );
add_filter( 'bricks/frontend/render_data',        'snn_learn_bricks_render_content', 20, 2 );
function snn_learn_bricks_render_content( $content, $post, $context = 'text' ) {
    if ( ! is_string( $content ) || ( false === strpos( $content, '{snn_learn_' ) && false === strpos( $content, '{current_user_current_course_certificate_hash' ) ) ) {
        return $content;
    }

    return preg_replace_callback( '/\{((?:snn_learn_[a-z_]+|current_user_current_course_certificate_hash)(?::[a-z0-9_]+)?)\}/', function ( $m ) use ( $post ) {
        $value = snn_learn_bricks_resolve( $m[1], $post );
        return null === $value ? $m[0] : $value;
    }, $content );
}

// Renders the `course_objectives` repeater (an array of strings) as a list.
// Each row's first line becomes the bold title; the remaining lines its text.
function snn_learn_bricks_get_objectives( $post_id, $arg = '' ) {
    $rows = array_values( array_filter( array_map( 'trim', (array) get_post_meta( $post_id, 'course_objectives', true ) ), 'strlen' ) );

    if ( 'count' === $arg ) {
        return (string) count( $rows );
    }
    if ( ! $rows ) {
        return '';
    }

    $html = '<ul class="snn-learn-objectives">';
    foreach ( $rows as $row ) {
        $lines = preg_split( '/\R/', $row, 2 );
        $html .= '<li>';
        if ( isset( $lines[1] ) && '' !== trim( $lines[1] ) ) {
            $html .= '<strong>' . esc_html( $lines[0] ) . '</strong><br>' . nl2br( esc_html( trim( $lines[1] ) ) );
        } else {
            $html .= esc_html( $lines[0] );
        }
        $html .= '</li>';
    }

    return $html . '</ul>';
}

// Shared logic: resolve progress value for the current user / post
function snn_learn_bricks_get_progress_value( $clean_tag, $post ) {
    $user_id = get_current_user_id();
    $bool    = ( $clean_tag === 'snn_learn_progress:bool' );

    if ( ! $user_id ) {
        return $bool ? 'false' : '0';
    }

    $post_id   = is_object( $post ) ? $post->ID : (int) $post;
    $course_id = snn_learn_get_course_id( $post_id );

    if ( ! $course_id ) {
        return $bool ? 'false' : '0';
    }

    $progress = snn_learn_calc_progress( $user_id, $course_id );

    if ( $bool ) {
        return $progress > 1 ? 'true' : 'false';
    }

    return (string) $progress;
}

// Returns the formatted date of the most recently completed lesson for the
// current user in the current post's course. Returns '' when not applicable.
function snn_learn_bricks_get_completed_date( $post ) {
    global $wpdb;

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return '';
    }

    $post_id   = is_object( $post ) ? $post->ID : (int) $post;
    $course_id = snn_learn_get_course_id( $post_id );
    if ( ! $course_id ) {
        return '';
    }

    $t         = $wpdb->prefix . 'snn_learn_enrollments';
    $timestamp = $wpdb->get_var( $wpdb->prepare(
        "SELECT MAX(completed_at) FROM $t
          WHERE user_id = %d AND course_id = %d AND is_course = 0 AND completed_at IS NOT NULL",
        $user_id, $course_id
    ) );

    if ( ! $timestamp ) {
        return '';
    }

    return date_i18n( get_option( 'date_format' ), (int) $timestamp );
}

// Returns a deterministic 32-character alphanumeric hash (a-z0-9) seeded by
// user_id + course_id. Same seed always produces the same hash.
function snn_learn_bricks_get_certificate_hash( $post ) {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return '';
    }

    $post_id   = is_object( $post ) ? $post->ID : (int) $post;
    $course_id = snn_learn_get_course_id( $post_id );
    if ( ! $course_id ) {
        return '';
    }

    return snn_learn_cert_hash( $user_id, $course_id );
}
