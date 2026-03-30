<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Localize AJAX data for frontend shortcodes
 */
function snn_learn_frontend_ajax_data() {
    if ( ! is_user_logged_in() ) return;

    $post_type = snn_learn_get_option( 'post_type' );
    if ( ! is_singular( $post_type ) ) return;

    $post_id   = get_the_ID();
    $course_id = snn_learn_get_course_id( $post_id );

    // Inline the AJAX config so frontend JS can use it
    ?>
    <script>
    var snnLearn = <?php echo wp_json_encode( array(
        'ajax_url'           => admin_url( 'admin-ajax.php' ),
        'nonce'              => wp_create_nonce( 'snn_learn_nonce' ),
        'post_id'            => $post_id,
        'course_id'          => $course_id,
        'user_id'            => get_current_user_id(),
        'completion_mode'    => snn_learn_get_option( 'completion_mode' ),
        'completion_seconds' => (int) snn_learn_get_option( 'completion_seconds' ),
    ) ); ?>;
    </script>
    <?php
}
add_action( 'wp_head', 'snn_learn_frontend_ajax_data' );

/**
 * AJAX: Mark lesson as completed (video watch or manual button)
 */
function snn_learn_ajax_complete_lesson() {
    check_ajax_referer( 'snn_learn_nonce', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not logged in' );
    }

    $user_id   = get_current_user_id();
    $post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;

    if ( ! $post_id || ! $course_id ) {
        wp_send_json_error( 'Missing data' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'snn_learn_enrollments';
    $now   = time();

    // Upsert the lesson row
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, completed_at FROM $table WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ) );

    if ( $existing ) {
        $update_data = array( 'last_activity_at' => $now );
        if ( empty( $existing->completed_at ) ) {
            $update_data['completed_at'] = $now;
        }
        $wpdb->update( $table, $update_data, array( 'id' => $existing->id ), array( '%d', '%d' ), array( '%d' ) );
    } else {
        $wpdb->insert( $table, array(
            'user_id'          => $user_id,
            'post_id'          => $post_id,
            'course_id'        => $course_id,
            'enrolled_at'      => $now,
            'completed_at'     => $now,
            'last_activity_at' => $now,
        ), array( '%d', '%d', '%d', '%d', '%d', '%d' ) );
    }

    // Also ensure course-level enrollment row exists
    snn_learn_ensure_course_enrollment( $user_id, $course_id );

    wp_send_json_success( array( 'completed' => true ) );
}
add_action( 'wp_ajax_snn_learn_complete_lesson', 'snn_learn_ajax_complete_lesson' );

/**
 * AJAX: Record video play start (enrollment trigger)
 */
function snn_learn_ajax_video_started() {
    check_ajax_referer( 'snn_learn_nonce', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not logged in' );
    }

    $user_id   = get_current_user_id();
    $post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;

    if ( ! $post_id || ! $course_id ) {
        wp_send_json_error( 'Missing data' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'snn_learn_enrollments';
    $now   = time();

    // Upsert lesson enrollment (without completion)
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM $table WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ) );

    if ( $existing ) {
        $wpdb->update( $table, array( 'last_activity_at' => $now ), array( 'id' => $existing->id ), array( '%d' ), array( '%d' ) );
    } else {
        $wpdb->insert( $table, array(
            'user_id'          => $user_id,
            'post_id'          => $post_id,
            'course_id'        => $course_id,
            'enrolled_at'      => $now,
            'last_activity_at' => $now,
        ), array( '%d', '%d', '%d', '%d', '%d' ) );
    }

    // Also ensure course-level enrollment row exists
    snn_learn_ensure_course_enrollment( $user_id, $course_id );

    wp_send_json_success();
}
add_action( 'wp_ajax_snn_learn_video_started', 'snn_learn_ajax_video_started' );

/**
 * Ensure course-level enrollment exists (grandparent row)
 */
function snn_learn_ensure_course_enrollment( $user_id, $course_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'snn_learn_enrollments';
    $now   = time();

    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE user_id = %d AND post_id = %d",
        $user_id, $course_id
    ) );

    if ( ! $exists ) {
        $wpdb->insert( $table, array(
            'user_id'          => $user_id,
            'post_id'          => $course_id,
            'course_id'        => $course_id,
            'enrolled_at'      => $now,
            'last_activity_at' => $now,
        ), array( '%d', '%d', '%d', '%d', '%d' ) );
    } else {
        $wpdb->update( $table, array( 'last_activity_at' => $now ), array( 'id' => $exists ), array( '%d' ), array( '%d' ) );
    }
}
