<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Redirect chapter pages to their first child lesson.
 * A "chapter" is a post whose parent is a grandparent (course)
 * and that itself has children (lessons).
 */
function snn_learn_chapter_redirect() {
    if ( is_admin() || wp_doing_ajax() ) return;

    $post_type = snn_learn_get_option( 'post_type' );
    if ( ! is_singular( $post_type ) ) return;

    $post = get_queried_object();
    if ( ! $post || $post->post_parent == 0 ) return;

    // Check if this post IS a chapter (has a parent AND has children)
    $children = get_posts( array(
        'post_type'      => $post_type,
        'post_parent'    => $post->ID,
        'posts_per_page' => 1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ) );

    if ( ! empty( $children ) ) {
        wp_redirect( get_permalink( $children[0] ), 301 );
        exit;
    }
}
add_action( 'template_redirect', 'snn_learn_chapter_redirect' );
