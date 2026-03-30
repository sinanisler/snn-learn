<?php
/**
 * Plugin Name: SNN Learn
 * Description: A modern, high-performance LMS plugin for WordPress.
 * Version: 1.2
 * Author: Sinan Isler
 * Text Domain: snn-learn
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SNN_LEARN_VERSION', '1.2' );
define( 'SNN_LEARN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SNN_LEARN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Database Table Setup
 */
function snn_learn_create_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'snn_learn_enrollments';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id          BIGINT UNSIGNED NOT NULL,
        post_id          BIGINT UNSIGNED NOT NULL,
        course_id        BIGINT UNSIGNED NOT NULL,
        enrolled_at      INT UNSIGNED    NOT NULL,
        completed_at     INT UNSIGNED    DEFAULT NULL,
        last_activity_at INT UNSIGNED    DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_user_post  (user_id, post_id),
        KEY idx_course_id        (course_id),
        KEY idx_user_id          (user_id),
        KEY idx_completed_at     (completed_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'snn_learn_create_table' );

/**
 * Default Settings
 */
function snn_learn_defaults() {
    return array(
        'post_type'              => 'post',
        'video_custom_field'     => 'video_url',
        'player_color'           => '#6366f1',
        'player_bg_color'        => '#1e1e2e',
        'player_text_color'      => '#ffffff',
        'completion_mode'        => 'one_second',
        'completion_seconds'     => 1,
    );
}

function snn_learn_get_option( $key ) {
    $options  = get_option( 'snn_learn_settings', array() );
    $defaults = snn_learn_defaults();
    return isset( $options[ $key ] ) ? $options[ $key ] : ( isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
}

/**
 * Helper: Get grandparent (course) ID from any post in the hierarchy
 */
function snn_learn_get_course_id( $post_id ) {
    $ancestors = get_post_ancestors( $post_id );
    if ( ! empty( $ancestors ) ) {
        return end( $ancestors );
    }
    return $post_id;
}

/**
 * Helper: Get course hierarchy (chapters with lessons)
 */
function snn_learn_get_course_tree( $course_id ) {
    $post_type = snn_learn_get_option( 'post_type' );
    $chapters  = get_posts( array(
        'post_type'      => $post_type,
        'post_parent'    => $course_id,
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
    ) );

    $tree = array();
    foreach ( $chapters as $chapter ) {
        $lessons = get_posts( array(
            'post_type'      => $post_type,
            'post_parent'    => $chapter->ID,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ) );
        $tree[] = array(
            'chapter' => $chapter,
            'lessons' => $lessons,
        );
    }
    return $tree;
}

/**
 * Includes
 */
require_once SNN_LEARN_PATH . 'includes/admin-settings.php';
require_once SNN_LEARN_PATH . 'includes/admin-dashboard.php';
require_once SNN_LEARN_PATH . 'includes/admin-shortcodes-page.php';
require_once SNN_LEARN_PATH . 'includes/shortcodes.php';
require_once SNN_LEARN_PATH . 'includes/ajax-handlers.php';
require_once SNN_LEARN_PATH . 'includes/chapter-redirect.php';

/**
 * Admin Menu
 */
function snn_learn_admin_menu() {
    add_menu_page(
        'SNN Learn',
        'SNN Learn',
        'manage_options',
        'snn-learn',
        'snn_learn_dashboard_page',
        'dashicons-welcome-learn-more',
        30
    );

    add_submenu_page(
        'snn-learn',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'snn-learn',
        'snn_learn_dashboard_page'
    );

    add_submenu_page(
        'snn-learn',
        'Settings',
        'Settings',
        'manage_options',
        'snn-learn-settings',
        'snn_learn_settings_page'
    );

    add_submenu_page(
        'snn-learn',
        'Shortcodes',
        'Shortcodes',
        'manage_options',
        'snn-learn-shortcodes',
        'snn_learn_shortcodes_page'
    );
}
add_action( 'admin_menu', 'snn_learn_admin_menu' );

/**
 * Register Settings
 */
function snn_learn_register_settings() {
    register_setting( 'snn_learn_settings_group', 'snn_learn_settings', 'snn_learn_sanitize_settings' );
}
add_action( 'admin_init', 'snn_learn_register_settings' );

function snn_learn_sanitize_settings( $input ) {
    $defaults  = snn_learn_defaults();
    $sanitized = array();
    foreach ( $defaults as $key => $default ) {
        if ( isset( $input[ $key ] ) ) {
            if ( in_array( $key, array( 'player_color', 'player_bg_color', 'player_text_color' ), true ) ) {
                $sanitized[ $key ] = sanitize_hex_color( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : $default;
            } elseif ( $key === 'completion_seconds' ) {
                $sanitized[ $key ] = max( 1, intval( $input[ $key ] ) );
            } else {
                $sanitized[ $key ] = sanitize_text_field( $input[ $key ] );
            }
        } else {
            $sanitized[ $key ] = $default;
        }
    }
    return $sanitized;
}
