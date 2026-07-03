<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =============================================================================
 * SNN LEARN — EXTENDED REST API
 * rest-api.php
 * =============================================================================
 *
 * 33 new endpoints built entirely on EXISTING tables — zero schema changes.
 *
 * TABLES USED:
 *   wp_snn_learn_enrollments  — user_id, post_id, course_id, enrolled_at,
 *                                completed_at, last_activity_at, is_course,
 *                                is_lesson
 *   wp_users                  — ID, user_login, user_email, display_name
 *   wp_usermeta               — user_id, meta_key, meta_value
 *   wp_posts                  — ID, post_title, post_parent, post_type,
 *                                menu_order, post_status
 *   wp_postmeta               — post_id, meta_key, meta_value
 *
 * GDP NOTICE: All admin endpoints require manage_options capability.
 * Group-scoped access (snn_manage_company) to be added in Phase 2.
 *
 * =============================================================================
 */

// ============================================================
// SHARED HELPERS
// ============================================================

/**
 * Check if the current user is an admin (can manage learners).
 */
function snn_learn_rest_is_admin() {
    return current_user_can( 'manage_options' );
}

/**
 * Get the enrolments table name.
 */
function snn_learn_rest_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_learn_enrollments';
}

/**
 * Build a standardised user object with enrolment summary.
 */
function snn_learn_rest_build_user_object( $user, $course_id = null ) {
    global $wpdb;
    $t        = snn_learn_rest_table();
    $user_id  = (int) $user->ID;
    $obj      = [
        'id'           => $user_id,
        'username'     => $user->user_login,
        'display_name' => $user->display_name,
        'email'        => $user->user_email,
        'registered'   => $user->user_registered,
        'group'        => get_user_meta( $user_id, 'snn_group', true ) ?: '',
        'enrollments'  => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND is_course = 1", $user_id
        ) ),
        'completed_courses' => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND is_course = 1 AND completed_at IS NOT NULL", $user_id
        ) ),
        'last_activity_at'  => $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(last_activity_at) FROM $t WHERE user_id = %d", $user_id
        ) ),
    ];

    if ( $course_id ) {
        $obj['course_progress'] = snn_learn_calc_progress( $user_id, $course_id );
        $obj['course_enrolled'] = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM $t WHERE user_id = %d AND course_id = %d AND is_course = 1", $user_id, $course_id
        ) );
    }

    return $obj;
}

/**
 * Format seconds to HH:MM:SS.
 */
function snn_learn_rest_format_duration( $seconds ) {
    $h = floor( $seconds / 3600 );
    $m = floor( ( $seconds % 3600 ) / 60 );
    $s = $seconds % 60;
    return $h > 0
        ? sprintf( '%d:%02d:%02d', $h, $m, $s )
        : sprintf( '%d:%02d', $m, $s );
}

/**
 * Format a timestamp to ISO 8601 (or return null).
 */
function snn_learn_rest_ts_iso( $ts ) {
    return $ts ? wp_date( 'c', (int) $ts ) : null;
}

/**
 * Output CSV file with proper headers.
 */
function snn_learn_rest_output_csv( $filename, array $headers, array $rows ) {
    // Build CSV in memory
    $fh = fopen( 'php://temp', 'r+' );
    fputcsv( $fh, $headers );
    foreach ( $rows as $row ) {
        fputcsv( $fh, $row );
    }
    rewind( $fh );
    $csv = stream_get_contents( $fh );
    fclose( $fh );

    // Return as a REST response with correct headers
    $response = new WP_REST_Response( $csv, 200 );
    $response->header( 'Content-Type', 'text/csv; charset=utf-8' );
    $response->header( 'Content-Disposition', 'attachment; filename="' . sanitize_file_name( $filename ) . '.csv"' );
    $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
    return $response;
}


// ============================================================
// REGISTER ALL ENDPOINTS
// ============================================================

add_action( 'rest_api_init', function () {

    $admin_only = [ 'permission_callback' => function () {
        return snn_learn_rest_is_admin();
    } ];

    // ────────────────────────────────────────────────────────
    // 1. ADMIN USER MANAGEMENT
    // ────────────────────────────────────────────────────────

    register_rest_route( 'snn-learn/v1', '/admin/users', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_users_list',
        'args'     => [
            'search'    => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'group'     => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'course_id' => [ 'sanitize_callback' => 'absint' ],
            'page'      => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
            'per_page'  => [ 'default' => 20, 'sanitize_callback' => function ( $v ) { return min( max( absint( $v ), 1 ), 100 ); } ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/users/(?P<id>\d+)', array_merge( $admin_only, [
        [
            'methods'  => 'GET',
            'callback' => 'snn_learn_rest_admin_user_get',
        ],
        [
            'methods'  => 'PUT',
            'callback' => 'snn_learn_rest_admin_user_update',
            'args'     => [
                'display_name' => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'email'        => [ 'sanitize_callback' => 'sanitize_email' ],
                'group'        => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'password'     => [ 'sanitize_callback' => function ( $v ) { return $v; } ],
            ],
        ],
        [
            'methods'  => 'DELETE',
            'callback' => 'snn_learn_rest_admin_user_delete',
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/users', array_merge( $admin_only, [
        'methods'  => 'POST',
        'callback' => 'snn_learn_rest_admin_user_create',
        'args'     => [
            'username'     => [ 'required' => true, 'sanitize_callback' => 'sanitize_user' ],
            'email'        => [ 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
            'display_name' => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'group'        => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'password'     => [ 'sanitize_callback' => function ( $v ) { return $v; } ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/users/bulk-import', array_merge( $admin_only, [
        'methods'  => 'POST',
        'callback' => 'snn_learn_rest_admin_users_bulk_import',
        'args'     => [
            'users' => [ 'required' => true ],
        ],
    ] ) );

    // ────────────────────────────────────────────────────────
    // 2. ADMIN ENROLLMENT MANAGEMENT
    // ────────────────────────────────────────────────────────

    register_rest_route( 'snn-learn/v1', '/admin/enrollments', array_merge( $admin_only, [
        [
            'methods'  => 'GET',
            'callback' => 'snn_learn_rest_admin_enrollments_list',
            'args'     => [
                'user_id'       => [ 'sanitize_callback' => 'absint' ],
                'course_id'     => [ 'sanitize_callback' => 'absint' ],
                'group'         => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'completed'     => [ 'sanitize_callback' => function ( $v ) { return filter_var( $v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ); } ],
                'date_from'     => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'date_to'       => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'page'          => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
                'per_page'      => [ 'default' => 20, 'sanitize_callback' => function ( $v ) { return min( max( absint( $v ), 1 ), 100 ); } ],
            ],
        ],
        [
            'methods'  => 'POST',
            'callback' => 'snn_learn_rest_admin_enrollment_create',
            'args'     => [
                'user_id'   => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'course_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/enrollments/(?P<id>\d+)', array_merge( $admin_only, [
        'methods'  => 'DELETE',
        'callback' => 'snn_learn_rest_admin_enrollment_delete',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/enrollments/bulk', array_merge( $admin_only, [
        'methods'  => 'POST',
        'callback' => 'snn_learn_rest_admin_enrollments_bulk',
        'args'     => [
            'enrollments' => [ 'required' => true ],
        ],
    ] ) );

    // ────────────────────────────────────────────────────────
    // 3. COURSE CONTENT API (READ-ONLY, PUBLIC)
    // ────────────────────────────────────────────────────────

    $learner_only = [ 'permission_callback' => function () {
        return is_user_logged_in();
    } ];

    register_rest_route( 'snn-learn/v1', '/courses', array_merge( $learner_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_courses_list',
        'args'     => [
            'search'   => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'page'     => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
            'per_page' => [ 'default' => 20, 'sanitize_callback' => function ( $v ) { return min( max( absint( $v ), 1 ), 100 ); } ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/courses/(?P<id>\d+)', array_merge( $learner_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_course_get',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/courses/(?P<id>\d+)/chapters', array_merge( $learner_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_course_chapters',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/courses/(?P<id>\d+)/lessons', array_merge( $learner_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_course_lessons',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/lessons/(?P<id>\d+)', array_merge( $learner_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_lesson_get',
    ] ) );

    // ────────────────────────────────────────────────────────
    // 4. GROUP / DEPARTMENT MANAGEMENT
    // ────────────────────────────────────────────────────────

    register_rest_route( 'snn-learn/v1', '/admin/groups', array_merge( $admin_only, [
        [
            'methods'  => 'GET',
            'callback' => 'snn_learn_rest_admin_groups_list',
        ],
        [
            'methods'  => 'POST',
            'callback' => 'snn_learn_rest_admin_group_create',
            'args'     => [
                'name'        => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'description' => [ 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/groups/(?P<slug>[a-z0-9_\-]+)', array_merge( $admin_only, [
        [
            'methods'  => 'PUT',
            'callback' => 'snn_learn_rest_admin_group_update',
            'args'     => [
                'name'        => [ 'sanitize_callback' => 'sanitize_text_field' ],
                'description' => [ 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ],
        [
            'methods'  => 'DELETE',
            'callback' => 'snn_learn_rest_admin_group_delete',
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/groups/(?P<slug>[a-z0-9_\-]+)/users', array_merge( $admin_only, [
        [
            'methods'  => 'GET',
            'callback' => 'snn_learn_rest_admin_group_users_list',
            'args'     => [
                'page'     => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
                'per_page' => [ 'default' => 20, 'sanitize_callback' => function ( $v ) { return min( max( absint( $v ), 1 ), 100 ); } ],
            ],
        ],
        [
            'methods'  => 'POST',
            'callback' => 'snn_learn_rest_admin_group_users_add',
            'args'     => [
                'user_ids' => [ 'required' => true ],
            ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/groups/(?P<slug>[a-z0-9_\-]+)/users/(?P<user_id>\d+)', array_merge( $admin_only, [
        'methods'  => 'DELETE',
        'callback' => 'snn_learn_rest_admin_group_user_remove',
    ] ) );

    // ────────────────────────────────────────────────────────
    // 5. ANALYTICS & REPORTING
    // ────────────────────────────────────────────────────────

    register_rest_route( 'snn-learn/v1', '/admin/analytics/overview', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_analytics_overview',
        'args'     => [
            'group'    => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'days'     => [ 'default' => 30, 'sanitize_callback' => 'absint' ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/analytics/course/(?P<id>\d+)', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_analytics_course',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/analytics/user/(?P<id>\d+)', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_analytics_user',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/analytics/trends/enrollment', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_analytics_trend_enrollment',
        'args'     => [
            'days'     => [ 'default' => 30, 'sanitize_callback' => 'absint' ],
            'course_id'=> [ 'sanitize_callback' => 'absint' ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/analytics/trends/completion', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_analytics_trend_completion',
        'args'     => [
            'days'      => [ 'default' => 30, 'sanitize_callback' => 'absint' ],
            'course_id' => [ 'sanitize_callback' => 'absint' ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/analytics/leaderboard', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_analytics_leaderboard',
        'args'     => [
            'course_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            'limit'     => [ 'default' => 20, 'sanitize_callback' => function ( $v ) { return min( max( absint( $v ), 1 ), 100 ); } ],
        ],
    ] ) );

    // ────────────────────────────────────────────────────────
    // 6. BULK OPERATIONS
    // ────────────────────────────────────────────────────────

    register_rest_route( 'snn-learn/v1', '/admin/bulk/enroll', array_merge( $admin_only, [
        'methods'  => 'POST',
        'callback' => 'snn_learn_rest_admin_bulk_enroll',
        'args'     => [
            'user_ids'  => [ 'required' => true ],
            'course_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/bulk/mark-complete', array_merge( $admin_only, [
        'methods'  => 'POST',
        'callback' => 'snn_learn_rest_admin_bulk_mark_complete',
        'args'     => [
            'user_ids'  => [ 'required' => true ],
            'lesson_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/bulk/assign-group', array_merge( $admin_only, [
        'methods'  => 'POST',
        'callback' => 'snn_learn_rest_admin_bulk_assign_group',
        'args'     => [
            'user_ids' => [ 'required' => true ],
            'group'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] ) );

    // ────────────────────────────────────────────────────────
    // 7. CSV EXPORTS
    // ────────────────────────────────────────────────────────

    register_rest_route( 'snn-learn/v1', '/admin/export/users', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_export_users',
        'args'     => [
            'group'    => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'course_id'=> [ 'sanitize_callback' => 'absint' ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/export/enrollments', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_export_enrollments',
        'args'     => [
            'course_id' => [ 'sanitize_callback' => 'absint' ],
            'group'     => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'date_from' => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'date_to'   => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'completed' => [ 'sanitize_callback' => function ( $v ) { return filter_var( $v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ); } ],
        ],
    ] ) );

    register_rest_route( 'snn-learn/v1', '/admin/export/progress', array_merge( $admin_only, [
        'methods'  => 'GET',
        'callback' => 'snn_learn_rest_admin_export_progress',
        'args'     => [
            'course_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            'group'     => [ 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] ) );

} );


// ============================================================
// 1. ADMIN USER MANAGEMENT — CALLBACKS
// ============================================================

/**
 * GET /admin/users
 * List all users with optional filters: search, group, course_id, pagination.
 */
function snn_learn_rest_admin_users_list( $request ) {
    global $wpdb;
    $t       = snn_learn_rest_table();
    $search  = $request->get_param( 'search' );
    $group   = $request->get_param( 'group' );
    $course  = $request->get_param( 'course_id' );
    $page    = (int) $request->get_param( 'page' );
    $per     = (int) $request->get_param( 'per_page' );
    $offset  = ( $page - 1 ) * $per;

    $where   = [];
    $args    = [];
    $joins   = '';

    if ( $search ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
        $args[]  = $like; $args[] = $like; $args[] = $like;
    }

    if ( $group ) {
        $joins .= " INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id";
        $where[] = 'um.meta_key = %s AND um.meta_value = %s';
        $args[]  = 'snn_group'; $args[] = $group;
    }

    if ( $course ) {
        $joins .= " LEFT JOIN $t e ON u.ID = e.user_id AND e.course_id = %d AND e.is_course = 1";
        $args[] = (int) $course;
        $where[] = 'e.user_id IS NOT NULL';
    }

    $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

    // Total
    $total = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u $joins $where_sql",
            $args
        )
    );

    // Users
    $users = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT u.* FROM {$wpdb->users} u $joins $where_sql ORDER BY u.display_name ASC LIMIT %d OFFSET %d",
            array_merge( $args, [ $per, $offset ] )
        )
    );

    $data = array_map( function ( $u ) use ( $course ) {
        return snn_learn_rest_build_user_object( $u, $course );
    }, $users ?: [] );

    return rest_ensure_response( [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per,
        'users'    => $data,
    ] );
}

/**
 * GET /admin/users/{id}
 * Get a single user with their enrolment summary.
 */
function snn_learn_rest_admin_user_get( $request ) {
    $user_id = (int) $request->get_param( 'id' );
    $user    = get_userdata( $user_id );
    if ( ! $user ) {
        return new WP_Error( 'not_found', 'User not found.', [ 'status' => 404 ] );
    }

    // Also retrieve detailed course-by-course progress
    global $wpdb;
    $t    = snn_learn_rest_table();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT course_id, enrolled_at, completed_at, last_activity_at FROM $t WHERE user_id = %d AND is_course = 1 ORDER BY enrolled_at DESC",
        $user_id
    ) );

    $courses = array_map( function ( $r ) use ( $user_id ) {
        $cid = (int) $r->course_id;
        return [
            'course_id'        => $cid,
            'course_title'     => get_the_title( $cid ) ?: '(Untitled)',
            'progress'         => snn_learn_calc_progress( $user_id, $cid ),
            'enrolled_at'      => snn_learn_rest_ts_iso( $r->enrolled_at ),
            'completed_at'     => snn_learn_rest_ts_iso( $r->completed_at ),
            'last_activity_at' => snn_learn_rest_ts_iso( $r->last_activity_at ),
            'is_completed'     => ! empty( $r->completed_at ),
        ];
    }, $rows ?: [] );

    $data = snn_learn_rest_build_user_object( $user );
    $data['courses'] = $courses;

    return rest_ensure_response( $data );
}

/**
 * POST /admin/users
 * Create a new WordPress user.
 */
function snn_learn_rest_admin_user_create( $request ) {
    $username     = $request->get_param( 'username' );
    $email        = $request->get_param( 'email' );
    $display_name = $request->get_param( 'display_name' ) ?: $username;
    $group        = $request->get_param( 'group' ) ?: '';
    $password     = $request->get_param( 'password' ) ?: wp_generate_password();

    if ( username_exists( $username ) ) {
        return new WP_Error( 'duplicate_username', 'Username already exists.', [ 'status' => 409 ] );
    }
    if ( email_exists( $email ) ) {
        return new WP_Error( 'duplicate_email', 'Email already exists.', [ 'status' => 409 ] );
    }

    $user_id = wp_insert_user( [
        'user_login'   => $username,
        'user_email'   => $email,
        'display_name' => $display_name,
        'user_pass'    => $password,
        'role'         => 'subscriber',
    ] );

    if ( is_wp_error( $user_id ) ) {
        return new WP_Error( 'create_failed', $user_id->get_error_message(), [ 'status' => 400 ] );
    }

    if ( $group ) {
        update_user_meta( $user_id, 'snn_group', $group );
    }

    $user = get_userdata( $user_id );
    return rest_ensure_response( [ 'success' => true, 'user' => snn_learn_rest_build_user_object( $user ) ] );
}

/**
 * PUT /admin/users/{id}
 * Update a user's display name, email, group, or password.
 */
function snn_learn_rest_admin_user_update( $request ) {
    $user_id      = (int) $request->get_param( 'id' );
    $user         = get_userdata( $user_id );
    if ( ! $user ) {
        return new WP_Error( 'not_found', 'User not found.', [ 'status' => 404 ] );
    }

    $update = [ 'ID' => $user_id ];

    if ( $request->has_param( 'display_name' ) ) {
        $update['display_name'] = $request->get_param( 'display_name' );
    }
    if ( $request->has_param( 'email' ) ) {
        $email = $request->get_param( 'email' );
        $existing = email_exists( $email );
        if ( $existing && (int) $existing !== $user_id ) {
            return new WP_Error( 'duplicate_email', 'Email already belongs to another user.', [ 'status' => 409 ] );
        }
        $update['user_email'] = $email;
    }
    if ( $request->has_param( 'password' ) && $request->get_param( 'password' ) ) {
        $update['user_pass'] = $request->get_param( 'password' );
    }

    $result = wp_update_user( $update );
    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 400 ] );
    }

    if ( $request->has_param( 'group' ) ) {
        update_user_meta( $user_id, 'snn_group', $request->get_param( 'group' ) );
    }

    $user = get_userdata( $user_id );
    return rest_ensure_response( [ 'success' => true, 'user' => snn_learn_rest_build_user_object( $user ) ] );
}

/**
 * DELETE /admin/users/{id}
 * Delete a user and all their enrolment records.
 */
function snn_learn_rest_admin_user_delete( $request ) {
    global $wpdb;
    $user_id = (int) $request->get_param( 'id' );
    $user    = get_userdata( $user_id );
    if ( ! $user ) {
        return new WP_Error( 'not_found', 'User not found.', [ 'status' => 404 ] );
    }

    $t = snn_learn_rest_table();

    // Delete all enrolment rows first
    $deleted_enrollments = (int) $wpdb->delete( $t, [ 'user_id' => $user_id ], [ '%d' ] );

    // Remove group meta
    delete_user_meta( $user_id, 'snn_group' );

    // Delete the WP user (requires wp-admin includes)
    if ( ! function_exists( 'wp_delete_user' ) ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    $deleted = wp_delete_user( $user_id );

    if ( ! $deleted ) {
        return new WP_Error( 'delete_failed', 'Failed to delete user.', [ 'status' => 500 ] );
    }

    return rest_ensure_response( [
        'success'             => true,
        'user_id'             => $user_id,
        'enrollments_removed' => $deleted_enrollments,
    ] );
}

/**
 * POST /admin/users/bulk-import
 * Bulk import users from a JSON array. Each entry: { username, email, display_name?, group?, password? }
 */
function snn_learn_rest_admin_users_bulk_import( $request ) {
    $users  = $request->get_param( 'users' );
    if ( ! is_array( $users ) ) {
        return new WP_Error( 'bad_format', 'users must be an array.', [ 'status' => 400 ] );
    }
    if ( count( $users ) > 500 ) {
        return new WP_Error( 'too_many', 'Maximum 500 users per batch.', [ 'status' => 400 ] );
    }

    $created = [];
    $skipped = [];
    $errors  = [];

    foreach ( $users as $i => $entry ) {
        $username = sanitize_user( $entry['username'] ?? '' );
        $email    = sanitize_email( $entry['email'] ?? '' );

        if ( ! $username || ! $email ) {
            $errors[] = [ 'index' => $i, 'error' => 'Missing username or email.' ];
            continue;
        }

        if ( username_exists( $username ) || email_exists( $email ) ) {
            $skipped[] = [ 'index' => $i, 'username' => $username, 'email' => $email, 'reason' => 'Already exists.' ];
            continue;
        }

        $uid = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'display_name' => sanitize_text_field( $entry['display_name'] ?? $username ),
            'user_pass'    => $entry['password'] ?? wp_generate_password(),
            'role'         => 'subscriber',
        ] );

        if ( is_wp_error( $uid ) ) {
            $errors[] = [ 'index' => $i, 'error' => $uid->get_error_message() ];
            continue;
        }

        if ( ! empty( $entry['group'] ) ) {
            update_user_meta( $uid, 'snn_group', sanitize_text_field( $entry['group'] ) );
        }

        $created[] = [ 'id' => $uid, 'username' => $username, 'email' => $email ];
    }

    return rest_ensure_response( [
        'success'  => empty( $errors ),
        'created'  => count( $created ),
        'skipped'  => count( $skipped ),
        'errors'   => count( $errors ),
        'details'  => compact( 'created', 'skipped', 'errors' ),
    ] );
}


// ============================================================
// 2. ADMIN ENROLLMENT MANAGEMENT — CALLBACKS
// ============================================================

/**
 * GET /admin/enrollments
 * List enrollments with filters: user_id, course_id, group, completed, date range.
 */
function snn_learn_rest_admin_enrollments_list( $request ) {
    global $wpdb;
    $t         = snn_learn_rest_table();
    $where     = [];
    $args      = [];
    $joins     = [];

    // Filter columns
    if ( $request->get_param( 'user_id' ) ) {
        $where[] = 'e.user_id = %d';
        $args[]  = (int) $request->get_param( 'user_id' );
    }
    if ( $request->get_param( 'course_id' ) ) {
        $where[] = 'e.course_id = %d';
        $args[]  = (int) $request->get_param( 'course_id' );
    }

    // Completed filter (null = all, true = completed, false = not completed)
    $completed = $request->get_param( 'completed' );
    if ( null !== $completed ) {
        if ( $completed ) {
            $where[] = 'e.completed_at IS NOT NULL';
        } else {
            $where[] = 'e.completed_at IS NULL';
        }
    }

    // Date range (enrolled_at)
    if ( $request->get_param( 'date_from' ) ) {
        $ts = strtotime( $request->get_param( 'date_from' ) );
        if ( $ts ) {
            $where[] = 'e.enrolled_at >= %d';
            $args[]  = $ts;
        }
    }
    if ( $request->get_param( 'date_to' ) ) {
        $ts = strtotime( $request->get_param( 'date_to' ) . ' +1 day' );
        if ( $ts ) {
            $where[] = 'e.enrolled_at < %d';
            $args[]  = $ts;
        }
    }

    // Group filter via user meta join
    if ( $request->get_param( 'group' ) ) {
        $joins[] = "INNER JOIN {$wpdb->usermeta} um ON e.user_id = um.user_id";
        $where[] = 'um.meta_key = %s AND um.meta_value = %s';
        $args[]  = 'snn_group';
        $args[]  = $request->get_param( 'group' );
    }

    $join_sql  = $joins ? ' ' . implode( ' ', $joins ) : '';
    $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

    // Only count course-level rows (is_course = 1) for a clean enrolments list
    $is_course_clause = $where ? ' AND e.is_course = 1' : 'WHERE e.is_course = 1';
    $where_sql .= $is_course_clause;

    $page   = (int) $request->get_param( 'page' );
    $per    = (int) $request->get_param( 'per_page' );
    $offset = ( $page - 1 ) * $per;

    $total = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM $t e $join_sql $where_sql", $args )
    );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT e.*, u.display_name, u.user_email, p.post_title AS course_title
             FROM $t e
             INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
             INNER JOIN {$wpdb->posts} p ON p.ID = e.course_id
             $join_sql
             $where_sql
             ORDER BY e.enrolled_at DESC
             LIMIT %d OFFSET %d",
            array_merge( $args, [ $per, $offset ] )
        )
    );

    $data = array_map( function ( $r ) {
        return [
            'id'               => (int) $r->id,
            'user_id'          => (int) $r->user_id,
            'user_name'        => $r->display_name,
            'user_email'       => $r->user_email,
            'course_id'        => (int) $r->course_id,
            'course_title'     => $r->course_title,
            'progress'         => snn_learn_calc_progress( (int) $r->user_id, (int) $r->course_id ),
            'enrolled_at'      => snn_learn_rest_ts_iso( $r->enrolled_at ),
            'completed_at'     => snn_learn_rest_ts_iso( $r->completed_at ),
            'last_activity_at' => snn_learn_rest_ts_iso( $r->last_activity_at ),
            'is_completed'     => ! empty( $r->completed_at ),
        ];
    }, $rows ?: [] );

    return rest_ensure_response( [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per,
        'enrollments' => $data,
    ] );
}

/**
 * POST /admin/enrollments
 * Enrol a single user in a single course.
 */
function snn_learn_rest_admin_enrollment_create( $request ) {
    $user_id   = (int) $request->get_param( 'user_id' );
    $course_id = (int) $request->get_param( 'course_id' );

    if ( ! get_userdata( $user_id ) ) {
        return new WP_Error( 'bad_user', 'User not found.', [ 'status' => 404 ] );
    }
    if ( ! get_post( $course_id ) ) {
        return new WP_Error( 'bad_course', 'Course not found.', [ 'status' => 404 ] );
    }

    // Verify this is actually a course (top-level post of correct type)
    $pt = snn_learn_get( 'course_post_type' );
    $post = get_post( $course_id );
    if ( ! $post || $post->post_type !== $pt || $post->post_parent !== 0 ) {
        return new WP_Error( 'bad_course', 'Not a valid course.', [ 'status' => 400 ] );
    }

    global $wpdb;
    $t   = snn_learn_rest_table();
    $now = time();

    // Check if already enrolled
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $t WHERE user_id = %d AND course_id = %d AND is_course = 1",
        $user_id, $course_id
    ) );

    if ( $existing ) {
        return new WP_Error( 'already_enrolled', 'User is already enrolled in this course.', [ 'status' => 409 ] );
    }

    // Insert the course-enrolment row
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $t (user_id, post_id, course_id, enrolled_at, last_activity_at, is_course, is_lesson)
         VALUES (%d, %d, %d, %d, %d, 1, 0)",
        $user_id, $course_id, $course_id, $now, $now
    ) );

    $new_id = (int) $wpdb->insert_id;

    // Fire the first-enrollment action
    do_action( 'snn_learn_first_enrollment', $user_id, $course_id );

    return rest_ensure_response( [
        'success'     => true,
        'id'          => $new_id,
        'user_id'     => $user_id,
        'course_id'   => $course_id,
        'enrolled_at' => snn_learn_rest_ts_iso( $now ),
    ] );
}

/**
 * DELETE /admin/enrollments/{id}
 * Delete a single enrolment row.
 */
function snn_learn_rest_admin_enrollment_delete( $request ) {
    global $wpdb;
    $t     = snn_learn_rest_table();
    $id    = (int) $request->get_param( 'id' );
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );

    if ( ! $row ) {
        return new WP_Error( 'not_found', 'Enrolment not found.', [ 'status' => 404 ] );
    }

    $deleted = $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );

    return rest_ensure_response( [
        'success'      => (bool) $deleted,
        'id'           => $id,
        'user_id'      => (int) $row->user_id,
        'course_id'    => (int) $row->course_id,
    ] );
}

/**
 * POST /admin/enrollments/bulk
 * Bulk enrol an array of { user_id, course_id } pairs.
 */
function snn_learn_rest_admin_enrollments_bulk( $request ) {
    $enrollments = $request->get_param( 'enrollments' );
    if ( ! is_array( $enrollments ) ) {
        return new WP_Error( 'bad_format', 'enrollments must be an array.', [ 'status' => 400 ] );
    }
    if ( count( $enrollments ) > 1000 ) {
        return new WP_Error( 'too_many', 'Maximum 1000 pairs per batch.', [ 'status' => 400 ] );
    }

    global $wpdb;
    $t       = snn_learn_rest_table();
    $now     = time();
    $created = 0;
    $skipped = 0;
    $errors  = [];

    foreach ( $enrollments as $i => $pair ) {
        $user_id   = (int) ( $pair['user_id'] ?? 0 );
        $course_id = (int) ( $pair['course_id'] ?? 0 );

        if ( ! $user_id || ! $course_id || ! get_userdata( $user_id ) || ! get_post( $course_id ) ) {
            $errors[] = [ 'index' => $i, 'error' => 'Invalid user_id or course_id.' ];
            continue;
        }

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE user_id = %d AND course_id = %d AND is_course = 1",
            $user_id, $course_id
        ) );

        if ( $existing ) {
            $skipped++;
            continue;
        }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $t (user_id, post_id, course_id, enrolled_at, last_activity_at, is_course, is_lesson)
             VALUES (%d, %d, %d, %d, %d, 1, 0)",
            $user_id, $course_id, $course_id, $now, $now
        ) );

        do_action( 'snn_learn_first_enrollment', $user_id, $course_id );
        $created++;
    }

    return rest_ensure_response( [
        'success' => empty( $errors ),
        'created' => $created,
        'skipped' => $skipped,
        'errors'  => count( $errors ),
        'details' => $errors,
    ] );
}


// ============================================================
// 3. COURSE CONTENT API — CALLBACKS
// ============================================================

/**
 * GET /courses
 * List all published courses (top-level posts of the configured post type).
 */
function snn_learn_rest_courses_list( $request ) {
    $pt      = snn_learn_get( 'course_post_type' );
    $search  = $request->get_param( 'search' );
    $page    = (int) $request->get_param( 'page' );
    $per     = (int) $request->get_param( 'per_page' );

    $query = [
        'post_type'      => $pt,
        'post_parent'    => 0,
        'post_status'    => 'publish',
        'posts_per_page' => $per,
        'paged'          => $page,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ];
    if ( $search ) {
        $query['s'] = $search;
    }

    $q      = new WP_Query( $query );
    $user_id = get_current_user_id();

    $courses = array_map( function ( $p ) use ( $user_id ) {
        $lessons = snn_learn_get_course_lessons( $p->ID );
        $total   = count( $lessons );
        $progress = $user_id ? snn_learn_calc_progress( $user_id, $p->ID ) : 0;

        return [
            'id'              => $p->ID,
            'title'           => $p->post_title,
            'slug'            => $p->post_name,
            'excerpt'         => get_the_excerpt( $p ),
            'total_lessons'   => $total,
            'progress'        => $progress,
            'is_completed'    => $progress >= 100,
        ];
    }, $q->posts );

    return rest_ensure_response( [
        'total'    => (int) $q->found_posts,
        'page'     => $page,
        'per_page' => $per,
        'courses'  => $courses,
    ] );
}

/**
 * GET /courses/{id}
 * Get a single course with its full chapter → lesson hierarchy.
 */
function snn_learn_rest_course_get( $request ) {
    $course_id = (int) $request->get_param( 'id' );
    $post      = get_post( $course_id );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Course not found.', [ 'status' => 404 ] );
    }

    $pt       = snn_learn_get( 'course_post_type' );
    $user_id  = get_current_user_id();
    $lessons  = snn_learn_get_course_lessons( $course_id );

    // Pre-fetch completed lesson IDs
    $completed_ids = [];
    if ( $user_id ) {
        global $wpdb;
        $t    = snn_learn_rest_table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id FROM $t WHERE user_id = %d AND course_id = %d AND completed_at IS NOT NULL",
            $user_id, $course_id
        ) );
        $completed_ids = array_map( 'intval', array_column( $rows ?: [], 'post_id' ) );
    }

    // Build chapter → lesson tree
    $chapters = get_posts( [
        'post_type'      => $pt,
        'post_parent'    => $course_id,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ] );

    $lessons_by_chapter = [];
    foreach ( get_posts( [
        'post_type'       => $pt,
        'post_parent__in' => wp_list_pluck( $chapters ?: [], 'ID' ),
        'post_status'     => 'publish',
        'posts_per_page'  => -1,
        'orderby'         => 'menu_order',
        'order'           => 'ASC',
    ] ) as $l ) {
        $lessons_by_chapter[ $l->post_parent ][] = $l;
    }

    $chapters_out = [];
    foreach ( $chapters as $ch ) {
        $ch_lessons = [];
        foreach ( $lessons_by_chapter[ $ch->ID ] ?? [] as $l ) {
            $ch_lessons[] = [
                'id'          => $l->ID,
                'title'       => $l->post_title,
                'slug'        => $l->post_name,
                'permalink'   => get_permalink( $l->ID ),
                'completed'   => in_array( $l->ID, $completed_ids, true ),
            ];
        }
        $chapters_out[] = [
            'id'       => $ch->ID,
            'title'    => $ch->post_title,
            'lessons'  => $ch_lessons,
        ];
    }

    $total_lessons     = count( $lessons );
    $completed_lessons = count( $completed_ids );

    // Enrolment count
    global $wpdb;
    $t = snn_learn_rest_table();
    $enrolled_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE course_id = %d AND is_course = 1", $course_id
    ) );

    return rest_ensure_response( [
        'id'                => $course_id,
        'title'             => $post->post_title,
        'slug'              => $post->post_name,
        'chapters'          => $chapters_out,
        'total_lessons'     => $total_lessons,
        'completed_lessons' => $completed_lessons,
        'progress'          => $user_id ? snn_learn_calc_progress( $user_id, $course_id ) : 0,
        'enrolled_count'    => $enrolled_count,
    ] );
}

/**
 * GET /courses/{id}/chapters
 * List chapters for a course.
 */
function snn_learn_rest_course_chapters( $request ) {
    $course_id = (int) $request->get_param( 'id' );
    $pt        = snn_learn_get( 'course_post_type' );

    $chapters = get_posts( [
        'post_type'      => $pt,
        'post_parent'    => $course_id,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ] );

    $data = array_map( function ( $ch ) {
        return [
            'id'         => $ch->ID,
            'title'      => $ch->post_title,
            'slug'       => $ch->post_name,
            'menu_order' => $ch->menu_order,
        ];
    }, $chapters ?: [] );

    return rest_ensure_response( [ 'chapters' => $data ] );
}

/**
 * GET /courses/{id}/lessons
 * Get ALL lessons for a course, ordered by chapter then menu_order.
 */
function snn_learn_rest_course_lessons( $request ) {
    $course_id  = (int) $request->get_param( 'id' );
    $lesson_ids = snn_learn_get_course_lessons( $course_id );
    $user_id    = get_current_user_id();

    // Pre-fetch completed lesson IDs
    $completed_ids = [];
    if ( $user_id ) {
        global $wpdb;
        $t    = snn_learn_rest_table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id FROM $t WHERE user_id = %d AND course_id = %d AND completed_at IS NOT NULL",
            $user_id, $course_id
        ) );
        $completed_ids = array_map( 'intval', array_column( $rows ?: [], 'post_id' ) );
    }

    $data = array_map( function ( $lid ) use ( $completed_ids ) {
        return [
            'id'        => $lid,
            'title'     => get_the_title( $lid ),
            'permalink' => get_permalink( $lid ),
            'completed' => in_array( $lid, $completed_ids, true ),
        ];
    }, $lesson_ids );

    return rest_ensure_response( [ 'lessons' => $data, 'total' => count( $data ) ] );
}

/**
 * GET /lessons/{id}
 * Get a single lesson's details including its video URL.
 */
function snn_learn_rest_lesson_get( $request ) {
    $lesson_id = (int) $request->get_param( 'id' );
    $post      = get_post( $lesson_id );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Lesson not found.', [ 'status' => 404 ] );
    }

    $course_id   = snn_learn_get_course_id( $lesson_id );
    $video_field = snn_learn_get( 'video_field' );
    $video_url   = get_post_meta( $lesson_id, $video_field, true );

    // Unwrap array-stored video URLs
    if ( is_array( $video_url ) ) {
        $video_url = isset( $video_url['url'] ) ? $video_url['url'] : reset( $video_url );
        if ( is_array( $video_url ) ) {
            $video_url = isset( $video_url['url'] ) ? $video_url['url'] : (string) reset( $video_url );
        }
        $video_url = (string) $video_url;
    }

    $user_id   = get_current_user_id();
    $completed = false;
    $progress  = null;

    if ( $user_id ) {
        global $wpdb;
        $t = snn_learn_rest_table();
        $completed = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT completed_at FROM $t WHERE user_id = %d AND post_id = %d AND completed_at IS NOT NULL",
            $user_id, $lesson_id
        ) );
        if ( $course_id ) {
            $progress = snn_learn_calc_progress( $user_id, $course_id );
        }
    }

    return rest_ensure_response( [
        'id'             => $lesson_id,
        'title'          => $post->post_title,
        'slug'           => $post->post_name,
        'course_id'      => $course_id,
        'video_url'      => $video_url ?: null,
        'has_video'      => ! empty( $video_url ),
        'completed'      => $completed,
        'course_progress'=> $progress,
    ] );
}


// ============================================================
// 4. GROUP / DEPARTMENT MANAGEMENT — CALLBACKS
// ============================================================

/**
 * GET /admin/groups
 * List all groups stored in wp_usermeta (distinct snn_group values).
 * Also reads a snn_groups_list option for group descriptions.
 */
function snn_learn_rest_admin_groups_list( $request ) {
    global $wpdb;

    // Get distinct group names from user meta
    $names = $wpdb->get_col(
        "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value != '' ORDER BY meta_value ASC"
    );

    // Get group metadata (descriptions) from the options store
    $group_data = get_option( 'snn_groups_list', [] );

    $groups = array_map( function ( $name ) use ( $wpdb, $group_data ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value = %s",
            $name
        ) );
        return [
            'slug'        => sanitize_title( $name ),
            'name'        => $name,
            'description' => $group_data[ $name ]['description'] ?? '',
            'user_count'  => $count,
        ];
    }, $names ?: [] );

    return rest_ensure_response( [ 'groups' => $groups, 'total' => count( $groups ) ] );
}

/**
 * POST /admin/groups
 * Create a new group. The group name is stored in user meta (as a string value)
 * when users are assigned. Group metadata (description) is stored in an option.
 */
function snn_learn_rest_admin_group_create( $request ) {
    $name        = $request->get_param( 'name' );
    $description = $request->get_param( 'description' ) ?: '';
    $slug        = sanitize_title( $name );

    // Check it doesn't already exist in the meta
    global $wpdb;
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value = %s LIMIT 1",
        $name
    ) );

    $group_data = get_option( 'snn_groups_list', [] );
    if ( $exists || isset( $group_data[ $name ] ) ) {
        return new WP_Error( 'duplicate', 'A group with this name already exists.', [ 'status' => 409 ] );
    }

    $group_data[ $name ] = [ 'description' => $description, 'created_at' => time() ];
    update_option( 'snn_groups_list', $group_data );

    return rest_ensure_response( [
        'success'     => true,
        'slug'        => $slug,
        'name'        => $name,
        'description' => $description,
        'user_count'  => 0,
    ] );
}

/**
 * PUT /admin/groups/{slug}
 * Update a group's name or description.
 */
function snn_learn_rest_admin_group_update( $request ) {
    $slug        = $request->get_param( 'slug' );
    $new_name    = $request->get_param( 'name' );
    $description = $request->get_param( 'description' );

    // Resolve current name from slug
    global $wpdb;
    $old_name = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value LIKE %s LIMIT 1",
        $wpdb->esc_like( $slug ) . '%'
    ) );

    $group_data = get_option( 'snn_groups_list', [] );

    if ( ! $old_name && ! isset( $group_data[ $slug ] ) ) {
        // Fallback: look up in group_data by key
        foreach ( $group_data as $k => $v ) {
            if ( sanitize_title( $k ) === $slug ) {
                $old_name = $k;
                break;
            }
        }
    }

    if ( ! $old_name ) {
        return new WP_Error( 'not_found', 'Group not found.', [ 'status' => 404 ] );
    }

    // Update group metadata in the option
    if ( isset( $group_data[ $old_name ] ) ) {
        if ( $new_name && $new_name !== $old_name ) {
            $group_data[ $new_name ] = $group_data[ $old_name ];
            unset( $group_data[ $old_name ] );
            if ( $description !== null ) {
                $group_data[ $new_name ]['description'] = $description;
            }

            // Rename all users with the old group name
            $wpdb->update(
                $wpdb->usermeta,
                [ 'meta_value' => $new_name ],
                [ 'meta_key' => 'snn_group', 'meta_value' => $old_name ],
                [ '%s' ],
                [ '%s', '%s' ]
            );
            $old_name = $new_name;
        } elseif ( $description !== null ) {
            $group_data[ $old_name ]['description'] = $description;
        }
    }

    update_option( 'snn_groups_list', $group_data );

    return rest_ensure_response( [
        'success'     => true,
        'slug'        => sanitize_title( $old_name ),
        'name'        => $old_name,
        'description' => $group_data[ $old_name ]['description'] ?? '',
    ] );
}

/**
 * DELETE /admin/groups/{slug}
 * Delete a group and remove all users from it.
 */
function snn_learn_rest_admin_group_delete( $request ) {
    $slug = $request->get_param( 'slug' );

    global $wpdb;
    $name = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value LIKE %s LIMIT 1",
        $wpdb->esc_like( $slug ) . '%'
    ) );

    $group_data = get_option( 'snn_groups_list', [] );

    if ( ! $name && ! isset( $group_data[ $slug ] ) ) {
        foreach ( $group_data as $k => $v ) {
            if ( sanitize_title( $k ) === $slug ) {
                $name = $k;
                break;
            }
        }
    }

    if ( ! $name ) {
        return new WP_Error( 'not_found', 'Group not found.', [ 'status' => 404 ] );
    }

    // Remove group from all users
    $affected = (int) $wpdb->delete(
        $wpdb->usermeta,
        [ 'meta_key' => 'snn_group', 'meta_value' => $name ],
        [ '%s', '%s' ]
    );

    // Remove from group metadata
    if ( isset( $group_data[ $name ] ) ) {
        unset( $group_data[ $name ] );
        update_option( 'snn_groups_list', $group_data );
    }

    return rest_ensure_response( [
        'success'        => true,
        'slug'           => $slug,
        'users_removed'  => $affected,
    ] );
}

/**
 * GET /admin/groups/{slug}/users
 * List all users belonging to a group.
 */
function snn_learn_rest_admin_groups_users_list( $request ) {
    $slug = $request->get_param( 'slug' );
    $page = (int) $request->get_param( 'page' );
    $per  = (int) $request->get_param( 'per_page' );

    global $wpdb;

    // Resolve group name from slug
    $name = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value LIKE %s LIMIT 1",
        $wpdb->esc_like( $slug ) . '%'
    ) );

    if ( ! $name ) {
        // Try exact match in group_data
        $group_data = get_option( 'snn_groups_list', [] );
        foreach ( $group_data as $k => $v ) {
            if ( sanitize_title( $k ) === $slug ) {
                $name = $k;
                break;
            }
        }
    }

    if ( ! $name ) {
        return new WP_Error( 'not_found', 'Group not found.', [ 'status' => 404 ] );
    }

    $offset = ( $page - 1 ) * $per;

    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value = %s",
        $name
    ) );

    $user_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value = %s ORDER BY user_id ASC LIMIT %d OFFSET %d",
        $name, $per, $offset
    ) );

    $users = array_map( function ( $uid ) {
        $u = get_userdata( $uid );
        return $u ? snn_learn_rest_build_user_object( $u ) : null;
    }, $user_ids ?: [] );

    return rest_ensure_response( [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per,
        'users'    => array_filter( $users ),
    ] );
}

/**
 * POST /admin/groups/{slug}/users
 * Add one or more users to a group by their IDs.
 */
function snn_learn_rest_admin_groups_users_add( $request ) {
    $slug     = $request->get_param( 'slug' );
    $user_ids = $request->get_param( 'user_ids' );

    if ( ! is_array( $user_ids ) ) {
        return new WP_Error( 'bad_format', 'user_ids must be an array.', [ 'status' => 400 ] );
    }

    global $wpdb;

    // Resolve name
    $name = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value LIKE %s LIMIT 1",
        $wpdb->esc_like( $slug ) . '%'
    ) );

    $group_data = get_option( 'snn_groups_list', [] );
    if ( ! $name ) {
        foreach ( $group_data as $k => $v ) {
            if ( sanitize_title( $k ) === $slug ) {
                $name = $k;
                break;
            }
        }
    }

    // Auto-create the group if it doesn't exist yet
    if ( ! $name ) {
        $name = str_replace( '-', ' ', $slug );
        $group_data[ $name ] = [ 'description' => '', 'created_at' => time() ];
        update_option( 'snn_groups_list', $group_data );
    }

    $added   = 0;
    $skipped = 0;

    foreach ( $user_ids as $uid ) {
        $uid = absint( $uid );
        if ( ! $uid || ! get_userdata( $uid ) ) {
            continue;
        }
        update_user_meta( $uid, 'snn_group', $name );
        $added++;
    }

    return rest_ensure_response( [
        'success' => true,
        'added'   => $added,
        'skipped' => $skipped,
    ] );
}

/**
 * DELETE /admin/groups/{slug}/users/{user_id}
 * Remove a single user from a group.
 */
function snn_learn_rest_admin_group_user_remove( $request ) {
    $slug    = $request->get_param( 'slug' );
    $user_id = (int) $request->get_param( 'user_id' );

    if ( ! get_userdata( $user_id ) ) {
        return new WP_Error( 'not_found', 'User not found.', [ 'status' => 404 ] );
    }

    delete_user_meta( $user_id, 'snn_group' );

    return rest_ensure_response( [ 'success' => true, 'user_id' => $user_id ] );
}


// ============================================================
// 5. ANALYTICS & REPORTING — CALLBACKS
// ============================================================

/**
 * GET /admin/analytics/overview
 * Aggregate KPIs for the dashboard.
 */
function snn_learn_rest_admin_analytics_overview( $request ) {
    global $wpdb;
    $t        = snn_learn_rest_table();
    $group    = $request->get_param( 'group' );
    $days     = (int) $request->get_param( 'days' );

    $ts_days   = time() - ( $days * DAY_IN_SECONDS );
    $ts_14     = time() - ( 14 * DAY_IN_SECONDS );
    $ts_7      = time() - (  7 * DAY_IN_SECONDS );
    $wp_offset = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

    // Build a group-scoped user subquery
    $user_filter = '';
    $user_args   = [];
    if ( $group ) {
        $user_filter = "AND e.user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value = %s)";
        $user_args[] = $group;
    }

    // Total enrolments
    $args_total = array_merge( [ 1 ], $user_args );
    $total_enrollments = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM $t e WHERE e.is_course = %d $user_filter", $args_total )
    );

    // Recent enrolments
    $args_recent = array_merge( [ 1, $ts_days ], $user_args );
    $recent_enrollments = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM $t e WHERE e.is_course = %d AND e.enrolled_at >= %d $user_filter", $args_recent )
    );

    // Total + completed items (lessons + chapters)
    $args_items = $user_args;
    $total_items     = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM $t e WHERE e.is_course = 0 $user_filter", $args_items )
    );
    $completed_items = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM $t e WHERE e.is_course = 0 AND e.completed_at IS NOT NULL $user_filter", $args_items )
    );

    // Completion rate
    $completion_rate = 0;
    if ( $total_items > 0 ) {
        $completion_rate = round( ( $completed_items / $total_items ) * 100, 1 );
    }

    // Weekly active users
    $args_weekly = array_merge( [ $ts_7 ], $user_args );
    $weekly_active = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(DISTINCT e.user_id) FROM $t e WHERE e.last_activity_at >= %d $user_filter", $args_weekly )
    );

    // Gone cold = 14+ days inactive and not completed
    $args_cold = array_merge( [ $ts_14 ], $user_args );
    $gone_cold = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(DISTINCT e.user_id) FROM $t e WHERE e.is_course = 1 AND e.last_activity_at < %d AND e.completed_at IS NULL $user_filter", $args_cold )
    );

    // Active courses (courses with any enrolment)
    $active_courses = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(DISTINCT e.course_id) FROM $t e WHERE e.is_course = 1 $user_filter", $user_args )
    );

    // Avg days to complete
    $avg_days = (float) $wpdb->get_var(
        $wpdb->prepare( "SELECT AVG(e.completed_at - e.enrolled_at) / 86400 FROM $t e WHERE e.is_course = 1 AND e.completed_at IS NOT NULL $user_filter", $user_args )
    );

    // Peak enrolment day
    $peak = $wpdb->get_row( $wpdb->prepare(
        "SELECT ((e.enrolled_at + %d) DIV 86400) * 86400 AS day_ts, COUNT(*) AS cnt
         FROM $t e WHERE e.is_course = 1 GROUP BY day_ts ORDER BY cnt DESC LIMIT 1",
        $wp_offset
    ) );

    $peak_date = null;
    $peak_cnt  = 0;
    if ( $peak ) {
        $peak_date = wp_date( 'Y-m-d', (int) $peak->day_ts - $wp_offset );
        $peak_cnt  = (int) $peak->cnt;
    }

    return rest_ensure_response( [
        'total_enrollments'  => $total_enrollments,
        'recent_enrollments' => $recent_enrollments,
        'days_considered'    => $days,
        'completion_rate'    => $completion_rate,
        'total_items'        => $total_items,
        'completed_items'    => $completed_items,
        'weekly_active'      => $weekly_active,
        'gone_cold'          => $gone_cold,
        'active_courses'     => $active_courses,
        'avg_days_complete'  => $avg_days ? round( $avg_days, 1 ) : null,
        'peak_enrollment'    => [ 'date' => $peak_date, 'count' => $peak_cnt ],
    ] );
}

/**
 * GET /admin/analytics/course/{id}
 * Deep dive on a single course.
 */
function snn_learn_rest_admin_analytics_course( $request ) {
    global $wpdb;
    $t         = snn_learn_rest_table();
    $course_id = (int) $request->get_param( 'id' );

    if ( ! get_post( $course_id ) ) {
        return new WP_Error( 'not_found', 'Course not found.', [ 'status' => 404 ] );
    }

    $total_lessons = count( snn_learn_get_course_lessons( $course_id ) );

    $enrolled  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE course_id = %d AND is_course = 1", $course_id
    ) );
    $completed = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE course_id = %d AND is_course = 1 AND completed_at IS NOT NULL", $course_id
    ) );

    // Per-user progress breakdown
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT e.user_id, u.display_name, u.user_email,
                e.enrolled_at, e.completed_at, e.last_activity_at
         FROM $t e
         INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
         WHERE e.course_id = %d AND e.is_course = 1
         ORDER BY e.enrolled_at DESC
         LIMIT 200",
        $course_id
    ) );

    $users = array_map( function ( $r ) use ( $total_lessons ) {
        $uid = (int) $r->user_id;
        return [
            'user_id'          => $uid,
            'display_name'     => $r->display_name,
            'email'            => $r->user_email,
            'progress'         => snn_learn_calc_progress( $uid, (int) $r->course_id ?? 0 ),
            'enrolled_at'      => snn_learn_rest_ts_iso( $r->enrolled_at ),
            'completed_at'     => snn_learn_rest_ts_iso( $r->completed_at ),
            'last_activity_at' => snn_learn_rest_ts_iso( $r->last_activity_at ),
            'is_completed'     => ! empty( $r->completed_at ),
        ];
    }, $rows ?: [] );

    $rate = $enrolled > 0 ? round( ( $completed / $enrolled ) * 100, 1 ) : 0;

    return rest_ensure_response( [
        'course_id'      => $course_id,
        'course_title'   => get_the_title( $course_id ),
        'total_lessons'  => $total_lessons,
        'enrolled'       => $enrolled,
        'completed'      => $completed,
        'completion_rate'=> $rate,
        'users'          => $users,
    ] );
}

/**
 * GET /admin/analytics/user/{id}
 * Deep dive on a single user across all their courses.
 */
function snn_learn_rest_admin_analytics_user( $request ) {
    global $wpdb;
    $t       = snn_learn_rest_table();
    $user_id = (int) $request->get_param( 'id' );
    $user    = get_userdata( $user_id );
    if ( ! $user ) {
        return new WP_Error( 'not_found', 'User not found.', [ 'status' => 404 ] );
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT course_id, enrolled_at, completed_at, last_activity_at FROM $t
         WHERE user_id = %d AND is_course = 1 ORDER BY enrolled_at DESC",
        $user_id
    ) );

    $courses = array_map( function ( $r ) use ( $user_id ) {
        $cid = (int) $r->course_id;
        return [
            'course_id'        => $cid,
            'course_title'     => get_the_title( $cid ) ?: '(Untitled)',
            'progress'         => snn_learn_calc_progress( $user_id, $cid ),
            'total_lessons'    => count( snn_learn_get_course_lessons( $cid ) ),
            'enrolled_at'      => snn_learn_rest_ts_iso( $r->enrolled_at ),
            'completed_at'     => snn_learn_rest_ts_iso( $r->completed_at ),
            'last_activity_at' => snn_learn_rest_ts_iso( $r->last_activity_at ),
            'is_completed'     => ! empty( $r->completed_at ),
        ];
    }, $rows ?: [] );

    return rest_ensure_response( [
        'user'    => snn_learn_rest_build_user_object( $user ),
        'courses' => $courses,
    ] );
}

/**
 * GET /admin/analytics/trends/enrollment
 * Daily enrolment counts for chart data.
 */
function snn_learn_rest_admin_analytics_trend_enrollment( $request ) {
    global $wpdb;
    $t         = snn_learn_rest_table();
    $days      = (int) $request->get_param( 'days' );
    $course_id = $request->get_param( 'course_id' );
    $ts_cut    = time() - ( $days * DAY_IN_SECONDS );
    $wp_offset = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

    $where  = 'is_course = 1 AND enrolled_at >= %d';
    $args   = [ $ts_cut ];
    if ( $course_id ) {
        $where  .= ' AND course_id = %d';
        $args[]  = (int) $course_id;
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ((enrolled_at + %d) DIV 86400) AS day_key, COUNT(*) AS cnt
         FROM $t WHERE $where GROUP BY day_key ORDER BY day_key DESC LIMIT %d",
        array_merge( [ $wp_offset ], $args, [ $days ] )
    ) );

    $rows  = array_reverse( $rows ?: [] );
    $labels = array_map( function ( $r ) use ( $wp_offset ) {
        return wp_date( 'Y-m-d', (int) $r->day_key * 86400 - $wp_offset );
    }, $rows );
    $values = array_map( function ( $r ) { return (int) $r->cnt; }, $rows );

    return rest_ensure_response( [ 'labels' => $labels, 'values' => $values ] );
}

/**
 * GET /admin/analytics/trends/completion
 * Daily lesson completion counts for chart data.
 */
function snn_learn_rest_admin_analytics_trend_completion( $request ) {
    global $wpdb;
    $t         = snn_learn_rest_table();
    $days      = (int) $request->get_param( 'days' );
    $course_id = $request->get_param( 'course_id' );
    $ts_cut    = time() - ( $days * DAY_IN_SECONDS );
    $wp_offset = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

    $where  = 'is_lesson = 1 AND completed_at IS NOT NULL AND completed_at >= %d';
    $args   = [ $ts_cut ];
    if ( $course_id ) {
        $where  .= ' AND course_id = %d';
        $args[]  = (int) $course_id;
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ((completed_at + %d) DIV 86400) AS day_key, COUNT(*) AS cnt
         FROM $t WHERE $where GROUP BY day_key ORDER BY day_key DESC LIMIT %d",
        array_merge( [ $wp_offset ], $args, [ $days ] )
    ) );

    $rows   = array_reverse( $rows ?: [] );
    $labels = array_map( function ( $r ) use ( $wp_offset ) {
        return wp_date( 'Y-m-d', (int) $r->day_key * 86400 - $wp_offset );
    }, $rows );
    $values = array_map( function ( $r ) { return (int) $r->cnt; }, $rows );

    return rest_ensure_response( [ 'labels' => $labels, 'values' => $values ] );
}

/**
 * GET /admin/analytics/leaderboard
 * Top users by lesson completion count for a specific course.
 */
function snn_learn_rest_admin_analytics_leaderboard( $request ) {
    global $wpdb;
    $t         = snn_learn_rest_table();
    $course_id = (int) $request->get_param( 'course_id' );
    $limit     = (int) $request->get_param( 'limit' );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT e.user_id, u.display_name, COUNT(*) AS lessons_done
         FROM $t e
         INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
         WHERE e.course_id = %d AND e.is_lesson = 1 AND e.completed_at IS NOT NULL
         GROUP BY e.user_id
         ORDER BY lessons_done DESC
         LIMIT %d",
        $course_id, $limit
    ) );

    $total_lessons = count( snn_learn_get_course_lessons( $course_id ) );
    $rank = 1;

    $data = array_map( function ( $r ) use ( &$rank, $total_lessons ) {
        return [
            'rank'          => $rank++,
            'user_id'       => (int) $r->user_id,
            'display_name'  => $r->display_name,
            'lessons_done'  => (int) $r->lessons_done,
            'total_lessons' => $total_lessons,
            'percentage'    => $total_lessons > 0 ? round( ( (int) $r->lessons_done / $total_lessons ) * 100, 1 ) : 0,
        ];
    }, $rows ?: [] );

    return rest_ensure_response( [ 'leaderboard' => $data, 'course_id' => $course_id ] );
}


// ============================================================
// 6. BULK OPERATIONS — CALLBACKS
// ============================================================

/**
 * POST /admin/bulk/enroll
 * Enrol multiple users in a single course.
 * Body: { user_ids: [1,2,3], course_id: 42 }
 */
function snn_learn_rest_admin_bulk_enroll( $request ) {
    $user_ids  = $request->get_param( 'user_ids' );
    $course_id = (int) $request->get_param( 'course_id' );

    if ( ! is_array( $user_ids ) ) {
        return new WP_Error( 'bad_format', 'user_ids must be an array.', [ 'status' => 400 ] );
    }
    if ( count( $user_ids ) > 1000 ) {
        return new WP_Error( 'too_many', 'Maximum 1000 users per batch.', [ 'status' => 400 ] );
    }
    if ( ! get_post( $course_id ) ) {
        return new WP_Error( 'bad_course', 'Course not found.', [ 'status' => 404 ] );
    }

    global $wpdb;
    $t       = snn_learn_rest_table();
    $now     = time();
    $created = 0;
    $skipped = 0;

    foreach ( $user_ids as $uid ) {
        $uid = absint( $uid );
        if ( ! $uid || ! get_userdata( $uid ) ) continue;

        $exists = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM $t WHERE user_id = %d AND course_id = %d AND is_course = 1",
            $uid, $course_id
        ) );

        if ( $exists ) { $skipped++; continue; }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $t (user_id, post_id, course_id, enrolled_at, last_activity_at, is_course, is_lesson)
             VALUES (%d, %d, %d, %d, %d, 1, 0)",
            $uid, $course_id, $course_id, $now, $now
        ) );

        do_action( 'snn_learn_first_enrollment', $uid, $course_id );
        $created++;
    }

    return rest_ensure_response( [
        'success' => true,
        'created' => $created,
        'skipped' => $skipped,
    ] );
}

/**
 * POST /admin/bulk/mark-complete
 * Admin manually marks a lesson complete for multiple users.
 * Body: { user_ids: [1,2,3], lesson_id: 45 }
 */
function snn_learn_rest_admin_bulk_mark_complete( $request ) {
    $user_ids  = $request->get_param( 'user_ids' );
    $lesson_id = (int) $request->get_param( 'lesson_id' );

    if ( ! is_array( $user_ids ) ) {
        return new WP_Error( 'bad_format', 'user_ids must be an array.', [ 'status' => 400 ] );
    }
    if ( count( $user_ids ) > 500 ) {
        return new WP_Error( 'too_many', 'Maximum 500 users per batch.', [ 'status' => 400 ] );
    }
    if ( ! get_post( $lesson_id ) ) {
        return new WP_Error( 'bad_lesson', 'Lesson not found.', [ 'status' => 404 ] );
    }

    $course_id = snn_learn_get_course_id( $lesson_id );
    if ( ! $course_id ) {
        return new WP_Error( 'no_course', 'Could not resolve course for this lesson.', [ 'status' => 400 ] );
    }

    $updated = 0;
    $skipped = 0;

    foreach ( $user_ids as $uid ) {
        $uid = absint( $uid );
        if ( ! $uid || ! get_userdata( $uid ) ) continue;

        // Use the existing helper — it handles upsert + chapter/course auto-completion
        snn_learn_record_lesson( $uid, $lesson_id, $course_id, true );
        $updated++;
    }

    return rest_ensure_response( [
        'success'   => true,
        'updated'   => $updated,
        'skipped'   => $skipped,
        'lesson_id' => $lesson_id,
        'course_id' => $course_id,
    ] );
}

/**
 * POST /admin/bulk/assign-group
 * Assign multiple users to a group.
 * Body: { user_ids: [1,2,3], group: "Sales" }
 */
function snn_learn_rest_admin_bulk_assign_group( $request ) {
    $user_ids = $request->get_param( 'user_ids' );
    $group    = $request->get_param( 'group' );

    if ( ! is_array( $user_ids ) ) {
        return new WP_Error( 'bad_format', 'user_ids must be an array.', [ 'status' => 400 ] );
    }
    if ( count( $user_ids ) > 500 ) {
        return new WP_Error( 'too_many', 'Maximum 500 users per batch.', [ 'status' => 400 ] );
    }
    if ( empty( $group ) ) {
        return new WP_Error( 'bad_group', 'group is required.', [ 'status' => 400 ] );
    }

    // Ensure group metadata exists
    $group_data = get_option( 'snn_groups_list', [] );
    if ( ! isset( $group_data[ $group ] ) ) {
        $group_data[ $group ] = [ 'description' => '', 'created_at' => time() ];
        update_option( 'snn_groups_list', $group_data );
    }

    $assigned = 0;
    foreach ( $user_ids as $uid ) {
        $uid = absint( $uid );
        if ( ! $uid || ! get_userdata( $uid ) ) continue;
        update_user_meta( $uid, 'snn_group', $group );
        $assigned++;
    }

    return rest_ensure_response( [
        'success'  => true,
        'assigned' => $assigned,
        'group'    => $group,
    ] );
}


// ============================================================
// 7. CSV EXPORTS — CALLBACKS
// ============================================================

/**
 * GET /admin/export/users
 * Export users CSV. Optional filters: group, course_id.
 */
function snn_learn_rest_admin_export_users( $request ) {
    global $wpdb;
    $t       = snn_learn_rest_table();
    $group   = $request->get_param( 'group' );
    $course  = $request->get_param( 'course_id' );

    $joins = '';
    $where = [];
    $args  = [];

    if ( $group ) {
        $joins  .= " INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id";
        $where[] = 'um.meta_key = %s AND um.meta_value = %s';
        $args[]  = 'snn_group'; $args[] = $group;
    }
    if ( $course ) {
        $joins  .= " LEFT JOIN $t e ON u.ID = e.user_id AND e.course_id = %d AND e.is_course = 1";
        $args[]  = (int) $course;
        $where[] = 'e.user_id IS NOT NULL';
    }

    $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT u.ID, u.display_name, u.user_email, u.user_registered
             FROM {$wpdb->users} u $joins $where_sql
             ORDER BY u.display_name ASC
             LIMIT 10000",
            $args
        )
    );

    $csv_headers = [ 'ID', 'Display Name', 'Email', 'Registered', 'Group', 'Enrollments', 'Completed Courses', 'Last Active' ];
    $csv_rows    = [];

    foreach ( $rows ?: [] as $u ) {
        $uid   = (int) $u->ID;
        $group = get_user_meta( $uid, 'snn_group', true ) ?: '';
        $enr   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND is_course = 1", $uid
        ) );
        $done  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND is_course = 1 AND completed_at IS NOT NULL", $uid
        ) );
        $last  = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(last_activity_at) FROM $t WHERE user_id = %d", $uid
        ) );

        $csv_rows[] = [
            $uid,
            $u->display_name,
            $u->user_email,
            $u->user_registered,
            $group,
            $enr,
            $done,
            $last ? wp_date( 'Y-m-d H:i:s', (int) $last ) : '',
        ];
    }

    return snn_learn_rest_output_csv( 'snn-learn-users', $csv_headers, $csv_rows );
}

/**
 * GET /admin/export/enrollments
 * Export enrollments CSV. Filters: course_id, group, date_from, date_to, completed.
 */
function snn_learn_rest_admin_export_enrollments( $request ) {
    global $wpdb;
    $t       = snn_learn_rest_table();
    $where   = [];
    $args    = [];
    $joins   = '';

    if ( $request->get_param( 'course_id' ) ) {
        $where[] = 'e.course_id = %d';
        $args[]  = (int) $request->get_param( 'course_id' );
    }

    $completed = $request->get_param( 'completed' );
    if ( null !== $completed ) {
        $where[] = $completed ? 'e.completed_at IS NOT NULL' : 'e.completed_at IS NULL';
    }

    if ( $request->get_param( 'date_from' ) ) {
        $ts = strtotime( $request->get_param( 'date_from' ) );
        if ( $ts ) { $where[] = 'e.enrolled_at >= %d'; $args[] = $ts; }
    }
    if ( $request->get_param( 'date_to' ) ) {
        $ts = strtotime( $request->get_param( 'date_to' ) . ' +1 day' );
        if ( $ts ) { $where[] = 'e.enrolled_at < %d'; $args[] = $ts; }
    }

    if ( $request->get_param( 'group' ) ) {
        $joins   .= " INNER JOIN {$wpdb->usermeta} um ON e.user_id = um.user_id";
        $where[]  = 'um.meta_key = %s AND um.meta_value = %s';
        $args[]   = 'snn_group';
        $args[]   = $request->get_param( 'group' );
    }

    $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) . ' AND e.is_course = 1' : 'WHERE e.is_course = 1';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT u.display_name, u.user_email, p.post_title AS course_title,
                    e.enrolled_at, e.completed_at, e.last_activity_at
             FROM $t e
             INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
             INNER JOIN {$wpdb->posts} p ON p.ID = e.course_id
             $joins
             $where_sql
             ORDER BY e.enrolled_at DESC
             LIMIT 10000",
            $args
        )
    );

    $csv_headers = [ 'Name', 'Email', 'Course', 'Enrolled Date', 'Completed Date', 'Last Active', 'Status' ];
    $csv_rows    = [];
    foreach ( $rows ?: [] as $r ) {
        $csv_rows[] = [
            $r->display_name,
            $r->user_email,
            $r->course_title,
            $r->enrolled_at ? wp_date( 'Y-m-d H:i:s', (int) $r->enrolled_at ) : '',
            $r->completed_at ? wp_date( 'Y-m-d H:i:s', (int) $r->completed_at ) : '',
            $r->last_activity_at ? wp_date( 'Y-m-d H:i:s', (int) $r->last_activity_at ) : '',
            $r->completed_at ? 'Completed' : 'In Progress',
        ];
    }

    return snn_learn_rest_output_csv( 'snn-learn-enrollments', $csv_headers, $csv_rows );
}

/**
 * GET /admin/export/progress
 * Export per-user progress for a specific course. Filters: course_id (required), group.
 */
function snn_learn_rest_admin_export_progress( $request ) {
    global $wpdb;
    $t         = snn_learn_rest_table();
    $course_id = (int) $request->get_param( 'course_id' );
    $group     = $request->get_param( 'group' );
    $total     = count( snn_learn_get_course_lessons( $course_id ) );

    $joins = '';
    $where = [ 'e.course_id = %d', 'e.is_course = 1' ];
    $args  = [ $course_id ];

    if ( $group ) {
        $joins   .= " INNER JOIN {$wpdb->usermeta} um ON e.user_id = um.user_id";
        $where[]  = 'um.meta_key = %s AND um.meta_value = %s';
        $args[]   = 'snn_group';
        $args[]   = $group;
    }

    $where_sql = 'WHERE ' . implode( ' AND ', $where );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT u.display_name, u.user_email, e.user_id,
                    e.enrolled_at, e.completed_at
             FROM $t e
             INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
             $joins
             $where_sql
             ORDER BY u.display_name ASC
             LIMIT 10000",
            $args
        )
    );

    $csv_headers = [ 'Name', 'Email', 'Progress %', 'Lessons Completed', 'Total Lessons', 'Enrolled Date', 'Completed Date', 'Status' ];
    $csv_rows    = [];
    foreach ( $rows ?: [] as $r ) {
        $uid   = (int) $r->user_id;
        $completed_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND course_id = %d AND is_lesson = 1 AND completed_at IS NOT NULL",
            $uid, $course_id
        ) );
        $progress = $total > 0 ? round( ( $completed_count / $total ) * 100, 1 ) : 0;

        $csv_rows[] = [
            $r->display_name,
            $r->user_email,
            $progress . '%',
            $completed_count,
            $total,
            $r->enrolled_at ? wp_date( 'Y-m-d H:i:s', (int) $r->enrolled_at ) : '',
            $r->completed_at ? wp_date( 'Y-m-d H:i:s', (int) $r->completed_at ) : '',
            $r->completed_at ? 'Completed' : 'In Progress',
        ];
    }

    return snn_learn_rest_output_csv( 'snn-learn-course-progress', $csv_headers, $csv_rows );
}
