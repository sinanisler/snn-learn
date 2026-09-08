<?php
/**
 * SNN Learn — Course Fields
 *
 * A small custom-fields registry: define field groups in the admin, have them
 * render as meta boxes on the post types you pick, and save to plain post meta
 * under the slug you choose.
 *
 * Storage formats are deliberately identical to the ones the bundled Bricks
 * video player already reads (third_party/video-player.php):
 *   - a "double" field stores [ col_a, col_b ]
 *   - a repeating "double" field stores [ [ a, b ], [ a, b ], ... ]
 * so `chapters` and `subtitles` keep working untouched.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const SNN_CF_FIELDS_OPTION = 'snn_learn_course_fields';
const SNN_CF_CPT_OPTION    = 'snn_learn_course_cpt';
const SNN_CF_ENABLED_OPTION = 'snn_learn_course_fields_enabled';

// ============================================================
// 1. FIELD TYPES
// ============================================================

/**
 * Every supported field type.
 *
 * 'cols'    — 1 for a single input, 2 for the paired "double" inputs.
 * 'control' — how the input is drawn / what picker (if any) it gets.
 */
function snn_cf_field_types() {
    return [
        'text'            => [ 'label' => 'Text',            'cols' => 1, 'control' => 'text' ],
        'textarea'        => [ 'label' => 'Textarea',        'cols' => 1, 'control' => 'textarea' ],
        'number'          => [ 'label' => 'Number',          'cols' => 1, 'control' => 'number' ],
        'url'             => [ 'label' => 'URL',             'cols' => 1, 'control' => 'url' ],
        'date'            => [ 'label' => 'Date',            'cols' => 1, 'control' => 'date' ],
        'color'           => [ 'label' => 'Color',           'cols' => 1, 'control' => 'color' ],
        'select'          => [ 'label' => 'Select',          'cols' => 1, 'control' => 'select' ],
        'true_false'      => [ 'label' => 'True/False',      'cols' => 1, 'control' => 'checkbox' ],
        'video'           => [ 'label' => 'Video (SNN Media Library)', 'cols' => 1, 'control' => 'video' ],
        'vtt'             => [ 'label' => 'Subtitle .vtt (SNN Media Library)', 'cols' => 1, 'control' => 'vtt' ],
        'media'           => [ 'label' => 'Media (WordPress)', 'cols' => 1, 'control' => 'media' ],
        'double_text'     => [ 'label' => 'Double Text',     'cols' => 2, 'control' => 'text' ],
        'double_textarea' => [ 'label' => 'Double Textarea', 'cols' => 2, 'control' => 'textarea' ],
        'subtitles'       => [ 'label' => 'Subtitles (.vtt + label)', 'cols' => 2, 'control' => 'vtt_pair' ],
    ];
}

function snn_cf_type_def( $type ) {
    $types = snn_cf_field_types();
    return $types[ $type ] ?? $types['text'];
}

/**
 * Types that can be edited inline from the posts list table.
 *
 * The pickers are included — their modals work the same on the list screen —
 * but the paired and repeating types are not, since Quick Edit has no room to
 * draw a repeater.
 */
function snn_cf_quick_edit_types() {
    return [ 'text', 'number', 'url', 'date', 'color', 'select', 'true_false', 'textarea', 'video', 'vtt', 'media' ];
}

// ============================================================
// 2. DEFAULTS
// ============================================================

/**
 * The field set shipped out of the box — the course/lesson fields this plugin
 * already reads elsewhere. Editable, removable, and extendable from the
 * "Course Fields" settings screen.
 *
 * Two defaults use SNN-media-aware types instead of a plain text/double text:
 * `video_url` is a video picker and `subtitles` is a .vtt picker, both of which
 * write exactly the same meta value a hand-typed URL would.
 */
function snn_cf_default_fields() {
    $cpt = snn_cf_cpt_slug();

    $f = function ( $group, $label, $slug, $width, $type, $extra = [] ) use ( $cpt ) {
        return array_merge( [
            'group'      => $group,
            'label'      => $label,
            'slug'       => $slug,
            'width'      => $width,
            'type'       => $type,
            'post_types' => [ $cpt ],
            'repeater'   => 0,
            'quick_edit' => 0,
            'return'     => 'url',
            'options'    => '',
            'help'       => '',
            'ai_enabled' => 0,
            'ai_prompt'  => '',
            'ai_count'   => 0,
        ], $extra );
    };

    return [
        $f( 'Course Fields', 'Chapters', 'chapters', 30, 'double_text', [
            'repeater'   => 1,
            'help'       => 'Timestamp and chapter title, e.g. 00:45 | Setting up',
            'ai_enabled' => 1,
            'ai_count'   => 8,
            'ai_prompt'  => 'Divide this lesson into chapters. Use the transcript timestamps to find where each new topic actually begins. '
                . 'The first chapter must start at 00:00. Column A is the start time as MM:SS, column B is a short descriptive title of 2 to 5 words. '
                . 'Prefer fewer, meaningful chapters over many trivial ones.',
        ] ),
        $f( 'Course Fields', 'Subtitles', 'subtitles', 30, 'subtitles', [
            'repeater' => 1,
            'help'     => 'Filled in automatically when you pick a video that already has a .vtt.',
        ] ),
        $f( 'Course Fields', 'Video URL',    'video_url',    20, 'video', [ 'quick_edit' => 1 ] ),
        $f( 'Course Fields', 'Video Length', 'video_length', 20, 'text',  [ 'quick_edit' => 1 ] ),
        $f( 'Course Fields', 'Course Objectives', 'course_objectives', 30, 'textarea', [
            'repeater'   => 1,
            'help'       => 'What the learner can do after this lesson. One objective per row.',
            'ai_enabled' => 1,
            'ai_count'   => 6,
            'ai_prompt'  => 'Write the learning objectives for this lesson: what a learner will be able to do once they have finished it. '
                . 'Start each objective with a verb. Keep each to one sentence. Cover only what the transcript actually teaches.',
        ] ),
        $f( 'Course Fields', 'FAQ', 'faq', 40, 'double_textarea', [
            'repeater'   => 1,
            'help'       => 'Question and answer.',
            'ai_enabled' => 1,
            'ai_count'   => 5,
            'ai_prompt'  => 'Write the questions a learner is most likely to ask after watching this lesson, with answers. '
                . 'Column A is the question, column B is the answer in two or three sentences. '
                . 'Answer only from what the transcript covers.',
        ] ),
        $f( 'Course Fields', 'Free Preview',      'free_preview',      10, 'true_false' ),
        $f( 'Course Fields', 'Badge Name',        'badge_name',        10, 'text',            [ 'quick_edit' => 1 ] ),
        $f( 'Course Fields', 'Badge',             'badge',             10, 'media' ),
        $f( 'Course Meta',   'Enrolled Posts',    'snn_edu_enrolled_posts',  30, 'textarea' ),
        $f( 'Course Meta',   'Completed Posts',   'snn_edu_completed_posts', 25, 'textarea' ),
    ];
}

/** Post type registration settings. */
function snn_cf_cpt_defaults() {
    return [
        'enabled'      => 0,
        'slug'         => 'course',
        'singular'     => 'Course',
        'plural'       => 'Courses',
        'menu_icon'    => 'dashicons-welcome-learn-more',
        'menu_position'=> 5,
        'public'       => 1,
        'hierarchical' => 1,
        'has_archive'  => 1,
        'show_in_rest' => 1,
        'rewrite_slug' => 'course',
        'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields', 'revisions' ],
        'taxonomies'   => '',
    ];
}

function snn_cf_cpt_settings() {
    $saved = get_option( SNN_CF_CPT_OPTION, [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }
    $settings = array_merge( snn_cf_cpt_defaults(), $saved );

    // The video player and progress tracking read the slug from the older
    // option, so that one stays authoritative for the slug.
    $settings['slug'] = snn_learn_get( 'course_post_type' ) ?: $settings['slug'];

    return $settings;
}

function snn_cf_cpt_slug() {
    $slug = snn_learn_get( 'course_post_type' );
    return $slug ? $slug : 'course';
}

// ============================================================
// 3. FIELD REGISTRY STORAGE
// ============================================================

/** True when the registry should render meta boxes and save meta. */
function snn_cf_enabled() {
    return (bool) get_option( SNN_CF_ENABLED_OPTION, 0 );
}

/**
 * The registered fields.
 *
 * Never saved yet → the defaults. Saved as an empty list → an empty list,
 * because "I deleted every field" is a legitimate state.
 */
function snn_cf_get_fields() {
    // Sanitising the whole registry once per request matters: the posts list
    // table asks for it again for every row it draws.
    global $snn_cf_registry_cache;

    if ( is_array( $snn_cf_registry_cache ) ) {
        return $snn_cf_registry_cache;
    }

    $saved = get_option( SNN_CF_FIELDS_OPTION, null );

    $snn_cf_registry_cache = ( null === $saved || ! is_array( $saved ) )
        ? snn_cf_default_fields()
        : array_values( array_map( 'snn_cf_sanitize_field', $saved ) );

    return $snn_cf_registry_cache;
}

/** Drops the per-request registry cache; call after writing the option. */
function snn_cf_flush_cache() {
    global $snn_cf_registry_cache;
    $snn_cf_registry_cache = null;
}

/** Fields attached to one post type, in registration order. */
function snn_cf_fields_for( $post_type ) {
    return array_values( array_filter( snn_cf_get_fields(), function ( $field ) use ( $post_type ) {
        return in_array( $post_type, (array) $field['post_types'], true );
    } ) );
}

/** Fields attached to one post type, bucketed by group name. */
function snn_cf_groups_for( $post_type ) {
    $groups = [];
    foreach ( snn_cf_fields_for( $post_type ) as $field ) {
        $name = $field['group'] !== '' ? $field['group'] : 'Fields';
        $groups[ $name ][] = $field;
    }
    return $groups;
}

/** Normalises one raw field definition into the shape the renderer expects. */
function snn_cf_sanitize_field( $raw ) {
    $raw   = is_array( $raw ) ? $raw : [];
    $types = snn_cf_field_types();
    $type  = isset( $raw['type'] ) && isset( $types[ $raw['type'] ] ) ? $raw['type'] : 'text';

    $slug = sanitize_key( $raw['slug'] ?? '' );
    if ( '' === $slug ) {
        $slug = sanitize_key( $raw['label'] ?? '' );
    }

    $width = (int) ( $raw['width'] ?? 100 );
    $width = max( 5, min( 100, $width ?: 100 ) );

    $post_types = array_values( array_filter( array_map(
        'sanitize_key',
        (array) ( $raw['post_types'] ?? [] )
    ) ) );

    return [
        'group'      => sanitize_text_field( $raw['group'] ?? 'Fields' ),
        'label'      => sanitize_text_field( $raw['label'] ?? '' ),
        'slug'       => $slug,
        'width'      => $width,
        'type'       => $type,
        'post_types' => $post_types,
        'repeater'   => empty( $raw['repeater'] ) ? 0 : 1,
        'quick_edit' => empty( $raw['quick_edit'] ) ? 0 : 1,
        'return'     => ( ( $raw['return'] ?? 'url' ) === 'id' ) ? 'id' : 'url',
        'options'    => sanitize_textarea_field( $raw['options'] ?? '' ),
        'help'       => sanitize_text_field( $raw['help'] ?? '' ),
        // Transcript-driven generation. The output shape is derived from
        // `type` + `repeater`, so a prompt is all a new AI field needs.
        'ai_enabled' => empty( $raw['ai_enabled'] ) ? 0 : 1,
        'ai_prompt'  => sanitize_textarea_field( $raw['ai_prompt'] ?? '' ),
        'ai_count'   => max( 0, min( 50, (int) ( $raw['ai_count'] ?? 0 ) ) ),
    ];
}

/** Saves the registry, dropping rows with no slug. */
function snn_cf_save_fields( array $fields ) {
    $clean = [];
    foreach ( $fields as $field ) {
        $field = snn_cf_sanitize_field( $field );
        if ( '' === $field['slug'] ) {
            continue;
        }
        $clean[] = $field;
    }
    update_option( SNN_CF_FIELDS_OPTION, $clean );
    snn_cf_flush_cache();

    return $clean;
}

/** Select options parsed from one "value : Label" per line. */
function snn_cf_parse_options( $raw ) {
    $out = [];
    foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }
        $parts = array_map( 'trim', explode( ':', $line, 2 ) );
        $out[ $parts[0] ] = $parts[1] ?? $parts[0];
    }
    return $out;
}

// ============================================================
// 4. POST TYPE REGISTRATION
// ============================================================

/**
 * Registers the course post type when the setting is enabled.
 *
 * Ships disabled so it cannot collide with a post type of the same slug that
 * is already registered from another plugin; enable it once that one is gone.
 */
add_action( 'init', 'snn_cf_register_post_type', 5 );
function snn_cf_register_post_type() {
    $s = snn_cf_cpt_settings();

    if ( empty( $s['enabled'] ) || '' === $s['slug'] ) {
        return;
    }
    if ( post_type_exists( $s['slug'] ) ) {
        return;
    }

    $singular = $s['singular'] !== '' ? $s['singular'] : 'Course';
    $plural   = $s['plural'] !== '' ? $s['plural'] : 'Courses';

    register_post_type( $s['slug'], [
        'labels' => [
            'name'               => $plural,
            'singular_name'      => $singular,
            'menu_name'          => $plural,
            'add_new_item'       => 'Add New ' . $singular,
            'edit_item'          => 'Edit ' . $singular,
            'new_item'           => 'New ' . $singular,
            'view_item'          => 'View ' . $singular,
            'search_items'       => 'Search ' . $plural,
            'not_found'          => 'No ' . strtolower( $plural ) . ' found',
            'not_found_in_trash' => 'No ' . strtolower( $plural ) . ' found in Trash',
            'all_items'          => 'All ' . $plural,
            'parent_item_colon'  => 'Parent ' . $singular . ':',
        ],
        'public'             => ! empty( $s['public'] ),
        'publicly_queryable' => ! empty( $s['public'] ),
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => ! empty( $s['show_in_rest'] ),
        'hierarchical'       => ! empty( $s['hierarchical'] ),
        'has_archive'        => ! empty( $s['has_archive'] ),
        'menu_icon'          => $s['menu_icon'] !== '' ? $s['menu_icon'] : 'dashicons-welcome-learn-more',
        'menu_position'      => (int) $s['menu_position'] ?: 5,
        'supports'           => (array) $s['supports'] ?: [ 'title', 'editor' ],
        'taxonomies'         => array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $s['taxonomies'] ) ) ) ),
        'rewrite'            => [
            'slug'       => $s['rewrite_slug'] !== '' ? $s['rewrite_slug'] : $s['slug'],
            'with_front' => false,
            'hierarchical' => ! empty( $s['hierarchical'] ),
        ],
    ] );
}

/** Post types offered as targets in the settings UI. */
function snn_cf_selectable_post_types() {
    $out = [];
    foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $pt ) {
        if ( in_array( $pt->name, [ 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ], true ) ) {
            continue;
        }
        $out[ $pt->name ] = $pt->labels->singular_name ?: $pt->name;
    }

    // The configured course slug may not be registered yet (registration is
    // off, or it comes from another plugin loading later) — always offer it.
    $slug = snn_cf_cpt_slug();
    if ( ! isset( $out[ $slug ] ) ) {
        $out = [ $slug => snn_cf_cpt_settings()['singular'] . ' (not registered yet)' ] + $out;
    }

    return $out;
}

// ============================================================
// 5. VALUE SANITIZING
// ============================================================

/** Sanitizes one input value according to the field type and column index. */
function snn_cf_sanitize_scalar( $field, $value, $col = 0 ) {
    $value = is_scalar( $value ) ? (string) $value : '';
    $value = wp_unslash( $value );

    switch ( $field['type'] ) {
        case 'textarea':
        case 'double_textarea':
            return sanitize_textarea_field( $value );

        case 'url':
        case 'video':
        case 'vtt':
            return '' === trim( $value ) ? '' : esc_url_raw( trim( $value ) );

        case 'subtitles':
            // Column 0 is the .vtt URL, column 1 the human-readable track name.
            return 0 === $col
                ? ( '' === trim( $value ) ? '' : esc_url_raw( trim( $value ) ) )
                : sanitize_text_field( $value );

        case 'media':
            if ( 'id' === $field['return'] ) {
                return $value === '' ? '' : absint( $value );
            }
            return '' === trim( $value ) ? '' : esc_url_raw( trim( $value ) );

        case 'number':
            $value = trim( $value );
            return is_numeric( $value ) ? $value + 0 : '';

        case 'true_false':
            return $value ? 1 : 0;

        case 'color':
            return sanitize_hex_color( trim( $value ) ) ?: '';

        default:
            return sanitize_text_field( $value );
    }
}

/**
 * Turns the raw $_POST value for one field into what gets stored.
 *
 * Doubles keep the [ a, b ] pair shape and repeaters keep the list-of-rows
 * shape the Bricks video player already reads for `chapters` / `subtitles`.
 */
function snn_cf_sanitize_value( $field, $raw ) {
    $cols = snn_cf_type_def( $field['type'] )['cols'];

    if ( $field['repeater'] ) {
        $rows = is_array( $raw ) ? $raw : [];
        $out  = [];

        foreach ( $rows as $row ) {
            if ( 2 === $cols ) {
                $a = snn_cf_sanitize_scalar( $field, is_array( $row ) ? ( $row['a'] ?? '' ) : '', 0 );
                $b = snn_cf_sanitize_scalar( $field, is_array( $row ) ? ( $row['b'] ?? '' ) : '', 1 );
                if ( '' === $a && '' === $b ) {
                    continue; // Blank row left over from the "add row" button.
                }
                $out[] = [ $a, $b ];
            } else {
                $v = snn_cf_sanitize_scalar( $field, is_array( $row ) ? ( $row['a'] ?? '' ) : $row, 0 );
                if ( '' === $v ) {
                    continue;
                }
                $out[] = $v;
            }
        }

        return $out;
    }

    if ( 2 === $cols ) {
        $a = snn_cf_sanitize_scalar( $field, is_array( $raw ) ? ( $raw['a'] ?? '' ) : '', 0 );
        $b = snn_cf_sanitize_scalar( $field, is_array( $raw ) ? ( $raw['b'] ?? '' ) : '', 1 );
        return ( '' === $a && '' === $b ) ? '' : [ $a, $b ];
    }

    return snn_cf_sanitize_scalar( $field, is_array( $raw ) ? '' : $raw, 0 );
}

/** Reads a stored value back into the [ rows ] shape the renderer draws. */
function snn_cf_value_rows( $field, $stored ) {
    $cols = snn_cf_type_def( $field['type'] )['cols'];

    if ( $field['repeater'] ) {
        // A field that used to be a plain text/textarea holds a bare string.
        // Show it as the first row instead of dropping it, or turning the
        // repeater on would silently discard the existing content on save.
        if ( is_scalar( $stored ) && '' !== (string) $stored ) {
            return [ [ (string) $stored, '' ] ];
        }

        $rows = is_array( $stored ) ? $stored : [];
        $out  = [];
        foreach ( $rows as $row ) {
            $out[] = 2 === $cols
                ? [ (string) ( $row[0] ?? '' ), (string) ( $row[1] ?? '' ) ]
                : [ is_scalar( $row ) ? (string) $row : '', '' ];
        }
        return $out ?: [ [ '', '' ] ]; // Always draw one empty row to type into.
    }

    // A repeater that was switched back off leaves a list behind — fall back to
    // its first row rather than rendering the word "Array" into the input.
    if ( is_array( $stored ) && isset( $stored[0] ) && is_array( $stored[0] ) ) {
        $stored = $stored[0];
    }

    if ( 2 === $cols ) {
        $pair = is_array( $stored ) ? $stored : [ '', '' ];
        return [ [ (string) ( $pair[0] ?? '' ), (string) ( $pair[1] ?? '' ) ] ];
    }

    if ( is_array( $stored ) ) {
        $stored = $stored[0] ?? '';
    }

    return [ [ is_scalar( $stored ) ? (string) $stored : '', '' ] ];
}

// ============================================================
// 6. META BOXES
// ============================================================

add_action( 'add_meta_boxes', function ( $post_type ) {
    if ( ! snn_cf_enabled() ) {
        return;
    }

    foreach ( snn_cf_groups_for( $post_type ) as $group_name => $fields ) {
        add_meta_box(
            'snn_cf_' . md5( $group_name ),
            $group_name,
            'snn_cf_render_meta_box',
            $post_type,
            'normal',
            'high',
            [ 'fields' => $fields ]
        );
    }
}, 10, 1 );

function snn_cf_render_meta_box( $post, $box ) {
    static $nonce_done = false;

    if ( ! $nonce_done ) {
        wp_nonce_field( 'snn_cf_save', 'snn_cf_nonce' );
        $nonce_done = true;
    }

    echo '<div class="snn-cf-toolbar">'
        . '<button type="button" class="button-link snn-cf-toggle-all" data-open="1">Expand all</button>'
        . '<button type="button" class="button-link snn-cf-toggle-all" data-open="0">Collapse all</button>'
        . '</div>';

    echo '<div class="snn-cf-grid">';
    foreach ( $box['args']['fields'] as $field ) {
        snn_cf_render_field( $field, get_post_meta( $post->ID, $field['slug'], true ) );
    }
    echo '</div>';
}

/**
 * A short description of what a field currently holds, shown on its collapsed
 * header so the whole group can be read at a glance without opening anything.
 */
function snn_cf_value_summary( $field, $stored ) {
    if ( $field['repeater'] ) {
        $count = is_array( $stored ) ? count( $stored ) : ( is_scalar( $stored ) && '' !== (string) $stored ? 1 : 0 );
        if ( ! $count ) {
            return '';
        }
        return $count . ' ' . ( 1 === $count ? 'row' : 'rows' );
    }

    if ( 'true_false' === $field['type'] ) {
        return $stored ? 'Yes' : 'No';
    }

    if ( is_array( $stored ) ) {
        $stored = implode( ' · ', array_filter( array_map( 'strval', $stored ) ) );
    }

    $value = trim( (string) $stored );
    if ( '' === $value ) {
        return '';
    }

    // A URL is only recognisable by its last segment at this width.
    if ( preg_match( '#^https?://#i', $value ) ) {
        $value = rawurldecode( basename( wp_parse_url( $value, PHP_URL_PATH ) ?: $value ) );
    }

    $value = preg_replace( '/\s+/', ' ', $value );

    return mb_strlen( $value ) > 60 ? mb_substr( $value, 0, 60 ) . '…' : $value;
}

function snn_cf_render_field( $field, $stored ) {
    $def     = snn_cf_type_def( $field['type'] );
    $rows    = snn_cf_value_rows( $field, $stored );
    $name    = 'snn_cf[' . $field['slug'] . ']';
    $summary = snn_cf_value_summary( $field, $stored );

    printf(
        '<div class="snn-cf-field" style="flex-basis:%s%%" data-type="%s" data-cols="%d" data-slug="%s" data-repeater="%d">',
        esc_attr( $field['width'] ),
        esc_attr( $field['type'] ),
        (int) $def['cols'],
        esc_attr( $field['slug'] ),
        (int) $field['repeater']
    );

    // Every field starts collapsed — the header carries enough to decide
    // whether it is worth opening.
    printf(
        '<button type="button" class="snn-cf-head" aria-expanded="false">'
        . '<span class="snn-cf-caret" aria-hidden="true"></span>'
        . '<span class="snn-cf-head-label">%s</span>'
        . '<span class="snn-cf-summary %s">%s</span>'
        . '</button>',
        esc_html( $field['label'] ),
        '' === $summary ? 'is-empty' : '',
        esc_html( '' === $summary ? 'empty' : $summary )
    );

    echo '<div class="snn-cf-body" hidden>';

    if ( '' !== $field['help'] ) {
        printf( '<p class="snn-cf-help">%s</p>', esc_html( $field['help'] ) );
    }

    // Marks the field as submitted, so clearing every repeater row (which
    // posts no value at all) still saves as "empty" instead of being skipped.
    printf( '<input type="hidden" name="snn_cf_present[]" value="%s">', esc_attr( $field['slug'] ) );

    echo '<div class="snn-cf-rows">';
    foreach ( $rows as $index => $row ) {
        snn_cf_render_row( $field, $name, $index, $row );
    }
    echo '</div>';

    echo '<div class="snn-cf-field-actions-row">';
    if ( $field['repeater'] ) {
        echo '<button type="button" class="button snn-cf-add">+ Add Row</button>';
    }
    if ( $field['ai_enabled'] ) {
        printf(
            '<button type="button" class="button button-secondary snn-cf-generate" data-slug="%s">'
            . '<span class="snn-cf-generate-text">Generate from transcript</span></button>',
            esc_attr( $field['slug'] )
        );
    }
    echo '</div>';

    echo '<p class="snn-cf-status" role="status"></p>';

    echo '</div></div>';
}

/** One row of inputs — a repeater row, or the single row of a plain field. */
function snn_cf_render_row( $field, $name, $index, $row ) {
    $def  = snn_cf_type_def( $field['type'] );
    $base = $field['repeater'] ? $name . '[' . $index . ']' : $name;

    echo '<div class="snn-cf-row">';

    if ( $field['repeater'] ) {
        echo '<span class="snn-cf-handle" title="Drag to reorder">⋮⋮</span>';
    }

    if ( 2 === $def['cols'] ) {
        snn_cf_render_input( $field, $base . '[a]', $row[0], 0 );
        snn_cf_render_input( $field, $base . '[b]', $row[1], 1 );
    } else {
        snn_cf_render_input( $field, $field['repeater'] ? $base . '[a]' : $base, $row[0], 0 );
    }

    if ( $field['repeater'] ) {
        echo '<button type="button" class="button-link snn-cf-remove" title="Remove row">✕</button>';
    }

    echo '</div>';
}

function snn_cf_render_input( $field, $name, $value, $col ) {
    $control = snn_cf_type_def( $field['type'] )['control'];
    $id      = 'snn_cf_' . sanitize_key( $name );

    // The label column of a subtitle pair is a plain text input.
    if ( 'vtt_pair' === $control ) {
        $control = 0 === $col ? 'vtt' : 'text';
    }

    echo '<div class="snn-cf-input">';

    switch ( $control ) {
        case 'textarea':
            printf(
                '<textarea name="%s" id="%s" rows="4" placeholder="%s">%s</textarea>',
                esc_attr( $name ), esc_attr( $id ),
                esc_attr( 0 === $col ? '' : 'Answer' ),
                esc_textarea( $value )
            );
            break;

        case 'checkbox':
            // The hidden input guarantees a posted value when the box is off.
            printf(
                '<label class="snn-cf-check"><input type="hidden" name="%1$s" value="0">'
                . '<input type="checkbox" name="%1$s" id="%2$s" value="1" %3$s> Yes</label>',
                esc_attr( $name ), esc_attr( $id ), checked( 1, (int) $value, false )
            );
            break;

        case 'select':
            printf( '<select name="%s" id="%s"><option value="">—</option>', esc_attr( $name ), esc_attr( $id ) );
            foreach ( snn_cf_parse_options( $field['options'] ) as $opt_value => $opt_label ) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr( $opt_value ), selected( (string) $value, (string) $opt_value, false ), esc_html( $opt_label )
                );
            }
            echo '</select>';
            break;

        case 'video':
        case 'vtt':
            printf(
                '<div class="snn-cf-picker"><input type="text" name="%s" id="%s" value="%s" placeholder="%s" class="snn-cf-picker-input">'
                . '<button type="button" class="button snn-cf-pick" data-mode="%s">%s</button></div>',
                esc_attr( $name ), esc_attr( $id ), esc_attr( $value ),
                esc_attr( 'video' === $control ? 'https://…/lesson.mp4' : 'https://…/captions.vtt' ),
                esc_attr( $control ),
                esc_html( 'video' === $control ? 'Choose Video' : 'Choose .vtt' )
            );
            if ( 'video' === $control ) {
                printf( '<div class="snn-cf-preview" data-preview-for="%s"></div>', esc_attr( $id ) );
            }
            break;

        case 'media':
            printf(
                '<div class="snn-cf-picker"><input type="text" name="%s" id="%s" value="%s" class="snn-cf-picker-input" placeholder="%s">'
                . '<button type="button" class="button snn-cf-wp-media" data-return="%s">Choose</button></div>'
                . '<div class="snn-cf-preview" data-preview-for="%s"></div>',
                esc_attr( $name ), esc_attr( $id ), esc_attr( $value ),
                esc_attr( 'id' === $field['return'] ? 'Attachment ID' : 'Attachment URL' ),
                esc_attr( $field['return'] ),
                esc_attr( $id )
            );
            break;

        default:
            $input_type = in_array( $control, [ 'number', 'url', 'date', 'color' ], true ) ? $control : 'text';
            printf(
                '<input type="%s" name="%s" id="%s" value="%s" placeholder="%s">',
                esc_attr( $input_type ), esc_attr( $name ), esc_attr( $id ), esc_attr( $value ),
                esc_attr( 2 === snn_cf_type_def( $field['type'] )['cols'] ? ( 0 === $col ? 'First value' : 'Second value' ) : '' )
            );
    }

    echo '</div>';
}

// ============================================================
// 7. SAVING POST META
// ============================================================

add_action( 'save_post', 'snn_cf_save_post', 10, 2 );
function snn_cf_save_post( $post_id, $post ) {
    if ( ! snn_cf_enabled() ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    if ( ! isset( $_POST['snn_cf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_cf_nonce'] ) ), 'snn_cf_save' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Only fields whose editor was actually on the screen may be written.
    $present = array_map( 'sanitize_key', (array) ( $_POST['snn_cf_present'] ?? [] ) );
    $posted  = isset( $_POST['snn_cf'] ) && is_array( $_POST['snn_cf'] ) ? wp_unslash( $_POST['snn_cf'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is sanitized per field type below.

    foreach ( snn_cf_fields_for( $post->post_type ) as $field ) {
        if ( ! in_array( $field['slug'], $present, true ) ) {
            continue;
        }

        $value = snn_cf_sanitize_value( $field, $posted[ $field['slug'] ] ?? null );

        if ( '' === $value || [] === $value ) {
            delete_post_meta( $post_id, $field['slug'] );
        } else {
            update_post_meta( $post_id, $field['slug'], $value );
        }
    }
}

// ============================================================
// 8. QUICK EDIT
// ============================================================

/** Fields flagged for Quick Edit on one post type, minus unsupported types. */
function snn_cf_quick_edit_fields( $post_type ) {
    $allowed = snn_cf_quick_edit_types();

    return array_values( array_filter( snn_cf_fields_for( $post_type ), function ( $field ) use ( $allowed ) {
        return $field['quick_edit'] && ! $field['repeater'] && in_array( $field['type'], $allowed, true );
    } ) );
}

add_filter( 'manage_posts_columns', 'snn_cf_quick_edit_column', 10, 2 );
add_filter( 'manage_pages_columns', function ( $columns ) {
    return snn_cf_quick_edit_column( $columns, get_current_screen() ? get_current_screen()->post_type : '' );
} );
function snn_cf_quick_edit_column( $columns, $post_type ) {
    if ( snn_cf_enabled() && snn_cf_quick_edit_fields( $post_type ) ) {
        // Hidden by CSS — it exists only to carry the values to Quick Edit.
        $columns['snn_cf_data'] = 'SNN';
    }
    return $columns;
}

add_action( 'manage_posts_custom_column', 'snn_cf_quick_edit_column_content', 10, 2 );
add_action( 'manage_pages_custom_column', 'snn_cf_quick_edit_column_content', 10, 2 );
function snn_cf_quick_edit_column_content( $column, $post_id ) {
    if ( 'snn_cf_data' !== $column ) {
        return;
    }

    $values = [];
    foreach ( snn_cf_quick_edit_fields( get_post_type( $post_id ) ) as $field ) {
        $values[ $field['slug'] ] = (string) get_post_meta( $post_id, $field['slug'], true );
    }

    printf(
        '<div class="snn-cf-qe-data" data-values="%s"></div>',
        esc_attr( wp_json_encode( $values ) )
    );
}

add_action( 'quick_edit_custom_box', function ( $column, $post_type ) {
    if ( 'snn_cf_data' !== $column ) {
        return;
    }

    $fields = snn_cf_quick_edit_fields( $post_type );
    if ( ! $fields ) {
        return;
    }
    ?>
    <fieldset class="inline-edit-col-full snn-cf-qe">
        <div class="inline-edit-col">
            <span class="title">SNN Fields</span>
            <?php foreach ( $fields as $field ) : ?>
                <label class="snn-cf-qe-row">
                    <span class="title"><?= esc_html( $field['label'] ) ?></span>
                    <span class="input-text-wrap">
                        <?php snn_cf_render_input( $field, 'snn_cf[' . $field['slug'] . ']', '', 0 ); ?>
                    </span>
                </label>
            <?php endforeach; ?>
            <?php foreach ( $fields as $field ) : ?>
                <input type="hidden" name="snn_cf_present[]" value="<?= esc_attr( $field['slug'] ) ?>">
            <?php endforeach; ?>
            <?php
            // Must live inside this fieldset: it is cloned into the inline-edit
            // form, and anything printed elsewhere on the page never posts.
            wp_nonce_field( 'snn_cf_quick_edit', 'snn_cf_qe_nonce', false );
            ?>
        </div>
    </fieldset>
    <?php
}, 10, 2 );

/**
 * Quick Edit posts through the normal save flow but carries its own nonce, so
 * it needs its own write pass.
 */
add_action( 'save_post', function ( $post_id, $post ) {
    if ( ! snn_cf_enabled() ) {
        return;
    }
    if ( ! isset( $_POST['snn_cf_qe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_cf_qe_nonce'] ) ), 'snn_cf_quick_edit' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $present = array_map( 'sanitize_key', (array) ( $_POST['snn_cf_present'] ?? [] ) );
    $posted  = isset( $_POST['snn_cf'] ) && is_array( $_POST['snn_cf'] ) ? wp_unslash( $_POST['snn_cf'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field type below.

    foreach ( snn_cf_quick_edit_fields( $post->post_type ) as $field ) {
        if ( ! in_array( $field['slug'], $present, true ) ) {
            continue;
        }

        $value = snn_cf_sanitize_value( $field, $posted[ $field['slug'] ] ?? null );

        if ( '' === $value ) {
            delete_post_meta( $post_id, $field['slug'] );
        } else {
            update_post_meta( $post_id, $field['slug'], $value );
        }
    }
}, 10, 2 );

// ============================================================
// 9. ASSETS
// ============================================================

function snn_cf_asset_version() {
    $path = plugin_dir_path( __FILE__ ) . 'assets/js/snn-course-fields.js';
    return '1.0.' . ( @filemtime( $path ) ?: 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/** Everything the field editor JS needs to run on the current screen. */
function snn_cf_js_config( $post_type = '', $post_id = 0 ) {
    // Where a picked video's .vtt should land, and what to call the track.
    $subtitle_field = '';
    foreach ( snn_cf_fields_for( $post_type ) as $field ) {
        if ( 'subtitles' === $field['type'] ) {
            $subtitle_field = $field['slug'];
            break;
        }
    }

    $language = trim( (string) snn_media_get( 'stt_language' ) );

    return [
        'restUrl'        => esc_url_raw( rest_url( 'snn-learn/v1/media/' ) ),
        'generateUrl'    => esc_url_raw( rest_url( 'snn-learn/v1/fields/generate' ) ),
        'nonce'          => wp_create_nonce( 'wp_rest' ),
        'postId'         => (int) $post_id,
        'subtitleField'  => $subtitle_field,
        'subtitleLabel'  => '' !== $language ? $language : 'en',
    ];
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    $screen    = get_current_screen();
    $is_editor = in_array( $hook, [ 'post.php', 'post-new.php' ], true );
    $is_list   = 'edit.php' === $hook;
    $is_setup  = false !== strpos( (string) $hook, 'snn-learn-course-fields' );

    if ( ! $is_setup && ! ( snn_cf_enabled() && ( $is_editor || $is_list ) ) ) {
        return;
    }

    // On post screens, load only where this post type actually has fields.
    if ( ! $is_setup && $screen && ! snn_cf_fields_for( $screen->post_type ) ) {
        return;
    }

    $base = plugin_dir_url( __FILE__ );
    $ver  = snn_cf_asset_version();

    wp_enqueue_style( 'snn-course-fields', $base . 'assets/css/snn-course-fields.css', [], $ver );
    wp_enqueue_script( 'snn-course-fields', $base . 'assets/js/snn-course-fields.js', [], $ver, true );
    $config = snn_cf_js_config(
        $screen ? $screen->post_type : '',
        $is_editor ? (int) get_the_ID() : 0
    );
    wp_add_inline_script( 'snn-course-fields', 'window.SNN_CF = ' . wp_json_encode( $config ) . ';', 'before' );

    // The WordPress media modal backs the "Media (WordPress)" field type, in
    // the editor and in Quick Edit alike.
    if ( $is_editor || $is_list ) {
        wp_enqueue_media();
    }
} );

// ============================================================
// 11. AI GENERATION FROM THE LESSON TRANSCRIPT
// ============================================================

/**
 * The JSON Schema for one field's output, derived from its own definition.
 *
 * This is what keeps the feature generic: a field already declares its shape
 * through `type` and `repeater`, so enabling AI on a brand new field needs a
 * prompt and nothing else — no schema, no parser, no PHP.
 */
function snn_cf_ai_schema( $field ) {
    $cols = snn_cf_type_def( $field['type'] )['cols'];

    $properties = [ 'a' => [ 'type' => 'string', 'description' => 'The value.' ] ];
    $required   = [ 'a' ];

    if ( 2 === $cols ) {
        $properties['a']['description'] = 'First column.';
        $properties['b'] = [ 'type' => 'string', 'description' => 'Second column.' ];
        $required[]      = 'b';
    }

    return [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => [ 'rows' ],
        'properties'           => [
            'rows' => [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => $required,
                    'properties'           => $properties,
                ],
            ],
        ],
    ];
}

/** Spells out the shape and the column meaning for the model. */
function snn_cf_ai_system_prompt( $field ) {
    $cols  = snn_cf_type_def( $field['type'] )['cols'];
    $lines = [
        'You are writing the "' . $field['label'] . '" field for one lesson in an online course.',
        'You are given the lesson transcript, with a timestamp at the start of each line.',
        'Base everything strictly on the transcript. Never invent material the lesson does not cover.',
    ];

    if ( 2 === $cols ) {
        $lines[] = 'Return an array of rows. Each row has "a" (the first column) and "b" (the second column).';
    } else {
        $lines[] = 'Return an array of rows. Each row has a single value "a".';
    }

    if ( $field['repeater'] ) {
        if ( $field['ai_count'] > 0 ) {
            $lines[] = 'Aim for about ' . $field['ai_count'] . ' rows, but follow the material — a short lesson '
                . 'deserves fewer, and a dense one may need a few more. Quality over hitting the number.';
        }
    } else {
        $lines[] = 'Return exactly one row.';
    }

    $lines[] = 'Write in the same language the transcript is spoken in.';

    return implode( ' ', $lines );
}

/**
 * Locates the transcript for a post: its video field, that video's library row,
 * and the .vtt sitting beside it.
 *
 * @param string $override A video URL from the unsaved editor form, which wins
 *                         over stored meta so Generate works before saving.
 * @return string|WP_Error
 */
function snn_cf_transcript_for( $post_id, $override = '' ) {
    $url = trim( (string) $override );

    if ( '' === $url ) {
        foreach ( snn_cf_fields_for( get_post_type( $post_id ) ) as $field ) {
            if ( 'video' === $field['type'] ) {
                $url = (string) get_post_meta( $post_id, $field['slug'], true );
                if ( '' !== $url ) {
                    break;
                }
            }
        }
    }

    if ( '' === $url ) {
        return new WP_Error( 'snn_cf_no_video', 'Pick a video for this lesson first — the text is written from its subtitles.' );
    }

    $vtt = snn_media_vtt_text( snn_media_find_by_url( $url ) );
    if ( is_wp_error( $vtt ) ) {
        return $vtt;
    }

    $transcript = snn_media_vtt_to_transcript( $vtt );
    if ( '' === trim( $transcript ) ) {
        return new WP_Error( 'snn_cf_empty_vtt', 'That video\'s subtitle file is empty.' );
    }

    return $transcript;
}

/** Turns the model's rows into the [ [ a, b ], ... ] shape the editor draws. */
function snn_cf_ai_normalize_rows( $field, $rows ) {
    $cols = snn_cf_type_def( $field['type'] )['cols'];
    $out  = [];

    foreach ( (array) $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        $a = trim( (string) ( $row['a'] ?? '' ) );
        $b = 2 === $cols ? trim( (string) ( $row['b'] ?? '' ) ) : '';

        if ( '' === $a && '' === $b ) {
            continue;
        }

        $out[] = [ $a, $b ];

        if ( ! $field['repeater'] ) {
            break; // A plain field takes the first row and ignores the rest.
        }
    }

    return $out;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'snn-learn/v1', '/fields/generate', [
        'methods'             => 'POST',
        'callback'            => 'snn_cf_rest_generate',
        'permission_callback' => function ( WP_REST_Request $request ) {
            return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
        },
    ] );
} );

/**
 * POST /fields/generate — writes one field from the lesson transcript.
 *
 * Returns rows for the editor to fill in. Nothing is written to post meta: the
 * author reviews and edits what came back, then saves the post as normal.
 */
function snn_cf_rest_generate( WP_REST_Request $request ) {
    $post_id = (int) $request->get_param( 'post_id' );
    $slug    = sanitize_key( (string) $request->get_param( 'field' ) );
    $post    = get_post( $post_id );

    if ( ! $post ) {
        return new WP_Error( 'snn_cf_no_post', 'That post does not exist.', [ 'status' => 404 ] );
    }

    $field = null;
    foreach ( snn_cf_fields_for( $post->post_type ) as $candidate ) {
        if ( $candidate['slug'] === $slug ) {
            $field = $candidate;
            break;
        }
    }

    if ( ! $field ) {
        return new WP_Error( 'snn_cf_no_field', 'No such field on this post type.', [ 'status' => 404 ] );
    }
    if ( ! $field['ai_enabled'] ) {
        return new WP_Error( 'snn_cf_ai_off', 'Generation is not enabled for this field.', [ 'status' => 400 ] );
    }
    if ( '' === trim( $field['ai_prompt'] ) ) {
        return new WP_Error( 'snn_cf_no_prompt', 'This field has no generation prompt yet. Add one on the Course Fields screen.', [ 'status' => 400 ] );
    }

    $transcript = snn_cf_transcript_for( $post_id, (string) $request->get_param( 'video_url' ) );
    if ( is_wp_error( $transcript ) ) {
        return new WP_Error( $transcript->get_error_code(), $transcript->get_error_message(), [ 'status' => 400 ] );
    }

    $user_prompt = $field['ai_prompt'] . "\n\n"
        . 'Lesson title: ' . get_the_title( $post_id ) . "\n\n"
        . "Transcript:\n" . $transcript;

    $result = snn_media_openrouter_json(
        snn_cf_ai_system_prompt( $field ),
        $user_prompt,
        snn_cf_ai_schema( $field )
    );

    if ( is_wp_error( $result ) ) {
        return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 502 ] );
    }

    $rows = snn_cf_ai_normalize_rows( $field, $result['rows'] ?? [] );
    if ( ! $rows ) {
        return new WP_Error( 'snn_cf_ai_empty', 'The model returned no usable rows. Try again or adjust the prompt.', [ 'status' => 502 ] );
    }

    return rest_ensure_response( [
        'success' => true,
        'rows'    => $rows,
        'cols'    => snn_cf_type_def( $field['type'] )['cols'],
    ] );
}

// ============================================================
// 10. SETTINGS PAGE
// ============================================================

function snn_cf_supported_features() {
    return [
        'title'           => 'Title',
        'editor'          => 'Editor',
        'thumbnail'       => 'Featured Image',
        'excerpt'         => 'Excerpt',
        'page-attributes' => 'Page Attributes (parent / order)',
        'custom-fields'   => 'Custom Fields',
        'revisions'       => 'Revisions',
        'author'          => 'Author',
        'comments'        => 'Comments',
    ];
}

function snn_cf_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $notice = snn_cf_handle_settings_save();

    $enabled = snn_cf_enabled();
    $cpt     = snn_cf_cpt_settings();
    $fields  = snn_cf_get_fields();
    $types   = snn_cf_field_types();
    $targets = snn_cf_selectable_post_types();
    ?>
    <div class="snn-cf-admin wrap">
        <h1>SNN Learn &mdash; Course Fields</h1>
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped strings in the save handler.
        echo $notice;
        ?>

        <form method="post" action="">
            <?php wp_nonce_field( 'snn_cf_settings_save', 'snn_cf_settings_nonce' ); ?>

            <div class="snn-cf-card">
                <h2>Field Registry</h2>
                <label class="snn-cf-toggle">
                    <input type="checkbox" name="snn_cf_enabled" value="1" <?= checked( true, $enabled, false ) ?>>
                    <span>Enable these fields</span>
                </label>
                <p class="snn-cf-note">
                    Off by default so this cannot fight another plugin that already renders the same
                    field slugs. Turn it on once those are gone &mdash; two editors writing the same
                    meta key will overwrite each other on save.
                </p>
            </div>

            <div class="snn-cf-card">
                <h2>Post Type</h2>
                <label class="snn-cf-toggle">
                    <input type="checkbox" name="snn_cf_cpt[enabled]" value="1" <?= checked( 1, (int) $cpt['enabled'], false ) ?>>
                    <span>Register this post type</span>
                </label>
                <p class="snn-cf-note">
                    Skipped automatically when a post type with this slug is already registered
                    elsewhere, so turning it on is safe to try.
                </p>

                <div class="snn-cf-settings-grid">
                    <div>
                        <label for="snn_cf_cpt_slug">Slug</label>
                        <input type="text" id="snn_cf_cpt_slug" name="snn_cf_cpt[slug]" value="<?= esc_attr( $cpt['slug'] ) ?>" maxlength="20" placeholder="course">
                        <p class="snn-cf-note">Shared with the Video Player screen &mdash; changing it here changes it there.</p>
                    </div>
                    <div>
                        <label for="snn_cf_cpt_singular">Singular Label</label>
                        <input type="text" id="snn_cf_cpt_singular" name="snn_cf_cpt[singular]" value="<?= esc_attr( $cpt['singular'] ) ?>" placeholder="Course">
                    </div>
                    <div>
                        <label for="snn_cf_cpt_plural">Plural Label</label>
                        <input type="text" id="snn_cf_cpt_plural" name="snn_cf_cpt[plural]" value="<?= esc_attr( $cpt['plural'] ) ?>" placeholder="Courses">
                    </div>
                    <div>
                        <label for="snn_cf_cpt_rewrite">URL Base</label>
                        <input type="text" id="snn_cf_cpt_rewrite" name="snn_cf_cpt[rewrite_slug]" value="<?= esc_attr( $cpt['rewrite_slug'] ) ?>" placeholder="course">
                    </div>
                    <div>
                        <label for="snn_cf_cpt_icon">Menu Icon</label>
                        <input type="text" id="snn_cf_cpt_icon" name="snn_cf_cpt[menu_icon]" value="<?= esc_attr( $cpt['menu_icon'] ) ?>" placeholder="dashicons-welcome-learn-more">
                    </div>
                    <div>
                        <label for="snn_cf_cpt_pos">Menu Position</label>
                        <input type="number" id="snn_cf_cpt_pos" name="snn_cf_cpt[menu_position]" value="<?= esc_attr( $cpt['menu_position'] ) ?>" min="1" max="100">
                    </div>
                    <div>
                        <label for="snn_cf_cpt_tax">Taxonomies</label>
                        <input type="text" id="snn_cf_cpt_tax" name="snn_cf_cpt[taxonomies]" value="<?= esc_attr( $cpt['taxonomies'] ) ?>" placeholder="course_category, course_topics">
                        <p class="snn-cf-note">Comma separated slugs of taxonomies registered elsewhere.</p>
                    </div>
                </div>

                <div class="snn-cf-checks">
                    <?php foreach ( [ 'public' => 'Public', 'hierarchical' => 'Hierarchical (parent / child)', 'has_archive' => 'Has Archive', 'show_in_rest' => 'Show in REST / block editor' ] as $key => $label ) : ?>
                        <label>
                            <input type="checkbox" name="snn_cf_cpt[<?= esc_attr( $key ) ?>]" value="1" <?= checked( 1, (int) $cpt[ $key ], false ) ?>>
                            <?= esc_html( $label ) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p class="snn-cf-sub">Supports</p>
                <div class="snn-cf-checks">
                    <?php foreach ( snn_cf_supported_features() as $key => $label ) : ?>
                        <label>
                            <input type="checkbox" name="snn_cf_cpt[supports][]" value="<?= esc_attr( $key ) ?>"
                                <?= checked( true, in_array( $key, (array) $cpt['supports'], true ), false ) ?>>
                            <?= esc_html( $label ) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="snn-cf-note">
                    Courses, chapters and lessons all live in this one post type &mdash; depth decides the
                    role &mdash; so keep <strong>Hierarchical</strong> and <strong>Page Attributes</strong> on.
                </p>
            </div>

            <div class="snn-cf-card">
                <div class="snn-cf-card-head">
                    <h2>Fields</h2>
                    <div class="snn-cf-card-actions">
                        <button type="button" class="button" id="snn-cf-add-field">+ Add Field</button>
                        <button type="submit" class="button snn-cf-restore" name="snn_cf_restore" value="1">Restore Defaults</button>
                    </div>
                </div>

                <div id="snn-cf-field-list">
                    <?php foreach ( $fields as $i => $field ) : ?>
                        <?php snn_cf_render_field_editor( $i, $field, $types, $targets ); ?>
                    <?php endforeach; ?>
                </div>
                <p class="snn-cf-empty" <?= $fields ? 'hidden' : '' ?>>No fields registered yet.</p>
            </div>

            <p><button type="submit" class="button button-primary button-hero">Save Changes</button></p>
        </form>

        <template id="snn-cf-field-template">
            <?php snn_cf_render_field_editor( '__i__', snn_cf_sanitize_field( [ 'group' => 'Course Fields', 'width' => 100, 'post_types' => [ snn_cf_cpt_slug() ] ] ), $types, $targets ); ?>
        </template>
    </div>
    <?php
}

/** One editable row in the field registry table. */
function snn_cf_render_field_editor( $index, $field, $types, $targets ) {
    $name = 'snn_cf_fields[' . $index . ']';
    ?>
    <div class="snn-cf-field-editor" data-index="<?= esc_attr( $index ) ?>">
        <div class="snn-cf-field-bar">
            <span class="snn-cf-field-title"><?= esc_html( $field['label'] ?: 'New Field' ) ?></span>
            <span class="snn-cf-field-actions">
                <button type="button" class="button-link snn-cf-move-up" title="Move up">&#9650;</button>
                <button type="button" class="button-link snn-cf-move-down" title="Move down">&#9660;</button>
                <button type="button" class="button-link snn-cf-delete" title="Remove field">Remove</button>
            </span>
        </div>

        <div class="snn-cf-field-body">
            <div class="snn-cf-col" style="flex-basis:180px">
                <label>Group Name</label>
                <input type="text" name="<?= esc_attr( $name ) ?>[group]" value="<?= esc_attr( $field['group'] ) ?>" placeholder="Course Fields">
            </div>
            <div class="snn-cf-col" style="flex-basis:180px">
                <label>Field Label</label>
                <input type="text" class="snn-cf-label-input" name="<?= esc_attr( $name ) ?>[label]" value="<?= esc_attr( $field['label'] ) ?>" placeholder="Video URL">
            </div>
            <div class="snn-cf-col" style="flex-basis:180px">
                <label>Slug Name</label>
                <input type="text" name="<?= esc_attr( $name ) ?>[slug]" value="<?= esc_attr( $field['slug'] ) ?>" placeholder="video_url">
            </div>
            <div class="snn-cf-col" style="flex-basis:90px">
                <label>Width (%)</label>
                <input type="number" name="<?= esc_attr( $name ) ?>[width]" value="<?= esc_attr( $field['width'] ) ?>" min="5" max="100" step="5">
            </div>
            <div class="snn-cf-col" style="flex-basis:220px">
                <label>Field Type</label>
                <select class="snn-cf-type-select" name="<?= esc_attr( $name ) ?>[type]">
                    <?php foreach ( $types as $key => $def ) : ?>
                        <option value="<?= esc_attr( $key ) ?>" <?= selected( $field['type'], $key, false ) ?>><?= esc_html( $def['label'] ) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="snn-cf-col" style="flex-basis:100%">
                <label>Post Types</label>
                <div class="snn-cf-checks snn-cf-checks-tight">
                    <?php foreach ( $targets as $pt => $pt_label ) : ?>
                        <label>
                            <input type="checkbox" name="<?= esc_attr( $name ) ?>[post_types][]" value="<?= esc_attr( $pt ) ?>"
                                <?= checked( true, in_array( $pt, (array) $field['post_types'], true ), false ) ?>>
                            <?= esc_html( $pt_label ) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="snn-cf-col" style="flex-basis:100%">
                <div class="snn-cf-checks snn-cf-checks-tight">
                    <label>
                        <input type="hidden" name="<?= esc_attr( $name ) ?>[repeater]" value="0">
                        <input type="checkbox" name="<?= esc_attr( $name ) ?>[repeater]" value="1" <?= checked( 1, (int) $field['repeater'], false ) ?>>
                        Repeater
                    </label>
                    <label>
                        <input type="hidden" name="<?= esc_attr( $name ) ?>[quick_edit]" value="0">
                        <input type="checkbox" name="<?= esc_attr( $name ) ?>[quick_edit]" value="1" <?= checked( 1, (int) $field['quick_edit'], false ) ?>>
                        Quick Edit
                    </label>
                    <label class="snn-cf-return">
                        Return
                        <select name="<?= esc_attr( $name ) ?>[return]">
                            <option value="url" <?= selected( $field['return'], 'url', false ) ?>>URL</option>
                            <option value="id" <?= selected( $field['return'], 'id', false ) ?>>ID</option>
                        </select>
                    </label>
                </div>
            </div>
            <div class="snn-cf-col" style="flex-basis:100%">
                <label>Help Text <span class="snn-cf-note-inline">shown under the field in the editor</span></label>
                <input type="text" name="<?= esc_attr( $name ) ?>[help]" value="<?= esc_attr( $field['help'] ) ?>">
            </div>
            <div class="snn-cf-col snn-cf-options-col" style="flex-basis:100%">
                <label>Select Options <span class="snn-cf-note-inline">one per line, <code>value : Label</code></span></label>
                <textarea name="<?= esc_attr( $name ) ?>[options]" rows="3"><?= esc_textarea( $field['options'] ) ?></textarea>
            </div>

            <div class="snn-cf-col snn-cf-ai" style="flex-basis:100%">
                <div class="snn-cf-checks snn-cf-checks-tight">
                    <label>
                        <input type="hidden" name="<?= esc_attr( $name ) ?>[ai_enabled]" value="0">
                        <input type="checkbox" class="snn-cf-ai-toggle" name="<?= esc_attr( $name ) ?>[ai_enabled]" value="1"
                            <?= checked( 1, (int) $field['ai_enabled'], false ) ?>>
                        <strong>Generate from transcript</strong>
                    </label>
                    <label class="snn-cf-ai-count">
                        Aim for
                        <input type="number" name="<?= esc_attr( $name ) ?>[ai_count]" value="<?= esc_attr( $field['ai_count'] ) ?>"
                            min="0" max="50" style="width:70px">
                        rows
                        <span class="snn-cf-note-inline">a target, not a rule &mdash; 0 to leave it open</span>
                    </label>
                </div>
                <div class="snn-cf-ai-prompt">
                    <label>Prompt</label>
                    <textarea name="<?= esc_attr( $name ) ?>[ai_prompt]" rows="4"
                        placeholder="Describe what to write. The transcript and the output shape are added automatically."><?= esc_textarea( $field['ai_prompt'] ) ?></textarea>
                    <p class="snn-cf-note">
                        The lesson's <code>.vtt</code>, the field's column layout and the row target are appended for you &mdash;
                        write only the instruction. Needs a text generation model in
                        <a href="<?= esc_url( admin_url( 'admin.php?page=snn-learn-media-settings' ) ) ?>">Media Settings</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Handles the settings form post. Returns the admin notice markup, or ''.
 */
function snn_cf_handle_settings_save() {
    if ( ! isset( $_POST['snn_cf_settings_nonce'] ) ) {
        return '';
    }
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_cf_settings_nonce'] ) ), 'snn_cf_settings_save' ) ) {
        return '<div class="notice notice-error"><p>Security check failed. Nothing was saved.</p></div>';
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return '';
    }

    if ( isset( $_POST['snn_cf_restore'] ) ) {
        delete_option( SNN_CF_FIELDS_OPTION );
        snn_cf_flush_cache();
        return '<div class="notice notice-success is-dismissible"><p><strong>Default fields restored.</strong></p></div>';
    }

    update_option( SNN_CF_ENABLED_OPTION, isset( $_POST['snn_cf_enabled'] ) ? 1 : 0 );

    // ---- Post type ----
    $raw_cpt  = isset( $_POST['snn_cf_cpt'] ) && is_array( $_POST['snn_cf_cpt'] ) ? wp_unslash( $_POST['snn_cf_cpt'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized field by field below.
    $defaults = snn_cf_cpt_defaults();
    $slug     = sanitize_key( $raw_cpt['slug'] ?? '' ) ?: $defaults['slug'];

    $features = array_values( array_intersect(
        array_map( 'sanitize_key', (array) ( $raw_cpt['supports'] ?? [] ) ),
        array_keys( snn_cf_supported_features() )
    ) );

    $cpt = [
        'enabled'       => empty( $raw_cpt['enabled'] ) ? 0 : 1,
        'slug'          => $slug,
        'singular'      => sanitize_text_field( $raw_cpt['singular'] ?? '' ) ?: $defaults['singular'],
        'plural'        => sanitize_text_field( $raw_cpt['plural'] ?? '' ) ?: $defaults['plural'],
        'menu_icon'     => sanitize_text_field( $raw_cpt['menu_icon'] ?? '' ) ?: $defaults['menu_icon'],
        'menu_position' => max( 1, min( 100, (int) ( $raw_cpt['menu_position'] ?? 5 ) ) ),
        'public'        => empty( $raw_cpt['public'] ) ? 0 : 1,
        'hierarchical'  => empty( $raw_cpt['hierarchical'] ) ? 0 : 1,
        'has_archive'   => empty( $raw_cpt['has_archive'] ) ? 0 : 1,
        'show_in_rest'  => empty( $raw_cpt['show_in_rest'] ) ? 0 : 1,
        'rewrite_slug'  => sanitize_title( $raw_cpt['rewrite_slug'] ?? '' ) ?: $slug,
        'supports'      => $features ?: [ 'title', 'editor' ],
        'taxonomies'    => sanitize_text_field( $raw_cpt['taxonomies'] ?? '' ),
    ];

    $slug_changed = $slug !== snn_cf_cpt_slug();

    update_option( SNN_CF_CPT_OPTION, $cpt );
    // Keep the slug the video player and progress tracking read in sync.
    update_option( 'snn_learn_course_post_type', $slug );

    // ---- Fields ----
    $raw_fields = isset( $_POST['snn_cf_fields'] ) && is_array( $_POST['snn_cf_fields'] ) ? wp_unslash( $_POST['snn_cf_fields'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in snn_cf_sanitize_field().
    $saved      = snn_cf_save_fields( $raw_fields );

    // Rewrite rules embed the post type slug, so they have to be rebuilt.
    if ( $slug_changed || ! empty( $cpt['enabled'] ) ) {
        snn_cf_register_post_type();
        flush_rewrite_rules( false );
    }

    return sprintf(
        '<div class="notice notice-success is-dismissible"><p><strong>Saved.</strong> %d field%s registered.</p></div>',
        count( $saved ),
        1 === count( $saved ) ? '' : 's'
    );
}
