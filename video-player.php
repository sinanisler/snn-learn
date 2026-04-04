<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
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
    <style>
    #<?= $uid ?>-wrap {
        --vp-primary : <?= $c_primary ?>;
        --vp-bg      : <?= $c_bg ?>;
        --vp-bg-fade : <?= $c_bg ?>e0;
        --vp-text    : <?= $c_text ?>;
    }
    .snn-video-player-wrap {
        position   : relative;
        width      : 100%;
        background : var(--vp-bg);
        overflow   : hidden;
        user-select: none;
        font-family: sans-serif;
    }
    .snn-video-player-wrap video {
        width  : 100%;
        display: block;
        cursor : pointer;
    }
    .snn-video-overlay {
        position  : absolute;
        top       : 0;
        left      : 0;
        right     : 0;
        bottom    : 0;
        z-index   : 1;
        cursor    : pointer;
    }
    .snn-video-controls {
        position  : absolute;
        bottom    : 0;
        left      : 0;
        right     : 0;
        padding   : 8px 12px 10px;
        background: linear-gradient(transparent, var(--vp-bg-fade));
        transition: opacity .3s;
        z-index   : 2;
    }
    .snn-video-seek {
        width         : 100%;
        height        : 6px;
        background    : rgba(255,255,255,0.4);
        border-radius : 3px;
        cursor        : pointer;
        position      : relative;
        margin-bottom : 8px;
    }
    .snn-video-progress {
        height        : 100%;
        width         : 0%;
        background    : var(--vp-primary);
        border-radius : 3px;
        pointer-events: none;
    }
    .snn-video-seek-thumb {
        position      : absolute;
        top           : 50%;
        left          : 0%;
        transform     : translate(-50%, -50%);
        font-size     : 11px;
        line-height   : 1;
        pointer-events: none;
        color         : var(--vp-primary);
    }
    .snn-video-btn-row {
        display    : flex;
        align-items: center;
        gap        : 10px;
    }
    .snn-video-play-btn,
    .snn-video-full-btn,
    .snn-video-vol-btn {
        background     : none;
        border         : none;
        cursor         : pointer;
        color          : var(--vp-text);
        padding        : 0;
        line-height    : 1;
        display        : flex;
        align-items    : center;
        justify-content: center;
        width          : 30px;
        height         : 30px;
    }
    .snn-video-spacer {
        flex: 1;
    }
    .snn-video-time {
        font-size           : 12px;
        color               : var(--vp-text);
        font-variant-numeric: tabular-nums;
        min-width           : 92px;
    }
    .snn-video-vol-wrap {
        display    : flex;
        align-items: center;
        gap        : 6px;
    }
    .snn-video-vol-range {
        -webkit-appearance: none;
        appearance        : none;
        width             : 70px;
        height            : 4px;
        border-radius     : 2px;
        background        : rgba(255,255,255,0.4);
        outline           : none;
        cursor            : pointer;
    }
    .snn-video-vol-range::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance        : none;
        width             : 12px;
        height            : 12px;
        border-radius     : 50%;
        background        : var(--vp-primary);
        cursor            : pointer;
    }
    .snn-video-vol-range::-moz-range-thumb {
        width        : 12px;
        height       : 12px;
        border-radius: 50%;
        background   : var(--vp-primary);
        border       : none;
        cursor       : pointer;
    }
    .snn-video-speed-wrap {
        position: relative;
    }
    .snn-video-speed-btn {
        background : none;
        border     : none;
        cursor     : pointer;
        color      : var(--vp-text);
        font-size  : 12px;
        font-weight: 600;
        padding    : 2px 6px;
        line-height: 1;
        border-radius: 3px;
        border     : 1px solid rgba(255,255,255,0.3);
    }
    .snn-video-speed-menu {
        display       : none;
        position      : absolute;
        bottom        : 28px;
        right         : 0;
        background    : rgba(15,23,42,0.95);
        border        : 1px solid rgba(255,255,255,0.15);
        border-radius : 6px;
        overflow      : hidden;
        min-width     : 70px;
    }
    .snn-video-speed-menu.open {
        display: block;
    }
    .snn-video-speed-opt {
        display    : block;
        width      : 100%;
        text-align : center;
        padding    : 7px 14px;
        background : none;
        border     : none;
        color      : var(--vp-text);
        font-size  : 13px;
        cursor     : pointer;
    }
    .snn-video-speed-opt:hover,
    .snn-video-speed-opt.active {
        background: var(--vp-primary);
        color     : #fff;
    }
    .snn-video-completed-badge {
        display      : none;
        position     : absolute;
        top          : 10px;
        right        : 10px;
        background   : var(--vp-primary);
        color        : var(--vp-text);
        border-radius: 20px;
        padding      : 4px 12px;
        font-size    : 12px;
        font-weight  : bold;
        z-index      : 3;
    }
    </style>

    <div id="<?= $uid ?>-wrap" class="snn-video-player-wrap">

        <video id="<?= $uid ?>" preload="metadata" playsinline></video>

        <!-- Overlay: blocks native right-click menu & video controls -->
        <div id="<?= $uid ?>-overlay" class="snn-video-overlay"></div>

        <!-- Controls Bar -->
        <div id="<?= $uid ?>-controls" class="snn-video-controls">

            <!-- Progress / Seek bar -->
            <div id="<?= $uid ?>-barwrap" class="snn-video-seek">
                <div id="<?= $uid ?>-bar"   class="snn-video-progress"></div>
                <div id="<?= $uid ?>-thumb" class="snn-video-seek-thumb">&#128280;</div>
            </div>

            <!-- Buttons row -->
            <div class="snn-video-btn-row">
                <button id="<?= $uid ?>-play" class="snn-video-play-btn" title="Play / Pause"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></button>
                <div class="snn-video-vol-wrap">
                    <button id="<?= $uid ?>-vol" class="snn-video-vol-btn" title="Mute / Unmute"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg></button>
                    <input id="<?= $uid ?>-volrange" class="snn-video-vol-range" type="range" min="0" max="1" step="0.05" value="1">
                </div>
                <span   id="<?= $uid ?>-time" class="snn-video-time">0:00 / 0:00</span>
                <div class="snn-video-spacer"></div>
                <div class="snn-video-speed-wrap">
                    <button id="<?= $uid ?>-speed" class="snn-video-speed-btn" title="Playback Speed">1x</button>
                    <div id="<?= $uid ?>-speedmenu" class="snn-video-speed-menu">
                        <button class="snn-video-speed-opt active" data-rate="1">1x</button>
                        <button class="snn-video-speed-opt" data-rate="1.2">1.2x</button>
                        <button class="snn-video-speed-opt" data-rate="1.5">1.5x</button>
                        <button class="snn-video-speed-opt" data-rate="2">2x</button>
                        <button class="snn-video-speed-opt" data-rate="4">4x</button>
                    </div>
                </div>
                <button id="<?= $uid ?>-full" class="snn-video-full-btn" title="Fullscreen"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg></button>
            </div>

        </div>

        <!-- Completed badge (always hidden in HTML; JS checks fresh status to defeat page cache) -->
        <div id="<?= $uid ?>-badge" class="snn-video-completed-badge">&#10003; Completed</div>

    </div>

    <script>
    (function () {
        var video     = document.getElementById('<?= $uid ?>');
        var wrap      = document.getElementById('<?= $uid ?>-wrap');
        var playBtn   = document.getElementById('<?= $uid ?>-play');
        var fullBtn   = document.getElementById('<?= $uid ?>-full');
        var bar       = document.getElementById('<?= $uid ?>-bar');
        var barWrap   = document.getElementById('<?= $uid ?>-barwrap');
        var thumb     = document.getElementById('<?= $uid ?>-thumb');
        var timeEl    = document.getElementById('<?= $uid ?>-time');
        var badge     = document.getElementById('<?= $uid ?>-badge');
        var controls  = document.getElementById('<?= $uid ?>-controls');
        var volBtn    = document.getElementById('<?= $uid ?>-vol');
        var volRange  = document.getElementById('<?= $uid ?>-volrange');
        var speedBtn  = document.getElementById('<?= $uid ?>-speed');
        var speedMenu = document.getElementById('<?= $uid ?>-speedmenu');
        var overlay   = document.getElementById('<?= $uid ?>-overlay');

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

        // Fetch completion status fresh â€” bypasses any cached HTML page
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
        function toggleFullscreen() { fullBtn.click(); }
        playBtn.addEventListener('click', togglePlay);

        // Overlay: single click = play/pause, double click = fullscreen, block right-click
        var overlayClickTimer = null;
        overlay.addEventListener('click', function () {
            if (overlayClickTimer) {
                clearTimeout(overlayClickTimer);
                overlayClickTimer = null;
                toggleFullscreen();
            } else {
                overlayClickTimer = setTimeout(function () {
                    overlayClickTimer = null;
                    togglePlay();
                }, 250);
            }
        });
        overlay.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        video.addEventListener('contextmenu',   function (e) { e.preventDefault(); });
        var SVG_PLAY  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
        var SVG_PAUSE = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
        var SVG_EXPAND  = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>';
        var SVG_COMPRESS = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>';
        video.addEventListener('play',   function () { playBtn.innerHTML = SVG_PAUSE; });
        video.addEventListener('pause',  function () { playBtn.innerHTML = SVG_PLAY; });
        video.addEventListener('ended',  function () {
            playBtn.innerHTML = SVG_PLAY;
            if (ON_END && !completed) markComplete();
        });

        // Time update â€” track cumulative watched time accurately
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

        // Seek â€” click
        barWrap.addEventListener('click', function (e) {
            if (!video.duration) return;
            var rect = barWrap.getBoundingClientRect();
            video.currentTime = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width)) * video.duration;
        });

        // Seek â€” drag
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

        // Volume â€” restore from localStorage
        var SVG_VOL_ON  = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg>';
        var SVG_VOL_OFF = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>';
        (function () {
            var savedVol = parseFloat(localStorage.getItem('snnvp_volume'));
            if (!isNaN(savedVol)) {
                video.volume = savedVol;
                volRange.value = savedVol;
                volBtn.innerHTML = savedVol === 0 ? SVG_VOL_OFF : SVG_VOL_ON;
            }
            var savedMute = localStorage.getItem('snnvp_muted');
            if (savedMute === '1') {
                video.muted = true;
                volBtn.innerHTML = SVG_VOL_OFF;
            }
        })();
        volRange.addEventListener('input', function () {
            var v = parseFloat(volRange.value);
            video.volume = v;
            video.muted  = (v === 0);
            volBtn.innerHTML = v === 0 ? SVG_VOL_OFF : SVG_VOL_ON;
            localStorage.setItem('snnvp_volume', v);
            localStorage.setItem('snnvp_muted', v === 0 ? '1' : '0');
        });
        volBtn.addEventListener('click', function () {
            video.muted = !video.muted;
            if (video.muted) {
                volBtn.innerHTML = SVG_VOL_OFF;
                localStorage.setItem('snnvp_muted', '1');
            } else {
                if (video.volume === 0) { video.volume = 1; volRange.value = 1; localStorage.setItem('snnvp_volume', 1); }
                volBtn.innerHTML = SVG_VOL_ON;
                localStorage.setItem('snnvp_muted', '0');
            }
        });
        video.addEventListener('volumechange', function () {
            volRange.value = video.muted ? 0 : video.volume;
        });

        // Speed â€” restore from localStorage
        var SPEEDS = [1, 1.2, 1.5, 2, 4];
        (function () {
            var savedRate = parseFloat(localStorage.getItem('snnvp_speed'));
            if (SPEEDS.indexOf(savedRate) !== -1) {
                video.playbackRate = savedRate;
                speedBtn.textContent = savedRate + 'x';
                speedMenu.querySelectorAll('.snn-video-speed-opt').forEach(function (o) {
                    o.classList.toggle('active', parseFloat(o.dataset.rate) === savedRate);
                });
            }
        })();
        speedBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            speedMenu.classList.toggle('open');
        });
        speedMenu.querySelectorAll('.snn-video-speed-opt').forEach(function (opt) {
            opt.addEventListener('click', function (e) {
                e.stopPropagation();
                var rate = parseFloat(opt.dataset.rate);
                video.playbackRate = rate;
                speedBtn.textContent = rate + 'x';
                localStorage.setItem('snnvp_speed', rate);
                speedMenu.querySelectorAll('.snn-video-speed-opt').forEach(function (o) {
                    o.classList.toggle('active', o === opt);
                });
                speedMenu.classList.remove('open');
            });
        });
        document.addEventListener('click', function () { speedMenu.classList.remove('open'); });

        // Fullscreen
        fullBtn.addEventListener('click', function () {
            if (document.fullscreenElement) {
                document.exitFullscreen();
                fullBtn.innerHTML = SVG_EXPAND;
            } else {
                wrap.requestFullscreen();
                fullBtn.innerHTML = SVG_COMPRESS;
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function (e) {
            var tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (!wrap.contains(video)) return;
            if (e.key === ' ' || e.code === 'Space') {
                e.preventDefault();
                togglePlay();
            } else if (e.key === 'm' || e.key === 'M') {
                volBtn.click();
            } else if (e.key === 'f' || e.key === 'F') {
                fullBtn.click();
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                video.currentTime = Math.min(video.duration || 0, video.currentTime + 10);
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                video.currentTime = Math.max(0, video.currentTime - 10);
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
