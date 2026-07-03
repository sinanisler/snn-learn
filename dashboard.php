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
    $ts_60_days_ago = time() - ( 60 * DAY_IN_SECONDS );
    $ts_30_days_ago = time() - ( 30 * DAY_IN_SECONDS );
    $ts_14_days_ago = time() - ( 14 * DAY_IN_SECONDS );
    $ts_7_days_ago  = time() - (  7 * DAY_IN_SECONDS );

    // WordPress timezone offset in seconds.
    $wp_utc_offset = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

    // ---- Group / Department filter ----
    $group_filter = isset( $_GET['snn_group'] ) ? sanitize_text_field( wp_unslash( $_GET['snn_group'] ) ) : '';
    $group_join   = '';
    $group_where  = '';
    $group_args   = [];
    if ( $group_filter ) {
        $group_join  = " INNER JOIN {$wpdb->usermeta} um_filter ON e.user_id = um_filter.user_id";
        $group_where = ' AND um_filter.meta_key = %s AND um_filter.meta_value = %s';
        $group_args  = [ 'snn_group', $group_filter ];
    }

    // ---- 3-minute transient cache (busted on enrollment/completion hooks) ----
    $cache_key = 'snn_dashboard_v2_' . md5( $group_filter . get_current_user_id() );
    $force     = isset( $_GET['snn_refresh'] );
    $cached    = $force ? false : get_transient( $cache_key );

    if ( $cached !== false && is_array( $cached ) ) {
        extract( $cached );
    } else {

        // ---- KPI: Total + Recent enrollments (merged) ----
        $q_enr = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN e.enrolled_at >= %d THEN 1 ELSE 0 END) AS recent,
                    SUM(CASE WHEN e.enrolled_at >= %d AND e.enrolled_at < %d THEN 1 ELSE 0 END) AS prev_recent
             FROM $t e $group_join
             WHERE e.is_course = 1 $group_where",
            array_merge( [ $ts_30_days_ago, $ts_60_days_ago, $ts_30_days_ago ], $group_args )
        ) );
        $total_enrollments  = (int) ( $q_enr->total ?? 0 );
        $recent_enrollments = (int) ( $q_enr->recent ?? 0 );
        $prev_enrollments   = (int) ( $q_enr->prev_recent ?? 0 );

        // ---- KPI: Total items + Completed items (simple ratio, no per-user averaging) ----
        $q_items = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total_items,
                    SUM(CASE WHEN e.completed_at IS NOT NULL THEN 1 ELSE 0 END) AS completed_items
             FROM $t e $group_join
             WHERE e.is_course = 0 $group_where",
            $group_args
        ) );
        $total_items_all     = (int) ( $q_items->total_items ?? 0 );
        $completed_items_all = (int) ( $q_items->completed_items ?? 0 );
        $completion_rate     = $total_items_all > 0 ? round( ( $completed_items_all / $total_items_all ) * 100, 1 ) : 0;

        // ---- KPI: Weekly Active Users + previous period ----
        $weekly_active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.user_id) FROM $t e $group_join WHERE e.last_activity_at >= %d $group_where",
            array_merge( [ $ts_7_days_ago ], $group_args )
        ) );
        $prev_wau = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.user_id) FROM $t e $group_join WHERE e.last_activity_at >= %d AND e.last_activity_at < %d $group_where",
            array_merge( [ $ts_14_days_ago, $ts_7_days_ago ], $group_args )
        ) );

        // ---- KPI: New Users (first enrollment in last 7 days) ----
        $new_users = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.user_id) FROM $t e $group_join
             WHERE e.is_course = 1 AND e.enrolled_at >= %d $group_where
               AND e.user_id NOT IN (
                   SELECT e2.user_id FROM {$wpdb->prefix}snn_learn_enrollments e2
                   WHERE e2.is_course = 1 AND e2.enrolled_at < %d
               )",
            array_merge( [ $ts_7_days_ago, $ts_7_days_ago ], $group_args )
        ) );

        // ---- KPI: Gone Cold, Active Courses, Avg Days ----
        $gone_cold      = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.user_id) FROM $t e $group_join WHERE e.is_course = 1 AND e.last_activity_at < %d AND e.completed_at IS NULL $group_where",
            array_merge( [ $ts_14_days_ago ], $group_args )
        ) );
        $active_courses = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.course_id) FROM $t e $group_join WHERE e.is_course = 1 $group_where",
            $group_args
        ) );
        $avg_days       = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(e.completed_at - e.enrolled_at) / 86400 FROM $t e $group_join WHERE e.is_course = 1 AND e.completed_at IS NOT NULL $group_where",
            $group_args
        ) );

        // ---- Peak enrollment day (last 365 days, not all-time) ----
        $ts_year_ago = time() - ( 365 * DAY_IN_SECONDS );
        $peak_day = $wpdb->get_row( $wpdb->prepare(
            "SELECT ((e.enrolled_at + %d) DIV 86400) * 86400 AS day_ts, COUNT(*) AS cnt
             FROM $t e $group_join
             WHERE e.is_course = 1 AND e.enrolled_at >= %d $group_where
             GROUP BY day_ts ORDER BY cnt DESC LIMIT 1",
            array_merge( [ $wp_utc_offset, $ts_year_ago ], $group_args )
        ) );
        if ( $peak_day ) {
            $peak_day->date = wp_date( 'Y-m-d', (int) $peak_day->day_ts - $wp_utc_offset );
        }

        // ---- Course Enrollment Trend (last 30 days) ----
        $trend_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ((e.enrolled_at + %d) DIV 86400) AS day_key, COUNT(*) AS cnt
             FROM $t e $group_join
             WHERE e.is_course = 1 AND e.enrolled_at >= %d $group_where
             GROUP BY day_key ORDER BY day_key DESC LIMIT 30",
            array_merge( [ $wp_utc_offset, $ts_30_days_ago ], $group_args )
        ) );
        $trend_rows   = array_reverse( $trend_rows ?: [] );
        $trend_labels = array_map( function ( $row ) use ( $wp_utc_offset ) {
            return wp_date( 'Y-m-d', (int) $row->day_key * 86400 - $wp_utc_offset );
        }, $trend_rows );
        $trend_data   = array_map( function ( $row ) { return (int) $row->cnt; }, $trend_rows );

        // ---- Completion Trend (last 30 days, lesson completions per day) ----
        $comp_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ((e.completed_at + %d) DIV 86400) AS day_key, COUNT(*) AS cnt
             FROM $t e $group_join
             WHERE e.is_lesson = 1 AND e.completed_at IS NOT NULL AND e.completed_at >= %d $group_where
             GROUP BY day_key ORDER BY day_key DESC LIMIT 30",
            array_merge( [ $wp_utc_offset, $ts_30_days_ago ], $group_args )
        ) );
        $comp_rows     = array_reverse( $comp_rows ?: [] );
        // Build a map of day→completions for alignment with trend_labels
        $comp_map = [];
        foreach ( $comp_rows as $r ) {
            $comp_map[ wp_date( 'Y-m-d', (int) $r->day_key * 86400 - $wp_utc_offset ) ] = (int) $r->cnt;
        }
        $comp_data = array_map( function ( $label ) use ( $comp_map ) {
            return $comp_map[ $label ] ?? 0;
        }, $trend_labels );

        // ---- Course performance (fixed: shows actual course completions + lesson completions) ----
        $course_perf_base = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.course_id,
                    COUNT(DISTINCT e.user_id) AS enrolled,
                    SUM(CASE WHEN e.completed_at IS NOT NULL THEN 1 ELSE 0 END) AS course_completed,
                    COALESCE(lc.lesson_done, 0) AS lesson_completions
             FROM $t e
             LEFT JOIN (
                 SELECT course_id, COUNT(*) AS lesson_done
                 FROM $t
                 WHERE is_lesson = 1 AND completed_at IS NOT NULL
                 GROUP BY course_id
             ) lc ON lc.course_id = e.course_id
             WHERE e.is_course = 1
             GROUP BY e.course_id
             ORDER BY enrolled DESC
             LIMIT 50"
        ) );
        $courses_perf = array_map( function ( $c ) {
            $cid          = (int) $c->course_id;
            $total_items  = count( snn_learn_get_course_lessons( $cid ) );
            $c->course_completed    = (int) $c->course_completed;
            $c->lesson_completions  = (int) $c->lesson_completions;
            $c->rate = $c->enrolled > 0 ? round( ( $c->course_completed / $c->enrolled ) * 100, 1 ) : 0;
            return $c;
        }, $course_perf_base ?: [] );

        // ---- At-risk students ----
        $at_risk = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.user_id, e.course_id, e.last_activity_at
             FROM $t e $group_join
             WHERE e.is_course = 1 AND e.last_activity_at < %d AND e.completed_at IS NULL $group_where
             ORDER BY e.last_activity_at ASC LIMIT 20",
            array_merge( [ $ts_14_days_ago ], $group_args )
        ) );

        // ---- Recent activity feed (lesson-level + course-level, richer feed) ----
        $recent_activity = $wpdb->get_results( $wpdb->prepare(
            "(SELECT e.user_id, e.course_id, e.post_id, e.last_activity_at, e.completed_at,
                     'lesson_completed' AS event_type, p.post_title AS item_title
              FROM $t e
              INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
              WHERE e.is_lesson = 1 AND e.completed_at IS NOT NULL
              ORDER BY e.completed_at DESC LIMIT 15)
             UNION ALL
             (SELECT e.user_id, e.course_id, e.post_id, e.last_activity_at, e.completed_at,
                     'enrolled' AS event_type, '' AS item_title
              FROM $t e
              WHERE e.is_course = 1
              ORDER BY e.enrolled_at DESC LIMIT 10)
             ORDER BY last_activity_at DESC LIMIT 20"
        ) );

        // ── NEW KPI: Today's Active Learners (since midnight in site timezone) ──
        $today_midnight = strtotime( 'today', (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );
        $today_active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.user_id) FROM $t e $group_join WHERE e.last_activity_at >= %d $group_where",
            array_merge( [ $today_midnight ], $group_args )
        ) );

        // ── NEW KPI: Zero-Progress Enrollments (enrolled but 0 lessons done) ──
        $zero_progress = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t e $group_join
             WHERE e.is_course = 1 $group_where
               AND e.user_id NOT IN (
                   SELECT l.user_id FROM {$wpdb->prefix}snn_learn_enrollments l
                   WHERE l.is_lesson = 1 AND l.completed_at IS NOT NULL
               )",
            $group_args
        ) );

        // ── NEW KPI: Total Lessons Completed (all-time) ──
        $total_lessons_done = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t e $group_join WHERE e.is_lesson = 1 AND e.completed_at IS NOT NULL $group_where",
            $group_args
        ) );

        // ── NEW KPI: Completions This Week (courses fully completed in last 7 days) ──
        $completions_this_week = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t e $group_join WHERE e.is_course = 1 AND e.completed_at >= %d $group_where",
            array_merge( [ $ts_7_days_ago ], $group_args )
        ) );

        // ── NEW KPI: Completion Velocity (avg lessons per active user this week) ──
        $lessons_this_week = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t e $group_join WHERE e.is_lesson = 1 AND e.completed_at >= %d $group_where",
            array_merge( [ $ts_7_days_ago ], $group_args )
        ) );
        $completion_velocity = $weekly_active > 0 ? round( $lessons_this_week / $weekly_active, 1 ) : 0;

        // ── NEW KPI: Re-Engaged Users (active this week, inactive in prior 7-14 day window) ──
        $re_engaged = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.user_id) FROM $t e $group_join
             WHERE e.last_activity_at >= %d $group_where
               AND e.user_id NOT IN (
                   SELECT DISTINCT e2.user_id FROM {$wpdb->prefix}snn_learn_enrollments e2
                   WHERE e2.last_activity_at >= %d AND e2.last_activity_at < %d
               )",
            array_merge( [ $ts_7_days_ago, $ts_14_days_ago, $ts_7_days_ago ], $group_args )
        ) );

        // ── NEW KPI: Avg First-Lesson Time (hours from enrollment to first lesson completion) ──
        $avg_first_lesson_hrs = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(first_lesson.completed_at - e.enrolled_at) / 3600
             FROM $t e $group_join
             INNER JOIN (
                 SELECT l.user_id, l.course_id, MIN(l.completed_at) AS completed_at
                 FROM {$wpdb->prefix}snn_learn_enrollments l
                 WHERE l.is_lesson = 1 AND l.completed_at IS NOT NULL
                 GROUP BY l.user_id, l.course_id
             ) first_lesson ON first_lesson.user_id = e.user_id AND first_lesson.course_id = e.course_id
             WHERE e.is_course = 1 $group_where",
            $group_args
        ) );

        // ---- Store in cache (3 minutes) ----
        set_transient( $cache_key, compact(
            'total_enrollments', 'recent_enrollments', 'prev_enrollments',
            'total_items_all', 'completed_items_all', 'completion_rate',
            'weekly_active', 'prev_wau', 'new_users',
            'gone_cold', 'active_courses', 'avg_days',
            'peak_day',
            'trend_labels', 'trend_data', 'comp_data',
            'courses_perf',
            'at_risk',
            'recent_activity',
            'today_active', 'zero_progress', 'total_lessons_done',
            'completions_this_week', 'completion_velocity', 're_engaged',
            'avg_first_lesson_hrs'
        ), 3 * MINUTE_IN_SECONDS );
    }

    // ---- Period-over-period change helpers ----
    $enr_change_html = '';
    if ( $prev_enrollments > 0 ) {
        $pct = round( ( ( $recent_enrollments - $prev_enrollments ) / $prev_enrollments ) * 100, 1 );
        $cls = $pct >= 0 ? 'text-green-600' : 'text-red-500';
        $arrow = $pct >= 0 ? '↑' : '↓';
        $enr_change_html = "<span class=\"$cls text-xs font-semibold ml-1\">{$arrow} " . abs( $pct ) . "%</span>";
    }
    $wau_change_html = '';
    if ( $prev_wau > 0 ) {
        $pct = round( ( ( $weekly_active - $prev_wau ) / $prev_wau ) * 100, 1 );
        $cls = $pct >= 0 ? 'text-green-600' : 'text-red-500';
        $arrow = $pct >= 0 ? '↑' : '↓';
        $wau_change_html = "<span class=\"$cls text-xs font-semibold ml-1\">{$arrow} " . abs( $pct ) . "%</span>";
    }

    // ---- Group list for filter dropdown ----
    $group_data   = get_option( 'snn_groups_list', [] );
    $group_names  = [];
    if ( ! empty( $group_data ) ) {
        $group_names = array_keys( $group_data );
    } else {
        // Fallback: distinct group values from user meta
        $group_names = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'snn_group' AND meta_value != '' ORDER BY meta_value ASC" );
    }
    ?>
    <div class="snn-learn-dashboard">
    <div class="p-6">

        <!-- Header Row: Title + Group Filter + Refresh + Export -->
        <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
            <h1 class="text-2xl font-bold text-gray-800">SNN Learn &mdash; Dashboard</h1>
            <div class="flex items-center gap-3 flex-wrap">
                <?php if ( ! empty( $group_names ) ) : ?>
                <form method="get" action="" class="flex items-center gap-2" id="snn-group-form">
                    <input type="hidden" name="page" value="snn-learn">
                    <label for="snn_group_select" class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Group</label>
                    <select id="snn_group_select" name="snn_group" onchange="document.getElementById('snn-group-form').submit()" class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">All Users</option>
                        <?php foreach ( $group_names as $gn ) : ?>
                            <option value="<?= esc_attr( $gn ) ?>" <?= selected( $group_filter, $gn, false ) ?>><?= esc_html( $gn ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
                <button id="snn-refresh-btn" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors" title="Refresh dashboard data">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refresh
                </button>
                <a href="<?= esc_url( rest_url( 'snn-learn/v1/admin/export/enrollments' ) . ( $group_filter ? '?group=' . urlencode( $group_filter ) : '' ) ) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors no-underline" title="Export enrollments CSV">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Export CSV
                </a>
            </div>
        </div>

        <!-- KPI Row 1 — 8 cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2.5 mb-2.5">

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Today</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $today_active ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">WAU</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $weekly_active ) ?><?= $wau_change_html ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">0% Progress</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $zero_progress ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Done (Week)</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $completions_this_week ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Lessons Done</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $total_lessons_done ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Compl. Rate</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $completion_rate, 1 ) ?>%</p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">1st Lesson</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= $avg_first_lesson_hrs ? number_format( $avg_first_lesson_hrs, 1 ) . 'h' : '&mdash;' ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Velocity</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $completion_velocity, 1 ) ?></p>
            </div>

        </div>

        <!-- KPI Row 2 — 8 cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2.5 mb-3">

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Enrollments</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $total_enrollments ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Last 30d</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $recent_enrollments ) ?><?= $enr_change_html ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">New Users</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $new_users ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Re-Engaged</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $re_engaged ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Gone Cold</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $gone_cold ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Courses</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= number_format( $active_courses ) ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Avg Finish</p>
                <p class="snn-kpi-value text-xl font-bold text-gray-800"><?= $avg_days ? number_format( $avg_days, 1 ) . 'd' : '&mdash;' ?></p>
            </div>

            <div class="snn-kpi-card bg-white rounded-lg shadow-sm p-2.5">
                <p class="snn-kpi-label text-xs font-semibold text-gray-400 uppercase tracking-wider">Peak Day</p>
                <p class="snn-kpi-value text-base font-bold text-gray-800"><?= $peak_day ? esc_html( $peak_day->date ) : '&mdash;' ?></p>
            </div>

        </div>

        <!-- Chart + Course Performance -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-3">

            <div class="snn-chart-card bg-white rounded-lg shadow-sm p-3">
                <h2 class="snn-chart-title text-xs font-semibold text-gray-500 mb-2">Enrollment &amp; Completion Trend — Last 30 Days</h2>
                <canvas id="snn-trend-chart" height="90"></canvas>
            </div>

            <div class="snn-perf-card bg-white rounded-lg shadow-sm p-3">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="snn-perf-title text-xs font-semibold text-gray-500">Course Performance</h2>
                    <a href="<?= esc_url( rest_url( 'snn-learn/v1/admin/export/progress' ) . ( $group_filter ? '?group=' . urlencode( $group_filter ) : '' ) ) ?>" class="text-xs text-blue-600 hover:underline font-medium">CSV</a>
                </div>
                <div class="overflow-auto max-h-40">
                    <table class="snn-perf-table w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold text-gray-400 border-b">
                                <th class="pb-2 pr-4">Course</th>
                                <th class="pb-2 pr-4">Enrolled</th>
                                <th class="pb-2 pr-4">Finished</th>
                                <th class="pb-2">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $courses_perf as $c ) :
                            $rate       = $c->rate;
                            $title      = get_the_title( $c->course_id );
                            $badge      = $rate >= 70 ? 'bg-green-100 text-green-800' : ( $rate >= 40 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800' );
                            $course_url = get_permalink( $c->course_id );
                            $edit_url   = get_edit_post_link( $c->course_id );
                        ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="py-2 pr-4 text-blue-600 font-medium">
                                <?php if ( $title ) : ?>
                                <a href="<?= esc_url( $edit_url ?: '#' ) ?>" class="hover:underline" title="Edit course"><?= esc_html( $title ) ?></a>
                                <?php else : ?>
                                #<?= $c->course_id ?>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 pr-4"><?= (int) $c->enrolled ?></td>
                            <td class="py-2 pr-4"><?= (int) $c->course_completed ?></td>
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

        <!-- At-Risk + Feed -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

            <div class="snn-risk-card bg-white rounded-lg shadow-sm p-3">
                <h2 class="snn-risk-title text-xs font-semibold text-red-600 mb-2">&#9888; At-Risk <span class="font-normal text-gray-400">(14+ days inactive, not completed)</span></h2>
                <div class="overflow-auto max-h-44">
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
                            $user_url     = $user ? get_edit_user_link( $r->user_id ) : '';
                            $course_edit  = get_edit_post_link( $r->course_id );
                        ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="py-2 pr-4">
                                <?php if ( $user && $user_url ) : ?>
                                <a href="<?= esc_url( $user_url ) ?>" class="text-blue-600 hover:underline font-medium"><?= esc_html( $user->display_name ) ?></a>
                                <?php else : ?>
                                #<?= $r->user_id ?>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 pr-4">
                                <?php if ( $course_title && $course_edit ) : ?>
                                <a href="<?= esc_url( $course_edit ) ?>" class="text-blue-600 hover:underline"><?= esc_html( $course_title ) ?></a>
                                <?php else : ?>
                                <?= $course_title ? esc_html( $course_title ) : '#' . $r->course_id ?>
                                <?php endif; ?>
                            </td>
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

            <div class="snn-feed-card bg-white rounded-lg shadow-sm p-3">
                <h2 class="snn-feed-title text-xs font-semibold text-gray-500 mb-2">Recent Activity Feed</h2>
                <div class="snn-feed-list space-y-1.5 overflow-auto max-h-44">
                <?php foreach ( $recent_activity as $a ) :
                    $user         = get_userdata( $a->user_id );
                    $course_title = get_the_title( $a->course_id );
                    $is_lesson    = ( $a->event_type ?? '' ) === 'lesson_completed';
                    $is_done      = $is_lesson || ! empty( $a->completed_at );
                    $item_label   = $is_lesson ? 'completed lesson' : ( ! empty( $a->completed_at ) ? 'completed' : 'enrolled in' );
                    $detail_text  = $is_lesson && ! empty( $a->item_title ) ? esc_html( $a->item_title ) : ( $course_title ? esc_html( $course_title ) : '#' . $a->course_id );
                ?>
                <div class="snn-feed-item flex items-center gap-3 text-sm border-b border-gray-100 pb-2">
                    <span class="snn-feed-icon w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0 <?= $is_done ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' ?>">
                        <?= $user ? strtoupper( mb_substr( $user->display_name, 0, 1 ) ) : '?' ?>
                    </span>
                    <div class="snn-feed-text flex-1 min-w-0 truncate">
                        <span class="font-medium"><?= $user ? esc_html( $user->display_name ) : 'User #' . $a->user_id ?></span>
                        <span class="text-gray-400"> <?= $item_label ?> </span>
                        <span><?= $detail_text ?></span>
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
        // ---- Dual-line Enrollment + Completion Trend Chart ----
        var ctx = document.getElementById('snn-trend-chart');
        if (ctx && typeof Chart !== 'undefined') {
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
                    },{
                        label: 'Completions',
                        data: <?= json_encode( $comp_data ) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16,185,129,0.06)',
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        borderDash: [4, 3]
                    }]
                },
                options: {
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { boxWidth: 12, padding: 16, font: { size: 11 }, usePointStyle: true } }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                        x: { ticks: { maxTicksLimit: 8, maxRotation: 0 } }
                    },
                    interaction: { mode: 'index', intersect: false }
                }
            });
        }

        // ---- AJAX Refresh button ----
        var refreshBtn = document.getElementById('snn-refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                var url = new URL(window.location.href);
                url.searchParams.set('snn_refresh', '1');
                url.searchParams.set('_t', Date.now());
                refreshBtn.disabled = true;
                refreshBtn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Refreshing...';
                window.location.href = url.toString();
            });
        }
    });
    </script>
    <?php
}
