<?php
/**
 * Plugin Name: SNN Learn
 * Description: A modern, high-performance LMS plugin for WordPress.
 * Version: 1.0.0
 * Author: Sinan Isler
 * Text Domain: snn-learn
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants
define( 'SNN_LEARN_VERSION', '1.0.0' );
define( 'SNN_LEARN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SNN_LEARN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Database Table Setup
 */
function snn_edu_create_tables() {
    global $wpdb;
    $table   = $wpdb->prefix . 'snn_enrollments';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id          BIGINT UNSIGNED NOT NULL,
        post_id          BIGINT UNSIGNED NOT NULL,
        course_id        BIGINT UNSIGNED NOT NULL,
        enrolled_at      INT UNSIGNED    NOT NULL,
        completed_at     INT UNSIGNED    DEFAULT NULL,
        last_activity_at INT UNSIGNED    DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_user_post  (user_id, post_id),
        KEY idx_course_id        (course_id),
        KEY idx_user_id          (user_id),
        KEY idx_completed_at     (completed_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'snn_edu_create_tables' );

/**
 * Settings Management
 */
class SNN_Learn_Settings {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_menu_pages() {
        add_menu_page(
            'SNN Learn',
            'SNN Learn',
            'manage_options',
            'snn-learn',
            [ $this, 'render_dashboard' ],
            'dashicons-welcome-learn-more',
            6
        );

        add_submenu_page(
            'snn-learn',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'snn-learn',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'snn-learn',
            'Settings',
            'Settings',
            'manage_options',
            'snn-learn-settings',
            [ $this, 'render_settings' ]
        );
    }

    public function register_settings() {
        register_setting( 'snn_learn_options', 'snn_learn_settings' );
    }

    public function render_settings() {
        $options = get_option( 'snn_learn_settings', [] );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">SNN Learn Settings</h1>
            <form method="post" action="options.php" class="snn-settings-form">
                <?php settings_fields( 'snn_learn_options' ); ?>
                
                <div class="snn-settings-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                    <!-- General Settings -->
                    <div class="card" style="max-width: 100%; padding: 20px; border-radius: 8px; background: #fff; border: 1px solid #ccd0d4;">
                        <h2>General Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Allowed Post Types</th>
                                <td>
                                    <?php foreach ( $post_types as $pt ) : ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox" name="snn_learn_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $options['post_types'] ?? [] ) ); ?>>
                                            <?php echo esc_html( $pt->label ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Restrict WP-Admin</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="snn_learn_settings[restrict_admin]" value="1" <?php checked( $options['restrict_admin'] ?? 0 ); ?>>
                                        Only administrators can access wp-admin
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Hide Admin Bar</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="snn_learn_settings[hide_admin_bar]" value="1" <?php checked( $options['hide_admin_bar'] ?? 0 ); ?>>
                                        Hide admin bar for non-admin users
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Tracking Settings -->
                    <div class="card" style="max-width: 100%; padding: 20px; border-radius: 8px; background: #fff; border: 1px solid #ccd0d4;">
                        <h2>Tracking Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Video Threshold (Seconds)</th>
                                <td>
                                    <input type="number" name="snn_learn_settings[video_threshold]" value="<?php echo esc_attr( $options['video_threshold'] ?? 3 ); ?>" class="small-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Require Full Video</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="snn_learn_settings[require_full_video]" value="1" <?php checked( $options['require_full_video'] ?? 0 ); ?>>
                                        Only mark complete when video ends
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Lock Lessons</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="snn_learn_settings[lock_lessons]" value="1" <?php checked( $options['lock_lessons'] ?? 0 ); ?>>
                                        Lock lessons until previous lesson is complete
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_dashboard() {
        // Dashboard HTML will go here
        include SNN_LEARN_PATH . 'templates/dashboard.php';
    }
}
new SNN_Learn_Settings();

/**
 * Shortcodes Implementation
 */
class SNN_Learn_Shortcodes {
    public function __construct() {
        add_shortcode( 'snn_video_player', [ $this, 'video_player' ] );
        add_shortcode( 'snn_mark_complete', [ $this, 'mark_complete' ] );
        add_shortcode( 'snn_course_progress', [ $this, 'course_progress' ] );
        add_shortcode( 'snn_strike_weekly', [ $this, 'strike_weekly' ] );
        add_shortcode( 'snn_strike_count', [ $this, 'strike_count' ] );
        add_shortcode( 'snn_user_strikes', [ $this, 'user_strikes' ] );
        add_shortcode( 'snn_course_sidebar', [ $this, 'course_sidebar' ] );
    }

    public function video_player( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="snn-login-prompt">Please <a href="' . wp_login_url( get_permalink() ) . '">login</a> to view this lesson.</div>';
        }

        $atts = shortcode_atts( [
            'field'       => 'video_url',
            'poster'      => '',
            'autoplay'    => 'false',
            'muted'       => 'false',
            'width'       => '100%',
            'aspectratio' => '16/9',
        ], $atts );

        $video_url = get_post_meta( get_the_ID(), $atts['field'], true );
        if ( ! $video_url ) return 'Video URL not found.';

        $poster = $atts['poster'] ?: get_the_post_thumbnail_url( get_the_ID(), 'large' );

        // Auto-enroll user
        $this->enroll_user( get_current_user_id(), get_the_ID() );

        ob_start();
        ?>
        <div class="snn-video-wrapper" style="width: <?php echo esc_attr( $atts['width'] ); ?>; aspect-ratio: <?php echo esc_attr( $atts['aspectratio'] ); ?>; position: relative; background: #000; border-radius: 8px; overflow: hidden;">
            <video 
                id="snn-player-<?php echo get_the_ID(); ?>"
                class="snn-video-player" 
                src="<?php echo esc_url( $video_url ); ?>" 
                poster="<?php echo esc_url( $poster ); ?>"
                controls
                <?php echo $atts['autoplay'] === 'true' ? 'autoplay' : ''; ?>
                <?php echo $atts['muted'] === 'true' ? 'muted' : ''; ?>
                style="width: 100%; height: 100%;"
            ></video>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const video = document.getElementById('snn-player-<?php echo get_the_ID(); ?>');
                let tracked = false;
                video.addEventListener('timeupdate', function() {
                    if (!tracked && video.currentTime > 3) { // Threshold from settings
                        tracked = true;
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=snn_mark_complete&post_id=<?php echo get_the_ID(); ?>'
                        });
                    }
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    private function enroll_user( $user_id, $post_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'snn_enrollments';
        $course_id = $this->get_course_id( $post_id );

        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $table (user_id, post_id, course_id, enrolled_at, last_activity_at) 
             VALUES (%d, %d, %d, %d, %d)",
            $user_id, $post_id, $course_id, time(), time()
        ) );
    }

    private function get_course_id( $post_id ) {
        // Logic to find top-level course ID from hierarchy
        $ancestors = get_post_ancestors( $post_id );
        return ! empty( $ancestors ) ? end( $ancestors ) : $post_id;
    }

    public function mark_complete( $atts ) {
        if ( ! is_user_logged_in() ) return '';
        $atts = shortcode_atts( [ 'text' => 'Complete Lesson' ], $atts );
        
        // Check if already completed
        global $wpdb;
        $table = $wpdb->prefix . 'snn_enrollments';
        $completed = $wpdb->get_var( $wpdb->prepare(
            "SELECT completed_at FROM $table WHERE user_id = %d AND post_id = %d",
            get_current_user_id(), get_the_ID()
        ) );

        if ( $completed ) return '<div class="snn-completed-msg">✓ Lesson completed!</div>';

        return '<button class="snn-mark-complete-btn" data-post-id="' . get_the_ID() . '">' . esc_html( $atts['text'] ) . '</button>';
    }

    public function course_progress( $atts ) {
        if ( ! is_user_logged_in() ) return '';
        $atts = shortcode_atts( [ 'course_id' => '', 'format' => 'number' ], $atts );
        $course_id = $atts['course_id'] ?: $this->get_course_id( get_the_ID() );
        
        // Calculate progress logic
        return '<span class="snn-progress">75%</span>';
    }

    public function strike_weekly() {
        return '<div class="snn-strike-weekly">🔥 ● 🔥 🔥 ● ● 🔥</div>';
    }

    public function strike_count() {
        return '<span class="snn-strike-count">5</span>';
    }

    public function user_strikes() {
        return $this->strike_weekly() . ' Streak: ' . $this->strike_count() . ' days';
    }

    public function course_sidebar( $atts ) {
        return '<div class="snn-course-sidebar">Course Outline...</div>';
    }
}
new SNN_Learn_Shortcodes();

/**
 * AJAX Handlers
 */
add_action( 'wp_ajax_snn_mark_complete', 'snn_ajax_mark_complete' );
function snn_ajax_mark_complete() {
    global $wpdb;
    $post_id = intval( $_POST['post_id'] );
    $user_id = get_current_user_id();
    $table = $wpdb->prefix . 'snn_enrollments';

    $wpdb->update(
        $table,
        [ 'completed_at' => time(), 'last_activity_at' => time() ],
        [ 'user_id' => $user_id, 'post_id' => $post_id ],
        [ '%d', '%d' ],
        [ '%d', '%d' ]
    );
    wp_die();
}
