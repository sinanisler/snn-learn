<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * [snn_learn_video_player] - Custom HTML5 video player
 */
function snn_learn_video_player_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'src' => '' ), $atts, 'snn_learn_video_player' );

    $post_id = get_the_ID();

    // Get video URL from attribute or custom field
    $video_url = $atts['src'];
    if ( empty( $video_url ) ) {
        $field_slug = snn_learn_get_option( 'video_custom_field' );
        $video_url  = get_post_meta( $post_id, $field_slug, true );
    }

    if ( empty( $video_url ) ) {
        return '<!-- snn_learn_video_player: no video URL found -->';
    }

    $color    = esc_attr( snn_learn_get_option( 'player_color' ) );
    $bg       = esc_attr( snn_learn_get_option( 'player_bg_color' ) );
    $txt      = esc_attr( snn_learn_get_option( 'player_text_color' ) );
    $uid      = 'snn-vp-' . $post_id . '-' . wp_rand( 100, 999 );

    ob_start();
    ?>
    <div class="snn-learn-video-wrapper" id="<?php echo $uid; ?>" style="position:relative;max-width:100%;background:<?php echo $bg; ?>;border-radius:8px;overflow:hidden;line-height:0;">

        <video class="snn-learn-video" style="width:100%;display:block;cursor:pointer;" preload="metadata">
            <source src="<?php echo esc_url( $video_url ); ?>" type="video/mp4">
        </video>

        <!-- Controls Bar -->
        <div class="snn-learn-video-controls" style="position:absolute;bottom:0;left:0;right:0;background:<?php echo $bg; ?>;padding:8px 12px;display:flex;align-items:center;gap:10px;opacity:0;transition:opacity .2s;">

            <!-- Play/Pause -->
            <button class="snn-learn-btn-play" style="background:none;border:none;color:<?php echo $txt; ?>;font-size:18px;cursor:pointer;padding:0;line-height:1;" title="Play">&#9654;</button>

            <!-- Progress Bar -->
            <div class="snn-learn-progress-bar" style="flex:1;height:6px;background:rgba(255,255,255,.2);border-radius:3px;cursor:pointer;position:relative;">
                <div class="snn-learn-progress-fill" style="height:100%;width:0%;background:<?php echo $color; ?>;border-radius:3px;position:relative;transition:width .1s;">
                    <span class="snn-learn-progress-knob" style="position:absolute;right:-6px;top:-4px;width:14px;height:14px;background:<?php echo $color; ?>;border-radius:50%;border:2px solid <?php echo $txt; ?>;">&#8203;</span>
                </div>
            </div>

            <!-- Time -->
            <span class="snn-learn-time" style="color:<?php echo $txt; ?>;font-size:12px;font-family:monospace;min-width:80px;text-align:center;line-height:1;">0:00 / 0:00</span>

            <!-- Fullscreen -->
            <button class="snn-learn-btn-fs" style="background:none;border:none;color:<?php echo $txt; ?>;font-size:16px;cursor:pointer;padding:0;line-height:1;" title="Fullscreen">&#x26F6;</button>
        </div>
    </div>

    <script>
    (function(){
        var w = document.getElementById('<?php echo $uid; ?>');
        if(!w) return;
        var v = w.querySelector('.snn-learn-video');
        var ctrl = w.querySelector('.snn-learn-video-controls');
        var btnPlay = w.querySelector('.snn-learn-btn-play');
        var progBar = w.querySelector('.snn-learn-progress-bar');
        var progFill = w.querySelector('.snn-learn-progress-fill');
        var timeEl = w.querySelector('.snn-learn-time');
        var btnFs = w.querySelector('.snn-learn-btn-fs');
        var started = false;
        var completionFired = false;
        var mode = (typeof snnLearn !== 'undefined') ? snnLearn.completion_mode : 'one_second';
        var compSec = (typeof snnLearn !== 'undefined') ? snnLearn.completion_seconds : 1;

        function fmt(s){ s=Math.floor(s); var m=Math.floor(s/60); s=s%60; return m+':'+(s<10?'0':'')+s; }

        // Show controls on hover
        w.addEventListener('mouseenter', function(){ ctrl.style.opacity='1'; });
        w.addEventListener('mouseleave', function(){ if(!v.paused) ctrl.style.opacity='0'; });

        // Play/Pause
        function togglePlay(){
            if(v.paused){ v.play(); } else { v.pause(); }
        }
        btnPlay.addEventListener('click', function(e){ e.stopPropagation(); togglePlay(); });
        v.addEventListener('click', togglePlay);

        v.addEventListener('play', function(){
            btnPlay.innerHTML = '&#9208;';
            ctrl.style.opacity = '1';
            if(!started && typeof snnLearn !== 'undefined' && snnLearn.ajax_url){
                started = true;
                var fd = new FormData();
                fd.append('action','snn_learn_video_started');
                fd.append('nonce', snnLearn.nonce);
                fd.append('post_id', snnLearn.post_id);
                fd.append('course_id', snnLearn.course_id);
                fetch(snnLearn.ajax_url, {method:'POST', body:fd, credentials:'same-origin'});
            }
        });

        v.addEventListener('pause', function(){
            btnPlay.innerHTML = '&#9654;';
            ctrl.style.opacity = '1';
        });

        // Time update
        v.addEventListener('timeupdate', function(){
            if(v.duration){
                var pct = (v.currentTime / v.duration) * 100;
                progFill.style.width = pct + '%';
                timeEl.textContent = fmt(v.currentTime) + ' / ' + fmt(v.duration);
            }
            // Completion check (seconds mode)
            if(!completionFired && mode === 'one_second' && v.currentTime >= compSec){
                completionFired = true;
                fireCompletion();
            }
        });

        // Completion check (full video mode)
        v.addEventListener('ended', function(){
            btnPlay.innerHTML = '&#9654;';
            ctrl.style.opacity = '1';
            if(!completionFired && mode === 'full_video'){
                completionFired = true;
                fireCompletion();
            }
        });

        function fireCompletion(){
            if(typeof snnLearn === 'undefined' || !snnLearn.ajax_url) return;
            var fd = new FormData();
            fd.append('action','snn_learn_complete_lesson');
            fd.append('nonce', snnLearn.nonce);
            fd.append('post_id', snnLearn.post_id);
            fd.append('course_id', snnLearn.course_id);
            fetch(snnLearn.ajax_url, {method:'POST', body:fd, credentials:'same-origin'});
        }

        // Seek
        progBar.addEventListener('click', function(e){
            var rect = progBar.getBoundingClientRect();
            var pct = (e.clientX - rect.left) / rect.width;
            v.currentTime = pct * v.duration;
        });

        // Fullscreen
        btnFs.addEventListener('click', function(e){
            e.stopPropagation();
            if(w.requestFullscreen) w.requestFullscreen();
            else if(w.webkitRequestFullscreen) w.webkitRequestFullscreen();
        });

        // Keyboard
        w.setAttribute('tabindex','0');
        w.addEventListener('keydown', function(e){
            if(e.code==='Space'){ e.preventDefault(); togglePlay(); }
            if(e.code==='ArrowRight'){ v.currentTime = Math.min(v.duration, v.currentTime+5); }
            if(e.code==='ArrowLeft'){ v.currentTime = Math.max(0, v.currentTime-5); }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'snn_learn_video_player', 'snn_learn_video_player_shortcode' );


/**
 * [snn_learn_progress] - Output completion percentage as a number
 */
function snn_learn_progress_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) return '0';

    $atts = shortcode_atts( array( 'course_id' => '' ), $atts, 'snn_learn_progress' );

    $user_id   = get_current_user_id();
    $course_id = ! empty( $atts['course_id'] ) ? absint( $atts['course_id'] ) : snn_learn_get_course_id( get_the_ID() );

    // Get all lessons for this course
    $tree        = snn_learn_get_course_tree( $course_id );
    $total       = 0;
    $lesson_ids  = array();
    foreach ( $tree as $branch ) {
        foreach ( $branch['lessons'] as $lesson ) {
            $lesson_ids[] = $lesson->ID;
            $total++;
        }
    }

    if ( $total === 0 ) return '0';

    global $wpdb;
    $table = $wpdb->prefix . 'snn_learn_enrollments';
    $placeholders = implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) );
    $params       = array_merge( array( $user_id ), $lesson_ids );

    $completed = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE user_id = %d AND post_id IN ($placeholders) AND completed_at IS NOT NULL",
        $params
    ) );

    $pct = round( ( $completed / $total ) * 100 );
    return (string) $pct;
}
add_shortcode( 'snn_learn_progress', 'snn_learn_progress_shortcode' );


/**
 * [snn_learn_course_list] - Full chapter & lesson tree
 */
function snn_learn_course_list_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'course_id' => '' ), $atts, 'snn_learn_course_list' );

    $course_id = ! empty( $atts['course_id'] ) ? absint( $atts['course_id'] ) : snn_learn_get_course_id( get_the_ID() );
    $tree      = snn_learn_get_course_tree( $course_id );

    if ( empty( $tree ) ) return '';

    $user_id      = is_user_logged_in() ? get_current_user_id() : 0;
    $completed_ids = array();

    if ( $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'snn_learn_enrollments';
        $rows  = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM $table WHERE user_id = %d AND course_id = %d AND completed_at IS NOT NULL",
            $user_id, $course_id
        ) );
        $completed_ids = array_map( 'intval', $rows );
    }

    $current_post_id = get_the_ID();

    ob_start();
    ?>
    <div class="snn-learn-course-list">
    <?php foreach ( $tree as $branch ) : ?>
        <div class="snn-learn-chapter" data-chapter-id="<?php echo $branch['chapter']->ID; ?>">
            <div class="snn-learn-chapter-title"><?php echo esc_html( $branch['chapter']->post_title ); ?></div>
            <?php if ( ! empty( $branch['lessons'] ) ) : ?>
            <div class="snn-learn-lessons">
                <?php foreach ( $branch['lessons'] as $lesson ) :
                    $is_completed = in_array( $lesson->ID, $completed_ids, true );
                    $is_current   = ( $lesson->ID === $current_post_id );
                ?>
                <a
                    href="<?php echo get_permalink( $lesson->ID ); ?>"
                    class="snn-learn-lesson-item<?php echo $is_current ? ' snn-learn-lesson-current' : ''; ?>"
                    data-lesson-id="<?php echo $lesson->ID; ?>"
                >
                    <span class="snn-learn-lesson-status"><?php echo $is_completed ? '&#10003;' : '&#9675;'; ?></span>
                    <span class="snn-learn-lesson-title"><?php echo esc_html( $lesson->post_title ); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'snn_learn_course_list', 'snn_learn_course_list_shortcode' );


/**
 * [snn_learn_mark_completed] - Button for non-video lessons
 */
function snn_learn_mark_completed_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) return '';

    $atts = shortcode_atts( array(
        'text'           => 'Mark as Completed',
        'completed_text' => 'Completed ✓',
    ), $atts, 'snn_learn_mark_completed' );

    $post_id   = get_the_ID();
    $course_id = snn_learn_get_course_id( $post_id );
    $user_id   = get_current_user_id();

    // Check if already completed
    global $wpdb;
    $table = $wpdb->prefix . 'snn_learn_enrollments';
    $already = $wpdb->get_var( $wpdb->prepare(
        "SELECT completed_at FROM $table WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ) );
    $is_done = ! empty( $already );

    $uid = 'snn-mc-' . $post_id . '-' . wp_rand( 100, 999 );

    ob_start();
    ?>
    <button
        id="<?php echo $uid; ?>"
        class="snn-learn-mark-completed-btn"
        data-post-id="<?php echo $post_id; ?>"
        data-course-id="<?php echo $course_id; ?>"
        <?php echo $is_done ? 'disabled' : ''; ?>
    ><?php echo $is_done ? esc_html( $atts['completed_text'] ) : esc_html( $atts['text'] ); ?></button>

    <script>
    (function(){
        var btn = document.getElementById('<?php echo $uid; ?>');
        if(!btn || btn.disabled) return;
        btn.addEventListener('click', function(){
            if(typeof snnLearn === 'undefined' || !snnLearn.ajax_url) return;
            btn.disabled = true;
            btn.textContent = '...';
            var fd = new FormData();
            fd.append('action','snn_learn_complete_lesson');
            fd.append('nonce', snnLearn.nonce);
            fd.append('post_id', snnLearn.post_id);
            fd.append('course_id', snnLearn.course_id);
            fetch(snnLearn.ajax_url, {method:'POST', body:fd, credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(d){
                btn.textContent = <?php echo wp_json_encode( $atts['completed_text'] ); ?>;
            })
            .catch(function(){
                btn.textContent = 'Error';
                btn.disabled = false;
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'snn_learn_mark_completed', 'snn_learn_mark_completed_shortcode' );
