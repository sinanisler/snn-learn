<?php
/** 
 * Plugin Name: SNN Learn
 * Description: A modern, high-performance LMS plugin for WordPress.
 * Version: 2.0
 * Author: Sinan Isler
 * Text Domain: snn-learn
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// 1. DATABASE
// ============================================================

function snn_learn_create_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'snn_learn_enrollments';
    $charset = $wpdb->get_charset_collate();

    // dbDelta rules: lowercase types, exactly one space between column name and type, two spaces before (id) in PRIMARY KEY
    $sql = "CREATE TABLE $table (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint unsigned NOT NULL,
        post_id bigint unsigned NOT NULL,
        course_id bigint unsigned NOT NULL,
        enrolled_at int unsigned NOT NULL,
        completed_at int unsigned DEFAULT NULL,
        last_activity_at int unsigned DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_user_post (user_id, post_id),
        KEY idx_course_id (course_id),
        KEY idx_user_id (user_id),
        KEY idx_completed_at (completed_at),
        KEY idx_enrolled_at (enrolled_at),
        KEY idx_last_activity_at (last_activity_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'snn_learn_create_table' );

// Auto-create / upgrade table on every plugin load — safe to run repeatedly (dbDelta is idempotent)
add_action( 'plugins_loaded', function () {
    if ( get_option( 'snn_learn_db_version' ) !== '2.2' ) {
        snn_learn_create_table();
        update_option( 'snn_learn_db_version', '2.2' );
    }
} );

// ============================================================
// 2. SETTINGS HELPERS
// ============================================================

function snn_learn_defaults() {
    return [
        'course_post_type'            => 'course',
        'video_field'                 => 'video_url',
        'video_color_primary'         => '#3b82f6',
        'video_color_bg'              => '#1e293b',
        'video_color_text'            => '#f8fafc',
        'video_complete_seconds'      => 1,
        'video_complete_on_end'       => 0,
        // User Permalinks
        'user_permalinks_enabled'     => 0,
        'user_permalink_normal_base'  => 'user',
        'user_permalink_instr_base'   => 'instructor',
        'user_permalink_normal_roles' => [ 'subscriber' ],
        'user_permalink_instr_roles'  => [ 'instructor' ],
    ];
}

function snn_learn_get( $key ) {
    $defaults = snn_learn_defaults();
    return get_option( 'snn_learn_' . $key, $defaults[ $key ] ?? '' );
}

// ============================================================
// 3. ADMIN MENU
// ============================================================

add_action( 'admin_menu', function () {
    add_menu_page(
        'SNN Learn',
        'SNN Learn',
        'manage_options',
        'snn-learn',
        'snn_learn_dashboard_page',
        'dashicons-welcome-learn-more',
        2
    );
    add_submenu_page( 'snn-learn', 'SNN Learn Dashboard',  'Dashboard',  'manage_options', 'snn-learn',            'snn_learn_dashboard_page'  );
    add_submenu_page( 'snn-learn', 'Settings',   'Settings',   'manage_options', 'snn-learn-settings',   'snn_learn_settings_page'   );
    add_submenu_page( 'snn-learn', 'Shortcodes', 'Shortcodes', 'manage_options', 'snn-learn-shortcodes', 'snn_learn_shortcodes_page' );
    // Note: chapters and lessons share the same post type — depth in hierarchy determines the role.
} );

// ============================================================
// 4. ADMIN ASSETS — injected only on SNN Learn pages
// ============================================================

add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'snn-learn' ) === false ) return;
    ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        #wpcontent { background: #f1f5f9; }
        .snn-learn-dashboard .wrap,
        .snn-learn-settings .wrap,
        .snn-learn-shortcodes .wrap { max-width: 100%; }
    </style>
    <?php
} );

// ============================================================
// 5. DASHBOARD PAGE
// ============================================================

function snn_learn_dashboard_page() {
    global $wpdb;
    $t = $wpdb->prefix . 'snn_learn_enrollments';

    // Pre-calculate timestamps in PHP so MySQL receives plain integers.
    // Comparing two integers is the fastest operation a DB can do and keeps
    // queries sargable — MySQL can use the idx_enrolled_at / idx_last_activity_at
    // indexes without wrapping the column in a function.
    $ts_30_days_ago = time() - ( 30 * DAY_IN_SECONDS );
    $ts_14_days_ago = time() - ( 14 * DAY_IN_SECONDS );
    $ts_7_days_ago  = time() - (  7 * DAY_IN_SECONDS );

    // ---- KPI Queries ----
    $total_enrollments  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
    $recent_enrollments = (int)   $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE enrolled_at >= %d", $ts_30_days_ago ) );
    $completion_rate    = (float) $wpdb->get_var( "SELECT (COUNT(CASE WHEN completed_at IS NOT NULL THEN 1 END) / NULLIF(COUNT(*),0)) * 100 FROM $t WHERE post_id != course_id" );
    $weekly_active      = (int)   $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $t WHERE last_activity_at >= %d", $ts_7_days_ago ) );
    $gone_cold          = (int)   $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE last_activity_at < %d AND completed_at IS NULL", $ts_14_days_ago ) );
    $active_courses     = (int)   $wpdb->get_var( "SELECT COUNT(DISTINCT course_id) FROM $t" );
    $avg_days           = (float) $wpdb->get_var( "SELECT AVG(completed_at - enrolled_at) / 86400 FROM $t WHERE completed_at IS NOT NULL" );
    // Use integer division instead of FROM_UNIXTIME() — avoids per-row date function overhead.
    // Groups by UTC day boundary; date is formatted in PHP with gmdate().
    $peak_day = $wpdb->get_row( "SELECT (enrolled_at DIV 86400) * 86400 AS day_ts, COUNT(*) AS cnt FROM $t GROUP BY day_ts ORDER BY cnt DESC LIMIT 1" );
    if ( $peak_day ) {
        $peak_day->date = gmdate( 'Y-m-d', (int) $peak_day->day_ts );
    }

    // ---- Course Enrollment Trend (last 30 days) ----
    // Uses the master course-enrollment row (post_id = course_id) that snn_learn_record_lesson()
    // inserts when a user first starts a course. This eliminates the expensive subquery +
    // temporary table that MIN(enrolled_at) GROUP BY previously required.
    $trend_rows   = $wpdb->get_results( $wpdb->prepare(
        "SELECT FROM_UNIXTIME(enrolled_at, '%%Y-%%m-%%d') AS day, COUNT(*) AS cnt
         FROM $t
         WHERE post_id = course_id
           AND enrolled_at >= %d
         GROUP BY day
         ORDER BY day DESC
         LIMIT 30",
        $ts_30_days_ago
    ) );
    $trend_rows   = array_reverse( $trend_rows );
    $trend_labels = array_column( $trend_rows, 'day' );
    $trend_data   = array_map( 'intval', array_column( $trend_rows, 'cnt' ) );

    // ---- Course performance ----
    $courses_perf = $wpdb->get_results( "SELECT course_id, COUNT(*) AS enrolled, SUM(completed_at IS NOT NULL) AS completed FROM $t GROUP BY course_id ORDER BY enrolled DESC LIMIT 50" );

    // ---- At-risk students ----
    $at_risk = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, course_id, last_activity_at FROM $t WHERE last_activity_at < %d AND completed_at IS NULL ORDER BY last_activity_at ASC LIMIT 20",
        $ts_14_days_ago
    ) );

    // ---- Recent activity feed ----
    $recent_activity = $wpdb->get_results( "SELECT user_id, course_id, enrolled_at, completed_at FROM $t ORDER BY enrolled_at DESC LIMIT 20" );
    ?>
    <div class="snn-learn-dashboard">
    <div class="p-6 max-w-screen-xl">

        <h1 class="text-2xl font-bold text-gray-800 mb-6">SNN Learn &mdash; Dashboard</h1>

        <!-- Row 1: Primary KPIs -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Lesson Enrollments</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $total_enrollments ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Last 30 Days</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $recent_enrollments ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Completion Rate</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $completion_rate, 1 ) ?>%</p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Weekly Active Users</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $weekly_active ) ?></p>
            </div>

        </div>

        <!-- Row 2: Mini Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Gone Cold</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $gone_cold ) ?></p>
                <p class="snn-kpi-desc text-xs text-gray-400 mt-1">14+ days inactive</p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Active Courses</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $active_courses ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Avg Days to Complete</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= $avg_days ? number_format( $avg_days, 1 ) : '&mdash;' ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Peak Enrollment Day</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800 mt-1"><?= $peak_day ? esc_html( $peak_day->date ) : '&mdash;' ?></p>
                <?php if ( $peak_day ): ?>
                <p class="snn-kpi-desc text-xs text-gray-400 mt-1"><?= number_format( $peak_day->cnt ) ?> enrollments</p>
                <?php endif; ?>
            </div>

        </div>

        <!-- Row 3: Chart + Course Table -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

            <div class="snn-chart-card bg-white rounded-xl shadow-sm p-5">
                <h2 class="snn-chart-title text-sm font-semibold text-gray-600 mb-4">Course Enrollment Trend &mdash; Last 30 Days</h2>
                <canvas id="snn-trend-chart" height="140"></canvas>
            </div>

            <div class="snn-perf-card bg-white rounded-xl shadow-sm p-5">
                <h2 class="snn-perf-title text-sm font-semibold text-gray-600 mb-4">Course Performance</h2>
                <div class="overflow-auto max-h-52">
                    <table class="snn-perf-table w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold text-gray-400 border-b">
                                <th class="pb-2 pr-4">Course</th>
                                <th class="pb-2 pr-4">Enrolled</th>
                                <th class="pb-2 pr-4">Completed</th>
                                <th class="pb-2">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $courses_perf as $c ) :
                            $rate       = $c->enrolled ? round( $c->completed / $c->enrolled * 100 ) : 0;
                            $title      = get_the_title( $c->course_id );
                            $badge      = $rate >= 70 ? 'bg-green-100 text-green-800' : ( $rate >= 40 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800' );
                            $course_url = get_permalink( $c->course_id );
                        ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="py-2 pr-4 text-blue-600 font-medium">
                                <?php if ( $title && $course_url ) : ?>
                                <a href="<?= esc_url( $course_url ) ?>" target="_blank" class="hover:underline"><?= esc_html( $title ) ?></a>
                                <?php else : ?>
                                <?= $title ? esc_html( $title ) : '#' . $c->course_id ?>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 pr-4"><?= (int) $c->enrolled ?></td>
                            <td class="py-2 pr-4"><?= (int) $c->completed ?></td>
                            <td class="py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $badge ?>"><?= $rate ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ( empty( $courses_perf ) ) : ?>
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">No data yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Row 4: At-Risk + Feed -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="snn-risk-card bg-white rounded-xl shadow-sm p-5">
                <h2 class="snn-risk-title text-sm font-semibold text-red-600 mb-4">&#9888; At-Risk Students <span class="font-normal text-gray-400">(14+ days inactive, not completed)</span></h2>
                <div class="overflow-auto max-h-60">
                    <table class="snn-risk-table w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold text-gray-400 border-b">
                                <th class="pb-2 pr-4">User</th>
                                <th class="pb-2 pr-4">Course</th>
                                <th class="pb-2">Last Active</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $at_risk as $r ) :
                            $user         = get_userdata( $r->user_id );
                            $course_title = get_the_title( $r->course_id );
                        ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="py-2 pr-4"><?= $user ? esc_html( $user->display_name ) : '#' . $r->user_id ?></td>
                            <td class="py-2 pr-4"><?= $course_title ? esc_html( $course_title ) : '#' . $r->course_id ?></td>
                            <td class="py-2 text-red-500"><?= $r->last_activity_at ? human_time_diff( $r->last_activity_at ) . ' ago' : '&mdash;' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ( empty( $at_risk ) ) : ?>
                        <tr><td colspan="3" class="py-6 text-center text-gray-400">No at-risk students &#10003;</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="snn-feed-card bg-white rounded-xl shadow-sm p-5">
                <h2 class="snn-feed-title text-sm font-semibold text-gray-600 mb-4">Recent Activity Feed</h2>
                <div class="snn-feed-list space-y-2 overflow-auto max-h-60">
                <?php foreach ( $recent_activity as $a ) :
                    $user         = get_userdata( $a->user_id );
                    $course_title = get_the_title( $a->course_id );
                    $is_done      = ! empty( $a->completed_at );
                ?>
                <div class="snn-feed-item flex items-center gap-3 text-sm border-b border-gray-100 pb-2">
                    <span class="snn-feed-icon w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0 <?= $is_done ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' ?>">
                        <?= $user ? strtoupper( mb_substr( $user->display_name, 0, 1 ) ) : '?' ?>
                    </span>
                    <div class="snn-feed-text flex-1 min-w-0 truncate">
                        <span class="font-medium"><?= $user ? esc_html( $user->display_name ) : 'User #' . $a->user_id ?></span>
                        <span class="text-gray-400"> <?= $is_done ? 'completed' : 'enrolled in' ?> </span>
                        <span><?= $course_title ? esc_html( $course_title ) : '#' . $a->course_id ?></span>
                    </div>
                    <span class="snn-feed-time text-xs text-gray-400 shrink-0"><?= human_time_diff( $a->enrolled_at ) ?> ago</span>
                </div>
                <?php endforeach; ?>
                <?php if ( empty( $recent_activity ) ) : ?>
                <p class="text-center text-gray-400 py-6">No activity yet</p>
                <?php endif; ?>
                </div>
            </div>

        </div>

    </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var ctx = document.getElementById('snn-trend-chart');
        if (!ctx || typeof Chart === 'undefined') return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode( $trend_labels ) ?>,
                datasets: [{
                    label: 'Enrollments',
                    data: <?= json_encode( $trend_data ) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.08)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                    x: { ticks: { maxTicksLimit: 8, maxRotation: 0 } }
                }
            }
        });
    });
    </script>
    <?php
}

// ============================================================
// 6. SETTINGS PAGE
// ============================================================

function snn_learn_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Handle: Reset all enrollment data
    if ( isset( $_POST['snn_learn_reset_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_reset_nonce'] ) ), 'snn_learn_reset_data' ) ) {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}snn_learn_enrollments" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        echo '<div class="notice notice-warning is-dismissible"><p><strong>All enrollment data has been permanently deleted.</strong></p></div>';
    }

    if ( isset( $_POST['snn_learn_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_nonce'] ) ), 'snn_learn_settings_save' ) ) {
        $text_fields = [ 'course_post_type', 'video_field', 'video_color_primary', 'video_color_bg', 'video_color_text', 'video_complete_seconds' ];
        foreach ( $text_fields as $f ) {
            $val = isset( $_POST[ 'snn_learn_' . $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'snn_learn_' . $f ] ) ) : '';
            update_option( 'snn_learn_' . $f, $val );
        }
        // Checkbox
        update_option( 'snn_learn_video_complete_on_end', isset( $_POST['snn_learn_video_complete_on_end'] ) ? 1 : 0 );

        // ---- User Permalinks ----
        update_option( 'snn_learn_user_permalinks_enabled', isset( $_POST['snn_learn_user_permalinks_enabled'] ) ? 1 : 0 );

        $normal_base = sanitize_title( wp_unslash( $_POST['snn_learn_user_permalink_normal_base'] ?? '' ) );
        $instr_base  = sanitize_title( wp_unslash( $_POST['snn_learn_user_permalink_instr_base']  ?? '' ) );
        update_option( 'snn_learn_user_permalink_normal_base', $normal_base ?: 'user' );
        update_option( 'snn_learn_user_permalink_instr_base',  $instr_base  ?: 'instructor' );

        $normal_roles = isset( $_POST['snn_learn_user_permalink_normal_roles'] )
            ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['snn_learn_user_permalink_normal_roles'] ) )
            : [];
        $instr_roles  = isset( $_POST['snn_learn_user_permalink_instr_roles'] )
            ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['snn_learn_user_permalink_instr_roles'] ) )
            : [];
        update_option( 'snn_learn_user_permalink_normal_roles', $normal_roles );
        update_option( 'snn_learn_user_permalink_instr_roles',  $instr_roles );

        // Hard-flush: rewrites the rules to the DB and to .htaccess/.nginx conf immediately.
        // Called on every save so slug/role changes take effect without any extra admin step.
        flush_rewrite_rules( true );

        echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
    }
    ?>
    <div class="snn-learn-settings wrap">
        <h1>SNN Learn &mdash; Settings</h1>
        <p>make the important ones as charts</p>
        <form method="post" action="">
            <?php wp_nonce_field( 'snn_learn_settings_save', 'snn_learn_nonce' ); ?>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><label for="snn_cpt">Course Post Type Slug</label></th>
                    <td>
                        <input type="text" id="snn_cpt" name="snn_learn_course_post_type" value="<?= esc_attr( snn_learn_get( 'course_post_type' ) ) ?>" class="regular-text">
                        <p class="description">
                            The <strong>single post type</strong> slug used for courses, chapters, and lessons (e.g. <code>course</code>).<br>
                            Role is determined by depth: top-level = course &nbsp;/&nbsp; child of course = chapter &nbsp;/&nbsp; child of chapter = lesson.
                        </p>
                    </td>
                </tr>


                <tr>
                    <th scope="row"><label for="snn_vf">Video URL Custom Field Slug</label></th>
                    <td>
                        <input type="text" id="snn_vf" name="snn_learn_video_field" value="<?= esc_attr( snn_learn_get( 'video_field' ) ) ?>" class="regular-text">
                        <p class="description">The ACF / custom field key that holds the video file URL for each lesson (e.g. <code>video_url</code>).</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="snn_vcp">Player Primary Color</label></th>
                    <td><input type="color" id="snn_vcp" name="snn_learn_video_color_primary" value="<?= esc_attr( snn_learn_get( 'video_color_primary' ) ) ?>"></td>
                </tr>

                <tr>
                    <th scope="row"><label for="snn_vcb">Player Background Color</label></th>
                    <td><input type="color" id="snn_vcb" name="snn_learn_video_color_bg" value="<?= esc_attr( snn_learn_get( 'video_color_bg' ) ) ?>"></td>
                </tr>

                <tr>
                    <th scope="row"><label for="snn_vct">Player Icon / Text Color</label></th>
                    <td><input type="color" id="snn_vct" name="snn_learn_video_color_text" value="<?= esc_attr( snn_learn_get( 'video_color_text' ) ) ?>"></td>
                </tr>

                <tr>
                    <th scope="row"><label for="snn_vcs">Mark Complete After (seconds)</label></th>
                    <td>
                        <input type="number" id="snn_vcs" name="snn_learn_video_complete_seconds" value="<?= esc_attr( snn_learn_get( 'video_complete_seconds' ) ) ?>" min="1" class="small-text">
                        <p class="description">How many cumulative seconds of playback before the lesson is marked completed. Default: <strong>1</strong>.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Mark Complete on Video End Only</th>
                    <td>
                        <label>
                            <input type="checkbox" name="snn_learn_video_complete_on_end" value="1" <?= checked( 1, snn_learn_get( 'video_complete_on_end' ), false ) ?>>
                            When checked, completion fires only when the video plays to the very end (overrides seconds setting).
                        </label>
                    </td>
                </tr>

            </table>

            <!-- ===== User Permalinks Section ===== -->
            <hr style="margin:32px 0 24px;border-color:#e5e7eb">
            <h2 style="font-size:15px;font-weight:700;color:#111827;margin:0 0 4px">User Permalinks</h2>
            <p style="color:#6b7280;font-size:13px;margin:0 0 20px">
                Replace the default <code>/author/username/</code> URLs with role-based, ID-driven URLs &mdash;
                e.g. <code>/user/42/</code> for normal users or <code>/instructor/7/</code> for instructors.
                User IDs are used instead of usernames for privacy.
            </p>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">Enable User Permalinks</th>
                    <td>
                        <label>
                            <input type="checkbox" name="snn_learn_user_permalinks_enabled" value="1"
                                <?= checked( 1, snn_learn_get( 'user_permalinks_enabled' ), false ) ?>>
                            Enable role-based, ID-driven author archive URLs.
                        </label>
                        <p class="description" style="margin-top:6px">
                            After saving, the plugin automatically refreshes rewrite rules on the next page load.
                            If URLs still don't work, visit <strong>Settings &rarr; Permalinks</strong> and click <strong>Save Changes</strong>.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="snn_normal_base">Normal User URL Base</label></th>
                    <td>
                        <span style="color:#6b7280"><?= esc_html( rtrim( home_url('/'), '/' ) ) ?>/</span><input
                            type="text" id="snn_normal_base"
                            name="snn_learn_user_permalink_normal_base"
                            value="<?= esc_attr( snn_learn_get( 'user_permalink_normal_base' ) ) ?>"
                            style="width:200px"
                            class="small-text" placeholder="user">/42/
                        <p class="description">Default: <code>user</code> &mdash; produces <code>/user/42/</code></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Normal User Roles</th>
                    <td>
                        <?php
                        $snn_all_roles    = wp_roles()->get_names();
                        $snn_saved_normal = (array) snn_learn_get( 'user_permalink_normal_roles' );
                        foreach ( $snn_all_roles as $snn_role_key => $snn_role_name ) :
                        ?>
                        <label style="display:inline-flex;align-items:center;gap:5px;margin-right:16px;margin-bottom:6px">
                            <input type="checkbox"
                                name="snn_learn_user_permalink_normal_roles[]"
                                value="<?= esc_attr( $snn_role_key ) ?>"
                                <?= in_array( $snn_role_key, $snn_saved_normal, true ) ? 'checked' : '' ?>>
                            <?= esc_html( translate_user_role( $snn_role_name ) ) ?>
                        </label>
                        <?php endforeach; ?>
                        <p class="description" style="margin-top:6px">Users with any selected role will use the <em>Normal User URL Base</em>.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="snn_instr_base">Instructor URL Base</label></th>
                    <td>
                        <span style="color:#6b7280"><?= esc_html( rtrim( home_url('/'), '/' ) ) ?>/</span><input
                            type="text" id="snn_instr_base"
                            name="snn_learn_user_permalink_instr_base"
                            value="<?= esc_attr( snn_learn_get( 'user_permalink_instr_base' ) ) ?>"
                            class="small-text" placeholder="instructor"
                            style="width:200px">/7/
                        <p class="description">Default: <code>instructor</code> &mdash; produces <code>/instructor/7/</code></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Instructor Roles</th>
                    <td>
                        <?php
                        $snn_saved_instr = (array) snn_learn_get( 'user_permalink_instr_roles' );
                        foreach ( $snn_all_roles as $snn_role_key => $snn_role_name ) :
                        ?>
                        <label style="display:inline-flex;align-items:center;gap:5px;margin-right:16px;margin-bottom:6px">
                            <input type="checkbox"
                                name="snn_learn_user_permalink_instr_roles[]"
                                value="<?= esc_attr( $snn_role_key ) ?>"
                                <?= in_array( $snn_role_key, $snn_saved_instr, true ) ? 'checked' : '' ?>>
                            <?= esc_html( translate_user_role( $snn_role_name ) ) ?>
                        </label>
                        <?php endforeach; ?>
                        <p class="description" style="margin-top:6px">
                            Users with any selected role use the <em>Instructor URL Base</em>.
                            <strong>Instructor is checked first</strong> and takes priority if a user has both role types.
                        </p>
                    </td>
                </tr>

            </table>

            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <!-- Danger Zone -->
        <hr style="margin:40px 0 24px;border-color:#fca5a5">
        <h2 style="color:#dc2626;font-size:16px;font-weight:700;margin-bottom:6px">&#9888; Danger Zone</h2>
        <p style="color:#6b7280;font-size:13px;margin-bottom:20px">These actions are <strong>irreversible</strong>. Proceed with caution.</p>

        <!-- Admin: reset all data -->
        <div style="background:#fff8f8;border:1px solid #fca5a5;border-radius:8px;padding:20px 24px;max-width:620px;margin-bottom:16px">
            <h3 style="margin:0 0 6px;font-size:14px;color:#991b1b">Reset All Enrollment Data</h3>
            <p style="margin:0 0 14px;font-size:13px;color:#6b7280">Permanently deletes <strong>every row</strong> from the <code>snn_learn_enrollments</code> table. All user progress, completions, and enrollment history will be gone.</p>
            <form method="post" action="" onsubmit="return confirm('WARNING: This will permanently delete ALL enrollment data for ALL users. This cannot be undone. Are you absolutely sure?');">
                <?php wp_nonce_field( 'snn_learn_reset_data', 'snn_learn_reset_nonce' ); ?>
                <button type="submit" class="button" style="background:#dc2626;border-color:#b91c1c;color:#fff;font-weight:600">&#128465; Delete All Enrollment Data</button>
            </form>
        </div>

        <!-- User data deletion info -->
        <div style="background:#fff8f8;border:1px solid #fca5a5;border-radius:8px;padding:20px 24px;max-width:620px">
            <h3 style="margin:0 0 6px;font-size:14px;color:#991b1b">User: Delete My Own Data</h3>
            <p style="margin:0 0 10px;font-size:13px;color:#6b7280">Place the shortcode below anywhere on your site (e.g. a privacy/account page) to let logged-in users permanently delete their own learning records.</p>
            <code style="background:#eff6ff;color:#2563eb;padding:6px 14px;border-radius:5px;font-size:13px;border:1px solid #bfdbfe;font-family:monospace;cursor:pointer" onclick="navigator.clipboard.writeText(this.textContent.trim())" title="Click to copy">[snn_learn_delete_my_data]</code>
            <span style="color:#16a34a;font-size:12px;margin-left:8px;opacity:0;transition:opacity .3s" id="snn-user-del-copied">&#10003; Copied!</span>
            <script>document.querySelector('[onclick*="snn-user-del-copied"]') && 0; document.querySelectorAll('code[onclick]').forEach(function(c){c.addEventListener('click',function(){var s=document.getElementById('snn-user-del-copied');if(s){s.style.opacity='1';setTimeout(function(){s.style.opacity='0';},1600);}});});</script>
        </div>

    </div>
    <?php
}

// ============================================================
// 7. SHORTCODES ADMIN PAGE
// ============================================================

function snn_learn_shortcodes_page() {
    $shortcodes = [
        [
            'tag'         => '[snn_learn_video_player]',
            'description' => 'Renders the native HTML5 video player for the current lesson. Video source is read from the custom field configured in Settings. Tracks watched seconds and fires the REST completion endpoint automatically. Cache-aware: on every page load it performs a fresh lesson-status check via a cache-busting REST request (with a _t=Date.now() timestamp) so the "Completed" badge appears correctly even when a caching plugin such as WP Rocket or Cloudflare serves cached HTML. When completion fires, it dispatches a custom JavaScript event snnLearnProgress on the document — developers can listen to this event to update other parts of the page (e.g. a sidebar progress bar) in real time without a page refresh.',
            'attributes'  => [],
        ],
        [
            'tag'         => '[snn_learn_progress]',
            'description' => 'Outputs a plain number (0–100) representing the current user\'s completion percentage for the grandparent course of the current post. Safe to use inside any loop or builder element. Supports an optional output attribute: use output="bool" to receive the string "true" or "false" instead of a number — useful for conditional visibility logic in page builders such as Elementor or Bricks.',
            'attributes'  => [
                'course_id' => 'Optional. Manually specify a course ID. Defaults to the grandparent course of the current post.',
                'output'    => 'Optional. Set to "bool" to return "true" or "false" instead of 0–100. Returns "true" when progress is greater than 1%.',
            ],
        ],
        [
            'tag'         => '[snn_learn_course_chapter_lesson_list]',
            'description' => 'Renders the full chapter → lesson navigation list for the current course. Chapters and lessons share one post type — chapters are direct children of the course (no link), lessons are children of chapters (linked). Ordered by menu_order. Completed lessons show a ✓ mark. The link of the lesson currently being viewed receives the CSS class snn-lesson-current, making it easy to style the active item in your sidebar navigation.',
            'attributes'  => [
                'course_id' => 'Optional. Override the course ID.',
            ],
        ],
        [
            'tag'         => '[snn_learn_mark_completed]',
            'description' => 'Renders a "Mark as Completed" button for doc / article / code-snippet lessons that have no video. On click it calls the REST API to mark the lesson complete and enroll the user in the course. Cache-aware: on every page load it performs a live status check via a cache-busting REST request so the button reflects the correct completed state even when caching plugins serve stale HTML. Also dispatches the snnLearnProgress JavaScript event on completion so other page elements can react immediately.',
            'attributes'  => [
                'label'           => 'Button text. Default: "Mark as Completed".',
                'completed_label' => 'Text shown after completion. Default: "✓ Completed".',
            ],
        ],
        [
            'tag'         => '[snn_learn_delete_my_data]',
            'description' => 'Renders a "Delete My Learning Data" button (logged-in users only). On click, after a confirmation dialog, permanently deletes all the current user\'s enrollment and progress records from the database. Useful for GDPR / privacy pages.',
            'attributes'  => [
                'label'           => 'Button text. Default: "Delete My Learning Data".',
                'confirmed_label' => 'Text shown after deletion. Default: "✓ Your data has been deleted.".',
            ],
        ],
        [
            'tag'         => '[snn_learn_my_courses]',
            'description' => 'Renders a list of all courses the current logged-in user is enrolled in, with their per-course progress percentage and a link to continue learning.',
            'attributes'  => [],
        ],
        [
            'tag'         => '[snn_learn_comment_list]',
            'description' => 'Renders the styled comment list for the current post. Features initials-based avatar circles (first + last name initials), star ratings (stored in comment meta as snn_rating_comment and editable from the WordPress comment edit screen), and a moderation notice shown to users whose comment is awaiting approval.',
            'attributes'  => [
                'avatar'            => 'Avatar circle size in pixels. Default: 48.',
                'order'             => 'Comment sort order. "ASC" (oldest first) or "DESC" (newest first). Default: DESC.',
                'number'            => 'Limit the number of comments shown. Default: all approved comments.',
                'show_ratings'      => 'Show or hide the star rating display. Set to "0" to hide. Default: 1 (visible).',
                'moderation_notice' => 'Custom text shown above a pending comment when the author visits the page via the WordPress moderation link. Default: "Your comment is saved and waiting for approval.".',
            ],
        ],
    ];
    ?>
    <div class="snn-learn-shortcodes wrap">
        <h1>SNN Learn &mdash; Shortcodes</h1>
        <p class="description" style="margin-bottom:20px">Click any shortcode tag to copy it to your clipboard.</p>

        <?php foreach ( $shortcodes as $sc ) : ?>
        <div class="snn-shortcode-item" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:14px;max-width:760px">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
                <code
                    class="snn-shortcode-tag"
                    style="background:#eff6ff;color:#2563eb;padding:6px 14px;border-radius:5px;font-size:14px;cursor:pointer;border:1px solid #bfdbfe;font-family:monospace"
                    onclick="snnCopyShortcode(this)"
                    title="Click to copy"
                ><?= esc_html( $sc['tag'] ) ?></code>
                <span class="snn-copy-feedback" style="color:#16a34a;font-size:12px;opacity:0;transition:opacity .3s">&#10003; Copied!</span>
            </div>
            <p style="margin:0 0 10px;color:#374151;font-size:13px;line-height:1.6"><?= esc_html( $sc['description'] ) ?></p>
            <?php if ( ! empty( $sc['attributes'] ) ) : ?>
            <table style="border-collapse:collapse;font-size:12px;width:100%">
                <thead>
                    <tr style="text-align:left">
                        <th style="padding:4px 10px 4px 0;color:#9ca3af;width:180px;border-bottom:1px solid #f3f4f6">Attribute</th>
                        <th style="padding:4px 0;color:#9ca3af;border-bottom:1px solid #f3f4f6">Description</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $sc['attributes'] as $attr => $desc ) : ?>
                <tr>
                    <td style="padding:5px 10px 5px 0;font-family:monospace;color:#7c3aed"><?= esc_html( $attr ) ?></td>
                    <td style="padding:5px 0;color:#4b5563"><?= esc_html( $desc ) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- REST API reference -->
        <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;max-width:760px;margin-top:20px">
            <h2 style="font-size:14px;font-weight:600;color:#374151;margin:0 0 10px">REST API Endpoints</h2>
            <table style="border-collapse:collapse;font-size:12px;width:100%">
                <thead>
                    <tr style="text-align:left">
                        <th style="padding:4px 12px 4px 0;color:#9ca3af;border-bottom:1px solid #e5e7eb">Method</th>
                        <th style="padding:4px 12px 4px 0;color:#9ca3af;border-bottom:1px solid #e5e7eb">Endpoint</th>
                        <th style="padding:4px 0;color:#9ca3af;border-bottom:1px solid #e5e7eb">Body / Params</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:6px 12px 6px 0;font-family:monospace;color:#2563eb">POST</td>
                        <td style="padding:6px 12px 6px 0;font-family:monospace;color:#374151">/wp-json/snn-learn/v1/complete</td>
                        <td style="padding:6px 0;color:#4b5563">JSON: <code>{ post_id: INT, complete: BOOL }</code> — Nonce via X-WP-Nonce header.</td>
                    </tr>
                    <tr>
                        <td style="padding:6px 12px 6px 0;font-family:monospace;color:#2563eb">GET</td>
                        <td style="padding:6px 12px 6px 0;font-family:monospace;color:#374151">/wp-json/snn-learn/v1/progress</td>
                        <td style="padding:6px 0;color:#4b5563">Query: <code>?course_id=INT</code> — Returns <code>{ progress: 0-100 }</code>.</td>
                    </tr>
                    <tr>
                        <td style="padding:6px 12px 6px 0;font-family:monospace;color:#dc2626">DELETE</td>
                        <td style="padding:6px 12px 6px 0;font-family:monospace;color:#374151">/wp-json/snn-learn/v1/my-data</td>
                        <td style="padding:6px 0;color:#4b5563">No body. Permanently deletes all enrollment rows for the authenticated user. Nonce via X-WP-Nonce header.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function snnCopyShortcode(el) {
        navigator.clipboard.writeText(el.textContent.trim()).then(function () {
            var msg = el.nextElementSibling;
            msg.style.opacity = '1';
            setTimeout(function () { msg.style.opacity = '0'; }, 1600);
        });
    }
    </script>
    <?php
}

// ============================================================
// 8. HELPER FUNCTIONS
// ============================================================

/**
 * Resolve the top-level course ID from any post in the hierarchy.
 *
 * One post type for everything. Role determined by depth:
 *   course  = post_parent 0  (top-level)
 *   chapter = post_parent = course ID
 *   lesson  = post_parent = chapter ID
 *
 *   course  → return itself
 *   chapter → post_parent is the course
 *   lesson  → post_parent is a chapter → return chapter's post_parent
 */
function snn_learn_get_course_id( $post_id = null ) {
    if ( ! $post_id ) $post_id = get_the_ID();
    $post = get_post( $post_id );
    if ( ! $post ) return 0;

    $pt = snn_learn_get( 'course_post_type' );
    if ( $post->post_type !== $pt ) return 0;

    // Top-level = course itself
    if ( ! $post->post_parent ) return (int) $post->ID;

    $parent = get_post( $post->post_parent );
    if ( ! $parent ) return 0;

    // Parent is top-level → current post is a chapter
    if ( ! $parent->post_parent ) return (int) $parent->ID;

    // Parent has a parent → current post is a lesson, parent is a chapter
    return (int) $parent->post_parent;
}

/**
 * Return an array of all lesson post IDs for a course, ordered by chapter then lesson menu_order.
 * One post type. Chapters = direct children of the course. Lessons = children of chapters.
 */
function snn_learn_get_course_lessons( $course_id ) {
    $pt = snn_learn_get( 'course_post_type' );

    // Query 1: get all chapter IDs (direct children of the course)
    $chapters = get_posts( [
        'post_type'      => $pt,
        'post_parent'    => (int) $course_id,
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );

    if ( empty( $chapters ) ) {
        return [];
    }

    // Query 2: get ALL lessons for ALL chapters in a single query (eliminates N+1)
    $all_lessons = get_posts( [
        'post_type'       => $pt,
        'post_parent__in' => $chapters,
        'posts_per_page'  => -1,
        'orderby'         => 'menu_order',
        'order'           => 'ASC',
        'post_status'     => 'publish',
    ] );

    // Group lessons by their parent chapter
    $lessons_by_chapter = [];
    foreach ( $all_lessons as $lesson ) {
        $lessons_by_chapter[ $lesson->post_parent ][] = (int) $lesson->ID;
    }

    // Assemble flat ordered list, chapters dictate master order
    $ordered_lesson_ids = [];
    foreach ( $chapters as $ch_id ) {
        if ( isset( $lessons_by_chapter[ $ch_id ] ) ) {
            $ordered_lesson_ids = array_merge( $ordered_lesson_ids, $lessons_by_chapter[ $ch_id ] );
        }
    }

    return $ordered_lesson_ids;
}

/**
 * Calculate completion percentage for a user in a course (0–100).
 */
function snn_learn_calc_progress( $user_id, $course_id ) {
    global $wpdb;
    $t             = $wpdb->prefix . 'snn_learn_enrollments';
    $total_lessons = count( snn_learn_get_course_lessons( $course_id ) );
    if ( ! $total_lessons ) return 0;

    $completed = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE user_id=%d AND course_id=%d AND completed_at IS NOT NULL",
        $user_id, $course_id
    ) );

    return min( 100, (int) round( $completed / $total_lessons * 100 ) );
}

/**
 * Upsert an enrollment / completion record for a (user, post/lesson) pair.
 * $mark_complete = true  → sets completed_at if not already set
 * $mark_complete = false → only updates last_activity_at (started / viewed)
 */
function snn_learn_record_lesson( $user_id, $post_id, $course_id, $mark_complete = true ) {
    global $wpdb;
    $t   = $wpdb->prefix . 'snn_learn_enrollments';
    $now = time();

    // 1. Ensure the top-level course enrollment row exists
    if ( $post_id != $course_id ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $t (user_id, post_id, course_id, enrolled_at, last_activity_at) VALUES (%d, %d, %d, %d, %d)",
            (int) $user_id, (int) $course_id, (int) $course_id, $now, $now
        ) );
    }

    // 2. Upsert the lesson row — single round-trip via ON DUPLICATE KEY UPDATE.
    // COALESCE preserves an existing completed_at (never un-completes a lesson).
    // Two query variants: when $mark_complete is false we omit completed_at from
    // the INSERT entirely so it defaults to NULL and the UPDATE clause ignores it.
    if ( $mark_complete ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $t (user_id, post_id, course_id, enrolled_at, completed_at, last_activity_at)
             VALUES (%d, %d, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE
                 last_activity_at = VALUES(last_activity_at),
                 completed_at     = COALESCE(completed_at, VALUES(completed_at))",
            (int) $user_id, (int) $post_id, (int) $course_id, $now, $now, $now
        ) );
    } else {
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $t (user_id, post_id, course_id, enrolled_at, last_activity_at)
             VALUES (%d, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE
                 last_activity_at = VALUES(last_activity_at)",
            (int) $user_id, (int) $post_id, (int) $course_id, $now, $now
        ) );
    }

    // 3. When a lesson is marked complete, auto-complete its parent chapter,
    //    then check if the whole course is now done too.
    if ( $mark_complete ) {
        snn_learn_maybe_complete_chapter( $user_id, $post_id, $course_id, $now );
        snn_learn_maybe_complete_course( $user_id, $course_id, $now );
    }
}

/**
 * Mark a chapter as completed as soon as the first lesson under it is completed.
 * Chapters redirect to their first lesson so users can never complete them manually.
 * Called automatically from snn_learn_record_lesson — never needs to be called directly.
 */
function snn_learn_maybe_complete_chapter( $user_id, $lesson_id, $course_id, $now = null ) {
    if ( ! $now ) $now = time();

    $lesson = get_post( $lesson_id );
    if ( ! $lesson || ! $lesson->post_parent ) return;

    $chapter_id = (int) $lesson->post_parent;

    // Confirm the parent is a chapter (its own parent must be the course)
    $chapter = get_post( $chapter_id );
    if ( ! $chapter || (int) $chapter->post_parent !== (int) $course_id ) return;

    global $wpdb;
    $t = $wpdb->prefix . 'snn_learn_enrollments';

    // Single ODKU upsert — eliminates the SELECT round-trip.
    // COALESCE preserves a pre-existing completed_at (chapter stays complete once completed).
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $t (user_id, post_id, course_id, enrolled_at, completed_at, last_activity_at)
         VALUES (%d, %d, %d, %d, %d, %d)
         ON DUPLICATE KEY UPDATE
             completed_at     = COALESCE(completed_at, VALUES(completed_at)),
             last_activity_at = VALUES(last_activity_at)",
        (int) $user_id, $chapter_id, (int) $course_id, $now, $now, $now
    ) );
}

/**
 * Mark the top-level course row as completed when every lesson in the course
 * has a completed_at value for this user.
 * Called automatically from snn_learn_record_lesson — never call directly.
 */
function snn_learn_maybe_complete_course( $user_id, $course_id, $now = null ) {
    if ( ! $now ) $now = time();

    $all_lessons = snn_learn_get_course_lessons( $course_id );
    if ( empty( $all_lessons ) ) return;

    global $wpdb;
    $t = $wpdb->prefix . 'snn_learn_enrollments';

    // Count how many of those lessons this user has completed
    $placeholders = implode( ',', array_fill( 0, count( $all_lessons ), '%d' ) );
    $args         = array_merge( [ $user_id ], $all_lessons );
    $done_count   = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE user_id = %d AND post_id IN ($placeholders) AND completed_at IS NOT NULL",
        $args
    ) );

    if ( $done_count < count( $all_lessons ) ) return;

    // All lessons done — stamp completed_at on the course row (COALESCE never un-completes it)
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $t (user_id, post_id, course_id, enrolled_at, completed_at, last_activity_at)
         VALUES (%d, %d, %d, %d, %d, %d)
         ON DUPLICATE KEY UPDATE
             completed_at     = COALESCE(completed_at, VALUES(completed_at)),
             last_activity_at = VALUES(last_activity_at)",
        (int) $user_id, (int) $course_id, (int) $course_id, $now, $now, $now
    ) );

    do_action( 'snn_learn_course_completed', (int) $user_id, (int) $course_id );
}

// ============================================================
// 9. REST API ENDPOINTS
// ============================================================

add_action( 'rest_api_init', function () {

    // POST /wp-json/snn-learn/v1/complete
    // Body: { post_id: INT, complete: BOOL }
    register_rest_route( 'snn-learn/v1', '/complete', [
        'methods'             => 'POST',
        'callback'            => 'snn_learn_rest_complete',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args'                => [
            'post_id'  => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            'complete' => [ 'default' => true ],
        ],
    ] );

    // GET /wp-json/snn-learn/v1/progress?course_id=INT
    register_rest_route( 'snn-learn/v1', '/progress', [
        'methods'             => 'GET',
        'callback'            => 'snn_learn_rest_progress',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args'                => [
            'course_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    // GET /wp-json/snn-learn/v1/lesson-status?post_id=INT
    register_rest_route( 'snn-learn/v1', '/lesson-status', [
        'methods'             => 'GET',
        'callback'            => 'snn_learn_rest_lesson_status',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args'                => [
            'post_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    // GET /wp-json/snn-learn/v1/completed-lessons?course_id=INT
    register_rest_route( 'snn-learn/v1', '/completed-lessons', [
        'methods'             => 'GET',
        'callback'            => 'snn_learn_rest_completed_lessons',
        'permission_callback' => function () { return is_user_logged_in(); },
        'args'                => [
            'course_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    // DELETE /wp-json/snn-learn/v1/my-data
    // Logged-in user permanently deletes all their own enrollment rows.
    register_rest_route( 'snn-learn/v1', '/my-data', [
        'methods'             => 'DELETE',
        'callback'            => 'snn_learn_rest_delete_my_data',
        'permission_callback' => function () { return is_user_logged_in(); },
    ] );

} );

function snn_learn_rest_complete( WP_REST_Request $request ) {
    $post_id  = (int) $request->get_param( 'post_id' );
    $complete = (bool) $request->get_param( 'complete' );
    $user_id  = get_current_user_id();

    if ( ! $post_id ) {
        return new WP_Error( 'bad_post', 'Invalid post_id.', [ 'status' => 400 ] );
    }

    $course_id = snn_learn_get_course_id( $post_id );
    if ( ! $course_id ) {
        return new WP_Error( 'no_course', 'Could not resolve a course for this post.', [ 'status' => 400 ] );
    }

    snn_learn_record_lesson( $user_id, $post_id, $course_id, $complete );
    $progress = snn_learn_calc_progress( $user_id, $course_id );

    return rest_ensure_response( [
        'success'   => true,
        'post_id'   => $post_id,
        'course_id' => $course_id,
        'complete'  => $complete,
        'progress'  => $progress,
    ] );
}

function snn_learn_rest_progress( WP_REST_Request $request ) {
    $course_id = (int) $request->get_param( 'course_id' );
    $user_id   = get_current_user_id();
    return rest_ensure_response( [ 'progress' => snn_learn_calc_progress( $user_id, $course_id ) ] );
}

function snn_learn_rest_lesson_status( WP_REST_Request $request ) {
    global $wpdb;
    $post_id   = (int) $request->get_param( 'post_id' );
    $user_id   = get_current_user_id();
    $t         = $wpdb->prefix . 'snn_learn_enrollments';
    $completed = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT completed_at FROM $t WHERE user_id=%d AND post_id=%d AND completed_at IS NOT NULL",
        $user_id, $post_id
    ) );
    return rest_ensure_response( [ 'completed' => $completed ] );
}

function snn_learn_rest_completed_lessons( WP_REST_Request $request ) {
    global $wpdb;
    $course_id     = (int) $request->get_param( 'course_id' );
    $user_id       = get_current_user_id();
    $t             = $wpdb->prefix . 'snn_learn_enrollments';
    $rows          = $wpdb->get_results( $wpdb->prepare(
        "SELECT post_id FROM $t WHERE user_id=%d AND course_id=%d AND completed_at IS NOT NULL",
        $user_id, $course_id
    ) );
    $completed_ids = array_map( 'intval', array_column( $rows, 'post_id' ) );
    return rest_ensure_response( [ 'completed' => $completed_ids ] );
}

function snn_learn_rest_delete_my_data( WP_REST_Request $request ) {
    global $wpdb;
    $user_id = get_current_user_id();
    $t       = $wpdb->prefix . 'snn_learn_enrollments';
    $wpdb->delete( $t, [ 'user_id' => (int) $user_id ], [ '%d' ] );
    return rest_ensure_response( [ 'success' => true ] );
}

// Force no-cache headers on all SNN Learn REST responses — prevents Cloudflare / proxy caching
add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
    if ( strpos( $request->get_route(), '/snn-learn/' ) !== false ) {
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Expires', '0' );
    }
    return $response;
}, 10, 3 );

// ============================================================
// 10. SHORTCODES
// ============================================================

// ----------------------------------------------------------
// [snn_learn_video_player]
// ----------------------------------------------------------
add_shortcode( 'snn_learn_video_player', function ( $atts ) {
    if ( ! is_user_logged_in() ) return '';

    $post_id     = get_the_ID();
    $video_field = snn_learn_get( 'video_field' );
    $video_url   = get_post_meta( $post_id, $video_field, true );

    if ( ! $video_url ) {
        return '<!-- snn_learn_video_player: no video found in field "' . esc_html( $video_field ) . '" -->';
    }

    $c_primary  = esc_attr( snn_learn_get( 'video_color_primary' ) ?: '#3b82f6' );
    $c_bg       = esc_attr( snn_learn_get( 'video_color_bg' )      ?: '#1e293b' );
    $c_text     = esc_attr( snn_learn_get( 'video_color_text' )    ?: '#f8fafc' );
    $comp_sec   = (int)  snn_learn_get( 'video_complete_seconds' ) ?: 1;
    $on_end     = (int)  snn_learn_get( 'video_complete_on_end' );
    $rest_url   = esc_js( rest_url( 'snn-learn/v1/complete' ) );
    $nonce      = esc_js( wp_create_nonce( 'wp_rest' ) );
    $uid        = 'snnvp_' . $post_id . '_' . substr( md5( uniqid( '', true ) ), 0, 6 );

    $status_url = esc_js( rest_url( 'snn-learn/v1/lesson-status' ) );

    ob_start();
    ?>
    <div id="<?= $uid ?>-wrap" class="snn-video-player-wrap" style="position:relative;width:100%;background:<?= $c_bg ?>;overflow:hidden;user-select:none;font-family:sans-serif">

        <video id="<?= $uid ?>" preload="metadata" playsinline style="width:100%;display:block;cursor:pointer"></video>

        <!-- Controls Bar -->
        <div id="<?= $uid ?>-controls" class="snn-video-controls" style="position:absolute;bottom:0;left:0;right:0;padding:8px 12px 10px;background:linear-gradient(transparent,<?= $c_bg ?>e0);transition:opacity .3s">

            <!-- Progress / Seek bar -->
            <div id="<?= $uid ?>-barwrap" class="snn-video-seek" style="width:100%;height:5px;background:rgba(255,255,255,0.2);border-radius:3px;cursor:pointer;position:relative;margin-bottom:8px">
                <div id="<?= $uid ?>-bar" class="snn-video-progress" style="height:100%;width:0%;background:<?= $c_primary ?>;border-radius:3px;pointer-events:none"></div>
                <div id="<?= $uid ?>-thumb" class="snn-video-seek-thumb" style="position:absolute;top:50%;transform:translate(-50%,-50%);left:0%;font-size:11px;line-height:1;pointer-events:none;color:<?= $c_primary ?>">&#128280;</div>
            </div>

            <!-- Buttons row -->
            <div style="display:flex;align-items:center;gap:10px">
                <button id="<?= $uid ?>-play" class="snn-video-play-btn" style="background:none;border:none;cursor:pointer;font-size:20px;color:<?= $c_text ?>;padding:0;line-height:1" title="Play / Pause">&#9654;</button>
                <span   id="<?= $uid ?>-time" class="snn-video-time"     style="font-size:12px;color:<?= $c_text ?>;font-variant-numeric:tabular-nums;min-width:92px">0:00 / 0:00</span>
                <div style="flex:1"></div>
                <button id="<?= $uid ?>-full" class="snn-video-full-btn" style="background:none;border:none;cursor:pointer;font-size:18px;color:<?= $c_text ?>;padding:0;line-height:1" title="Fullscreen">&#9654;&#9654;</button>
            </div>

        </div>

        <!-- Completed badge (always hidden in HTML; JS checks fresh status to defeat page cache) -->
        <div id="<?= $uid ?>-badge" class="snn-video-completed-badge" style="display:none;position:absolute;top:10px;right:10px;background:<?= $c_primary ?>;color:<?= $c_text ?>;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:bold">&#10003; Completed</div>

    </div>

    <script>
    (function () {
        var video    = document.getElementById('<?= $uid ?>');
        var wrap     = document.getElementById('<?= $uid ?>-wrap');
        var playBtn  = document.getElementById('<?= $uid ?>-play');
        var fullBtn  = document.getElementById('<?= $uid ?>-full');
        var bar      = document.getElementById('<?= $uid ?>-bar');
        var barWrap  = document.getElementById('<?= $uid ?>-barwrap');
        var thumb    = document.getElementById('<?= $uid ?>-thumb');
        var timeEl   = document.getElementById('<?= $uid ?>-time');
        var badge    = document.getElementById('<?= $uid ?>-badge');
        var controls = document.getElementById('<?= $uid ?>-controls');

        var POST_ID      = <?= (int) $post_id ?>;
        var COMP_SEC     = <?= (int) $comp_sec ?>;
        var ON_END       = <?= $on_end ? 'true' : 'false' ?>;
        var REST_URL     = '<?= $rest_url ?>';
        var STATUS_URL   = '<?= $status_url ?>';
        var NONCE        = '<?= $nonce ?>';
        var completed    = false; // always start false; JS fetches real state below
        var watchedSec   = 0;
        var lastTime     = 0;
        var hideTimer    = null;

        // Fetch completion status fresh — bypasses any cached HTML page
        fetch(STATUS_URL + '?post_id=' + POST_ID + '&_t=' + Date.now(), {
            headers: { 'X-WP-Nonce': NONCE },
            cache: 'no-store'
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.completed) { completed = true; badge.style.display = 'block'; }
        });

        // Set src after DOM ready to allow poster placeholder
        video.src = '<?= esc_js( $video_url ) ?>';

        function fmt(s) {
            s = Math.floor(s || 0);
            return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
        }

        // Play / Pause
        function togglePlay() { video.paused ? video.play() : video.pause(); }
        playBtn.addEventListener('click', togglePlay);
        video.addEventListener('click',  togglePlay);
        video.addEventListener('play',   function () { playBtn.innerHTML = '&#9646;&#9646;'; });
        video.addEventListener('pause',  function () { playBtn.innerHTML = '&#9654;'; });
        video.addEventListener('ended',  function () {
            playBtn.innerHTML = '&#9654;';
            if (ON_END && !completed) markComplete();
        });

        // Time update — track cumulative watched time accurately
        video.addEventListener('timeupdate', function () {
            if (!video.duration) return;
            var now   = video.currentTime;
            var delta = now - lastTime;
            // ignore seeks (delta > 2s) and negative deltas
            if (delta > 0 && delta < 2) watchedSec += delta;
            lastTime = now;

            var pct = (now / video.duration) * 100;
            bar.style.width  = pct + '%';
            thumb.style.left = pct + '%';
            timeEl.textContent = fmt(now) + ' / ' + fmt(video.duration);

            if (!ON_END && !completed && watchedSec >= COMP_SEC) markComplete();
        });

        // Seek — click
        barWrap.addEventListener('click', function (e) {
            if (!video.duration) return;
            var rect = barWrap.getBoundingClientRect();
            video.currentTime = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width)) * video.duration;
        });

        // Seek — drag
        var dragging = false;
        barWrap.addEventListener('mousedown', function () { dragging = true; });
        document.addEventListener('mousemove', function (e) {
            if (!dragging || !video.duration) return;
            var rect = barWrap.getBoundingClientRect();
            video.currentTime = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width)) * video.duration;
        });
        document.addEventListener('mouseup', function () { dragging = false; });

        // Touch seek
        barWrap.addEventListener('touchstart', function (e) {
            var t = e.touches[0];
            var rect = barWrap.getBoundingClientRect();
            if (video.duration) video.currentTime = Math.max(0, Math.min(1, (t.clientX - rect.left) / rect.width)) * video.duration;
        }, { passive: true });

        // Fullscreen
        fullBtn.addEventListener('click', function () {
            if (document.fullscreenElement) {
                document.exitFullscreen();
                fullBtn.innerHTML = '&#9654;&#9654;';
            } else {
                wrap.requestFullscreen();
                fullBtn.innerHTML = '&#9632;';
            }
        });

        // Auto-hide controls
        function showControls() {
            controls.style.opacity = '1';
            clearTimeout(hideTimer);
            if (!video.paused) hideTimer = setTimeout(function () { controls.style.opacity = '0'; }, 2500);
        }
        wrap.addEventListener('mousemove',  showControls);
        wrap.addEventListener('touchstart', showControls, { passive: true });
        wrap.addEventListener('mouseleave', function () {
            if (!video.paused) hideTimer = setTimeout(function () { controls.style.opacity = '0'; }, 800);
        });
        video.addEventListener('play',  function () { hideTimer = setTimeout(function () { controls.style.opacity = '0'; }, 2500); });
        video.addEventListener('pause', function () { clearTimeout(hideTimer); controls.style.opacity = '1'; });

        // Mark complete
        function markComplete() {
            if (completed) return;
            completed = true;
            badge.style.display = 'block';
            fetch(REST_URL + '?_t=' + Date.now(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                cache: 'no-store',
                body: JSON.stringify({ post_id: POST_ID, complete: true })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (typeof d.progress !== 'undefined') {
                    document.dispatchEvent(new CustomEvent('snnLearnProgress', {
                        detail: { progress: d.progress, course_id: d.course_id, post_id: POST_ID }
                    }));
                }
            });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
} );

// ----------------------------------------------------------
// [snn_learn_progress]
// ----------------------------------------------------------
add_shortcode( 'snn_learn_progress', function ( $atts ) {
    $atts    = shortcode_atts( [ 'course_id' => 0, 'output' => '' ], $atts );
    $user_id = get_current_user_id();
    if ( ! $user_id ) return $atts['output'] === 'bool' ? 'false' : '0';

    $course_id = (int) $atts['course_id'] ?: snn_learn_get_course_id();
    if ( ! $course_id ) return $atts['output'] === 'bool' ? 'false' : '0';

    $progress = snn_learn_calc_progress( $user_id, $course_id );

    if ( $atts['output'] === 'bool' ) {
        return $progress > 1 ? 'true' : 'false';
    }

    return (string) $progress;
} );

// ----------------------------------------------------------
// [snn_learn_course_chapter_lesson_list]
// ----------------------------------------------------------
add_shortcode( 'snn_learn_course_chapter_lesson_list', function ( $atts ) {
    $atts = shortcode_atts( [ 'course_id' => 0 ], $atts );

    $course_id  = (int) $atts['course_id'] ?: snn_learn_get_course_id();
    if ( ! $course_id ) return '';

    $pt         = snn_learn_get( 'course_post_type' );
    $user_id    = get_current_user_id();
    $current_id = get_the_ID();

    // Query 1: chapters = direct children of the course
    $chapters = get_posts( [
        'post_type'      => $pt,
        'post_parent'    => $course_id,
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
    ] );

    // Query 2: ALL lessons for ALL chapters in one query (eliminates N+1)
    $lessons_by_chapter = [];
    if ( ! empty( $chapters ) ) {
        $all_lessons = get_posts( [
            'post_type'       => $pt,
            'post_parent__in' => wp_list_pluck( $chapters, 'ID' ),
            'posts_per_page'  => -1,
            'orderby'         => 'menu_order',
            'order'           => 'ASC',
            'post_status'     => 'publish',
        ] );
        foreach ( $all_lessons as $l ) {
            $lessons_by_chapter[ $l->post_parent ][] = $l;
        }
    }

    // Pre-fetch completed lesson IDs for current user via PHP
    $completed_ids = [];
    if ( $user_id ) {
        global $wpdb;
        $t    = $wpdb->prefix . 'snn_learn_enrollments';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id FROM $t WHERE user_id=%d AND course_id=%d AND completed_at IS NOT NULL",
            $user_id, $course_id
        ) );
        $completed_ids = array_map( 'intval', array_column( $rows, 'post_id' ) );
    }

    ob_start();
    echo '<nav class="snn-course-nav">';

    foreach ( $chapters as $ch ) {
        echo '<div class="snn-chapter">';
        echo '<span class="snn-chapter-title">' . esc_html( $ch->post_title ) . '</span>';

        $lessons = $lessons_by_chapter[ $ch->ID ] ?? [];

        if ( $lessons ) {
            echo '<ul class="snn-lessons-list">';
            foreach ( $lessons as $l ) {
                $is_current   = ( $l->ID === $current_id );
                $is_completed = in_array( $l->ID, $completed_ids, true );
                $cls          = 'snn-lesson-item';
                if ( $is_current )   $cls .= ' snn-lesson-current';
                if ( $is_completed ) $cls .= ' snn-lesson-completed';

                echo '<li class="' . esc_attr( $cls ) . '">';
                echo '<a class="snn-lesson-link" href="' . esc_url( get_permalink( $l->ID ) ) . '">';
                if ( $is_completed ) echo '<span class="snn-lesson-check" aria-label="Completed">&#10003; </span>';
                echo esc_html( $l->post_title );
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    echo '</nav>';
    return ob_get_clean();
} );

// ----------------------------------------------------------
// [snn_learn_mark_completed]
// ----------------------------------------------------------
add_shortcode( 'snn_learn_mark_completed', function ( $atts ) {
    $atts = shortcode_atts( [
        'label'           => 'Mark as Completed',
        'completed_label' => '&#10003; Completed',
    ], $atts );

    if ( ! is_user_logged_in() ) return '';

    $post_id    = get_the_ID();
    $rest_url   = esc_attr( rest_url( 'snn-learn/v1/complete' ) );
    $status_url = esc_attr( rest_url( 'snn-learn/v1/lesson-status' ) );
    $nonce      = esc_attr( wp_create_nonce( 'wp_rest' ) );
    $uid        = 'snn_mc_' . $post_id;

    // Always render as not-done — JS fetches real state to defeat HTML page cache
    $label    = $atts['label'];
    $disabled = '';
    $done_cls = '';

    ob_start();
    ?>
    <button
        id="<?= esc_attr( $uid ) ?>"
        class="snn-mark-completed-btn<?= $done_cls ?>"
        data-post-id="<?= (int) $post_id ?>"
        data-rest-url="<?= $rest_url ?>"
        data-status-url="<?= $status_url ?>"
        data-nonce="<?= $nonce ?>"
        data-done-label="<?= esc_attr( $atts['completed_label'] ) ?>"
        onclick="snnMarkCompleted(this)"
        <?= $disabled ?>
    ><?= $label ?></button>

    <script>
    if (typeof snnMarkCompleted === 'undefined') {
        window.snnMarkCompleted = function (btn) {
            if (btn.disabled) return;
            btn.disabled = true;
            fetch(btn.dataset.restUrl + '?_t=' + Date.now(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': btn.dataset.nonce },
                cache: 'no-store',
                body: JSON.stringify({ post_id: parseInt(btn.dataset.postId), complete: true })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.innerHTML = btn.dataset.doneLabel;
                btn.classList.add('snn-mark-completed-done');
                if (typeof d.progress !== 'undefined') {
                    document.dispatchEvent(new CustomEvent('snnLearnProgress', {
                        detail: { progress: d.progress, course_id: d.course_id, post_id: parseInt(btn.dataset.postId) }
                    }));
                }
            })
            .catch(function () { btn.disabled = false; });
        };
    }
    // Check completion status fresh on load — bypasses cached HTML
    (function() {
        var btn = document.getElementById('<?= esc_js( $uid ) ?>');
        if (!btn) return;
        fetch(btn.dataset.statusUrl + '?post_id=' + btn.dataset.postId + '&_t=' + Date.now(), {
            headers: { 'X-WP-Nonce': btn.dataset.nonce },
            cache: 'no-store'
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.completed) {
                btn.innerHTML = btn.dataset.doneLabel;
                btn.classList.add('snn-mark-completed-done');
                btn.disabled = true;
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
} );

// ----------------------------------------------------------
// [snn_learn_delete_my_data]
// ----------------------------------------------------------
add_shortcode( 'snn_learn_delete_my_data', function ( $atts ) {
    $atts = shortcode_atts( [
        'label'           => 'Delete My Learning Data',
        'confirmed_label' => '&#10003; Your data has been deleted.',
    ], $atts );

    if ( ! is_user_logged_in() ) return '';

    $delete_url = esc_attr( rest_url( 'snn-learn/v1/my-data' ) );
    $nonce      = esc_attr( wp_create_nonce( 'wp_rest' ) );
    $uid        = 'snn_del_' . get_current_user_id();

    ob_start();
    ?>
    <button
        id="<?= esc_attr( $uid ) ?>"
        class="snn-delete-my-data-btn"
        data-delete-url="<?= $delete_url ?>"
        data-nonce="<?= $nonce ?>"
        data-done-label="<?= esc_attr( $atts['confirmed_label'] ) ?>"
        onclick="snnDeleteMyData(this)"
        style="background:#dc2626;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600"
    ><?= esc_html( $atts['label'] ) ?></button>
    <script>
    if (typeof snnDeleteMyData === 'undefined') {
        window.snnDeleteMyData = function (btn) {
            if (!confirm('This will permanently delete all your learning progress and enrolment history. This cannot be undone. Continue?')) return;
            btn.disabled = true;
            fetch(btn.dataset.deleteUrl + '?_t=' + Date.now(), {
                method: 'DELETE',
                headers: { 'X-WP-Nonce': btn.dataset.nonce },
                cache: 'no-store'
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.outerHTML = '<span style="color:#16a34a;font-weight:600">' + btn.dataset.doneLabel + '</span>';
            })
            .catch(function () { btn.disabled = false; alert('An error occurred. Please try again.'); });
        };
    }
    </script>
    <?php
    return ob_get_clean();
} );

// ----------------------------------------------------------
// [snn_learn_my_courses]
// ----------------------------------------------------------
add_shortcode( 'snn_learn_my_courses', function () {
    $user_id = get_current_user_id();
    if ( ! $user_id ) return '';

    global $wpdb;
    $t = $wpdb->prefix . 'snn_learn_enrollments';

    // Fetch all course rows for this user (post_id = course_id = top-level enrollment)
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT course_id, enrolled_at, completed_at FROM $t
          WHERE user_id = %d AND post_id = course_id
          ORDER BY enrolled_at DESC",
        $user_id
    ) );

    if ( empty( $rows ) ) return '';

    ob_start();
    echo '<ul class="snn-my-courses">';
    foreach ( $rows as $row ) {
        $course_id  = (int) $row->course_id;
        $title      = get_the_title( $course_id );
        $url        = get_permalink( $course_id );
        $progress   = snn_learn_calc_progress( $user_id, $course_id );
        $is_done    = ! empty( $row->completed_at );

        echo '<li class="snn-my-courses-item">';
        echo '<a class="snn-my-courses-link" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
        echo ' <span class="snn-my-courses-progress">' . (int) $progress . '%</span>';
        if ( $is_done ) {
            echo ' <span class="snn-my-courses-done">&#10003;</span>';
        }
        echo '</li>';
    }
    echo '</ul>';
    return ob_get_clean();
} );

// ============================================================
// 11. CHAPTER → FIRST LESSON REDIRECT
// ============================================================

add_action( 'template_redirect', function () {
    if ( ! is_singular() ) return;

    $post = get_post();
    $pt   = snn_learn_get( 'course_post_type' );

    if ( ! $post || $post->post_type !== $pt ) return;

    // A "chapter" has a parent (not top-level) whose parent is 0 (top-level = course).
    if ( ! $post->post_parent ) return; // it's a course, skip
    $parent = get_post( $post->post_parent );
    if ( ! $parent || $parent->post_parent !== 0 ) return; // parent is not a course

    // This is a chapter — redirect to its first lesson child
    $first_lesson = get_posts( [
        'post_type'      => $pt,
        'post_parent'    => $post->ID,
        'posts_per_page' => 1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );

    if ( $first_lesson ) {
        wp_redirect( get_permalink( $first_lesson[0] ), 302 );
        exit;
    }
} );

// ============================================================
// 12. COMMENT LIST SHORTCODE
// ============================================================

function snn_learn_get_user_initials( $display_name ) {
    $names    = explode( ' ', trim( $display_name ) );
    $initials = count( $names ) === 1
        ? strtoupper( substr( $names[0], 0, 1 ) )
        : strtoupper( substr( $names[0], 0, 1 ) . substr( end( $names ), 0, 1 ) );
    return $initials;
}

// [snn_learn_comment_list]
// Atts: avatar (px), order (ASC|DESC), number, show_ratings (1|0), moderation_notice
add_shortcode( 'snn_learn_comment_list', function ( $atts ) {
    $atts = shortcode_atts( [
        'avatar'            => 48,
        'order'             => 'DESC',
        'number'            => '',
        'show_ratings'      => '1',
        'moderation_notice' => 'Your comment is saved and waiting for approval.',
    ], $atts );

    $avatar       = max( 24, intval( $atts['avatar'] ) );
    $order        = in_array( strtoupper( $atts['order'] ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $atts['order'] ) : 'DESC';
    $number       = $atts['number'] !== '' ? intval( $atts['number'] ) : '';
    $show_ratings = ( $atts['show_ratings'] !== '0' && ! empty( $atts['show_ratings'] ) );
    $mod_notice   = sanitize_text_field( $atts['moderation_notice'] );

    $post_id = get_queried_object_id() ?: get_the_ID();
    if ( ! $post_id ) return '';

    $unapp_id = isset( $_GET['unapproved'] )      ? intval( $_GET['unapproved'] ) : 0;
    $unapp_hx = isset( $_GET['moderation-hash'] ) ? sanitize_text_field( $_GET['moderation-hash'] ) : '';

    $font_size   = max( 12, intval( $avatar * 0.38 ) );
    $author_col  = $avatar + 40;

    ob_start();
    ?>
<style>
.snn-cl-wrap{width:100%}
#comment{display:none!important}
.snn-cl-list{list-style:none;margin:0;padding:0}
.snn-cl-item{display:flex;align-items:flex-start;padding:20px 0;border-bottom:1px solid #f0f0f0}
.snn-cl-item:last-child{border-bottom:0}
.snn-cl-col-author{flex:0 0 <?php echo esc_attr( $author_col ); ?>px;text-align:center;padding-right:16px}
.snn-cl-avatar{width:<?php echo esc_attr( $avatar ); ?>px;height:<?php echo esc_attr( $avatar ); ?>px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto;background:lightgray;color:#fff;font-weight:700;font-size:<?php echo esc_attr( $font_size ); ?>px;line-height:1}
.snn-cl-meta{font-size:11px;color:#aaa;margin-top:6px;line-height:1.5}
.snn-cl-col-body{flex:1;min-width:0}
.snn-cl-bubble{background:#f7f8fa;padding:14px 16px;border-radius:10px}
.snn-cl-text{line-height:1.8;color:#333;word-break:break-word}
.snn-cl-text p{margin:0 0 .5em}
.snn-cl-text blockquote{font-family:inherit;font-size:inherit;margin:8px 0;padding-left:12px;border-left:3px solid #e0e0e0;color:#666}
.snn-cl-rating{margin-top:8px;font-size:18px;line-height:1}
.snn-cl-star-on{color:#0556c7}
.snn-cl-star-off{color:#ddd}
.snn-cl-mod-notice{background:#fff8e1;color:#856404;padding:12px 16px;border-radius:8px;margin-bottom:18px;border:1px solid #ffe082;font-size:14px;font-weight:600}
.snn-cl-unapp .snn-cl-bubble{border:2px dashed #ffe082}
.snn-cl-unapp{opacity:.75}
.snn-cl-empty{color:#aaa;text-align:center;padding:28px 0;font-size:14px}
</style>
<div class="snn-cl-wrap">
    <?php
    // --- Moderation / unapproved comment preview ---
    if ( $unapp_id && $unapp_hx ) {
        $unapp = get_comment( $unapp_id );
        if ( $unapp && $unapp->comment_approved == '0' && (int) $unapp->comment_post_ID === $post_id ) {
            echo '<div class="snn-cl-mod-notice">' . esc_html( $mod_notice ) . '</div>';
            $ini = snn_learn_get_user_initials( $unapp->comment_author ?: 'A' );
            ?>
            <ul class="snn-cl-list">
                <li class="snn-cl-item snn-cl-unapp">
                    <div class="snn-cl-col-author">
                        <div class="snn-cl-avatar"><?php echo esc_html( $ini ); ?></div>
                        <div class="snn-cl-meta">
                            <?php echo esc_html( get_comment_date( '', $unapp ) ); ?><br>
                            <?php echo esc_html( get_comment_time( '', true, $unapp ) ); ?>
                        </div>
                    </div>
                    <div class="snn-cl-col-body">
                        <div class="snn-cl-bubble">
                            <div class="snn-cl-text"><?php echo wp_kses_post( apply_filters( 'comment_text', $unapp->comment_content, $unapp ) ); ?></div>
                        </div>
                    </div>
                </li>
            </ul>
            <?php
        }
    }

    // --- Approved comments ---
    $args = [
        'post_id'                   => $post_id,
        'status'                    => 'approve',
        'order'                     => $order,
        'no_found_rows'             => true,
        'update_comment_meta_cache' => true,
        'hierarchical'              => false,
    ];
    if ( $number !== '' ) {
        $args['number'] = $number;
    }
    $comments = get_comments( $args );

    if ( empty( $comments ) ) {
        echo '<p class="snn-cl-empty">No comments yet.</p>';
    } else {
        echo '<ul class="snn-cl-list">';
        foreach ( $comments as $c ) {
            $ini    = snn_learn_get_user_initials( $c->comment_author ?: 'A' );
            $rating = $show_ratings ? max( 0, min( 5, intval( get_comment_meta( $c->comment_ID, 'snn_rating_comment', true ) ) ) ) : 0;
            ?>
            <li class="snn-cl-item" id="snn-cl-<?php echo esc_attr( $c->comment_ID ); ?>">
                <div class="snn-cl-col-author">
                    <div class="snn-cl-avatar"><?php echo esc_html( $ini ); ?></div>
                    <div class="snn-cl-meta">
                        <?php echo esc_html( get_comment_date( '', $c ) ); ?><br>
                        <?php echo esc_html( get_comment_time( '', true, $c ) ); ?>
                    </div>
                </div>
                <div class="snn-cl-col-body">
                    <div class="snn-cl-bubble">
                        <div class="snn-cl-text"><?php echo wp_kses_post( apply_filters( 'comment_text', $c->comment_content, $c ) ); ?></div>
                        <?php if ( $show_ratings && $rating >= 1 ) : ?>
                        <div class="snn-cl-rating">
                            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                <span class="<?php echo $i <= $rating ? 'snn-cl-star-on' : 'snn-cl-star-off'; ?>">&#9733;</span>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
            <?php
        }
        echo '</ul>';
    }
    ?>
</div>
    <?php
    return ob_get_clean();
} );

// ============================================================
// 13. ADMIN: COMMENT RATINGS COLUMN
// ============================================================

add_filter( 'manage_edit-comments_columns', 'snn_learn_add_comment_rating_column' );
function snn_learn_add_comment_rating_column( $columns ) {
    $out = [];
    foreach ( $columns as $key => $val ) {
        $out[ $key ] = $val;
        if ( $key === 'author' ) {
            $out['snn_rating'] = 'Rating';
        }
    }
    return $out;
}

add_action( 'manage_comments_custom_column', 'snn_learn_display_comment_rating_column', 10, 2 );
function snn_learn_display_comment_rating_column( $column, $comment_id ) {
    if ( $column !== 'snn_rating' ) return;
    $rating = max( 0, min( 5, intval( get_comment_meta( $comment_id, 'snn_rating_comment', true ) ) ) );
    echo '<div class="snn-learn-stars">';
    for ( $i = 1; $i <= 5; $i++ ) {
        echo '<span class="' . ( $i <= $rating ? 'snn-learn-star-on' : 'snn-learn-star-off' ) . '">&#9733;</span>';
    }
    echo '</div>';
}

// ============================================================
// 14. ADMIN: COMMENT RATING METABOX
// ============================================================

add_action( 'add_meta_boxes_comment', 'snn_learn_add_comment_rating_metabox' );
function snn_learn_add_comment_rating_metabox() {
    add_meta_box(
        'snn_learn_comment_rating_metabox',
        'Comment Rating',
        'snn_learn_comment_rating_metabox_html',
        'comment',
        'normal',
        'high'
    );
}

function snn_learn_comment_rating_metabox_html( $comment ) {
    $rating = max( 0, min( 5, intval( get_comment_meta( $comment->comment_ID, 'snn_rating_comment', true ) ) ) );
    wp_nonce_field( 'snn_learn_comment_rating_nonce_action', 'snn_learn_comment_rating_nonce', false );
    ?>
    <div style="padding:4px 0 8px">
        <div class="snn-learn-mb-stars" style="font-size:32px;line-height:1;margin-bottom:10px;cursor:pointer;user-select:none">
            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                <span data-val="<?php echo $i; ?>" class="<?php echo $i <= $rating ? 'snn-learn-star-on' : 'snn-learn-star-off'; ?>" style="margin-right:2px">&#9733;</span>
            <?php endfor; ?>
        </div>
        <select name="snn_rating_comment" id="snn_rating_comment" style="display:none">
            <?php for ( $i = 0; $i <= 5; $i++ ) : ?>
                <option value="<?php echo $i; ?>" <?php selected( $rating, $i ); ?>>
                    <?php echo $i === 0 ? 'No Rating' : $i . ' Star' . ( $i > 1 ? 's' : '' ); ?>
                </option>
            <?php endfor; ?>
        </select>
        <p class="description" style="margin-top:4px">Stored as <code>snn_rating_comment</code> in comment meta.</p>
    </div>
    <script>
    (function () {
        var wrap   = document.querySelector('.snn-learn-mb-stars');
        var stars  = wrap ? wrap.querySelectorAll('span') : [];
        var select = document.getElementById('snn_rating_comment');
        if (!wrap || !select) return;

        function paintStars(val) {
            stars.forEach(function (s, i) {
                s.className = i < val ? 'snn-learn-star-on' : 'snn-learn-star-off';
            });
        }

        stars.forEach(function (s) {
            s.addEventListener('click', function () {
                var v = parseInt(this.dataset.val, 10);
                select.value = v;
                paintStars(v);
            });
            s.addEventListener('mouseenter', function () {
                paintStars(parseInt(this.dataset.val, 10));
            });
        });

        wrap.addEventListener('mouseleave', function () {
            paintStars(parseInt(select.value, 10));
        });
    })();
    </script>
    <?php
}

add_action( 'edit_comment', 'snn_learn_save_comment_rating_metabox' );
function snn_learn_save_comment_rating_metabox( $comment_id ) {
    if (
        ! isset( $_POST['snn_learn_comment_rating_nonce'] ) ||
        ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_comment_rating_nonce'] ) ), 'snn_learn_comment_rating_nonce_action' )
    ) {
        return;
    }
    if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
        return;
    }
    if ( isset( $_POST['snn_rating_comment'] ) ) {
        $rating = max( 0, min( 5, intval( $_POST['snn_rating_comment'] ) ) );
        update_comment_meta( $comment_id, 'snn_rating_comment', $rating );
    }
}

// ============================================================
// 15. USER PERMALINKS
// ============================================================

/**
 * Step 1 – Change the global author base to a placeholder so WordPress does
 * not generate its own /author/slug rules that would conflict with ours.
 * Also auto-flushes stale rewrite rules once, at shutdown, when our custom
 * bases are not yet present in the cached rules.
 */
add_action( 'init', function () {
    if ( ! snn_learn_get( 'user_permalinks_enabled' ) ) return;

    global $wp_rewrite;
    $wp_rewrite->author_base = 'snn-learn-user'; // placeholder — our filter overrides everything
}, 1 );

/**
 * Step 2 – Replace ALL author rewrite rules with our ID-based patterns.
 * Returning a fresh array (not merging) wipes WordPress defaults, preventing
 * /author/username from being accessible (duplicate content / username leak).
 */
add_filter( 'author_rewrite_rules', function ( $rules ) {
    if ( ! snn_learn_get( 'user_permalinks_enabled' ) ) return $rules;

    $nb = sanitize_title( snn_learn_get( 'user_permalink_normal_base' ) ) ?: 'user';
    $ib = sanitize_title( snn_learn_get( 'user_permalink_instr_base' ) )  ?: 'instructor';

    // Build de-duplicated rule set (handles the edge case where both bases are the same)
    $custom = [
        $nb . '/([0-9]+)/page/?([0-9]{1,})/?$' => 'index.php?author=$matches[1]&paged=$matches[2]',
        $nb . '/([0-9]+)/?$'                    => 'index.php?author=$matches[1]',
    ];
    if ( $ib !== $nb ) {
        $custom[ $ib . '/([0-9]+)/page/?([0-9]{1,})/?$' ] = 'index.php?author=$matches[1]&paged=$matches[2]';
        $custom[ $ib . '/([0-9]+)/?$' ]                   = 'index.php?author=$matches[1]';
    }

    return $custom;
} );

/**
 * Step 3 – Rewrite the outbound author URL based on the user's role.
 * Fires for get_author_posts_url() and all author archive link helpers.
 */
add_filter( 'author_link', function ( $link, $author_id ) {
    if ( ! snn_learn_get( 'user_permalinks_enabled' ) ) return $link;

    $user = get_userdata( (int) $author_id );
    if ( ! $user ) return $link;

    $instr_roles   = (array) snn_learn_get( 'user_permalink_instr_roles' );
    $is_instructor = ! empty( array_intersect( $instr_roles, (array) $user->roles ) );

    $base = $is_instructor
        ? ( sanitize_title( snn_learn_get( 'user_permalink_instr_base' ) )  ?: 'instructor' )
        : ( sanitize_title( snn_learn_get( 'user_permalink_normal_base' ) ) ?: 'user' );

    return home_url( '/' . $base . '/' . (int) $author_id . '/' );
}, 10, 2 );

/**
 * Step 4 – Traffic controller on every resolved author archive.
 *  a) No-ops if the request is already on the correct canonical ID-based URL.
 *  b) Returns 404 when the URL prefix doesn't match the user's role
 *     (e.g. a subscriber visiting /instructor/42/).
 *  c) 301-redirects any other path (legacy /author/username, old placeholder
 *     base, etc.) to the correct canonical URL.
 */
add_action( 'template_redirect', function () {
    if ( ! snn_learn_get( 'user_permalinks_enabled' ) ) return;
    if ( ! is_author() ) return; // Exit fast on non-author pages

    $author = get_queried_object();
    if ( ! ( $author instanceof WP_User ) ) return;

    $instr_roles   = (array) snn_learn_get( 'user_permalink_instr_roles' );
    $is_instructor = ! empty( array_intersect( $instr_roles, (array) $author->roles ) );

    $instr_base  = sanitize_title( snn_learn_get( 'user_permalink_instr_base' ) )  ?: 'instructor';
    $normal_base = sanitize_title( snn_learn_get( 'user_permalink_normal_base' ) ) ?: 'user';

    $correct_base = $is_instructor ? $instr_base : $normal_base;
    $correct_url  = home_url( '/' . $correct_base . '/' . (int) $author->ID . '/' );

    // REQUEST_URI is only used for string prefix comparison — never output or used in queries.
    $req_path = ltrim( (string) ( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?: '' ), '/' );

    $on_correct = ( strpos( $req_path, $correct_base . '/' . (int) $author->ID ) === 0 );
    if ( $on_correct ) return; // Already on the canonical URL — nothing to do

    $on_instr  = ( strpos( $req_path, $instr_base . '/' ) === 0 );
    $on_normal = ( strpos( $req_path, $normal_base . '/' ) === 0 );

    // Wrong role prefix — serve a 404
    if ( ( $is_instructor && $on_normal ) || ( ! $is_instructor && $on_instr ) ) {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
        return;
    }

    // Legacy /author/username or any other unrecognised prefix — 301 redirect
    wp_redirect( $correct_url, 301 );
    exit;
}, 1 );

// Flush rewrite rules on plugin activation so our rules are active immediately.
register_activation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

// ============================================================
// Shared admin CSS for star colors (column list + metabox)
add_action( 'admin_head', 'snn_learn_comment_admin_star_css' );
function snn_learn_comment_admin_star_css() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, [ 'edit-comments', 'comment' ], true ) ) return;
    ?>
    <style>
    .snn-learn-stars { font-size: 0; white-space: nowrap; }
    .snn-learn-star-on, .snn-learn-star-off { font-size: 22px; display: inline-block; line-height: 1; }
    .snn-learn-star-on  { color: #f5a623; }
    .snn-learn-star-off { color: #c8c8c8; }
    #snn_learn_comment_rating_metabox h2.hndle { background: #2271b1; color: #fff; border-radius: 2px 2px 0 0; }
    .snn-learn-mb-stars .snn-learn-star-on  { color: #f5a623; }
    .snn-learn-mb-stars .snn-learn-star-off { color: #c8c8c8; }
    .snn-learn-mb-stars span:hover         { opacity: .85; }
    </style>
    <?php
}
