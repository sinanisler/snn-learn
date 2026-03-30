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

// ============================================================
// 2. SETTINGS HELPERS
// ============================================================

function snn_learn_defaults() {
    return [
        'course_post_type'       => 'course',
        'lesson_post_type'       => 'lesson',
        'video_field'            => 'video_url',
        'video_color_primary'    => '#3b82f6',
        'video_color_bg'         => '#1e293b',
        'video_color_text'       => '#f8fafc',
        'video_complete_seconds' => 1,
        'video_complete_on_end'  => 0,
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
        26
    );
    add_submenu_page( 'snn-learn', 'Dashboard',  'Dashboard',  'manage_options', 'snn-learn',            'snn_learn_dashboard_page'  );
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

    // ---- KPI Queries ----
    $total_enrollments  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
    $recent_enrollments = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE enrolled_at >= UNIX_TIMESTAMP(NOW() - INTERVAL 30 DAY)" );
    $completion_rate    = (float) $wpdb->get_var( "SELECT (COUNT(CASE WHEN completed_at IS NOT NULL THEN 1 END) / NULLIF(COUNT(*),0)) * 100 FROM $t" );
    $weekly_active      = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE last_activity_at >= UNIX_TIMESTAMP(NOW() - INTERVAL 7 DAY)" );
    $gone_cold          = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE last_activity_at < UNIX_TIMESTAMP(NOW() - INTERVAL 14 DAY) AND completed_at IS NULL" );
    $active_courses     = (int)   $wpdb->get_var( "SELECT COUNT(DISTINCT course_id) FROM $t" );
    $avg_days           = (float) $wpdb->get_var( "SELECT AVG(completed_at - enrolled_at) / 86400 FROM $t WHERE completed_at IS NOT NULL" );
    $peak_day           = $wpdb->get_row( "SELECT FROM_UNIXTIME(enrolled_at, '%Y-%m-%d') AS date, COUNT(*) AS cnt FROM $t GROUP BY date ORDER BY cnt DESC LIMIT 1" );

    // ---- Trend (last 30 days) ----
    $trend_rows   = $wpdb->get_results( "SELECT FROM_UNIXTIME(enrolled_at, '%Y-%m-%d') AS day, COUNT(*) AS cnt FROM $t GROUP BY day ORDER BY day DESC LIMIT 30" );
    $trend_rows   = array_reverse( $trend_rows );
    $trend_labels = array_column( $trend_rows, 'day' );
    $trend_data   = array_map( 'intval', array_column( $trend_rows, 'cnt' ) );

    // ---- Course performance ----
    $courses_perf = $wpdb->get_results( "SELECT course_id, COUNT(*) AS enrolled, SUM(completed_at IS NOT NULL) AS completed FROM $t GROUP BY course_id ORDER BY enrolled DESC LIMIT 50" );

    // ---- At-risk students ----
    $at_risk = $wpdb->get_results( "SELECT user_id, course_id, last_activity_at FROM $t WHERE last_activity_at < UNIX_TIMESTAMP(NOW() - INTERVAL 14 DAY) AND completed_at IS NULL ORDER BY last_activity_at ASC LIMIT 20" );

    // ---- Recent activity feed ----
    $recent_activity = $wpdb->get_results( "SELECT user_id, course_id, enrolled_at, completed_at FROM $t ORDER BY enrolled_at DESC LIMIT 20" );
    ?>
    <div class="snn-learn-dashboard">
    <div class="p-6 max-w-screen-xl">

        <h1 class="text-2xl font-bold text-gray-800 mb-6">SNN Learn &mdash; Dashboard</h1>

        <!-- Row 1: Primary KPIs -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 border-l-4 border-blue-500">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Enrollments</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $total_enrollments ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 border-l-4 border-green-500">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Last 30 Days</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $recent_enrollments ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 border-l-4 border-purple-500">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Completion Rate</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $completion_rate, 1 ) ?>%</p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 border-l-4 border-yellow-500">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Weekly Active</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $weekly_active ) ?></p>
            </div>

        </div>

        <!-- Row 2: Mini Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 border-l-4 border-red-500">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Gone Cold</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $gone_cold ) ?></p>
                <p class="snn-kpi-desc text-xs text-gray-400 mt-1">14+ days inactive</p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 border-l-4 border-indigo-500">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Active Courses</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $active_courses ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 border-l-4 border-teal-500">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Avg Days to Complete</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= $avg_days ? number_format( $avg_days, 1 ) : '&mdash;' ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 border-l-4 border-orange-500">
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
                <h2 class="snn-chart-title text-sm font-semibold text-gray-600 mb-4">Enrollment Trend &mdash; Last 30 Days</h2>
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
                            $rate  = $c->enrolled ? round( $c->completed / $c->enrolled * 100 ) : 0;
                            $title = get_the_title( $c->course_id );
                            $badge = $rate >= 70 ? 'bg-green-100 text-green-800' : ( $rate >= 40 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800' );
                        ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="py-2 pr-4 text-blue-600 font-medium"><?= $title ? esc_html( $title ) : '#' . $c->course_id ?></td>
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

    if ( isset( $_POST['snn_learn_nonce'] ) && wp_verify_nonce( $_POST['snn_learn_nonce'], 'snn_learn_settings_save' ) ) {
        $text_fields = [ 'course_post_type', 'lesson_post_type', 'video_field', 'video_color_primary', 'video_color_bg', 'video_color_text', 'video_complete_seconds' ];
        foreach ( $text_fields as $f ) {
            $val = isset( $_POST[ 'snn_learn_' . $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'snn_learn_' . $f ] ) ) : '';
            update_option( 'snn_learn_' . $f, $val );
        }
        // Checkbox
        update_option( 'snn_learn_video_complete_on_end', isset( $_POST['snn_learn_video_complete_on_end'] ) ? 1 : 0 );
        echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
    }
    ?>
    <div class="snn-learn-settings wrap">
        <h1>SNN Learn &mdash; Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'snn_learn_settings_save', 'snn_learn_nonce' ); ?>
            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><label for="snn_cpt">Course Post Type Slug</label></th>
                    <td>
                        <input type="text" id="snn_cpt" name="snn_learn_course_post_type" value="<?= esc_attr( snn_learn_get( 'course_post_type' ) ) ?>" class="regular-text">
                        <p class="description">The post type slug for top-level course posts (e.g. <code>course</code>).</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="snn_lpt">Chapter &amp; Lesson Post Type Slug</label></th>
                    <td>
                        <input type="text" id="snn_lpt" name="snn_learn_lesson_post_type" value="<?= esc_attr( snn_learn_get( 'lesson_post_type' ) ) ?>" class="regular-text">
                        <p class="description">
                            Chapters and lessons share <strong>one post type</strong>. Role is determined by hierarchy depth:<br>
                            &nbsp;&nbsp;<strong>Chapter</strong> = direct child of a course post (<code>post_parent</code> = course ID).<br>
                            &nbsp;&nbsp;<strong>Lesson</strong> = child of a chapter (<code>post_parent</code> = chapter ID).<br>
                            Visiting a chapter URL auto-redirects to its first lesson.
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
            <?php submit_button( 'Save Settings' ); ?>
        </form>
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
            'description' => 'Renders the native HTML5 video player for the current lesson. Video source is read from the custom field configured in Settings. Tracks watched seconds and fires the REST completion endpoint automatically.',
            'attributes'  => [],
        ],
        [
            'tag'         => '[snn_learn_progress]',
            'description' => 'Outputs a plain number (0–100) representing the current user\'s completion percentage for the grandparent course of the current post. Safe to use inside any loop or builder element.',
            'attributes'  => [
                'course_id' => 'Optional. Manually specify a course ID. Defaults to the grandparent course of the current post.',
            ],
        ],
        [
            'tag'         => '[snn_learn_course_chapter_lesson_list]',
            'description' => 'Renders the full chapter → lesson navigation list for the current course. Chapters and lessons share one post type — chapters are direct children of the course (no link), lessons are children of chapters (linked). Ordered by menu_order. Completed lessons show a ✓ mark.',
            'attributes'  => [
                'course_id' => 'Optional. Override the course ID.',
            ],
        ],
        [
            'tag'         => '[snn_learn_mark_completed]',
            'description' => 'Renders a "Mark as Completed" button for doc / article / code-snippet lessons that have no video. On click it calls the REST API to mark the lesson complete and enroll the user in the course.',
            'attributes'  => [
                'label'           => 'Button text. Default: "Mark as Completed".',
                'completed_label' => 'Text shown after completion. Default: "✓ Completed".',
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
 * Chapters and lessons share the same post type.
 * Role is determined by depth:
 *   course  (course_type)
 *   └─ chapter (lesson_type, post_parent = course_id)
 *      └─ lesson  (lesson_type, post_parent = chapter_id)
 *
 * So:
 *   course  → return itself
 *   chapter → post_parent is a course  → return post_parent
 *   lesson  → post_parent is a chapter → return chapter's post_parent
 */
function snn_learn_get_course_id( $post_id = null ) {
    if ( ! $post_id ) $post_id = get_the_ID();
    $post = get_post( $post_id );
    if ( ! $post ) return 0;

    $course_type = snn_learn_get( 'course_post_type' );
    $lesson_type = snn_learn_get( 'lesson_post_type' );

    // It's a course itself
    if ( $post->post_type === $course_type ) return (int) $post->ID;

    if ( $post->post_type === $lesson_type ) {
        $parent = get_post( $post->post_parent );
        if ( ! $parent ) return 0;

        // Parent is a course → this post is a chapter
        if ( $parent->post_type === $course_type ) return (int) $parent->ID;

        // Parent is also lesson_type → this post is a lesson, parent is a chapter
        if ( $parent->post_type === $lesson_type ) return (int) $parent->post_parent;
    }

    return 0;
}

/**
 * Return an array of all lesson post IDs for a course, ordered by chapter then lesson menu_order.
 * Chapters and lessons share the same post type — chapters are direct children of the course,
 * lessons are children of chapters.
 */
function snn_learn_get_course_lessons( $course_id ) {
    $lesson_type = snn_learn_get( 'lesson_post_type' );

    // First level: chapters (lesson_type children of course)
    $chapters = get_posts( [
        'post_type'      => $lesson_type,
        'post_parent'    => (int) $course_id,
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );

    $lesson_ids = [];
    foreach ( $chapters as $ch_id ) {
        // Second level: lessons (lesson_type children of chapter)
        $lids = get_posts( [
            'post_type'      => $lesson_type,
            'post_parent'    => $ch_id,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ] );
        foreach ( $lids as $lid ) $lesson_ids[] = (int) $lid;
    }
    return $lesson_ids;
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

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, completed_at FROM $t WHERE user_id=%d AND post_id=%d",
        (int) $user_id, (int) $post_id
    ) );

    if ( $existing ) {
        $data = [ 'last_activity_at' => $now ];
        if ( $mark_complete && ! $existing->completed_at ) {
            $data['completed_at'] = $now;
        }
        $wpdb->update( $t, $data, [ 'id' => $existing->id ] );
    } else {
        $wpdb->insert( $t, [
            'user_id'          => (int) $user_id,
            'post_id'          => (int) $post_id,
            'course_id'        => (int) $course_id,
            'enrolled_at'      => $now,
            'completed_at'     => $mark_complete ? $now : null,
            'last_activity_at' => $now,
        ] );
    }
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

    // Check already completed
    global $wpdb;
    $t         = $wpdb->prefix . 'snn_learn_enrollments';
    $user_id   = get_current_user_id();
    $pre_done  = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT completed_at FROM $t WHERE user_id=%d AND post_id=%d AND completed_at IS NOT NULL",
        $user_id, $post_id
    ) );

    ob_start();
    ?>
    <div id="<?= $uid ?>-wrap" class="snn-video-player-wrap" style="position:relative;width:100%;background:<?= $c_bg ?>;border-radius:10px;overflow:hidden;user-select:none;font-family:sans-serif">

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

        <!-- Completed badge -->
        <div id="<?= $uid ?>-badge" class="snn-video-completed-badge" style="display:<?= $pre_done ? 'block' : 'none' ?>;position:absolute;top:10px;right:10px;background:<?= $c_primary ?>;color:<?= $c_text ?>;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:bold">&#10003; Completed</div>

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
        var NONCE        = '<?= $nonce ?>';
        var completed    = <?= $pre_done ? 'true' : 'false' ?>;
        var watchedSec   = 0;
        var lastTime     = 0;
        var hideTimer    = null;

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
            fetch(REST_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
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
    $atts    = shortcode_atts( [ 'course_id' => 0 ], $atts );
    $user_id = get_current_user_id();
    if ( ! $user_id ) return '0';

    $course_id = (int) $atts['course_id'] ?: snn_learn_get_course_id();
    if ( ! $course_id ) return '0';

    return (string) snn_learn_calc_progress( $user_id, $course_id );
} );

// ----------------------------------------------------------
// [snn_learn_course_chapter_lesson_list]
// ----------------------------------------------------------
add_shortcode( 'snn_learn_course_chapter_lesson_list', function ( $atts ) {
    $atts = shortcode_atts( [ 'course_id' => 0 ], $atts );

    $course_id   = (int) $atts['course_id'] ?: snn_learn_get_course_id();
    if ( ! $course_id ) return '';

    $lesson_type = snn_learn_get( 'lesson_post_type' ); // shared by chapters and lessons
    $user_id     = get_current_user_id();
    $current_id  = get_the_ID();

    // Chapters = lesson_type direct children of the course
    $chapters = get_posts( [
        'post_type'      => $lesson_type,
        'post_parent'    => $course_id,
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
    ] );

    // Pre-fetch completed lesson IDs for current user
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

        // Lessons = lesson_type children of the chapter
        $lessons = get_posts( [
            'post_type'      => $lesson_type,
            'post_parent'    => $ch->ID,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ] );

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
                if ( $is_completed ) echo '<span class="snn-lesson-check" aria-label="Completed">&#10003;</span> ';
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

    $post_id  = get_the_ID();
    $user_id  = get_current_user_id();
    $rest_url = esc_attr( rest_url( 'snn-learn/v1/complete' ) );
    $nonce    = esc_attr( wp_create_nonce( 'wp_rest' ) );
    $uid      = 'snn_mc_' . $post_id;

    // Check if already completed
    global $wpdb;
    $t       = $wpdb->prefix . 'snn_learn_enrollments';
    $already = $wpdb->get_var( $wpdb->prepare(
        "SELECT completed_at FROM $t WHERE user_id=%d AND post_id=%d",
        $user_id, $post_id
    ) );
    $is_done = ! empty( $already );

    $label     = $is_done ? $atts['completed_label'] : $atts['label'];
    $disabled  = $is_done ? 'disabled' : '';
    $done_cls  = $is_done ? ' snn-mark-completed-done' : '';

    ob_start();
    ?>
    <button
        id="<?= esc_attr( $uid ) ?>"
        class="snn-mark-completed-btn<?= $done_cls ?>"
        data-post-id="<?= (int) $post_id ?>"
        data-rest-url="<?= $rest_url ?>"
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
            fetch(btn.dataset.restUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': btn.dataset.nonce },
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
    </script>
    <?php
    return ob_get_clean();
} );

// ============================================================
// 11. CHAPTER → FIRST LESSON REDIRECT
// ============================================================

add_action( 'template_redirect', function () {
    if ( ! is_singular() ) return;

    $post        = get_post();
    $lesson_type = snn_learn_get( 'lesson_post_type' );
    $course_type = snn_learn_get( 'course_post_type' );

    if ( ! $post || $post->post_type !== $lesson_type ) return;

    // A "chapter" is a lesson_type post whose parent is a course.
    $parent = get_post( $post->post_parent );
    if ( ! $parent || $parent->post_type !== $course_type ) return;

    // Redirect to first child lesson (lesson_type children of this chapter)
    $first_lesson = get_posts( [
        'post_type'      => $lesson_type,
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
