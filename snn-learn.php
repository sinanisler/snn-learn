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

require_once plugin_dir_path( __FILE__ ) . 'page-orders.php';

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
    $js_url = plugin_dir_url( __FILE__ ) . 'assets/js/';
    ?>
    <script src="<?= esc_url( $js_url . 'tailwind.min.js' ) ?>"></script>
    <script src="<?= esc_url( $js_url . 'chart.umd.min.js' ) ?>"></script>
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

require_once plugin_dir_path( __FILE__ ) . 'dashboard.php';

/*
 * NOTE: snn_learn_dashboard_page() is defined in dashboard.php.
 * That file also contains extensive database structure documentation
 * and query reference patterns useful for adding new statistics/charts.
 */

// snn_learn_dashboard_page() is defined in dashboard.php (loaded above).

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

        if ( class_exists( 'Simple_Page_Ordering' ) ) {
            Simple_Page_Ordering::handle_settings_save();
        }

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

            <?php
            if ( class_exists( 'Simple_Page_Ordering' ) ) {
                Simple_Page_Ordering::render_settings_section();
            }
            submit_button( 'Save Settings' );
            ?>
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
// 7. SHORTCODES ADMIN PAGE — moved to shortcodes.php
// ============================================================

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
    static $cache = [];
    $course_id = (int) $course_id;

    if ( isset( $cache[ $course_id ] ) ) {
        return $cache[ $course_id ];
    }

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
        $cache[ $course_id ] = [];
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

    $cache[ $course_id ] = $ordered_lesson_ids;
    return $ordered_lesson_ids;
}

/**
 * Calculate completion percentage for a user in a course (0–100).
 */
function snn_learn_calc_progress( $user_id, $course_id ) {
    global $wpdb;
    $t           = $wpdb->prefix . 'snn_learn_enrollments';
    $all_lessons = snn_learn_get_course_lessons( $course_id );
    $total       = count( $all_lessons );
    if ( ! $total ) return 0;

    $placeholders = implode( ',', array_fill( 0, $total, '%d' ) );
    $args         = array_merge( [ (int) $user_id ], $all_lessons );
    $completed    = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE user_id = %d AND post_id IN ($placeholders) AND completed_at IS NOT NULL",
        $args
    ) );

    return min( 100, (int) round( $completed / $total * 100 ) );
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

require_once plugin_dir_path( __FILE__ ) . 'video-player.php';
require_once plugin_dir_path( __FILE__ ) . 'shortcodes.php';

// Third-party integrations — only load when the relevant theme/plugin is active
if ( function_exists( 'bricks_is_builder' ) || wp_get_theme()->get_template() === 'bricks' ) {
    require_once plugin_dir_path( __FILE__ ) . 'third_party/bricks.php';
}

// ----------------------------------------------------------
// [snn_learn_video_player] — moved to shortcodes.php
// [snn_learn_progress]     — moved to shortcodes.php
// [snn_learn_course_chapter_lesson_list] — moved to shortcodes.php
// [snn_learn_mark_completed]  — moved to shortcodes.php
// [snn_learn_delete_my_data]  — moved to shortcodes.php
// [snn_learn_my_courses]           — moved to shortcodes.php
// [snn_learn_comment_list]          — moved to shortcodes.php
// [snn_learn_user_certificate]      — moved to shortcodes.php
// ----------------------------------------------------------


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
// 12. COMMENT LIST SHORTCODE — moved to shortcodes.php
// ============================================================

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
