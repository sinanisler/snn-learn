<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// SNN LEARN — EMAIL NOTIFICATIONS
// emails.php
// ============================================================
//
// Three notification types (all disabled by default):
//   1. Course Completion  — fires on snn_learn_course_completed
//   2. First Enrollment   — fires on snn_learn_first_enrollment
//   3. Comment Reply      — fires on comment_post (replies only)
//
// Each type has a checkbox toggle, a subject line, and a rich-text
// body. Merge tags like {{user_name}} are replaced at send time.
//
// Settings are stored as WordPress options prefixed with snn_learn_email_
// and managed via the "Emails" tab under SNN Learn → Settings.
// ============================================================

// ----------------------------------------------------------
// DEFAULTS — returned by snn_learn_get_email() when no value
//            has been saved yet.
// ----------------------------------------------------------
function snn_learn_email_defaults() {
    return [
        'course_completed_enabled'   => 0,
        'course_completed_subject'   => '🎓 You completed {{course_name}}!',
        'course_completed_body'      => '<p>Hi {{user_name}},</p>'
                                      . '<p>Congratulations! You\'ve completed <strong>{{course_name}}</strong>.</p>'
                                      . '<p><a href="{{certificate_url}}" style="display:inline-block;padding:12px 24px;background:#0556c7;color:#fff;border-radius:6px;text-decoration:none;font-weight:600">View Your Certificate →</a></p>'
                                      . '<p>– {{site_name}}</p>',

        'first_enrollment_enabled'   => 0,
        'first_enrollment_subject'   => 'Welcome to {{course_name}}!',
        'first_enrollment_body'      => '<p>Hi {{user_name}},</p>'
                                      . '<p>Welcome to <strong>{{course_name}}</strong>! We\'re excited to have you on board.</p>'
                                      . '<p><a href="{{course_url}}" style="display:inline-block;padding:12px 24px;background:#0556c7;color:#fff;border-radius:6px;text-decoration:none;font-weight:600">Start Learning →</a></p>'
                                      . '<p>– {{site_name}}</p>',

        'comment_reply_enabled'      => 0,
        'comment_reply_subject'      => 'New reply to your comment on {{lesson_name}}',
        'comment_reply_body'         => '<p>Hi {{user_name}},</p>'
                                      . '<p>Someone replied to your comment on <strong>{{lesson_name}}</strong>:</p>'
                                      . '<blockquote style="border-left:3px solid #e0e0e0;margin:12px 0;padding:8px 16px;color:#555">{{comment_content}}</blockquote>'
                                      . '<p><a href="{{lesson_url}}" style="display:inline-block;padding:12px 24px;background:#0556c7;color:#fff;border-radius:6px;text-decoration:none;font-weight:600">View the Conversation →</a></p>'
                                      . '<p>– {{site_name}}</p>',

        'inactivity_reminder_enabled' => 0,
        'inactivity_reminder_days'   => 7,
        'inactivity_reminder_subject'=> 'We miss you at {{course_name}}!',
        'inactivity_reminder_body'   => '<p>Hi {{user_name}},</p>'
                                      . '<p>We noticed you haven\'t been back to <strong>{{course_name}}</strong> for {{days_inactive}} days.</p>'
                                      . '<p><a href="{{resume_url}}" style="display:inline-block;padding:12px 24px;background:#0556c7;color:#fff;border-radius:6px;text-decoration:none;font-weight:600">Continue Learning →</a></p>'
                                      . '<p>You left off right here — pick up where you stopped!</p>'
                                      . '<p>– {{site_name}}</p>',
    ];
}

// ----------------------------------------------------------
// GETTER — returns a single email option, falling back to the
//          hard-coded default defined above.
// ----------------------------------------------------------
function snn_learn_get_email( $key ) {
    $defaults = snn_learn_email_defaults();
    $value    = get_option( 'snn_learn_email_' . $key, null );
    $default  = $defaults[ $key ] ?? '';

    // Return saved value only when non-null AND non-empty.
    // An empty saved value (e.g. user cleared the subject field)
    // falls back to the hard-coded default.
    if ( null !== $value && '' !== $value ) {
        return $value;
    }
    return $default;
}

// ----------------------------------------------------------
// MERGE-TAG REPLACER
// Replaces {{tag}} placeholders with values from $tags array.
// ----------------------------------------------------------
function snn_learn_email_replace_tags( $template, array $tags ) {
    foreach ( $tags as $tag => $value ) {
        $template = str_replace( '{{' . $tag . '}}', (string) $value, $template );
    }
    return $template;
}

// ----------------------------------------------------------
// SEND HELPER — thin wrapper around wp_mail with HTML headers.
// ----------------------------------------------------------
function snn_learn_email_send( $to, $subject, $body ) {
    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
    return wp_mail( $to, $subject, $body, $headers );
}


// ============================================================
// 1. COURSE COMPLETION EMAIL
//    Hook: snn_learn_course_completed (fired in snn-learn.php)
//    Tags: {{user_name}}, {{user_email}}, {{course_name}},
//          {{course_url}}, {{certificate_url}}, {{completion_date}},
//          {{site_name}}
// ============================================================

add_action( 'snn_learn_course_completed', 'snn_learn_email_course_completed', 10, 2 );
function snn_learn_email_course_completed( $user_id, $course_id ) {
    if ( ! snn_learn_get_email( 'course_completed_enabled' ) ) {
        return;
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return;
    }

    $course_name = get_the_title( $course_id );
    if ( ! $course_name ) {
        return;
    }

    $cert_url = add_query_arg( [
        'cid'            => $course_id,
        'uid'            => $user_id,
        'certificate_id' => snn_learn_cert_hash( $user_id, $course_id ),
        'completion_date'=> wp_date( 'Y-m-d' ),
    ], home_url( '/certificate/' ) );

    $tags = [
        'user_name'       => $user->display_name,
        'user_email'      => $user->user_email,
        'course_name'     => $course_name,
        'course_url'      => get_permalink( $course_id ),
        'certificate_url' => $cert_url,
        'completion_date' => wp_date( get_option( 'date_format' ) ),
        'site_name'       => get_bloginfo( 'name' ),
    ];

    $subject = snn_learn_email_replace_tags(
        snn_learn_get_email( 'course_completed_subject' ),
        $tags
    );
    $body    = snn_learn_email_replace_tags(
        snn_learn_get_email( 'course_completed_body' ),
        $tags
    );

    snn_learn_email_send( $user->user_email, $subject, $body );
}

// ============================================================
// 2. FIRST ENROLLMENT EMAIL
//    Hook: snn_learn_first_enrollment
//          (fired in snn_learn_record_lesson when INSERT IGNORE
//           actually inserts a new course-enrollment row)
//    Tags: {{user_name}}, {{user_email}}, {{course_name}},
//          {{course_url}}, {{site_name}}
// ============================================================

add_action( 'snn_learn_first_enrollment', 'snn_learn_email_first_enrollment', 10, 2 );
function snn_learn_email_first_enrollment( $user_id, $course_id ) {
    if ( ! snn_learn_get_email( 'first_enrollment_enabled' ) ) {
        return;
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return;
    }

    $course_name = get_the_title( $course_id );
    if ( ! $course_name ) {
        return;
    }

    $tags = [
        'user_name'   => $user->display_name,
        'user_email'  => $user->user_email,
        'course_name' => $course_name,
        'course_url'  => get_permalink( $course_id ),
        'site_name'   => get_bloginfo( 'name' ),
    ];

    $subject = snn_learn_email_replace_tags(
        snn_learn_get_email( 'first_enrollment_subject' ),
        $tags
    );
    $body    = snn_learn_email_replace_tags(
        snn_learn_get_email( 'first_enrollment_body' ),
        $tags
    );

    snn_learn_email_send( $user->user_email, $subject, $body );
}

// ============================================================
// 3. COMMENT REPLY EMAIL
//    Hook: comment_post (WordPress core, fires after comment saved)
//    Only triggers when comment_parent > 0 (i.e. a reply).
//    Tags: {{user_name}}, {{lesson_name}}, {{lesson_url}},
//          {{comment_content}}, {{site_name}}
// ============================================================

add_action( 'comment_post', 'snn_learn_email_comment_reply', 10, 3 );
function snn_learn_email_comment_reply( $comment_id, $comment_approved, $comment_data ) {
    if ( ! snn_learn_get_email( 'comment_reply_enabled' ) ) {
        return;
    }

    // Only fire for replies, not top-level comments
    if ( empty( $comment_data['comment_parent'] ) ) {
        return;
    }

    $parent = get_comment( (int) $comment_data['comment_parent'] );
    if ( ! $parent || empty( $parent->comment_author_email ) ) {
        return;
    }

    // Don't notify users about their own replies
    if ( isset( $comment_data['comment_author_email'] )
        && $comment_data['comment_author_email'] === $parent->comment_author_email ) {
        return;
    }

    $lesson_id   = (int) $comment_data['comment_post_ID'];
    $lesson_name = get_the_title( $lesson_id );
    $lesson_url  = get_permalink( $lesson_id ) . '#snn-cl-' . (int) $comment_id;

    $tags = [
        'user_name'       => $parent->comment_author,
        'lesson_name'     => $lesson_name ?: 'a lesson',
        'course_name'     => $lesson_name ?: 'a lesson',
        'lesson_url'      => $lesson_url,
        'comment_content' => wpautop( trim( $comment_data['comment_content'] ) ),
        'site_name'       => get_bloginfo( 'name' ),
    ];

    $subject = snn_learn_email_replace_tags(
        snn_learn_get_email( 'comment_reply_subject' ),
        $tags
    );
    $body    = snn_learn_email_replace_tags(
        snn_learn_get_email( 'comment_reply_body' ),
        $tags
    );

    snn_learn_email_send( $parent->comment_author_email, $subject, $body );
}

// ============================================================
// 4. INACTIVITY REMINDER EMAIL
//    Cron: snn_learn_inactivity_check (daily)
//    Tags: {{user_name}}, {{user_email}}, {{course_name}},
//          {{course_url}}, {{resume_url}}, {{days_inactive}},
//          {{site_name}}
// ============================================================

// Register the daily cron job on init
add_action( 'init', 'snn_learn_email_inactivity_schedule' );
function snn_learn_email_inactivity_schedule() {
    if ( ! wp_next_scheduled( 'snn_learn_inactivity_check' ) ) {
        wp_schedule_event( time(), 'daily', 'snn_learn_inactivity_check' );
    }
}

// Hook the cron event to our handler
add_action( 'snn_learn_inactivity_check', 'snn_learn_email_inactivity_cron' );
function snn_learn_email_inactivity_cron() {
    if ( ! snn_learn_get_email( 'inactivity_reminder_enabled' ) ) {
        return;
    }

    $days = (int) snn_learn_get_email( 'inactivity_reminder_days' );
    if ( $days < 1 ) {
        $days = 7;
    }

    global $wpdb;
    $t = $wpdb->prefix . 'snn_learn_enrollments';

    // Inactive between $days and $days+7 (don't email people who've been
    // gone for 2x the threshold — they've probably already been reminded).
    $ts_cutoff  = time() - ( $days * DAY_IN_SECONDS );
    $ts_far_end = time() - ( ( $days + 7 ) * DAY_IN_SECONDS );

    $stale = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, course_id, last_activity_at
         FROM $t
         WHERE is_course = 1
           AND last_activity_at < %d
           AND last_activity_at > %d
           AND completed_at IS NULL
         LIMIT 100",
        $ts_cutoff, $ts_far_end
    ) );

    if ( empty( $stale ) ) {
        return;
    }

    foreach ( $stale as $row ) {
        $user_id   = (int) $row->user_id;
        $course_id = (int) $row->course_id;

        // Prevent duplicate reminders: only email once per user+course per 30 days
        $meta_key      = 'snn_learn_inactivity_reminded_' . $course_id;
        $last_reminded = get_user_meta( $user_id, $meta_key, true );
        if ( $last_reminded && ( time() - (int) $last_reminded ) < 30 * DAY_IN_SECONDS ) {
            continue;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            continue;
        }

        $course_name = get_the_title( $course_id );
        if ( ! $course_name ) {
            continue;
        }

        // Find the last lesson the user interacted with for a "resume" link
        $last_lesson = $wpdb->get_row( $wpdb->prepare(
            "SELECT post_id FROM $t
             WHERE user_id = %d AND course_id = %d AND is_course = 0
             ORDER BY last_activity_at DESC LIMIT 1",
            $user_id, $course_id
        ) );

        $resume_url    = $last_lesson
            ? get_permalink( $last_lesson->post_id )
            : get_permalink( $course_id );
        $days_inactive = (int) round( ( time() - (int) $row->last_activity_at ) / DAY_IN_SECONDS );

        $tags = [
            'user_name'     => $user->display_name,
            'user_email'    => $user->user_email,
            'course_name'   => $course_name,
            'course_url'    => get_permalink( $course_id ),
            'resume_url'    => $resume_url,
            'days_inactive' => (string) $days_inactive,
            'site_name'     => get_bloginfo( 'name' ),
        ];

        $subject = snn_learn_email_replace_tags(
            snn_learn_get_email( 'inactivity_reminder_subject' ),
            $tags
        );
        $body = snn_learn_email_replace_tags(
            snn_learn_get_email( 'inactivity_reminder_body' ),
            $tags
        );

        snn_learn_email_send( $user->user_email, $subject, $body );

        // Mark as reminded so we don't spam
        update_user_meta( $user_id, $meta_key, time() );
    }
}

// ============================================================
// 5. SETTINGS RENDER — called from snn_learn_settings_page()
//    when the active tab is 'emails'.
// ============================================================

function snn_learn_emails_settings_section() {
    $editors = [
        'course_completed' => [
            'title'       => 'Course Completion',
            'desc'        => 'Sent when a user completes 100% of a course. The certificate link is included automatically.',
            'tags'        => [
                'user_name'       => "User's display name",
                'user_email'      => "User's email address",
                'course_name'     => 'Course title',
                'course_url'      => 'Course permalink',
                'certificate_url' => 'Certificate page URL',
                'completion_date' => 'Date of completion',
                'site_name'       => 'Site name',
            ],
        ],
        'first_enrollment' => [
            'title'       => 'First Enrollment',
            'desc'        => 'Sent the very first time a user enrolls in a course (not on every lesson visit).',
            'tags'        => [
                'user_name'   => "User's display name",
                'user_email'  => "User's email address",
                'course_name' => 'Course title',
                'course_url'  => 'Course permalink',
                'site_name'   => 'Site name',
            ],
        ],
        'comment_reply' => [
            'title'       => 'Comment Reply',
            'desc'        => 'Sent when someone replies to a user\'s comment. Does not notify users about their own replies.',
            'tags'        => [
                'user_name'       => "Parent comment author's name",
                'lesson_name'     => 'Lesson title',
                'lesson_url'      => 'Lesson permalink (anchored to the new reply)',
                'comment_content' => 'The reply content (HTML formatted)',
                'site_name'       => 'Site name',
            ],
        ],
        'inactivity_reminder' => [
            'title'       => 'Inactivity Reminder',
            'desc'        => 'Sent automatically via daily cron to users who haven\'t been active for the configured number of days. Each user+course pair gets at most one reminder every 30 days.',
            'has_days'    => true,
            'tags'        => [
                'user_name'     => "User's display name",
                'user_email'    => "User's email address",
                'course_name'   => 'Course title',
                'course_url'    => 'Course permalink',
                'resume_url'    => 'Link to last active lesson',
                'days_inactive' => 'Number of days since last activity',
                'site_name'     => 'Site name',
            ],
        ],
    ];

    foreach ( $editors as $key => $info ) :
        $enabled = (int) snn_learn_get_email( $key . '_enabled' );
        $subject = snn_learn_get_email( $key . '_subject' );
        $body    = snn_learn_get_email( $key . '_body' );
        ?>
        <!-- ===== <?= esc_html( $info['title'] ) ?> ===== -->
        <div class="snn-email-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                <div>
                    <h3 style="margin:0;font-size:15px;font-weight:700;color:#111827"><?= esc_html( $info['title'] ) ?></h3>
                    <p style="margin:4px 0 0;font-size:12px;color:#9ca3af"><?= esc_html( $info['desc'] ) ?></p>
                </div>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none">
                    <span style="font-size:12px;font-weight:600;color:#6b7280">Enable</span>
                    <input type="hidden"   name="snn_learn_email_<?= esc_attr( $key ) ?>_enabled" value="0">
                    <input type="checkbox" name="snn_learn_email_<?= esc_attr( $key ) ?>_enabled" value="1"
                        <?php checked( $enabled, 1 ); ?>
                        style="width:18px;height:18px;cursor:pointer;accent-color:#2271b1">
                </label>
            </div>

            <!-- Subject -->
            <div style="margin-bottom:16px">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">Subject Line</label>
                <input type="text"
                    name="snn_learn_email_<?= esc_attr( $key ) ?>_subject"
                    value="<?= esc_attr( $subject ) ?>"
                    style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:monospace"
                    maxlength="200">
            </div>

            <?php if ( ! empty( $info['has_days'] ) ) : ?>
            <!-- Inactivity Days Threshold -->
            <div style="margin-bottom:16px">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">
                    Send After Days of Inactivity
                </label>
                <div style="display:flex;align-items:center;gap:8px">
                    <input type="number"
                        name="snn_learn_email_<?= esc_attr( $key ) ?>_days"
                        value="<?= esc_attr( (int) snn_learn_get_email( $key . '_days' ) ) ?>"
                        min="1" max="90" step="1"
                        style="width:100px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px">
                    <span style="font-size:12px;color:#6b7280">days</span>
                </div>
                <p style="margin:4px 0 0;font-size:11px;color:#9ca3af">
                    The daily cron sends reminders to users inactive for <strong>this many days</strong> up to <strong>this+7 days</strong>. Each user+course pair is reminded at most once every 30 days.
                </p>
            </div>
            <?php endif; ?>

            <!-- Body — wp_editor -->
            <div style="margin-bottom:12px">
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">Email Body (HTML)</label>
                <?php
                wp_editor(
                    $body,
                    'snn_learn_email_' . $key . '_body',
                    [
                        'textarea_name' => 'snn_learn_email_' . $key . '_body',
                        'textarea_rows' => 6,
                        'teeny'         => true,
                        'media_buttons' => false,
                        'quicktags'     => true,
                        'tinymce'       => [
                            'toolbar1' => 'bold,italic,underline,strikethrough,bullist,numlist,blockquote,link,unlink,removeformat,undo,redo',
                            'toolbar2' => '',
                            'toolbar3' => '',
                        ],
                    ]
                );
                ?>
            </div>

            <!-- Merge tags help -->
            <details style="font-size:12px;color:#6b7280">
                <summary style="cursor:pointer;font-weight:600;color:#374151;margin-bottom:6px">
                    Available Merge Tags <span style="font-weight:400;color:#9ca3af">(click to copy, then paste into the editor)</span>
                </summary>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
                    <?php foreach ( $info['tags'] as $tag => $tag_desc ) : ?>
                        <span class="snn-email-tag"
                            data-tag="{{<?= esc_attr( $tag ) ?>}}"
                            title="<?= esc_attr( $tag_desc ) ?>"
                            style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:20px;font-family:monospace;font-size:11px;cursor:pointer;white-space:nowrap;transition:background .15s"
                            onclick="snnEmailCopyTag(this)">
                            {{<?= esc_html( $tag ) ?>}}
                            <span class="snn-tag-desc" style="color:#6b7280;font-family:inherit;font-size:10px">— <?= esc_html( $tag_desc ) ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
    <?php endforeach; ?>

    <script>
    function snnEmailCopyTag(el) {
        var tag = el.getAttribute('data-tag');
        navigator.clipboard.writeText(tag).then(function () {
            var orig = el.style.background;
            el.style.background = '#d1fae5';
            el.style.borderColor = '#6ee7b7';
            setTimeout(function () {
                el.style.background = orig;
                el.style.borderColor = '#bfdbfe';
            }, 1200);
        });
    }
    </script>

    <style>
    .snn-email-tag:hover { background: #dbeafe !important; border-color: #93c5fd !important; }
    </style>
    <?php
}

// ============================================================
// 5. SETTINGS SAVE HANDLER — called from snn_learn_settings_page()
//    when $_POST['snn_learn_nonce'] is verified.
// ============================================================

function snn_learn_emails_save_settings() {
    // Guard: only save when the Emails tab was active (its fields are in $_POST).
    // The hidden input for the checkbox is always present when the tab is
    // rendered. If missing, we're saving from a different tab — skip silently
    // instead of blanking out the email settings.
    if ( ! isset( $_POST['snn_learn_email_course_completed_enabled'] ) ) {
        return;
    }

    $email_keys = [ 'course_completed', 'first_enrollment', 'comment_reply', 'inactivity_reminder' ];

    foreach ( $email_keys as $key ) {
        // Checkbox (disabled by default — only saves 1 if explicitly checked)
        $enabled = isset( $_POST[ 'snn_learn_email_' . $key . '_enabled' ] )
            ? (int) $_POST[ 'snn_learn_email_' . $key . '_enabled' ]
            : 0;
        update_option( 'snn_learn_email_' . $key . '_enabled', $enabled ? 1 : 0 );

        // Subject line
        $subject = isset( $_POST[ 'snn_learn_email_' . $key . '_subject' ] )
            ? sanitize_text_field( wp_unslash( $_POST[ 'snn_learn_email_' . $key . '_subject' ] ) )
            : '';
        update_option( 'snn_learn_email_' . $key . '_subject', $subject );

        // Body — allow safe HTML (links, bold, lists, blockquotes, paragraphs)
        $body = isset( $_POST[ 'snn_learn_email_' . $key . '_body' ] )
            ? wp_kses_post( wp_unslash( $_POST[ 'snn_learn_email_' . $key . '_body' ] ) )
            : '';
        update_option( 'snn_learn_email_' . $key . '_body', $body );

        // Inactivity days (only applicable for inactivity_reminder)
        if ( $key === 'inactivity_reminder' ) {
            $days = isset( $_POST[ 'snn_learn_email_inactivity_reminder_days' ] )
                ? max( 1, min( 90, (int) $_POST[ 'snn_learn_email_inactivity_reminder_days' ] ) )
                : 7;
            update_option( 'snn_learn_email_inactivity_reminder_days', $days );
        }
    }
}
