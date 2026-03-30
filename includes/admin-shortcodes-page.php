<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function snn_learn_shortcodes_page() {
    $shortcodes = array(
        array(
            'name'        => 'snn_learn_video_player',
            'shortcode'   => '[snn_learn_video_player]',
            'description' => 'Renders a custom HTML5 video player. Uses the video URL from the custom field configured in Settings. Auto-enrolls and marks lesson completed based on watch progress.',
            'attributes'  => array(
                'src'   => 'Override video URL (optional, defaults to custom field value)',
            ),
        ),
        array(
            'name'        => 'snn_learn_progress',
            'shortcode'   => '[snn_learn_progress]',
            'description' => 'Outputs the current user\'s completion percentage for the current course as a plain number (e.g. "75"). Perfect for use inside loops.',
            'attributes'  => array(
                'course_id' => 'Override course ID (optional, defaults to current post\'s grandparent)',
            ),
        ),
        array(
            'name'        => 'snn_learn_course_list',
            'shortcode'   => '[snn_learn_course_list]',
            'description' => 'Outputs the full chapter and lesson list for the current course. Chapters are non-clickable headings, lessons are links. Shows completion status per lesson.',
            'attributes'  => array(
                'course_id' => 'Override course ID (optional, defaults to current post\'s grandparent)',
            ),
        ),
        array(
            'name'        => 'snn_learn_mark_completed',
            'shortcode'   => '[snn_learn_mark_completed]',
            'description' => 'Renders a "Mark as Completed" button for non-video lessons (docs, articles, snippets). Clicking marks the current lesson as completed for the logged-in user.',
            'attributes'  => array(
                'text'           => 'Button label (default: "Mark as Completed")',
                'completed_text' => 'Label after completion (default: "Completed ✓")',
            ),
        ),
    );
    ?>
    <div class="wrap snn-learn-shortcodes-wrap">
        <h1>SNN Learn Shortcodes</h1>
        <p style="color:#64748b;margin-bottom:24px;">Click any shortcode to copy it to your clipboard.</p>

        <div class="snn-learn-shortcode-grid" style="display:grid;grid-template-columns:1fr;gap:20px;max-width:800px;">
        <?php foreach ( $shortcodes as $sc ) : ?>
            <div class="snn-learn-shortcode-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">

                <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                    <h3 style="margin:0;font-size:16px;font-weight:600;"><?php echo esc_html( $sc['name'] ); ?></h3>
                    <code
                        class="snn-learn-copy-shortcode"
                        style="background:#f1f5f9;padding:4px 12px;border-radius:6px;cursor:pointer;font-size:13px;border:1px solid #e2e8f0;user-select:all;"
                        data-copy="<?php echo esc_attr( $sc['shortcode'] ); ?>"
                        title="Click to copy"
                    ><?php echo esc_html( $sc['shortcode'] ); ?></code>
                </div>

                <p style="margin:0 0 12px;font-size:14px;color:#475569;"><?php echo esc_html( $sc['description'] ); ?></p>

                <?php if ( ! empty( $sc['attributes'] ) ) : ?>
                <div style="margin-top:8px;">
                    <div style="font-size:12px;font-weight:600;color:#94a3b8;margin-bottom:6px;">ATTRIBUTES</div>
                    <?php foreach ( $sc['attributes'] as $attr => $desc ) : ?>
                    <div style="font-size:13px;margin-bottom:4px;">
                        <code style="background:#f8fafc;padding:1px 6px;border-radius:4px;font-size:12px;"><?php echo esc_html( $attr ); ?></code>
                        <span style="color:#64748b;margin-left:4px;"><?php echo esc_html( $desc ); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>
        <?php endforeach; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('.snn-learn-copy-shortcode').forEach(function(el){
                el.addEventListener('click', function(){
                    var text = this.getAttribute('data-copy');
                    if(navigator.clipboard){
                        navigator.clipboard.writeText(text);
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                    }
                    var orig = this.textContent;
                    this.textContent = 'Copied!';
                    var self = this;
                    setTimeout(function(){ self.textContent = orig; }, 1200);
                });
            });
        });
        </script>
    </div>
    <?php
}
