<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// BRICKS BUILDER DYNAMIC TAGS
// Registers {snn_learn_progress}, {snn_learn_progress:bool},
// {snn_learn_completed_date}, and
// {current_user_current_course_certificate_hash}
// Only loaded when the Bricks theme is active (checked in snn-learn.php).
// ============================================================

// Step 1: Register tags in the Bricks builder UI
add_filter( 'bricks/dynamic_tags_list', function ( $tags ) {
    $tags[] = [
        'name'  => '{snn_learn_progress}',
        'label' => 'Course Progress (%)',
        'group' => 'SNN Learn',
    ];
    $tags[] = [
        'name'  => '{snn_learn_progress:bool}',
        'label' => 'Course Progress (bool)',
        'group' => 'SNN Learn',
    ];
    $tags[] = [
        'name'  => '{snn_learn_completed_date}',
        'label' => 'Last Completed Lesson Date',
        'group' => 'SNN Learn',
    ];
    $tags[] = [
        'name'  => '{current_user_current_course_certificate_hash}',
        'label' => 'Course Certificate Hash',
        'group' => 'SNN Learn',
    ];
    return $tags;
} );

// Step 2a: Render a single tag when Bricks calls render_tag()
add_filter( 'bricks/dynamic_data/render_tag', 'snn_learn_bricks_render_tag', 20, 3 );
function snn_learn_bricks_render_tag( $tag, $post, $context = 'text' ) {
    if ( ! is_string( $tag ) ) {
        return $tag;
    }

    $clean = str_replace( [ '{', '}' ], '', $tag );

    if ( $clean === 'snn_learn_completed_date' ) {
        return snn_learn_bricks_get_completed_date( $post );
    }

    if ( $clean === 'current_user_current_course_certificate_hash' ) {
        return snn_learn_bricks_get_certificate_hash( $post );
    }

    if ( $clean !== 'snn_learn_progress' && $clean !== 'snn_learn_progress:bool' ) {
        return $tag;
    }

    return snn_learn_bricks_get_progress_value( $clean, $post );
}

// Step 2b: Replace tags inside larger content strings
add_filter( 'bricks/dynamic_data/render_content', 'snn_learn_bricks_render_content', 20, 3 );
add_filter( 'bricks/frontend/render_data',        'snn_learn_bricks_render_content', 20, 2 );
function snn_learn_bricks_render_content( $content, $post, $context = 'text' ) {
    $has_progress = strpos( $content, '{snn_learn_progress' ) !== false;
    $has_date     = strpos( $content, '{snn_learn_completed_date}' ) !== false;
    $has_cert     = strpos( $content, '{current_user_current_course_certificate_hash}' ) !== false;

    if ( ! $has_progress && ! $has_date && ! $has_cert ) {
        return $content;
    }

    if ( $has_progress ) {
        // Match {snn_learn_progress} and {snn_learn_progress:bool}
        preg_match_all( '/\{(snn_learn_progress(?::bool)?)\}/', $content, $matches );

        if ( ! empty( $matches[0] ) ) {
            foreach ( $matches[1] as $key => $clean_tag ) {
                $value   = snn_learn_bricks_get_progress_value( $clean_tag, $post );
                $content = str_replace( $matches[0][ $key ], $value, $content );
            }
        }
    }

    if ( $has_date ) {
        $value   = snn_learn_bricks_get_completed_date( $post );
        $content = str_replace( '{snn_learn_completed_date}', $value, $content );
    }

    if ( $has_cert ) {
        $value   = snn_learn_bricks_get_certificate_hash( $post );
        $content = str_replace( '{current_user_current_course_certificate_hash}', $value, $content );
    }

    return $content;
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
          WHERE user_id = %d AND course_id = %d AND post_id != course_id AND completed_at IS NOT NULL",
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

    $seed = $user_id . '+' . $course_id;
    $raw  = hash( 'sha256', $seed ); // 64 hex chars (0-9, a-f)

    // Expand character set to a-z0-9 (36 chars) while staying deterministic.
    // Walk through the raw hex string two hex digits at a time (0-255) and map
    // each byte value to one of the 36 allowed characters.
    $chars  = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $result = '';
    for ( $i = 0; $i < 32; $i++ ) {
        $byte    = hexdec( substr( $raw, $i * 2, 2 ) ); // 0-255
        $result .= $chars[ $byte % 36 ];
    }

    return $result;
}
