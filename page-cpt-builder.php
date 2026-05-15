<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =============================================================================
 * SNN LEARN — CPT / TAXONOMY / CUSTOM FIELDS BUILDER
 * page-cpt-builder.php
 * =============================================================================
 *
 * A native settings panel for creating and managing:
 *   1. Custom Post Types  (e.g. "course", "roadmap")
 *   2. Custom Taxonomies  (e.g. "Course Category", "topics", "skill-level")
 *   3. Custom Meta Fields (ACF-style field groups assigned to post types)
 *
 * Each CPT/taxonomy registration is stored as a JSON array in a single
 * WordPress option so it survives plugin updates and needs no extra table.
 *
 * On every save, `flush_rewrite_rules( true )` is called so URL changes take
 * effect immediately.
 */

// ============================================================
// UTILITIES
// ============================================================

/**
 * Return the full registry array from the option.
 * Structure:
 *   'post_types' => [ [ 'name' => 'Courses', 'slug' => 'course', ... ], ... ]
 *   'taxonomies' => [ [ 'name' => 'Course Category', 'slug' => 'courses', ... ], ... ]
 *   'field_groups' => [ [ 'group_name' => 'Course Fields', 'fields' => [ ... ], ... ], ... ]
 */
function snn_learn_cpt_get_registry() {
    return (array) get_option( 'snn_learn_cpt_registry', [
        'post_types'  => [],
        'taxonomies'   => [],
        'field_groups' => [],
    ] );
}

function snn_learn_cpt_save_registry( $registry ) {
    update_option( 'snn_learn_cpt_registry', (array) $registry );
    flush_rewrite_rules( true );
}

// Slug sanitisation (WordPress style)
function snn_learn_cpt_sanitize_slug( $slug ) {
    return sanitize_title( wp_unslash( $slug ) );
}

// Unique slug checker
function snn_learn_cpt_slug_unique( $slug, $existing, $ignore_index = null ) {
    foreach ( $existing as $i => $item ) {
        if ( $ignore_index !== null && (int) $i === $ignore_index ) {
            continue;
        }
        if ( isset( $item['slug'] ) && $item['slug'] === $slug ) {
            return false;
        }
    }
    return true;
}

// ============================================================
// DATA PERSISTENCE (handle form POST)
// ============================================================

add_action( 'admin_init', function () {
    if ( ! isset( $_POST['snn_learn_cpt_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_learn_cpt_nonce'] ) ), 'snn_learn_cpt_save' ) ) {
        return;
    }

    $reg = snn_learn_cpt_get_registry();

    // ---- Post Types ----
    if ( isset( $_POST['snn_cpt_post_types'] ) && is_array( $_POST['snn_cpt_post_types'] ) ) {
        $clean_pt = [];
        $raw_pts  = array_map( 'sanitize_text_field', wp_unslash( $_POST['snn_cpt_post_types'] ) );
        $raw_keys = array_map( 'sanitize_key', array_keys( $_POST['snn_cpt_post_types'] ) );

        foreach ( $raw_keys as $idx => $idx ) {
            $name     = $raw_pts[ $idx ]['name']     ?? '';
            $slug     = $raw_pts[ $idx ]['slug']     ?? '';
            $dashicon = $raw_pts[ $idx ]['dashicon'] ?? 'dashicons-admin-post';
            $hierarchical = ! empty( $raw_pts[ $idx ]['hierarchical'] );
            $show_in_rest = ! empty( $raw_pts[ $idx ]['show_in_rest'] );
            $has_archive  = ! empty( $raw_pts[ $idx ]['has_archive'] );
            $supports     = isset( $raw_pts[ $idx ]['supports'] ) && is_array( $raw_pts[ $idx ]['supports'] )
                ? array_map( 'sanitize_key', $raw_pts[ $idx ]['supports'] )
                : [ 'title', 'editor' ];

            if ( ! trim( $name ) || ! trim( $slug ) ) {
                continue;
            }
            $slug = snn_learn_cpt_sanitize_slug( $slug );
            if ( strlen( $slug ) > 20 ) {
                $slug = substr( $slug, 0, 20 );
            }
            if ( ! snn_learn_cpt_slug_unique( $slug, $clean_pt ) ) {
                continue;
            }

            $clean_pt[] = [
                'name'           => sanitize_text_field( $name ),
                'slug'           => $slug,
                'dashicon'       => sanitize_html_class( $dashicon ),
                'hierarchical'   => $hierarchical,
                'show_in_rest'   => $show_in_rest,
                'has_archive'    => $has_archive,
                'supports'       => $supports,
            ];
        }
        $reg['post_types'] = $clean_pt;
    } else {
        $reg['post_types'] = [];
    }

    // ---- Taxonomies ----
    if ( isset( $_POST['snn_cpt_taxonomies'] ) && is_array( $_POST['snn_cpt_taxonomies'] ) ) {
        $clean_tx = [];
        $raw_txs  = array_map( 'sanitize_text_field', wp_unslash( $_POST['snn_cpt_taxonomies'] ) );
        $raw_keys = array_map( 'sanitize_key', array_keys( $_POST['snn_cpt_taxonomies'] ) );

        foreach ( $raw_keys as $idx => $idx ) {
            $name           = $raw_txs[ $idx ]['name']           ?? '';
            $slug           = $raw_txs[ $idx ]['slug']           ?? '';
            $hierarchical   = ! empty( $raw_txs[ $idx ]['hierarchical'] );
            $post_types_str = $raw_txs[ $idx ]['post_types']     ?? '';
            $show_in_rest   = ! empty( $raw_txs[ $idx ]['show_in_rest'] );

            if ( ! trim( $name ) || ! trim( $slug ) ) {
                continue;
            }
            $slug = snn_learn_cpt_sanitize_slug( $slug );
            if ( ! snn_learn_cpt_slug_unique( $slug, $clean_tx ) ) {
                continue;
            }
            $post_types = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $post_types_str ) ) ) );

            $clean_tx[] = [
                'name'         => sanitize_text_field( $name ),
                'slug'         => $slug,
                'hierarchical' => $hierarchical,
                'post_types'   => $post_types,
                'show_in_rest' => $show_in_rest,
            ];
        }
        $reg['taxonomies'] = $clean_tx;
    } else {
        $reg['taxonomies'] = [];
    }

    // ---- Field Groups ----
    if ( isset( $_POST['snn_cpt_field_groups'] ) && is_array( $_POST['snn_cpt_field_groups'] ) ) {
        $clean_fg = [];
        $raw_fgs  = wp_unslash( $_POST['snn_cpt_field_groups'] );
        $raw_keys = array_map( 'sanitize_key', array_keys( $_POST['snn_cpt_field_groups'] ) );

        foreach ( $raw_keys as $idx => $idx ) {
            $group_name = sanitize_text_field( $raw_fgs[ $idx ]['group_name'] ?? '' );
            if ( ! trim( $group_name ) ) {
                continue;
            }

            $fields = [];
            if ( isset( $raw_fgs[ $idx ]['fields'] ) && is_array( $raw_fgs[ $idx ]['fields'] ) ) {
                foreach ( $raw_fgs[ $idx ]['fields'] as $field ) {
                    $field_slug = sanitize_title( wp_unslash( $field['slug'] ?? '' ) );
                    if ( ! trim( $field['label'] ?? '' ) || ! $field_slug ) {
                        continue;
                    }
                    $fields[] = [
                        'label'      => sanitize_text_field( $field['label'] ?? '' ),
                        'slug'       => $field_slug,
                        'type'       => sanitize_key( $field['type'] ?? 'text' ),
                        'width'      => max( 5, min( 100, (int) ( $field['width'] ?? 100 ) ) ),
                        'post_types' => isset( $field['post_types'] ) && is_array( $field['post_types'] )
                            ? array_map( 'sanitize_key', $field['post_types'] )
                            : [],
                        'options_page' => ! empty( $field['options_page'] ),
                        'repeater'     => ! empty( $field['repeater'] ),
                    ];
                }
            }

            if ( empty( $fields ) && ! empty( $group_name ) ) {
                continue;
            }

            $clean_fg[] = [
                'group_name' => $group_name,
                'fields'     => $fields,
            ];
        }
        $reg['field_groups'] = $clean_fg;
    } else {
        $reg['field_groups'] = [];
    }

    snn_learn_cpt_save_registry( $reg );

    wp_redirect( add_query_arg( 'saved', '1', wp_get_referer() ) );
    exit;
} );

// ============================================================
// ADMIN MENU REGISTRATION
// ============================================================

add_action( 'admin_menu', function () {
    add_submenu_page(
        'snn-learn',
        'Post Types & Taxonomies',
        'CPT Builder',
        'manage_options',
        'snn-learn-cpt',
        'snn_learn_cpt_builder_page'
    );
} );

// ============================================================
// FIELDS REGISTRATION (run on init)
// ============================================================

add_action( 'init', function () {
    $reg = snn_learn_cpt_get_registry();

    // ---- Register Post Types ----
    foreach ( (array) ( $reg['post_types'] ?? [] ) as $cpt ) {
        $slug = $cpt['slug'] ?? '';
        if ( ! $slug ) {
            continue;
        }

        $labels = [
            'name'          => $cpt['name']          ?? $slug,
            'singular_name' => $cpt['name']          ?? $slug,
        ];

        $args = [
            'labels'             => $labels,
            'public'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'      => ! empty( $cpt['show_in_rest'] ),
            'has_archive'       => ! empty( $cpt['has_archive'] ),
            'hierarchical'      => ! empty( $cpt['hierarchical'] ),
            'supports'          => ( $cpt['supports'] ?? [ 'title', 'editor' ] ) ?: [ 'title', 'editor' ],
            'capability_type'   => 'post',
            'map_meta_cap'      => true,
        ];

        // If dashicon starts with dashicons-, use that; otherwise allow any CSS class
        $dash = $cpt['dashicon'] ?? 'dashicons-admin-post';
        if ( strpos( $dash, 'dashicons' ) === 0 ) {
            $args['menu_icon'] = $dash;
        } else {
            $args['menu_icon'] = 'dashicons-admin-post';
        }

        register_post_type( $slug, $args );
    }

    // ---- Register Taxonomies ----
    foreach ( (array) ( $reg['taxonomies'] ?? [] ) as $tax ) {
        $slug = $tax['slug'] ?? '';
        if ( ! $slug ) {
            continue;
        }

        $labels = [
            'name'          => $tax['name']          ?? $slug,
            'singular_name' => $tax['name']          ?? $slug,
        ];

        $post_types = (array) ( $tax['post_types'] ?? [] );

        register_taxonomy( $slug, $post_types, [
            'labels'       => $labels,
            'hierarchical' => ! empty( $tax['hierarchical'] ),
            'show_in_rest' => ! empty( $tax['show_in_rest'] ),
            'public'       => true,
        ] );
    }
}, 5 );

// ============================================================
// META BOX REGISTRATION
// ============================================================

add_action( 'add_meta_boxes', function () {
    $reg = snn_learn_cpt_get_registry();

    foreach ( (array) ( $reg['field_groups'] ?? [] ) as $group ) {
        $group_name = $group['group_name'] ?? '';
        if ( ! $group_name ) {
            continue;
        }

        foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
            $field_slug  = $field['slug']  ?? '';
            $field_label = $field['label'] ?? $field_slug;
            $post_types = (array) ( $field['post_types'] ?? [] );

            if ( ! $field_slug ) {
                continue;
            }

            // Register meta key
            $meta_key    = 'snn_cpt_' . $field_slug;
            $field_type  = $field['type']  ?? 'text';
            $is_repeater = ! empty( $field['repeater'] );

            // Register meta for all assigned post types
            foreach ( $post_types as $pt ) {
                register_post_meta( $pt, $meta_key, [
                    'show_in_rest'    => true,
                    'single'          => ! $is_repeater,
                    'type'            => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ] );
            }

            // Add meta box to each assigned post type
            foreach ( $post_types as $pt ) {
                add_meta_box(
                    'snn_cpt_field_' . $field_slug,
                    esc_html( $field_label ),
                    function ( $post, $args ) use ( $meta_key, $field_type, $is_repeater, $field ) {
                        $value = get_post_meta( $post->ID, $meta_key, true );

                        if ( $field_type === 'true_false' ) :
                            ?>
                            <label>
                                <input type="checkbox" name="<?= esc_attr( $meta_key ) ?>" value="1" <?php checked( $value, '1' ); ?>>
                                <?= esc_html( $args['args']['label'] ) ?>
                            </label>
                            <?php
                        elseif ( $field_type === 'textarea' || $field_type === 'wysiwyg' ) :
                            ?>
                            <textarea name="<?= esc_attr( $meta_key ) ?>" rows="4" style="width:100%"><?= esc_textarea( $value ) ?></textarea>
                            <?php if ( $field_type === 'wysiwyg' ) : ?>
                                <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    var id = <?= wp_json_encode( $meta_key ) ?>;
                                    if (typeof wp.editor !== 'undefined') {
                                        wp.editor.initialize(id, {
                                            tinymce: true,
                                            quicktags: true
                                        });
                                    }
                                });
                                </script>
                            <?php endif;
                        elseif ( $field_type === 'media' ) :
                            ?>
                            <div class="snn-media-field-wrap">
                                <input type="hidden" name="<?= esc_attr( $meta_key ) ?>" id="<?= esc_attr( $meta_key ) ?>_id" value="<?= esc_attr( $value ) ?>">
                                <button type="button" class="button button-secondary snn-media-upload-btn" data-target="<?= esc_attr( $meta_key ) ?>_id">Upload / Select</button>
                                <?php if ( $value ) : ?>
                                    <img src="<?= esc_url( wp_get_attachment_url( $value ) ) ?>" style="max-height:80px;margin-top:8px;" alt="">
                                <?php endif; ?>
                            </div>
                            <script>
                            (function(){
                                document.querySelectorAll('.snn-media-upload-btn').forEach(function(btn){
                                    btn.addEventListener('click', function(e){
                                        e.preventDefault();
                                        var targetId = btn.dataset.target;
                                        var frame = wp.media({ multiple: false });
                                        frame.on('select', function(){
                                            var attachment = frame.state().get('selection').first().toJSON();
                                            document.getElementById(targetId).value = attachment.id;
                                        });
                                        frame.open();
                                    });
                                });
                            })();
                            </script>
                            <?php
                        elseif ( $field_type === 'double_text' ) :
                            $parts = $value ? explode('|', $value) : ['', ''];
                            ?>
                            <div style="display:flex;gap:8px">
                                <input type="text" name="<?= esc_attr( $meta_key ) ?>[]" value="<?= esc_attr( $parts[0] ?? '' ) ?>" placeholder="Label" style="flex:1">
                                <input type="text" name="<?= esc_attr( $meta_key ) ?>[]" value="<?= esc_attr( $parts[1] ?? '' ) ?>" placeholder="Value" style="flex:1">
                            </div>
                            <?php
                        else :
                            ?>
                            <input type="text" name="<?= esc_attr( $meta_key ) ?>" value="<?= esc_attr( $value ) ?>" style="width:100%">
                            <?php
                        endif;
                    },
                    $pt,
                    'normal',
                    'default',
                    [ 'label' => $field_label ]
                );
            }

            // Options page support (creates a top-level admin page for the group)
            if ( ! empty( $field['options_page'] ) ) {
                add_action( 'admin_menu', function () use ( $group_name, $field_slug, $field_label, $field_type, $field ) {
                    add_menu_page(
                        esc_html( $group_name ),
                        esc_html( $field_label ),
                        'manage_options',
                        'snn_cpt_opts_' . $field_slug,
                        function () use ( $field_slug, $field_label, $field_type, $field ) {
                            $opt_key = 'snn_cpt_opt_' . $field_slug;
                            $value   = get_option( $opt_key, '' );
                            ?>
                            <div class="wrap">
                                <h1><?= esc_html( $field_label ) ?></h1>
                                <?php if ( isset( $_POST['snn_cpt_opt_save_' . $field_slug] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'snn_cpt_opt_save_' . $field_slug ) ) : ?>
                                    <?php
                                    $new_val = sanitize_text_field( wp_unslash( $_POST[ $opt_key ] ?? '' ) );
                                    update_option( $opt_key, $new_val );
                                    ?>
                                    <div class="notice notice-success"><p><strong>Saved.</strong></p></div>
                                <?php endif; ?>
                                <form method="post" style="max-width:600px">
                                    <?php wp_nonce_field( 'snn_cpt_opt_save_' . $field_slug, '_wpnonce' ); ?>
                                    <?php if ( $field_type === 'textarea' ) : ?>
                                        <textarea name="<?= esc_attr( $opt_key ) ?>" rows="5" style="width:100%"><?= esc_textarea( $value ) ?></textarea>
                                    <?php elseif ( $field_type === 'true_false' ) : ?>
                                        <label><input type="checkbox" name="<?= esc_attr( $opt_key ) ?>" value="1" <?php checked( $value, '1' ); ?>> Yes</label>
                                    <?php else : ?>
                                        <input type="text" name="<?= esc_attr( $opt_key ) ?>" value="<?= esc_attr( $value ) ?>" style="width:100%">
                                    <?php endif; ?>
                                    <p style="margin-top:12px"><button type="submit" class="button button-primary" name="snn_cpt_opt_save_<?= esc_attr( $field_slug ) ?>">Save</button></p>
                                </form>
                            </div>
                            <?php
                        },
                        'dashicons-admin-generic',
                        80
                    );
                } );
            }
        }
    }
} );

// ============================================================
// SAVE POST META
// ============================================================

add_action( 'save_post', function ( $post_id ) {
    if ( ! isset( $_POST['snn_cpt_save_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_cpt_save_meta_nonce'] ) ), 'snn_cpt_save_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    $reg = snn_learn_cpt_get_registry();
    foreach ( (array) ( $reg['field_groups'] ?? [] ) as $group ) {
        foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
            $meta_key = 'snn_cpt_' . ( $field['slug'] ?? '' );
            if ( ! $meta_key || ! isset( $_POST[ $meta_key ] ) ) {
                continue;
            }

            if ( $field['type'] === 'double_text' && is_array( $_POST[ $meta_key ] ) ) {
                $clean = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $meta_key ] ) );
                update_post_meta( $post_id, $meta_key, implode( '|', $clean ) );
            } elseif ( $field['type'] === 'true_false' ) {
                update_post_meta( $post_id, $meta_key, isset( $_POST[ $meta_key ] ) ? '1' : '' );
            } else {
                $raw = is_array( $_POST[ $meta_key ] )
                    ? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $meta_key ] ) )
                    : sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) );
                update_post_meta( $post_id, $meta_key, $raw );
            }
        }
    }
} );

// ============================================================
// OPTIONS PAGE REGISTRY HELPER (so other code can read options easily)
// ============================================================

function snn_learn_cpt_get_option( $slug ) {
    return get_option( 'snn_cpt_opt_' . sanitize_title( $slug ), '' );
}

// ============================================================
// ADMIN PAGE RENDER
// ============================================================

function snn_learn_cpt_builder_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $reg            = snn_learn_cpt_get_registry();
    $post_types     = (array) ( $reg['post_types']  ?? [] );
    $taxonomies     = (array) ( $reg['taxonomies']   ?? [] );
    $field_groups   = (array) ( $reg['field_groups'] ?? [] );
    $all_registered = get_post_types( [ 'public' => true ] );
    $tax_obj_types  = get_taxonomies( [ 'public' => true ], 'objects' );

    $saved = isset( $_GET['saved'] );
    ?>
    <div class="snn-learn-cpt wrap">
        <h1>SNN Learn — CPT Builder</h1>
        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>Settings saved and rewrite rules flushed.</strong></p></div>
        <?php endif; ?>

        <form method="post" action="" id="snn-cpt-form">

            <!-- ===== Post Types Section ===== -->
            <div class="snn-cpt-section" style="background:#fff;border-radius:8px;padding:24px;margin-bottom:24px">
                <h2 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#111827">Custom Post Types</h2>
                <p style="margin:0 0 16px;color:#6b7280;font-size:13px">Define one or more custom post types. The first one becomes your default LMS post type.</p>

                <div id="snn-cpt-list-post-types">
                    <?php foreach ( $post_types as $i => $pt ) : ?>
                    <div class="snn-cpt-row" data-row-index="<?= (int) $i ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:14px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:10px">
                        <input type="hidden" name="snn_cpt_post_types[<?= (int) $i ?>][name]"     value="<?= esc_attr( $pt['name'] ?? '' ) ?>">
                        <input type="hidden" name="snn_cpt_post_types[<?= (int) $i ?>][slug]"     value="<?= esc_attr( $pt['slug'] ?? '' ) ?>">
                        <input type="hidden" name="snn_cpt_post_types[<?= (int) $i ?>][dashicon]" value="<?= esc_attr( $pt['dashicon'] ?? '' ) ?>">
                        <input type="hidden" name="snn_cpt_post_types[<?= (int) $i ?>][hierarchical]" value="<?= ! empty( $pt['hierarchical'] ) ? '1' : '' ?>">
                        <input type="hidden" name="snn_cpt_post_types[<?= (int) $i ?> ?>][show_in_rest]" value="<?= ! empty( $pt['show_in_rest'] ) ? '1' : '' ?>">
                        <input type="hidden" name="snn_cpt_post_types[<?= (int) $i ?>][has_archive]" value="<?= ! empty( $pt['has_archive'] ) ? '1' : '' ?>">
                        <input type="hidden" name="snn_cpt_post_types[<?= (int) $i ?>][supports]"   value="<?= esc_attr( implode( ',', (array) ( $pt['supports'] ?? [] ) ) ) ?>">

                        <span style="min-width:180px;font-weight:600;color:#374151"><?= esc_html( $pt['name'] ?? '' ) ?></span>
                        <code style="color:#6b7280;font-size:12px"><?= esc_html( $pt['slug'] ?? '' ) ?></code>
                        <button type="button" class="button snn-cpt-remove" style="margin-left:auto;color:#dc2626;border-color:#fca5a5">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="button button-secondary" id="snn-cpt-add-post-type" style="margin-top:8px">+ Add Post Type</button>
            </div>

            <!-- ===== Taxonomies Section ===== -->
            <div class="snn-cpt-section" style="background:#fff;border-radius:8px;padding:24px;margin-bottom:24px">
                <h2 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#111827">Custom Taxonomies</h2>
                <p style="margin:0 0 16px;color:#6b7280;font-size:13px">Register custom taxonomies and link them to your post types.</p>

                <div id="snn-cpt-list-taxonomies">
                    <?php foreach ( $taxonomies as $i => $tx ) : ?>
                    <div class="snn-cpt-row" data-row-index="<?= (int) $i ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:14px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:10px">
                        <input type="hidden" name="snn_cpt_taxonomies[<?= (int) $i ?>][name]"     value="<?= esc_attr( $tx['name'] ?? '' ) ?>">
                        <input type="hidden" name="snn_cpt_taxonomies[<?= (int) $i ?>][slug]"     value="<?= esc_attr( $tx['slug'] ?? '' ) ?>">
                        <input type="hidden" name="snn_cpt_taxonomies[<?= (int) $i ?>][hierarchical]" value="<?= ! empty( $tx['hierarchical'] ) ? '1' : '' ?>">
                        <input type="hidden" name="snn_cpt_taxonomies[<?= (int) $i ?>][post_types]" value="<?= esc_attr( implode( ',', (array) ( $tx['post_types'] ?? [] ) ) ) ?>">
                        <input type="hidden" name="snn_cpt_taxonomies[<?= (int) $i ?>][show_in_rest]" value="<?= ! empty( $tx['show_in_rest'] ) ? '1' : '' ?>">

                        <span style="min-width:180px;font-weight:600;color:#374151"><?= esc_html( $tx['name'] ?? '' ) ?></span>
                        <code style="color:#6b7280;font-size:12px"><?= esc_attr( $tx['slug'] ?? '' ) ?></code>
                        <span style="font-size:11px;color:#9ca3af"><?= ! empty( $tx['hierarchical'] ) ? 'Hierarchical' : 'Flat' ?></span>
                        <button type="button" class="button snn-cpt-remove" style="margin-left:auto;color:#dc2626;border-color:#fca5a5">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="button button-secondary" id="snn-cpt-add-taxonomy" style="margin-top:8px">+ Add Taxonomy</button>
            </div>

            <!-- ===== Custom Fields Section ===== -->
            <div class="snn-cpt-section" style="background:#fff;border-radius:8px;padding:24px;margin-bottom:24px">
                <h2 style="margin:0 0 4px;font-size:16px;font-weight:700;color:#111827">Custom Fields</h2>
                <p style="margin:0 0 16px;color:#6b7280;font-size:13px">
                    Define meta fields and assign them to post types. Checking <strong>Options Page</strong> creates a standalone admin page.
                    Checking <strong>Repeater</strong> stores the field as an array (useful for multi-value fields).
                </p>

                <div id="snn-cpt-list-fields">
                    <?php foreach ( $field_groups as $gi => $group ) : ?>
                    <div class="snn-fg-block" data-group-index="<?= (int) $gi ?>" style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px;padding:16px;margin-bottom:16px">
                        <div style="display:flex;gap:12px;align-items:center;margin-bottom:14px">
                            <input type="text" name="snn_cpt_field_groups[<?= (int) $gi ?>][group_name]" value="<?= esc_attr( $group['group_name'] ?? '' ) ?>" placeholder="Group Name (e.g. Course Fields)" style="flex:1;max-width:300px" class="snn-fg-group-name">
                            <button type="button" class="button button-secondary snn-fg-add-field" style="white-space:nowrap">+ Add Field</button>
                            <button type="button" class="button snn-cpt-remove" style="color:#dc2626;border-color:#fca5a5">Remove Group</button>
                        </div>

                        <div class="snn-fg-fields-list">
                            <?php foreach ( (array) ( $group['fields'] ?? [] ) as $fi => $field ) : ?>
                            <div class="snn-fg-field-row" data-field-index="<?= (int) $fi ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px">
                                <input type="hidden" name="snn_cpt_field_groups[<?= (int) $gi ?>][fields][<?= (int) $fi ?>][label]"      value="<?= esc_attr( $field['label'] ?? '' ) ?>">
                                <input type="hidden" name="snn_cpt_field_groups[<?= (int) $gi ?>][fields][<?= (int) $fi ?>][slug]"      value="<?= esc_attr( $field['slug'] ?? '' ) ?>">
                                <input type="hidden" name="snn_cpt_field_groups[<?= (int) $gi ?>][fields][<?= (int) $fi ?>][type]"      value="<?= esc_attr( $field['type'] ?? 'text' ) ?>">
                                <input type="hidden" name="snn_cpt_field_groups[<?= (int) $gi ?>][fields][<?= (int) $fi ?>][width]"      value="<?= (int) ( $field['width'] ?? 100 ) ?>">
                                <input type="hidden" name="snn_cpt_field_groups[<?= (int) $gi ?>][fields][<?= (int) $fi ?>][post_types]" value="<?= esc_attr( implode( ',', (array) ( $field['post_types'] ?? [] ) ) ) ?>">
                                <input type="hidden" name="snn_cpt_field_groups[<?= (int) $gi ?>][fields][<?= (int) $fi ?>][options_page]" value="<?= ! empty( $field['options_page'] ) ? '1' : '' ?>">
                                <input type="hidden" name="snn_cpt_field_groups[<?= (int) $gi ?>][fields][<?= (int) $fi ?>][repeater]"     value="<?= ! empty( $field['repeater'] ) ? '1' : '' ?>">

                                <span style="min-width:140px;font-weight:600;color:#374151"><?= esc_html( $field['label'] ?? '' ) ?></span>
                                <code style="color:#6b7280;font-size:11px"><?= esc_html( $field['slug'] ?? '' ) ?></code>
                                <span style="font-size:11px;color:#9ca3af;background:#e5e7eb;padding:2px 6px;border-radius:4px"><?= esc_html( $field['type'] ?? 'text' ) ?></span>
                                <span style="font-size:11px;color:#6b7280"><?= (int) ( $field['width'] ?? 100 ) ?>%</span>
                                <button type="button" class="button button-secondary snn-fg-remove-field" style="margin-left:auto;color:#dc2626;border-color:#fca5a5;font-size:11px;padding:0 6px;height:auto">&#10005;</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="button button-secondary" id="snn-cpt-add-field-group" style="margin-top:8px">+ Add Field Group</button>
            </div>

            <?php wp_nonce_field( 'snn_learn_cpt_save', 'snn_learn_cpt_nonce' ); ?>
            <input type="hidden" name="snn_cpt_save_meta_nonce" value="<?= esc_attr( wp_create_nonce( 'snn_cpt_save_meta' ) ) ?>">
            <?php submit_button( 'Save CPT Builder' ); ?>

        </form>
    </div>

    <script>
    (function() {
        'use strict';

        var counterPT  = <?= count( $post_types ) ?>;
        var counterTX  = <?= count( $taxonomies ) ?>;
        var counterFG  = <?= count( $field_groups ) ?>;
        var allPT      = <?= wp_json_encode( array_keys( $all_registered ) ) ?>;
        var allTax     = <?= wp_json_encode( array_keys( $tax_obj_types ) ) ?>;
        var fieldTypes = ['text','textarea','wysiwyg','double_text','true_false','media'];

        // ---- Post Type templates ----
        var ptTemplates = <?= wp_json_encode( array_map( function($pt){ return ['name'=>$pt['name']??'','slug'=>$pt['slug']??'','dashicon'=>$pt['dashicon']??'','hierarchical'=>!empty($pt['hierarchical']),'show_in_rest'=>!empty($pt['show_in_rest']),'has_archive'=>!empty($pt['has_archive']),'supports'=>$pt['supports']??['title','editor']]; }, $post_types ) ) ?>;

        function renderPTField(name, value, extra) {
            return '<input type="text" name="snn_cpt_post_types[' + counterPT + '][' + name + ']" value="' + (value||'') + '"' + (extra||'') + '>';
        }

        document.getElementById('snn-cpt-add-post-type').addEventListener('click', function() {
            var container = document.getElementById('snn-cpt-list-post-types');
            var html = '<div class="snn-cpt-row" data-row-index="' + counterPT + '" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:14px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:10px">';
            html += '<div style="display:flex;flex-direction:column;gap:8px;flex:1">';
            html += '<div style="display:flex;gap:8px;align-items:center"><label style="min-width:80px;font-size:12px;color:#6b7280">Name</label>' + renderPTField('name') + '</div>';
            html += '<div style="display:flex;gap:8px;align-items:center"><label style="min-width:80px;font-size:12px;color:#6b7280">Slug (max 20)</label>' + renderPTField('slug','','maxlength="20"') + '</div>';
            html += '<div style="display:flex;gap:8px;align-items:center"><label style="min-width:80px;font-size:12px;color:#6b7280">Dashicon</label><input type="text" name="snn_cpt_post_types[' + counterPT + '][dashicon]" value="dashicons-admin-post" style="width:200px"></div>';
            html += '<div style="display:flex;gap:8px;align-items:center"><label style="min-width:80px;font-size:12px;color:#6b7280">Supports</label>';
            html += '<label style="font-size:12px"><input type="checkbox" name="snn_cpt_post_types[' + counterPT + '][supports][]" value="title" checked> Title</label>';
            html += '<label style="font-size:12px"><input type="checkbox" name="snn_cpt_post_types[' + counterPT + '][supports][]" value="editor" checked> Editor</label>';
            html += '<label style="font-size:12px"><input type="checkbox" name="snn_cpt_post_types[' + counterPT + '][supports][]" value="thumbnail"> Thumbnail</label>';
            html += '<label style="font-size:12px"><input type="checkbox" name="snn_cpt_post_types[' + counterPT + '][supports][]" value="excerpt"> Excerpt</label>';
            html += '<label style="font-size:12px"><input type="checkbox" name="snn_cpt_post_types[' + counterPT + '][supports][]" value="page-attributes"> Page Attrs</label>';
            html += '</div></div>';
            html += '<div style="display:flex;flex-direction:column;gap:6px">';
            html += '<label style="font-size:12px;display:flex;align-items:center;gap:4px"><input type="checkbox" name="snn_cpt_post_types[' + counterPT + '][hierarchical]" value="1"> Hierarchical</label>';
            html += '<label style="font-size:12px;display:flex;align-items:center;gap:4px"><input type="checkbox" name="snn_cpt_post_types[' + counterPT + '][show_in_rest]" value="1" checked> REST</label>';
            html += '<label style="font-size:12px;display:flex;align-items:center;gap:4px"><input type="checkbox" name="snn_cpt_post_types[' + counterPT + '][has_archive]" value="1"> Archive</label>';
            html += '</div>';
            html += '<button type="button" class="button snn-cpt-remove" style="color:#dc2626;border-color:#fca5a5;align-self:flex-start">Remove</button>';
            html += '</div>';
            container.insertAdjacentHTML('beforeend', html);
            counterPT++;
        });

        // ---- Taxonomy templates ----
        document.getElementById('snn-cpt-add-taxonomy').addEventListener('click', function() {
            var container = document.getElementById('snn-cpt-list-taxonomies');
            var html = '<div class="snn-cpt-row" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:14px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:10px">';
            html += '<div style="display:flex;flex-direction:column;gap:8px;flex:1">';
            html += '<div style="display:flex;gap:8px;align-items:center"><label style="min-width:80px;font-size:12px;color:#6b7280">Name</label><input type="text" name="snn_cpt_taxonomies[' + counterTX + '][name]" value="" style="flex:1;max-width:220px"></div>';
            html += '<div style="display:flex;gap:8px;align-items:center"><label style="min-width:80px;font-size:12px;color:#6b7280">Slug</label><input type="text" name="snn_cpt_taxonomies[' + counterTX + '][slug]" value="" style="flex:1;max-width:220px"></div>';
            html += '<div style="display:flex;gap:8px;align-items:center"><label style="min-width:80px;font-size:12px;color:#6b7280">Post Types</label>';
            html += '<select name="snn_cpt_taxonomies[' + counterTX + '][post_types]" multiple style="min-width:160px;height:80px">';
            allPT.forEach(function(p){ html += '<option value="'+p+'" selected>'+p+'</option>'; });
            html += '</select><span style="font-size:11px;color:#9ca3af">Hold Ctrl/Cmd to select multiple</span></div>';
            html += '</div>';
            html += '<div style="display:flex;flex-direction:column;gap:6px">';
            html += '<label style="font-size:12px;display:flex;align-items:center;gap:4px"><input type="checkbox" name="snn_cpt_taxonomies[' + counterTX + '][hierarchical]" value="1" checked> Hierarchical</label>';
            html += '<label style="font-size:12px;display:flex;align-items:center;gap:4px"><input type="checkbox" name="snn_cpt_taxonomies[' + counterTX + '][show_in_rest]" value="1" checked> REST</label>';
            html += '</div>';
            html += '<button type="button" class="button snn-cpt-remove" style="color:#dc2626;border-color:#fca5a5;align-self:flex-start">Remove</button>';
            html += '</div>';
            container.insertAdjacentHTML('beforeend', html);
            counterTX++;
        });

        // ---- Field Group templates ----
        document.getElementById('snn-cpt-add-field-group').addEventListener('click', function() {
            var container = document.getElementById('snn-cpt-list-fields');
            var gi = counterFG;
            var html = '<div class="snn-fg-block" data-group-index="' + gi + '" style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px;padding:16px;margin-bottom:16px">';
            html += '<div style="display:flex;gap:12px;align-items:center;margin-bottom:14px">';
            html += '<input type="text" name="snn_cpt_field_groups[' + gi + '][group_name]" value="" placeholder="Group Name (e.g. Course Fields)" style="flex:1;max-width:300px" class="snn-fg-group-name">';
            html += '<button type="button" class="button button-secondary snn-fg-add-field" style="white-space:nowrap">+ Add Field</button>';
            html += '<button type="button" class="button snn-cpt-remove" style="color:#dc2626;border-color:#fca5a5">Remove Group</button>';
            html += '</div>';
            html += '<div class="snn-fg-fields-list"></div>';
            html += '</div>';
            container.insertAdjacentHTML('beforeend', html);
            counterFG++;
        });

        // ---- Add field to a group (delegated) ----
        document.getElementById('snn-cpt-list-fields').addEventListener('click', function(e) {
            if (!e.target.classList.contains('snn-fg-add-field')) return;
            var block = e.target.closest('.snn-fg-block');
            var gi    = parseInt(block.dataset.groupIndex || 0);
            var fl    = block.querySelector('.snn-fg-fields-list').children.length;
            var fi    = fl;

            var typeOptions = fieldTypes.map(function(t){
                return '<option value="'+t+'">'+t+'</option>';
            }).join('');

            var ptOptions = allPT.map(function(p){
                return '<option value="'+p+'" selected>'+p+'</option>';
            }).join('');

            var html = '<div class="snn-fg-field-row" data-field-index="'+fi+'" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px">';
            html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;flex:1">';
            html += '<div><label style="font-size:11px;color:#6b7280">Label</label><input type="text" name="snn_cpt_field_groups[' + gi + '][fields][' + fi + '][label]" value="" style="width:100%"></div>';
            html += '<div><label style="font-size:11px;color:#6b7280">Slug</label><input type="text" name="snn_cpt_field_groups[' + gi + '][fields][' + fi + '][slug]" value="" style="width:100%"></div>';
            html += '<div><label style="font-size:11px;color:#6b7280">Type</label><select name="snn_cpt_field_groups[' + gi + '][fields][' + fi + '][type]" style="width:100%">' + typeOptions + '</select></div>';
            html += '<div><label style="font-size:11px;color:#6b7280">Width (%)</label><input type="number" name="snn_cpt_field_groups[' + gi + '][fields][' + fi + '][width]" value="100" min="5" max="100" style="width:100%"></div>';
            html += '<div style="grid-column:1/-1"><label style="font-size:11px;color:#6b7280">Post Types</label><select name="snn_cpt_field_groups[' + gi + '][fields][' + fi + '][post_types]" multiple style="width:100%;height:60px">' + ptOptions + '</select></div>';
            html += '<div style="grid-column:1/-1;display:flex;gap:12px">';
            html += '<label style="font-size:12px;display:flex;align-items:center;gap:4px"><input type="checkbox" name="snn_cpt_field_groups[' + gi + '][fields][' + fi + '][options_page]" value="1"> Options Page</label>';
            html += '<label style="font-size:12px;display:flex;align-items:center;gap:4px"><input type="checkbox" name="snn_cpt_field_groups[' + gi + '][fields][' + fi + '][repeater]" value="1"> Repeater</label>';
            html += '</div>';
            html += '</div>';
            html += '<button type="button" class="button button-secondary snn-fg-remove-field" style="color:#dc2626;border-color:#fca5a5;font-size:11px;padding:0 6px;height:auto;align-self:flex-start">&#10005;</button>';
            html += '</div>';
            block.querySelector('.snn-fg-fields-list').insertAdjacentHTML('beforeend', html);
        });

        // ---- Remove row / group ----
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('snn-cpt-remove')) {
                var row = e.target.closest('.snn-cpt-row,.snn-fg-block');
                if (row) row.remove();
            }
            if (e.target.classList.contains('snn-fg-remove-field')) {
                var frow = e.target.closest('.snn-fg-field-row');
                if (frow) frow.remove();
            }
        });

    })();
    </script>
    <?php
}