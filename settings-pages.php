<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// SNN LEARN — SETTINGS PAGES (one per submenu)
// Loaded from snn-learn.php via:
//   require_once plugin_dir_path( __FILE__ ) . 'settings-pages.php';
//
// Each page has its OWN form, nonce, and save handler.
// No shared form = no cross-tab field blanking bugs.
// ============================================================

// ----------------------------------------------------------
// 6a. VIDEO PLAYER SETTINGS
// ----------------------------------------------------------
function snn_learn_video_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // ---- Save Handler ----
    if ( isset( $_POST['snn_learn_video_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_video_nonce'] ) ), 'snn_learn_video_save' ) ) {
        $fields = [ 'course_post_type', 'video_field', 'video_color_primary', 'video_color_bg', 'video_color_text', 'video_complete_seconds' ];
        foreach ( $fields as $f ) {
            if ( isset( $_POST[ 'snn_learn_' . $f ] ) ) {
                update_option( 'snn_learn_' . $f, sanitize_text_field( wp_unslash( $_POST[ 'snn_learn_' . $f ] ) ) );
            }
        }
        update_option( 'snn_learn_video_complete_on_end', isset( $_POST['snn_learn_video_complete_on_end'] ) ? 1 : 0 );
        echo '<div class="notice notice-success is-dismissible"><p><strong>Video player settings saved.</strong></p></div>';
    }

    ?>
    <div class="snn-learn-settings wrap" style="max-width:860px">
        <h1>SNN Learn &mdash; Video Player</h1>

        <form method="post" action="" style="margin-top:28px">
            <?php wp_nonce_field( 'snn_learn_video_save', 'snn_learn_video_nonce' ); ?>

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

            <div style="margin-top:4px;padding:16px 0;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:12px">
                <?php submit_button( 'Save Settings', 'primary', '', false, [ 'style' => 'background:#2271b1;border-color:#2271b1;color:#fff;font-weight:600;padding:10px 24px;border-radius:6px;font-size:14px;cursor:pointer' ] ); ?>
                <span id="snn_settings_saved_msg" style="color:#16a34a;font-size:13px;opacity:0;transition:opacity .3s;font-weight:600">&#10003; Saved</span>
            </div>
        </form>
    </div>

    <style>
    .snn-hex-sync { transition: border-color 0.15s; }
    .snn-hex-sync:focus { border-color: #2271b1; outline: none; }
    .snn-char-count { font-variant-numeric: tabular-nums; }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
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

        // ---- Live player color preview ----
        var previewWrap = document.getElementById('snn-player-preview');
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

// ----------------------------------------------------------
// 6b. EMAILS SETTINGS
// ----------------------------------------------------------
function snn_learn_emails_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // ---- Save Handler ----
    if ( isset( $_POST['snn_learn_emails_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_emails_nonce'] ) ), 'snn_learn_emails_save' ) ) {
        snn_learn_emails_save_settings();
        echo '<div class="notice notice-success is-dismissible"><p><strong>Email settings saved.</strong></p></div>';
    }

    ?>
    <div class="snn-learn-settings-emails wrap" style="max-width:860px">
        <h1>SNN Learn &mdash; Emails</h1>

        <form method="post" action="" style="margin-top:28px">
            <?php wp_nonce_field( 'snn_learn_emails_save', 'snn_learn_emails_nonce' ); ?>

            <div class="snn-settings-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)">
                <h2 style="margin:0 0 20px;font-size:16px;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;padding-bottom:14px">Email Notifications</h2>
                <p style="margin:0 0 20px;font-size:13px;color:#6b7280;line-height:1.6">
                    Configure automated emails sent to learners. All notifications are <strong>disabled by default</strong> — enable only the ones you need.
                    Use merge tags like <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px">{{user_name}}</code> to personalise each message.
                </p>
                <?php snn_learn_emails_settings_section(); ?>
            </div>

            <div style="margin-top:4px;padding:16px 0;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:12px">
                <?php submit_button( 'Save Settings', 'primary', '', false, [ 'style' => 'background:#2271b1;border-color:#2271b1;color:#fff;font-weight:600;padding:10px 24px;border-radius:6px;font-size:14px;cursor:pointer' ] ); ?>
                <span id="snn_settings_saved_msg" style="color:#16a34a;font-size:13px;opacity:0;transition:opacity .3s;font-weight:600">&#10003; Saved</span>
            </div>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var savedMsg = document.getElementById('snn_settings_saved_msg');
        var form = document.querySelector('.snn-learn-settings-emails form');
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

// ----------------------------------------------------------
// 6c. USER PERMALINKS SETTINGS
// ----------------------------------------------------------
function snn_learn_permalinks_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // ---- Save Handler ----
    if ( isset( $_POST['snn_learn_permalinks_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_permalinks_nonce'] ) ), 'snn_learn_permalinks_save' ) ) {
        update_option( 'snn_learn_user_permalinks_enabled', isset( $_POST['snn_learn_user_permalinks_enabled'] ) ? 1 : 0 );

        $normal_base = isset( $_POST['snn_learn_user_permalink_normal_base'] )
            ? sanitize_title( wp_unslash( $_POST['snn_learn_user_permalink_normal_base'] ) )
            : '';
        $instr_base  = isset( $_POST['snn_learn_user_permalink_instr_base'] )
            ? sanitize_title( wp_unslash( $_POST['snn_learn_user_permalink_instr_base'] ) )
            : '';
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

        flush_rewrite_rules( true );

        echo '<div class="notice notice-success is-dismissible"><p><strong>User permalink settings saved.</strong> Rewrite rules flushed.</p></div>';
    }

    ?>
    <div class="snn-learn-settings-permalinks wrap" style="max-width:860px">
        <h1>SNN Learn &mdash; User Permalinks</h1>

        <form method="post" action="" style="margin-top:28px">
            <?php wp_nonce_field( 'snn_learn_permalinks_save', 'snn_learn_permalinks_nonce' ); ?>

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

            <div style="margin-top:4px;padding:16px 0;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:12px">
                <?php submit_button( 'Save Settings', 'primary', '', false, [ 'style' => 'background:#2271b1;border-color:#2271b1;color:#fff;font-weight:600;padding:10px 24px;border-radius:6px;font-size:14px;cursor:pointer' ] ); ?>
                <span id="snn_settings_saved_msg" style="color:#16a34a;font-size:13px;opacity:0;transition:opacity .3s;font-weight:600">&#10003; Saved</span>
            </div>
        </form>
    </div>

    <style>
    .snn-role-pill { position: relative; user-select: none; transition: all 0.15s; }
    .snn-role-pill:hover { border-color: #2271b1; color: #2271b1; }
    .snn-role-pill input:checked + * { color: #2271b1; }
    .snn-char-count { font-variant-numeric: tabular-nums; }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
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

        // ---- Live permalink URL preview ----
        function updatePreview(baseId, previewId, urlId) {
            var baseInput = document.getElementById(baseId);
            var preview = document.getElementById(previewId);
            var urlEl = document.getElementById(urlId);
            if (!baseInput || !preview || !urlEl) return;
            var base = baseInput.value.trim() || baseInput.placeholder;
            preview.textContent = base;
            urlEl.textContent = '<?= esc_js( home_url( '/' ) ) ?>' + base + '/42/';
        }
        var nb = document.getElementById('snn_normal_base');
        var ib = document.getElementById('snn_instr_base');
        if (nb) nb.addEventListener('input', function() { updatePreview('snn_normal_base','snn_normal_preview_id','snn_normal_preview_url'); });
        if (ib) ib.addEventListener('input', function() { updatePreview('snn_instr_base','snn_instr_preview_id','snn_instr_preview_url'); });

        // ---- Role pill toggle ----
        document.querySelectorAll('.snn-role-pill').forEach(function(label) {
            var input = label.querySelector('input[type="checkbox"]');
            if (!input) return;

            input.addEventListener('click', function(e) { e.preventDefault(); });

            label.addEventListener('click', function(e) {
                if (e.target === input) return;
                input.checked = !input.checked;
                var isChecked = input.checked;
                label.style.color = isChecked ? '#2271b1' : '#6b7280';
                label.style.background = isChecked ? 'rgba(34,113,177,0.08)' : '#fff';
                label.style.fontWeight = isChecked ? '600' : '400';
                label.style.borderColor = isChecked ? '#2271b1' : '#d1d5db';
            });

            if (input.checked) {
                label.style.color = '#2271b1';
                label.style.background = 'rgba(34,113,177,0.08)';
                label.style.fontWeight = '600';
                label.style.borderColor = '#2271b1';
            }
        });

        // ---- Save button: show saved confirmation ----
        var savedMsg = document.getElementById('snn_settings_saved_msg');
        var form = document.querySelector('.snn-learn-settings-permalinks form');
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

// ----------------------------------------------------------
// 6d. PAGE ORDERING SETTINGS
// ----------------------------------------------------------
function snn_learn_ordering_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // ---- Save Handler ----
    if ( isset( $_POST['snn_learn_ordering_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_ordering_nonce'] ) ), 'snn_learn_ordering_save' ) ) {
        if ( class_exists( 'Simple_Page_Ordering' ) ) {
            Simple_Page_Ordering::handle_settings_save();
        }
        echo '<div class="notice notice-success is-dismissible"><p><strong>Page ordering settings saved.</strong></p></div>';
    }

    ?>
    <div class="snn-learn-settings-ordering wrap" style="max-width:860px">
        <h1>SNN Learn &mdash; Page Ordering</h1>

        <form method="post" action="" style="margin-top:28px">
            <?php wp_nonce_field( 'snn_learn_ordering_save', 'snn_learn_ordering_nonce' ); ?>

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

            <div style="margin-top:4px;padding:16px 0;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:12px">
                <?php submit_button( 'Save Settings', 'primary', '', false, [ 'style' => 'background:#2271b1;border-color:#2271b1;color:#fff;font-weight:600;padding:10px 24px;border-radius:6px;font-size:14px;cursor:pointer' ] ); ?>
                <span id="snn_settings_saved_msg" style="color:#16a34a;font-size:13px;opacity:0;transition:opacity .3s;font-weight:600">&#10003; Saved</span>
            </div>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var savedMsg = document.getElementById('snn_settings_saved_msg');
        var form = document.querySelector('.snn-learn-settings-ordering form');
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

// ----------------------------------------------------------
// 6e. DANGER ZONE
// ----------------------------------------------------------
function snn_learn_danger_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // ---- Reset Handler (uses its own nonce) ----
    if ( isset( $_POST['snn_learn_reset_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_reset_nonce'] ) ), 'snn_learn_reset_data' ) ) {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}snn_learn_enrollments" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        echo '<div class="notice notice-warning is-dismissible"><p><strong>All enrollment data has been permanently deleted.</strong></p></div>';
    }

    ?>
    <div class="snn-learn-settings-danger wrap" style="max-width:860px">
        <h1>SNN Learn &mdash; Danger Zone</h1>

        <div class="snn-settings-card" style="background:#fff;border:1px solid #fca5a5;border-radius:12px;padding:24px 28px;margin-top:28px;box-shadow:0 1px 3px rgba(0,0,0,0.06)">
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
                        style="background:#dc2626;border:1px solid #b91c1c;color:#fff;font-weight:600;padding:10px 22px;border-radius:6px;cursor:not-allowed;font-size:14px;opacity:0.45">&#128465; Delete All Enrollment Data</button>
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
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
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
    });
    </script>
    <?php
}
