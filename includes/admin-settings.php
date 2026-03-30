<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function snn_learn_settings_page() {
    $options  = get_option( 'snn_learn_settings', array() );
    $defaults = snn_learn_defaults();
    $o        = wp_parse_args( $options, $defaults );

    $post_types = get_post_types( array( 'public' => true ), 'objects' );
    ?>
    <div class="wrap snn-learn-settings-wrap">
        <h1>SNN Learn Settings</h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'snn_learn_settings_group' ); ?>

            <table class="form-table">

                <tr>
                    <th scope="row">Course Post Type</th>
                    <td>
                        <select name="snn_learn_settings[post_type]" class="snn-learn-select">
                            <?php foreach ( $post_types as $pt ) : ?>
                                <option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $o['post_type'], $pt->name ); ?>>
                                    <?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Select the post type used for courses, chapters, and lessons.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Video Custom Field Slug</th>
                    <td>
                        <input type="text" name="snn_learn_settings[video_custom_field]" value="<?php echo esc_attr( $o['video_custom_field'] ); ?>" class="regular-text" />
                        <p class="description">The meta key/custom field name that contains the video URL for lessons.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" colspan="2"><h2 style="margin:0;padding:0;">Video Player Colors</h2></th>
                </tr>

                <tr>
                    <th scope="row">Player Accent Color</th>
                    <td>
                        <input type="color" name="snn_learn_settings[player_color]" value="<?php echo esc_attr( $o['player_color'] ); ?>" />
                        <code><?php echo esc_html( $o['player_color'] ); ?></code>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Player Background Color</th>
                    <td>
                        <input type="color" name="snn_learn_settings[player_bg_color]" value="<?php echo esc_attr( $o['player_bg_color'] ); ?>" />
                        <code><?php echo esc_html( $o['player_bg_color'] ); ?></code>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Player Text/Icon Color</th>
                    <td>
                        <input type="color" name="snn_learn_settings[player_text_color]" value="<?php echo esc_attr( $o['player_text_color'] ); ?>" />
                        <code><?php echo esc_html( $o['player_text_color'] ); ?></code>
                    </td>
                </tr>

                <tr>
                    <th scope="row" colspan="2"><h2 style="margin:0;padding:0;">Completion Settings</h2></th>
                </tr>

                <tr>
                    <th scope="row">Video Completion Mode</th>
                    <td>
                        <label>
                            <input type="radio" name="snn_learn_settings[completion_mode]" value="one_second" <?php checked( $o['completion_mode'], 'one_second' ); ?> />
                            Mark completed after watching X seconds (default: 1 second)
                        </label>
                        <br/>
                        <label>
                            <input type="radio" name="snn_learn_settings[completion_mode]" value="full_video" <?php checked( $o['completion_mode'], 'full_video' ); ?> />
                            Mark completed only when video finishes (ended event)
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Seconds Before Completion</th>
                    <td>
                        <input type="number" name="snn_learn_settings[completion_seconds]" value="<?php echo esc_attr( $o['completion_seconds'] ); ?>" min="1" step="1" style="width:80px;" />
                        <p class="description">Only used when "X seconds" mode is selected above.</p>
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
