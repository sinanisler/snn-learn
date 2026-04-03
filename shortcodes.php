<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// SHORTCODES
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
// COMMENT LIST SHORTCODE
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
