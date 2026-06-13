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
    $default  = $defaults[ $key ] ?? '';
    $value    = get_option( 'snn_learn_' . $key, null );

    // Option never saved — use default.
    if ( null === $value ) {
        return $default;
    }

    // Option was saved as empty (e.g. saving from a different settings tab
    // that doesn't include this field) but the default is non-empty.
    // Fall back to the default to prevent blanking out critical values.
    if ( $value === '' && $default !== '' ) {
        return $default;
    }

    return $value;
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
        // Only save video fields when they are actually present in the form
        // (prevents blanking them out when saving from other tabs like Emails).
        $text_fields = [ 'course_post_type', 'video_field', 'video_color_primary', 'video_color_bg', 'video_color_text', 'video_complete_seconds' ];
        foreach ( $text_fields as $f ) {
            if ( ! isset( $_POST[ 'snn_learn_' . $f ] ) ) {
                continue; // Field not in form — skip, don't blank it
            }
            $val = sanitize_text_field( wp_unslash( $_POST[ 'snn_learn_' . $f ] ) );
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

        // ---- Email Notifications ----
        snn_learn_emails_save_settings();

        echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
    }
    ?>
    <div class="snn-learn-settings wrap" style="max-width:860px">
        <h1>SNN Learn &mdash; Settings</h1>

        <?php
        // Determine active tab from GET or default
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'video';
        ?>
        <div class="snn-settings-tabs" style="display:flex;gap:0;margin:20px 0 0;border-bottom:2px solid #e5e7eb">
            <?php
            $tabs = [
                'video'      => 'Video Player',
                'emails'     => 'Emails',
                'permalinks' => 'User Permalinks',
                'ordering'   => 'Page Ordering',
                'danger'     => 'Danger Zone',
            ];
            foreach ( $tabs as $tab_key => $tab_label ) :
                $is_active = ( $active_tab === $tab_key );
            ?>
                <a href="<?php echo esc_url( add_query_arg( 'tab', $tab_key, menu_page_url( 'snn-learn-settings', false ) ) ); ?>"
                   class="snn-tab-btn"
                   style="padding:10px 20px;font-size:14px;font-weight:600;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-2px;color:<?php echo $is_active ? '#2271b1' : '#6b7280'; ?>;border-bottom-color:<?php echo $is_active ? '#2271b1' : 'transparent'; ?>">
                    <?php echo esc_html( $tab_label ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="post" action="" style="margin-top:28px">
            <?php wp_nonce_field( 'snn_learn_settings_save', 'snn_learn_nonce' ); ?>

            <?php if ( $active_tab === 'video' ) : ?>

            <!-- ===== VIDEO SETTINGS CARD ===== -->
            <div class="snn-settings-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)">
                <h2 style="margin:0 0 20px;font-size:16px;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;padding-bottom:14px">Video Player Defaults</h2>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

                    <!-- Post Type Slug -->
                    <div style="grid-column:1/-1">
                        <label for="snn_cpt" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151">
                            Course Post Type Slug
                            <span class="snn-tip" data-tip="The single post type slug used for courses, chapters, and lessons. Role is determined by depth: top-level = course, child of course = chapter, child of chapter = lesson." style="cursor:help;color:#9ca3af;font-size:15px;line-height:1" title="More info">?</span>
                        </label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="text" id="snn_cpt" name="snn_learn_course_post_type"
                                value="<?= esc_attr( snn_learn_get( 'course_post_type' ) ) ?>"
                                class="regular-text snn-char-counter"
                                maxlength="20"
                                style="width:220px"
                                placeholder="course">
                            <span class="snn-char-count" style="font-size:11px;color:#9ca3af" data-for="snn_cpt">0/20</span>
                        </div>
                        <p style="margin:6px 0 0;font-size:12px;color:#6b7280">Single post type for courses, chapters, and lessons.</p>
                    </div>

                    <!-- Video URL Field -->
                    <div style="grid-column:1/-1">
                        <label for="snn_vf" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151">
                            Video URL Custom Field Slug
                            <span class="snn-tip" data-tip="The ACF / custom field key that holds the video file URL for each lesson." style="cursor:help;color:#9ca3af;font-size:15px;line-height:1" title="More info">?</span>
                        </label>
                        <input type="text" id="snn_vf" name="snn_learn_video_field"
                            value="<?= esc_attr( snn_learn_get( 'video_field' ) ) ?>"
                            class="regular-text"
                            style="width:280px"
                            placeholder="video_url">
                        <p style="margin:6px 0 0;font-size:12px;color:#6b7280">Custom field key holding the video file URL for each lesson.</p>
                    </div>

                    <!-- Color: Primary -->
                    <div>
                        <label for="snn_vcp" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151">Player Primary Color</label>
                        <div style="display:flex;align-items:center;gap:10px">
                            <input type="color" id="snn_vcp" name="snn_learn_video_color_primary"
                                value="<?= esc_attr( snn_learn_get( 'video_color_primary' ) ) ?>"
                                style="width:44px;height:36px;border:none;background:none;cursor:pointer">
                            <input type="text" id="snn_vcp_hex" value="<?= esc_attr( snn_learn_get( 'video_color_primary' ) ) ?>"
                                maxlength="7" placeholder="#3b82f6"
                                style="width:100px;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-family:monospace;font-size:13px"
                                class="snn-hex-sync" data-target="snn_vcp">
                            <span id="snn_vcp_preview" style="width:24px;height:24px;border-radius:50%;background:<?= esc_attr( snn_learn_get( 'video_color_primary' ) ) ?>;border:2px solid rgba(0,0,0,0.12);display:inline-block"></span>
                        </div>
                    </div>

                    <!-- Color: Background -->
                    <div>
                        <label for="snn_vcb" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151">Player Background Color</label>
                        <div style="display:flex;align-items:center;gap:10px">
                            <input type="color" id="snn_vcb" name="snn_learn_video_color_bg"
                                value="<?= esc_attr( snn_learn_get( 'video_color_bg' ) ) ?>"
                                style="width:44px;height:36px;border:none;background:none;cursor:pointer">
                            <input type="text" id="snn_vcb_hex" value="<?= esc_attr( snn_learn_get( 'video_color_bg' ) ) ?>"
                                maxlength="7" placeholder="#1e293b"
                                style="width:100px;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-family:monospace;font-size:13px"
                                class="snn-hex-sync" data-target="snn_vcb">
                            <span id="snn_vcb_preview" style="width:24px;height:24px;border-radius:50%;background:<?= esc_attr( snn_learn_get( 'video_color_bg' ) ) ?>;border:2px solid rgba(0,0,0,0.12);display:inline-block"></span>
                        </div>
                    </div>

                    <!-- Color: Text -->
                    <div>
                        <label for="snn_vct" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151">Player Icon / Text Color</label>
                        <div style="display:flex;align-items:center;gap:10px">
                            <input type="color" id="snn_vct" name="snn_learn_video_color_text"
                                value="<?= esc_attr( snn_learn_get( 'video_color_text' ) ) ?>"
                                style="width:44px;height:36px;border:none;background:none;cursor:pointer">
                            <input type="text" id="snn_vct_hex" value="<?= esc_attr( snn_learn_get( 'video_color_text' ) ) ?>"
                                maxlength="7" placeholder="#f8fafc"
                                style="width:100px;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-family:monospace;font-size:13px"
                                class="snn-hex-sync" data-target="snn_vct">
                            <span id="snn_vct_preview" style="width:24px;height:24px;border-radius:50%;background:<?= esc_attr( snn_learn_get( 'video_color_text' ) ) ?>;border:2px solid rgba(255,255,255,0.3);display:inline-block"></span>
                        </div>
                    </div>

                    <!-- Complete Seconds -->
                    <div>
                        <label for="snn_vcs" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151">
                            Mark Complete After (seconds)
                            <span class="snn-tip" data-tip="Cumulative watched seconds before the lesson is marked completed. Ignored when 'Video End Only' is checked." style="cursor:help;color:#9ca3af;font-size:15px;line-height:1" title="More info">?</span>
                        </label>
                        <input type="number" id="snn_vcs" name="snn_learn_video_complete_seconds"
                            value="<?= esc_attr( snn_learn_get( 'video_complete_seconds' ) ) ?>"
                            min="1" class="small-text" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;width:90px">
                    </div>

                    <!-- Complete on End -->
                    <div style="grid-column:1/-1;padding-top:4px">
                        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="snn_learn_video_complete_on_end" value="1"
                                <?= checked( 1, snn_learn_get( 'video_complete_on_end' ), false ) ?>
                                style="width:18px;height:18px;border-radius:4px;cursor:pointer">
                            <span style="font-size:13px;font-weight:600;color:#374151">Mark Complete on Video End Only</span>
                        </label>
                        <p style="margin:4px 0 0 26px;font-size:12px;color:#6b7280">When enabled, completion only fires when the video plays to the very end (overrides seconds setting).</p>
                    </div>

                </div>

                <!-- Live Player Preview -->
                <div style="margin-top:24px;padding-top:20px;border-top:1px solid #f3f4f6">
                    <p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em">Live Preview</p>
                    <div id="snn-player-preview" style="--vp-primary:<?= esc_attr( snn_learn_get( 'video_color_primary' ) ) ?>;--vp-bg:<?= esc_attr( snn_learn_get( 'video_color_bg' ) ) ?>;--vp-text:<?= esc_attr( snn_learn_get( 'video_color_text' ) ) ?>;border-radius:8px;overflow:hidden;max-width:480px;position:relative;background:var(--vp-bg);aspect-ratio:16/9;display:flex;align-items:center;justify-content:center">
                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;opacity:0.35">
                            <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="var(--vp-text)"><path d="M8 5v14l11-7z"/></svg>
                        </div>
                        <div style="position:absolute;bottom:0;left:0;right:0;padding:8px 12px 10px;background:linear-gradient(transparent,var(--vp-bg));display:flex;align-items:center;gap:10px">
                            <button type="button" style="background:none;border:none;cursor:pointer;color:var(--vp-text);padding:0;line-height:1;width:28px;height:28px;display:flex;align-items:center;justify-content:center;opacity:0.7">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                            </button>
                            <div style="flex:1;height:5px;background:rgba(255,255,255,0.35);border-radius:3px;position:relative">
                                <div style="height:100%;width:35%;background:var(--vp-primary);border-radius:3px"></div>
                            </div>
                            <span style="font-size:11px;color:var(--vp-text);opacity:0.7;font-variant-numeric:tabular-nums">1:24 / 4:02</span>
                        </div>
                    </div>
                </div>

            </div>

            <?php elseif ( $active_tab === 'emails' ) : ?>

            <!-- ===== EMAIL NOTIFICATIONS CARD ===== -->
            <div class="snn-settings-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)">
                <h2 style="margin:0 0 20px;font-size:16px;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;padding-bottom:14px">Email Notifications</h2>
                <p style="margin:0 0 20px;font-size:13px;color:#6b7280;line-height:1.6">
                    Configure automated emails sent to learners. All notifications are <strong>disabled by default</strong> — enable only the ones you need.
                    Use merge tags like <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px">{{user_name}}</code> to personalise each message.
                </p>
                <?php snn_learn_emails_settings_section(); ?>
            </div>

            <?php elseif ( $active_tab === 'permalinks' ) : ?>

            <!-- ===== USER PERMALINKS CARD ===== -->
            <div class="snn-settings-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)">
                <h2 style="margin:0 0 20px;font-size:16px;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;padding-bottom:14px">User Permalinks</h2>

                <p style="margin:0 0 20px;font-size:13px;color:#6b7280;line-height:1.6">
                    Replace default <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px">/author/username/</code> URLs with role-based, ID-driven URLs &mdash; e.g. <code>/user/42/</code> for normal users or <code>/instructor/7/</code> for instructors.
                </p>

                <!-- Enable Toggle -->
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;padding:14px 18px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1">
                        <input type="checkbox" name="snn_learn_user_permalinks_enabled" value="1"
                            <?= checked( 1, snn_learn_get( 'user_permalinks_enabled' ), false ) ?>
                            style="width:20px;height:20px;border-radius:5px;cursor:pointer">
                        <div>
                            <div style="font-weight:600;font-size:14px;color:#111827">Enable User Permalinks</div>
                            <div style="font-size:12px;color:#6b7280;margin-top:2px">Role-based, ID-driven author archive URLs</div>
                        </div>
                    </label>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

                    <!-- Normal User Base -->
                    <div>
                        <label for="snn_normal_base" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151">Normal User URL Base</label>
                        <div style="display:flex;align-items:center;gap:0;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;margin-bottom:8px">
                            <span style="padding:8px 10px;background:#f3f4f6;border-right:1px solid #d1d5db;font-size:12px;color:#6b7280;white-space:nowrap;font-family:monospace"><?= esc_html( home_url( '/' ) ) ?></span>
                            <input type="text" id="snn_normal_base"
                                name="snn_learn_user_permalink_normal_base"
                                value="<?= esc_attr( snn_learn_get( 'user_permalink_normal_base' ) ) ?>"
                                class="snn-char-counter"
                                maxlength="30"
                                style="flex:1;border:none;padding:8px 10px;font-size:13px;font-family:monospace;outline:none"
                                placeholder="user">
                            <span style="padding:8px 10px;background:#f3f4f6;border-left:1px solid #d1d5db;font-size:12px;color:#6b7280">/<span id="snn_normal_preview_id">42</span>/</span>
                        </div>
                        <span class="snn-char-count" style="font-size:11px;color:#9ca3af;display:block;margin-bottom:8px" data-for="snn_normal_base">0/30</span>
                        <p style="margin:0;font-size:12px;color:#6b7280">
                            Preview: <code id="snn_normal_preview_url" style="color:#059669;font-family:monospace"><?= esc_html( home_url( '/' ) . snn_learn_get( 'user_permalink_normal_base' ) ) ?>/42/</code>
                        </p>
                    </div>

                    <!-- Instructor Base -->
                    <div>
                        <label for="snn_instr_base" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151">Instructor URL Base</label>
                        <div style="display:flex;align-items:center;gap:0;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;margin-bottom:8px">
                            <span style="padding:8px 10px;background:#f3f4f6;border-right:1px solid #d1d5db;font-size:12px;color:#6b7280;white-space:nowrap;font-family:monospace"><?= esc_html( home_url( '/' ) ) ?></span>
                            <input type="text" id="snn_instr_base"
                                name="snn_learn_user_permalink_instr_base"
                                value="<?= esc_attr( snn_learn_get( 'user_permalink_instr_base' ) ) ?>"
                                class="snn-char-counter"
                                maxlength="30"
                                style="flex:1;border:none;padding:8px 10px;font-size:13px;font-family:monospace;outline:none"
                                placeholder="instructor">
                            <span style="padding:8px 10px;background:#f3f4f6;border-left:1px solid #d1d5db;font-size:12px;color:#6b7280">/<span id="snn_instr_preview_id">7</span>/</span>
                        </div>
                        <span class="snn-char-count" style="font-size:11px;color:#9ca3af;display:block;margin-bottom:8px" data-for="snn_instr_base">0/30</span>
                        <p style="margin:0;font-size:12px;color:#6b7280">
                            Preview: <code id="snn_instr_preview_url" style="color:#059669;font-family:monospace"><?= esc_html( home_url( '/' ) . snn_learn_get( 'user_permalink_instr_base' ) ) ?>/7/</code>
                        </p>
                    </div>

                    <!-- Normal Roles -->
                    <div>
                        <p style="margin:0 0 8px;font-weight:600;font-size:13px;color:#374151">Normal User Roles</p>
                        <?php
                        $snn_all_roles    = wp_roles()->get_names();
                        $snn_saved_normal = (array) snn_learn_get( 'user_permalink_normal_roles' );
                        foreach ( $snn_all_roles as $snn_role_key => $snn_role_name ) :
                            $checked = in_array( $snn_role_key, $snn_saved_normal, true );
                        ?>
                        <label class="snn-role-pill<?= $checked ? ' active' : '' ?>"
                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;border:1px solid #d1d5db;cursor:pointer;margin:0 8px 8px 0;font-size:13px;color:<?= $checked ? '#2271b1' : '#6b7280' ?>;background:<?= $checked ? 'rgba(34,113,177,0.08)' : '#fff' ?>;font-weight:<?= $checked ? '600' : '400' ?>;transition:all 0.15s">
                            <input type="checkbox" name="snn_learn_user_permalink_normal_roles[]"
                                value="<?= esc_attr( $snn_role_key ) ?>"
                                <?= $checked ? 'checked' : '' ?>
                                style="position:absolute;opacity:0;width:0;height:0">
                            <?= esc_html( translate_user_role( $snn_role_name ) ) ?>
                        </label>
                        <?php endforeach; ?>
                        <p style="margin:8px 0 0;font-size:12px;color:#6b7280">Selected roles use the Normal User URL Base.</p>
                    </div>

                    <!-- Instructor Roles -->
                    <div>
                        <p style="margin:0 0 8px;font-weight:600;font-size:13px;color:#374151">Instructor Roles</p>
                        <?php
                        $snn_saved_instr = (array) snn_learn_get( 'user_permalink_instr_roles' );
                        foreach ( $snn_all_roles as $snn_role_key => $snn_role_name ) :
                            $checked = in_array( $snn_role_key, $snn_saved_instr, true );
                        ?>
                        <label class="snn-role-pill<?= $checked ? ' active' : '' ?>"
                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;border:1px solid #d1d5db;cursor:pointer;margin:0 8px 8px 0;font-size:13px;color:<?= $checked ? '#2271b1' : '#6b7280' ?>;background:<?= $checked ? 'rgba(34,113,177,0.08)' : '#fff' ?>;font-weight:<?= $checked ? '600' : '400' ?>;transition:all 0.15s">
                            <input type="checkbox" name="snn_learn_user_permalink_instr_roles[]"
                                value="<?= esc_attr( $snn_role_key ) ?>"
                                <?= $checked ? 'checked' : '' ?>
                                style="position:absolute;opacity:0;width:0;height:0">
                            <?= esc_html( translate_user_role( $snn_role_name ) ) ?>
                        </label>
                        <?php endforeach; ?>
                        <p style="margin:8px 0 0;font-size:12px;color:#6b7280">Instructor checked first &mdash; takes priority if user has both role types.</p>
                    </div>

                </div>
            </div>

            <?php elseif ( $active_tab === 'ordering' ) : ?>

            <!-- ===== PAGE ORDERING CARD ===== -->
            <div class="snn-settings-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)">
                <h2 style="margin:0 0 20px;font-size:16px;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;padding-bottom:14px">Page Ordering</h2>
                <p style="margin:0 0 20px;font-size:13px;color:#6b7280;line-height:1.6">
                    Select which post types get drag-and-drop ordering in the admin list view.
                    Only checked types will show the drag handle and the &ldquo;Sort by Order&rdquo; link.
                </p>
                <?php
                if ( class_exists( 'Simple_Page_Ordering' ) ) {
                    Simple_Page_Ordering::render_settings_section();
                } else {
                    echo '<p style="color:#9ca3af;font-size:13px">Page Ordering is not available. Ensure the Simple Page Ordering class is loaded.</p>';
                }
                ?>
            </div>

            <?php elseif ( $active_tab === 'danger' ) : ?>

            <!-- ===== DANGER ZONE CARD ===== -->
            <div class="snn-settings-card" style="background:#fff;border:1px solid #fca5a5;border-radius:12px;padding:24px 28px;box-shadow:0 1px 3px rgba(0,0,0,0.06)">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                    <span style="font-size:24px">&#9888;</span>
                    <h2 style="margin:0;font-size:18px;font-weight:700;color:#dc2626">Danger Zone</h2>
                </div>
                <p style="margin:0 0 24px;font-size:13px;color:#6b7280">These actions are <strong>irreversible</strong>. Proceed with caution.</p>

                <!-- Admin: reset all data -->
                <div style="background:#fff8f8;border:1px solid #fca5a5;border-radius:10px;padding:20px 24px;margin-bottom:16px">
                    <h3 style="margin:0 0 6px;font-size:15px;color:#991b1b;font-weight:700">Reset All Enrollment Data</h3>
                    <p style="margin:0 0 16px;font-size:13px;color:#6b7280">
                        Permanently deletes <strong>every row</strong> from the <code>snn_learn_enrollments</code> table.
                        All user progress, completions, and enrollment history will be gone forever.
                    </p>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                        <label for="snn_delete_confirm" style="font-size:13px;color:#374151;font-weight:600">Type <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-family:monospace">DELETE</code> to confirm:</label>
                        <input type="text" id="snn_delete_confirm"
                            style="padding:6px 12px;border:1px solid #fca5a5;border-radius:6px;font-family:monospace;font-size:14px;width:140px;outline:none"
                            autocomplete="off">
                    </div>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'snn_learn_reset_data', 'snn_learn_reset_nonce' ); ?>
                        <button type="submit" id="snn_delete_btn" disabled
                            style="background:#dc2626;border:1px solid #b91c1c;color:#fff;font-weight:600;padding:10px 22px;border-radius:6px;cursor:not-allowed;font-size:14px;opacity:0.45"
                            onclick="return false">&#128465; Delete All Enrollment Data</button>
                    </form>
                </div>

                <!-- User data deletion info -->
                <div style="background:#fff8f8;border:1px solid #fca5a5;border-radius:10px;padding:20px 24px">
                    <h3 style="margin:0 0 6px;font-size:15px;color:#991b1b;font-weight:700">User: Delete My Own Data</h3>
                    <p style="margin:0 0 14px;font-size:13px;color:#6b7280">
                        Place the shortcode below anywhere on your site (e.g. a privacy/account page) to let logged-in users permanently delete their own learning records.
                    </p>
                    <div style="display:flex;align-items:center;gap:12px">
                        <code id="snn_del_shortcode" style="background:#eff6ff;color:#2563eb;padding:8px 16px;border-radius:6px;font-size:14px;border:1px solid #bfdbfe;font-family:monospace;cursor:pointer"
                            onclick="navigator.clipboard.writeText(this.textContent.trim());var f=this.nextElementSibling;f.style.opacity='1';setTimeout(function(){f.style.opacity='0';},1800)"
                            title="Click to copy">[snn_learn_delete_my_data]</code>
                        <span style="color:#16a34a;font-size:13px;opacity:0;transition:opacity .3s;font-weight:600">&#10003; Copied!</span>
                    </div>
                </div>
            </div>

            <?php endif; ?>

            <?php if ( $active_tab !== 'danger' ) : ?>
            <div style="margin-top:4px;padding:16px 0;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:12px">
                <?php submit_button( 'Save Settings', 'primary', '', false, [ 'style' => 'background:#2271b1;border-color:#2271b1;color:#fff;font-weight:600;padding:10px 24px;border-radius:6px;font-size:14px;cursor:pointer' ] ); ?>
                <span id="snn_settings_saved_msg" style="color:#16a34a;font-size:13px;opacity:0;transition:opacity .3s;font-weight:600">&#10003; Saved</span>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <style>
    /* Tab active state */
    .snn-tab-btn:hover { color: #2271b1; }

    /* Role pill toggles */
    .snn-role-pill { position: relative; user-select: none; transition: all 0.15s; }
    .snn-role-pill:hover { border-color: #2271b1; color: #2271b1; }
    .snn-role-pill input:checked + * { color: #2271b1; }

    /* Character counters */
    .snn-char-count { font-variant-numeric: tabular-nums; }

    /* Hex sync preview dots */
    .snn-hex-sync { transition: border-color 0.15s; }
    .snn-hex-sync:focus { border-color: #2271b1; outline: none; }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {

        // ---- Tab persistence: auto-hide saved message on tab switch ----
        document.querySelectorAll('.snn-tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var msg = document.getElementById('snn_settings_saved_msg');
                if (msg) msg.style.opacity = '0';
            });
        });

        // ---- Character counters ----
        document.querySelectorAll('.snn-char-counter').forEach(function(input) {
            var count = document.querySelector('.snn-char-count[data-for="' + input.id + '"]');
            if (!count) return;
            function update() {
                var max = parseInt(input.getAttribute('maxlength') || '0', 10);
                count.textContent = input.value.length + '/' + max;
            }
            input.addEventListener('input', update);
            update();
        });

        // ---- Color picker ↔ hex text sync ----
        function hexToRgb(hex) {
            var r = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return r ? '#' + r[1] + r[2] + r[3] : null;
        }
        document.querySelectorAll('.snn-hex-sync').forEach(function(text) {
            var targetId = text.dataset.target;
            var colorInput = document.getElementById(targetId);
            var previewDot = document.getElementById(targetId + '_preview');

            text.addEventListener('input', function() {
                var val = text.value;
                if (/^#[0-9a-f]{6}$/i.test(val)) {
                    colorInput.value = val;
                    if (previewDot) previewDot.style.background = val;
                    text.style.borderColor = '#d1d5db';
                } else {
                    text.style.borderColor = '#f87171';
                }
            });
            colorInput && colorInput.addEventListener('input', function() {
                text.value = colorInput.value;
                if (previewDot) previewDot.style.background = colorInput.value;
            });
        });

        // ---- Live permalink URL preview ----
        function updatePreview(baseId, previewId, urlId) {
            var baseInput = document.getElementById(baseId);
            var preview = document.getElementById(previewId);
            var urlEl = document.getElementById(urlId);
            if (!baseInput || !preview || !urlEl) return;
            var base = baseInput.value.trim() || baseInput.placeholder;
            preview.textContent = base;
            urlEl.textContent = '<?= esc_js( home_url( '/' ) ) ?>' + base + '/42/';
            urlEl.parentNode.style.color = '#059669';
        }
        var nb = document.getElementById('snn_normal_base');
        var ib = document.getElementById('snn_instr_base');
        if (nb) nb.addEventListener('input', function() { updatePreview('snn_normal_base','snn_normal_preview_id','snn_normal_preview_url'); });
        if (ib) ib.addEventListener('input', function() { updatePreview('snn_instr_base','snn_instr_preview_id','snn_instr_preview_url'); });

        // ---- Role pill toggle: clicking pill toggles checkbox + style ----
        document.querySelectorAll('.snn-role-pill').forEach(function(label) {
            var input = label.querySelector('input[type="checkbox"]');
            if (!input) return;

            // Prevent browser's native checkbox toggle on click
            input.addEventListener('click', function(e) {
                e.preventDefault();
            });

            // Handle pill click — toggle our way
            label.addEventListener('click', function(e) {
                if (e.target === input) return; // let the blocked input handler deal with it
                input.checked = !input.checked;
                var isChecked = input.checked;
                label.style.color = isChecked ? '#2271b1' : '#6b7280';
                label.style.background = isChecked ? 'rgba(34,113,177,0.08)' : '#fff';
                label.style.fontWeight = isChecked ? '600' : '400';
                label.style.borderColor = isChecked ? '#2271b1' : '#d1d5db';
            });

            // Init pill state from pre-checked inputs
            if (input.checked) {
                label.style.color = '#2271b1';
                label.style.background = 'rgba(34,113,177,0.08)';
                label.style.fontWeight = '600';
                label.style.borderColor = '#2271b1';
            }
        });

        // ---- Danger Zone: DELETE confirmation safeguard ----
        var deleteInput = document.getElementById('snn_delete_confirm');
        var deleteBtn   = document.getElementById('snn_delete_btn');
        if (deleteInput && deleteBtn) {
            deleteInput.addEventListener('input', function() {
                deleteBtn.disabled = (deleteInput.value.trim() !== 'DELETE');
                deleteBtn.style.opacity = deleteInput.value.trim() === 'DELETE' ? '1' : '0.45';
                deleteBtn.style.cursor = deleteInput.value.trim() === 'DELETE' ? 'pointer' : 'not-allowed';
                deleteBtn.style.pointerEvents = deleteInput.value.trim() === 'DELETE' ? 'auto' : 'none';
            });
            deleteBtn.addEventListener('click', function(e) {
                if (deleteInput.value.trim() !== 'DELETE') {
                    e.preventDefault();
                    deleteInput.style.borderColor = '#dc2626';
                    deleteInput.focus();
                    return;
                }
                if (!confirm('WARNING: This will permanently delete ALL enrollment data for ALL users. This cannot be undone. Are you absolutely sure?')) {
                    e.preventDefault();
                }
            });
        }

        // ---- Live player color preview: update CSS vars on color change ----
        var previewWrap = document.getElementById('snn-player-preview');
        var colorInputs = [
            { color: '<?= esc_js( snn_learn_get( 'video_color_primary' ) ) ?>', id: 'snn_vcp' },
            { color: '<?= esc_js( snn_learn_get( 'video_color_bg' ) ) ?>',      id: 'snn_vcb' },
            { color: '<?= esc_js( snn_learn_get( 'video_color_text' ) ) ?>',     id: 'snn_vct' },
        ];
        ['snn_vcp','snn_vcb','snn_vct'].forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('input', function() {
                if (!previewWrap) return;
                var primary = document.getElementById('snn_vcp').value;
                var bg      = document.getElementById('snn_vcb').value;
                var text    = document.getElementById('snn_vct').value;
                previewWrap.style.setProperty('--vp-primary', primary);
                previewWrap.style.setProperty('--vp-bg', bg);
                previewWrap.style.setProperty('--vp-text', text);
                // Update preview dots
                var dotP = document.getElementById('snn_vcp_preview');
                var dotB = document.getElementById('snn_vcb_preview');
                var dotT = document.getElementById('snn_vct_preview');
                if (dotP) dotP.style.background = primary;
                if (dotB) dotB.style.background = bg;
                if (dotT) dotT.style.background = text;
            });
        });

        // ---- Save button: show saved confirmation ----
        var savedMsg = document.getElementById('snn_settings_saved_msg');
        var form = document.querySelector('.snn-learn-settings form');
        if (form && savedMsg) {
            form.addEventListener('submit', function() {
                setTimeout(function() {
                    savedMsg.style.opacity = '1';
                    setTimeout(function() { savedMsg.style.opacity = '0'; }, 2500);
                }, 300);
            });
        }
    });
    </script>
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
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $t (user_id, post_id, course_id, enrolled_at, last_activity_at) VALUES (%d, %d, %d, %d, %d)",
            (int) $user_id, (int) $course_id, (int) $course_id, $now, $now
        ) );
        // Fire action only on the very first enrollment in this course
        if ( $result && $wpdb->rows_affected ) {
            do_action( 'snn_learn_first_enrollment', (int) $user_id, (int) $course_id );
        }
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
    if ( strpos( $request->get_route(), '/snn-learn/' ) !== false && ! is_wp_error( $response ) ) {
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
require_once plugin_dir_path( __FILE__ ) . 'emails.php';

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
