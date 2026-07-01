<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =============================================================================
 * SNN LEARN — ADMIN DASHBOARD
 * dashboard.php
 * =============================================================================
 *
 * This file renders the WordPress admin dashboard page for the SNN Learn LMS
 * plugin. It is loaded from snn-learn.php via:
 *   require_once plugin_dir_path( __FILE__ ) . 'dashboard.php';
 *
 * The page is registered as the first submenu of 'snn-learn' and its callback
 * is snn_learn_dashboard_page().
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DATABASE STRUCTURE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Table: {$wpdb->prefix}snn_learn_enrollments
 *         (typically: wp_snn_learn_enrollments)
 *
 * This is the ONLY custom table in the plugin. Every concept — course start,
 * lesson view, lesson completion, chapter completion, course completion — is
 * stored as a row in this single table.
 *
 * Schema
 * ──────
 *   id               BIGINT UNSIGNED  AUTO_INCREMENT PRIMARY KEY
 *   user_id          BIGINT UNSIGNED  NOT NULL   — wp_users.ID
 *   post_id          BIGINT UNSIGNED  NOT NULL   — the post being tracked
 *   course_id        BIGINT UNSIGNED  NOT NULL   — top-level course post ID
 *   enrolled_at      INT UNSIGNED     NOT NULL   — Unix timestamp of first enrollment/activity
 *   completed_at     INT UNSIGNED     DEFAULT NULL — Unix timestamp of completion (NULL = not done yet)
 *   last_activity_at INT UNSIGNED     DEFAULT NULL — Unix timestamp of the most recent activity
 *   is_course        TINYINT(1)       NOT NULL DEFAULT 0 — 1 = course enrollment row
 *   is_lesson        TINYINT(1)       NOT NULL DEFAULT 1 — 0 = chapter auto-completion row
 *
 * Unique constraint: UNIQUE KEY uq_user_post (user_id, post_id)
 *   → Each (user, post) pair can only have ONE row. ON DUPLICATE KEY UPDATE is
 *     used for all upserts.
 *
 * Indexes:
 *   PRIMARY KEY  (id)
 *   UNIQUE KEY   uq_user_post        (user_id, post_id)
 *   KEY          idx_course_id       (course_id)
 *   KEY          idx_user_id         (user_id)
 *   KEY          idx_completed_at    (completed_at)
 *   KEY          idx_enrolled_at     (enrolled_at)
 *   KEY          idx_last_activity_at (last_activity_at)
 *   KEY          idx_is_course       (is_course)
 *   KEY          idx_is_lesson       (is_lesson)
 *   KEY          idx_user_course     (user_id, course_id)
 *   KEY          idx_course_activity (is_course, last_activity_at)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CONTENT / HIERARCHY MODEL
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The plugin uses a SINGLE WordPress post type (default slug: "course") for
 * courses, chapters, and lessons. Role is determined purely by depth in the
 * WordPress parent→child hierarchy:
 *
 *   post_parent = 0         → COURSE   (top-level, the container)
 *   post_parent = course_id → CHAPTER  (direct child of a course)
 *   post_parent = chapter_id→ LESSON   (child of a chapter)
 *
 * There is no dedicated post type or taxonomy for chapters/lessons.
 * All posts share the same post type slug configured in Settings.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ROW SEMANTICS — WHAT EACH ROW REPRESENTS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Course enrollment row   → post_id = course_id  (post_id equals course_id)
 *      Created automatically the first time a user lands on any lesson in a
 *      course. completed_at is stamped when every lesson in the course is done.
 *      enrolled_at = the first activity timestamp for this user in this course.
 *
 *  Lesson row              → post_id = lesson post ID,  course_id = parent course ID
 *      Created/updated when the user watches enough of the video or clicks
 *      "Mark as Completed". completed_at is set on completion.
 *
 *  Chapter row             → post_id = chapter post ID, course_id = parent course ID
 *      Created/updated automatically by snn_learn_maybe_complete_chapter()
 *      the first time ANY lesson in the chapter is marked complete.
 *      Chapters redirect to their first lesson so users cannot visit them directly.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * KEY QUERY PATTERNS (reference for new charts / statistics)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. COUNT ALL ENROLLMENTS (all lesson/chapter/course rows):
 *      SELECT COUNT(*) FROM $t
 *
 * 2. COUNT ONLY COURSE-LEVEL ENROLLMENTS (one row per user per course):
 *      SELECT COUNT(*) FROM $t WHERE is_course = 1
 *
 * 3. ENROLLMENT TREND (course starts per day, last N days):
 *      SELECT FROM_UNIXTIME(enrolled_at, '%Y-%m-%d') AS day, COUNT(*) AS cnt
 *      FROM $t
 *      WHERE is_course = 1 AND enrolled_at >= UNIX_TIMESTAMP(NOW() - INTERVAL 30 DAY)
 *      GROUP BY day ORDER BY day;
 *    ← Use PHP time() arithmetic instead of FROM_UNIXTIME() in WHERE for sargability:
 *      WHERE enrolled_at >= %d  (pass $ts_30_days_ago as int)
 *
 * 4. COURSE COMPLETION RATE (across all lessons, excludes course & chapter rows):
 *      SELECT (COUNT(CASE WHEN completed_at IS NOT NULL THEN 1 END)
 *              / NULLIF(COUNT(*), 0)) * 100
 *      FROM $t WHERE is_lesson = 1
 *
 * 5. WEEKLY ACTIVE USERS (distinct users with recent activity):
 *      SELECT COUNT(DISTINCT user_id) FROM $t
 *      WHERE last_activity_at >= %d  (pass time() - 7*DAY_IN_SECONDS)
 *
 * 6. AT-RISK / STALE STUDENTS (inactive, not completed):
 *      SELECT user_id, course_id, last_activity_at FROM $t
 *      WHERE last_activity_at < %d AND completed_at IS NULL
 *      ORDER BY last_activity_at ASC
 *
 * 7. COURSE PERFORMANCE (enrolled + completed counts per course):
 *      SELECT course_id,
 *             COUNT(*) AS enrolled,
 *             SUM(completed_at IS NOT NULL) AS completed
 *      FROM $t
 *      GROUP BY course_id
 *      ORDER BY enrolled DESC
 *
 * 8. AVERAGE DAYS TO COMPLETE A COURSE:
 *      SELECT AVG(completed_at - enrolled_at) / 86400
 *      FROM $t WHERE is_course = 1 AND completed_at IS NOT NULL
 *
 * 9. PEAK ENROLLMENT DAY:
 *      $offset = wp_timezone()->getOffset(…);
 *      SELECT ((enrolled_at + $offset) DIV 86400) * 86400 AS day_ts, COUNT(*) AS cnt
 *      FROM $t WHERE is_course = 1
 *      GROUP BY day_ts ORDER BY cnt DESC LIMIT 1
 *      — Then in PHP: wp_date('Y-m-d', $row->day_ts - $offset)
 *      The WP timezone offset shifts day boundaries so grouping aligns with the
 *      site's configured timezone. wp_date() formats in that timezone too.
 *
 * 10. USER PROGRESS FOR A SPECIFIC COURSE:
 *       SELECT post_id FROM $t
 *       WHERE user_id = %d AND course_id = %d AND completed_at IS NOT NULL
 *       — Then compare against snn_learn_get_course_lessons($course_id) in PHP.
 *
 * 11. LESSON-COMPLETION STATUS (single lesson check):
 *       SELECT completed_at FROM $t
 *       WHERE user_id = %d AND post_id = %d AND completed_at IS NOT NULL
 *
 * 12. ALL COMPLETED LESSON IDs FOR USER IN COURSE:
 *       SELECT post_id FROM $t
 *       WHERE user_id = %d AND course_id = %d AND completed_at IS NOT NULL
 *
 * 13. COMPLETIONS PER DAY (for a completion-rate trend chart):
 *       SELECT FROM_UNIXTIME(completed_at, '%Y-%m-%d') AS day,
 *              COUNT(*) AS completed_lessons
 *       FROM $t
 *       WHERE is_lesson = 1 AND completed_at IS NOT NULL
 *         AND completed_at >= %d
 *       GROUP BY day ORDER BY day
 *
 * 14. NEW USERS (first enrollment date per user):
 *       SELECT user_id, MIN(enrolled_at) AS first_seen
 *       FROM $t WHERE is_course = 1
 *       GROUP BY user_id ORDER BY first_seen DESC
 *
 * 15. COURSE LEADERBOARD (users ranked by lesson completion count):
 *       SELECT user_id, COUNT(*) AS lessons_done
 *       FROM $t
 *       WHERE course_id = %d AND is_lesson = 1 AND completed_at IS NOT NULL
 *       GROUP BY user_id ORDER BY lessons_done DESC LIMIT 10
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PERFORMANCE NOTES
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  • Always pass timestamps as plain PHP integers (time() arithmetic) in WHERE
 *    clauses — never wrap columns in FROM_UNIXTIME() in WHERE; that prevents
 *    index usage. PHP-side calculation is always preferred.
 *  • For day-boundary grouping, add the WP UTC offset before DIV 86400 so
 *    days align with the site timezone. Use wp_date() for display formatting.
 *  • Use $wpdb->prepare() for every query that has user-supplied or dynamic values.
 *  • For N-item IN() clauses, build the placeholders with:
 *      implode(',', array_fill(0, count($ids), '%d'))
 *    and merge $ids into the prepare() args array.
 *  • Chart.js (v4 CDN) and Tailwind CSS (CDN) are loaded in admin_head only on
 *    SNN Learn admin pages — they do NOT affect the frontend.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HELPER FUNCTIONS (defined in snn-learn.php, available here)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  snn_learn_get( $key )
 *      Retrieves a plugin option with its default fallback.
 *      Keys: course_post_type, video_field, video_color_*, video_complete_*,
 *            user_permalinks_enabled, user_permalink_normal_base,
 *            user_permalink_instr_base, user_permalink_normal_roles,
 *            user_permalink_instr_roles
 *
 *  snn_learn_get_course_id( $post_id = null )
 *      Resolves the top-level course ID for any post in the hierarchy.
 *      Returns 0 when the post is not part of any course.
 *
 *  snn_learn_get_course_lessons( $course_id )
 *      Returns an ordered flat array of lesson post IDs for a course.
 *      Chapters → lessons, ordered by menu_order.
 *
 *  snn_learn_calc_progress( $user_id, $course_id )
 *      Returns 0–100 integer completion percentage.
 *
 *  snn_learn_record_lesson( $user_id, $post_id, $course_id, $mark_complete )
 *      Upserts a lesson/chapter/course row and cascades completion upward.
 *
 * =============================================================================
 */

// ============================================================
// DASHBOARD PAGE
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

    // WordPress timezone offset in seconds — used to shift Unix timestamps
    // so that day-boundary grouping (DIV 86400) aligns with the site's
    // configured timezone (Settings → General → Timezone).
    $wp_utc_offset = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

    // ---- KPI Queries (all use indexed is_course / is_lesson columns) ----
    $total_enrollments  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE is_course = 1" );
    $recent_enrollments = (int)   $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE is_course = 1 AND enrolled_at >= %d", $ts_30_days_ago ) );

    // Total items (Lessons + Chapters) vs Completed items (Lessons + Chapters)
    // Exclude course-level rows via is_course=0 to get the true completion pool.
    $total_items_all     = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE is_course = 0" );
    $completed_items_all = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE is_course = 0 AND completed_at IS NOT NULL" );

    // ---- Optimized: Single query for BOTH KPI completion rate AND course performance ----
    // Uses is_lesson = 1 so that chapter auto-completion rows are NOT counted.
    // This fixes the 128%+ completion-rate bug where chapters inflated the numerator.
    $all_user_done = $wpdb->get_results(
        "SELECT course_id, user_id, COUNT(completed_at) as done
         FROM $t
         WHERE is_lesson = 1 AND completed_at IS NOT NULL
         GROUP BY course_id, user_id"
    );

    // --- Build KPI completion rate ---
    $all_rates = [];
    $done_by_course = []; // Also reused for the Course Performance table below
    foreach ( $all_user_done as $stat ) {
        $cid         = (int) $stat->course_id;
        $total_items = count( snn_learn_get_course_lessons( $cid ) );
        $all_rates[] = $total_items > 0 ? ( $stat->done / $total_items ) * 100 : 0;
        $done_by_course[ $cid ][] = (int) $stat->done;
    }

    // Account for users who started a course but haven't completed any lessons (0% progress)
    $total_pairs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE is_course = 1" );
    if ( $total_pairs > count( $all_rates ) ) {
        $all_rates = array_merge( $all_rates, array_fill( 0, $total_pairs - count( $all_rates ), 0 ) );
    }
    $completion_rate = $all_rates ? round( array_sum( $all_rates ) / count( $all_rates ), 1 ) : 0;

    $weekly_active      = (int)   $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $t WHERE last_activity_at >= %d", $ts_7_days_ago ) );
    $gone_cold          = (int)   $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $t WHERE is_course = 1 AND last_activity_at < %d AND completed_at IS NULL", $ts_14_days_ago ) );
    $active_courses     = (int)   $wpdb->get_var( "SELECT COUNT(DISTINCT course_id) FROM $t WHERE is_course = 1" );
    $avg_days           = (float) $wpdb->get_var( "SELECT AVG(completed_at - enrolled_at) / 86400 FROM $t WHERE is_course = 1 AND completed_at IS NOT NULL" );

    // Peak enrollment day — add is_course=1 to narrow the set
    $peak_day = $wpdb->get_row( $wpdb->prepare(
        "SELECT ((enrolled_at + %d) DIV 86400) * 86400 AS day_ts, COUNT(*) AS cnt
         FROM $t WHERE is_course = 1 GROUP BY day_ts ORDER BY cnt DESC LIMIT 1",
        $wp_utc_offset
    ) );
    if ( $peak_day ) {
        $peak_day->date = wp_date( 'Y-m-d', (int) $peak_day->day_ts - $wp_utc_offset );
    }

    // ---- Course Enrollment Trend (last 30 days) ----
    $trend_rows   = $wpdb->get_results( $wpdb->prepare(
        "SELECT ((enrolled_at + %d) DIV 86400) AS day_key, COUNT(*) AS cnt
         FROM $t
         WHERE is_course = 1
           AND enrolled_at >= %d
         GROUP BY day_key
         ORDER BY day_key DESC
         LIMIT 30",
        $wp_utc_offset,
        $ts_30_days_ago
    ) );
    $trend_rows   = array_reverse( $trend_rows );
    $trend_labels = array_map( function ( $row ) use ( $wp_utc_offset ) {
        return wp_date( 'Y-m-d', (int) $row->day_key * 86400 - $wp_utc_offset );
    }, $trend_rows );
    $trend_data   = array_map( function ( $row ) { return (int) $row->cnt; }, $trend_rows );

    // ---- Course performance (reuses $done_by_course built above — no extra DB queries) ----
    $course_perf_base = $wpdb->get_results(
        "SELECT course_id,
                COUNT(DISTINCT user_id) AS enrolled
         FROM $t
         WHERE is_course = 1
         GROUP BY course_id
         ORDER BY enrolled DESC
         LIMIT 50"
    );
    $courses_perf = [];
    foreach ( $course_perf_base as $c ) {
        $course_id = (int) $c->course_id;

        $total_items = count( snn_learn_get_course_lessons( $course_id ) );
        $dones       = $done_by_course[ $course_id ] ?? []; // Reuses the single query!

        $rates = [];
        foreach ( $dones as $done ) {
            $rates[] = $total_items > 0 ? ( $done / $total_items ) * 100 : 0;
        }

        // Fill in 0% for enrolled users with no lesson activity
        if ( count( $rates ) < $c->enrolled ) {
            $rates = array_merge( $rates, array_fill( 0, (int) $c->enrolled - count( $rates ), 0 ) );
        }

        // Compute completed count from $dones (only lesson completions, no chapters)
        $c->completed = array_sum( $dones );
        $c->rate      = $rates ? round( array_sum( $rates ) / count( $rates ), 1 ) : 0;
        $courses_perf[] = $c;
    }

    // ---- At-risk students ----
    $at_risk = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, course_id, last_activity_at FROM $t
         WHERE is_course = 1 AND last_activity_at < %d AND completed_at IS NULL
         ORDER BY last_activity_at ASC LIMIT 20",
        $ts_14_days_ago
    ) );

    // ---- Recent activity feed ----
    $recent_activity = $wpdb->get_results(
        "SELECT user_id, course_id, enrolled_at, completed_at, last_activity_at
         FROM $t WHERE is_course = 1
         ORDER BY last_activity_at DESC LIMIT 20"
    );
    ?>
    <div class="snn-learn-dashboard">
    <div class="p-6">

        <h1 class="text-2xl font-bold text-gray-800 mb-6">SNN Learn &mdash; Dashboard</h1>

        <!-- Row 1: Primary KPIs -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Course Enrollments</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $total_enrollments ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Last 30 Days</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $recent_enrollments ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-xl shadow-sm p-5 ">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Avg Completion Rate</p>
                <p class="snn-kpi-value text-3xl font-bold text-gray-800 mt-1"><?= number_format( $completion_rate, 1 ) ?>%</p>
                <p class="snn-kpi-desc text-xs text-gray-400 mt-1">avg per user &mdash; <?= number_format( $completed_items_all ) ?> / <?= number_format( $total_items_all ) ?> total</p>
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
                            $rate       = $c->rate;
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
                            <td class="py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $badge ?>"><?= number_format( $rate, 1 ) ?>%</span></td>
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
                    <span class="snn-feed-time text-xs text-gray-400 shrink-0"><?= human_time_diff( $a->last_activity_at ) ?> ago</span>
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
