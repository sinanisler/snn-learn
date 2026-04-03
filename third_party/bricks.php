<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// BRICKS BUILDER DYNAMIC TAGS
// Registers {snn_learn_progress} and {snn_learn_progress:bool}
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
    return $tags;
} );

// Step 2a: Render a single tag when Bricks calls render_tag()
add_filter( 'bricks/dynamic_data/render_tag', 'snn_learn_bricks_render_tag', 20, 3 );
function snn_learn_bricks_render_tag( $tag, $post, $context = 'text' ) {
    if ( ! is_string( $tag ) ) {
        return $tag;
    }

    $clean = str_replace( [ '{', '}' ], '', $tag );

    if ( $clean !== 'snn_learn_progress' && $clean !== 'snn_learn_progress:bool' ) {
        return $tag;
    }

    return snn_learn_bricks_get_progress_value( $clean, $post );
}

// Step 2b: Replace tags inside larger content strings
add_filter( 'bricks/dynamic_data/render_content', 'snn_learn_bricks_render_content', 20, 3 );
add_filter( 'bricks/frontend/render_data',        'snn_learn_bricks_render_content', 20, 2 );
function snn_learn_bricks_render_content( $content, $post, $context = 'text' ) {
    if ( strpos( $content, '{snn_learn_progress' ) === false ) {
        return $content;
    }

    // Match {snn_learn_progress} and {snn_learn_progress:bool}
    preg_match_all( '/\{(snn_learn_progress(?::bool)?)\}/', $content, $matches );

    if ( empty( $matches[0] ) ) {
        return $content;
    }

    foreach ( $matches[1] as $key => $clean_tag ) {
        $tag_full = $matches[0][ $key ];
        $value    = snn_learn_bricks_get_progress_value( $clean_tag, $post );
        $content  = str_replace( $tag_full, $value, $content );
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
