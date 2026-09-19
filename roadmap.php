<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// ROADMAP
// ------------------------------------------------------------
// Public roadmap board: "roadmap" post type + roadmap-tag /
// roadmap-status / roadmap-type taxonomies, a Kanban admin board,
// the [snn_learn_roadmap] shortcode, member up-votes and one-level
// threaded comments with a small rich-text editor and a timed
// edit window. Disabled by default (SNN Learn → Roadmap).
// ============================================================

const SNN_RM_PT         = 'roadmap';
const SNN_RM_TAX_TAG    = 'roadmap-tag';
const SNN_RM_TAX_STATUS = 'roadmap-status';
const SNN_RM_TAX_TYPE   = 'roadmap-type';
const SNN_RM_OPTION     = 'snn_learn_roadmap';

// ============================================================
// 1. SETTINGS
// ============================================================

function snn_rm_defaults() {
    return [
        'enabled'        => 0,
        'votes'          => 1,
        'comments'       => 1,
        'edit_hours'     => 24,
        'open_mode'      => 'modal',   // modal | page
        'sort'           => 'votes',   // votes | newest | oldest | manual | title
        'excerpt_words'  => 30,
        'columns'        => [
            [ 'title' => 'Planned or Considering', 'status' => 'planned',     'color' => '#6b7280', 'width' => '1fr' ],
            [ 'title' => 'In Progress',            'status' => 'in-progress', 'color' => '#1d4ed8', 'width' => '1fr' ],
            [ 'title' => 'Published',              'status' => 'completed',   'color' => '#15803d', 'width' => '1fr' ],
        ],
    ];
}

function snn_rm_settings() {
    static $cache = null;
    if ( null !== $cache ) return $cache;

    $defaults = snn_rm_defaults();
    $saved    = get_option( SNN_RM_OPTION, [] );
    $s        = array_merge( $defaults, is_array( $saved ) ? $saved : [] );

    $cols = [];
    foreach ( $defaults['columns'] as $i => $def ) {
        $cols[ $i ] = array_merge( $def, (array) ( $s['columns'][ $i ] ?? [] ) );
    }
    $s['columns'] = $cols;

    return $cache = $s;
}

function snn_rm_enabled() {
    return (bool) snn_rm_settings()['enabled'];
}

/** Grid track value: "1fr", "320px", "30%", "minmax(0,2fr)" … anything else falls back to 1fr. */
function snn_rm_sanitize_width( $w ) {
    $w = trim( (string) $w );
    if ( preg_match( '/^\d+(\.\d+)?(fr|px|%|rem|em)$/', $w ) ) return $w;
    if ( preg_match( '/^\d+(\.\d+)?$/', $w ) ) return $w . 'fr';
    if ( preg_match( '/^minmax\(\s*\d+(\.\d+)?(px|%|rem|em)?\s*,\s*\d+(\.\d+)?(fr|px|%|rem|em)\s*\)$/', $w ) ) return $w;
    return '1fr';
}

// ============================================================
// 2. POST TYPE & TAXONOMIES
// ============================================================

// Priority 20 so an older theme/snippet registration of the same slugs
// (registered at the default priority) wins and nothing collides.
add_action( 'init', 'snn_rm_register', 20 );
function snn_rm_register() {
    if ( ! snn_rm_enabled() ) return;

    if ( ! post_type_exists( SNN_RM_PT ) ) {
        register_post_type( SNN_RM_PT, [
            'labels' => [
                'name'               => 'Roadmap',
                'singular_name'      => 'Roadmap Item',
                'add_new'            => 'Add Item',
                'add_new_item'       => 'Add Roadmap Item',
                'edit_item'          => 'Edit Roadmap Item',
                'new_item'           => 'New Roadmap Item',
                'view_item'          => 'View Roadmap Item',
                'search_items'       => 'Search Roadmap',
                'not_found'          => 'No roadmap items found',
                'all_items'          => 'All Items',
                'menu_name'          => 'Roadmap',
            ],
            'public'        => true,
            'has_archive'   => false,
            'show_in_rest'  => true,
            'menu_icon'     => 'dashicons-flag',
            'menu_position' => 21,
            'rewrite'       => [ 'slug' => SNN_RM_PT, 'with_front' => false ],
            'supports'      => [ 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'page-attributes', 'author' ],
        ] );
    } else {
        add_post_type_support( SNN_RM_PT, [ 'editor', 'excerpt', 'comments' ] );
    }

    $taxes = [
        SNN_RM_TAX_TAG    => [ 'Roadmap Tags',     'Roadmap Tag',    false ],
        SNN_RM_TAX_STATUS => [ 'Roadmap Statuses', 'Roadmap Status', true  ],
        SNN_RM_TAX_TYPE   => [ 'Roadmap Types',    'Roadmap Type',   true  ],
    ];
    foreach ( $taxes as $slug => [ $plural, $single, $hier ] ) {
        if ( taxonomy_exists( $slug ) ) {
            register_taxonomy_for_object_type( $slug, SNN_RM_PT );
            continue;
        }
        register_taxonomy( $slug, SNN_RM_PT, [
            'labels' => [
                'name'          => $plural,
                'singular_name' => $single,
                'menu_name'     => $single,
                'add_new_item'  => 'Add New ' . $single,
                'edit_item'     => 'Edit ' . $single,
                'search_items'  => 'Search ' . $plural,
            ],
            'hierarchical'      => $hier,
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => $slug, 'with_front' => false ],
        ] );
    }

    // Flush once after the feature is switched on/off (flag set on save).
    if ( get_option( 'snn_learn_roadmap_flush' ) ) {
        delete_option( 'snn_learn_roadmap_flush' );
        flush_rewrite_rules( false );
    }
}

// New roadmap items get comments open by default, regardless of the site-wide default.
add_filter( 'get_default_comment_status', function ( $status, $post_type ) {
    return $post_type === SNN_RM_PT ? 'open' : $status;
}, 10, 2 );

/** Create the three default statuses the first time the feature is enabled. */
function snn_rm_ensure_default_terms() {
    if ( ! taxonomy_exists( SNN_RM_TAX_STATUS ) ) return;
    $defaults = [ 'planned' => 'Planned', 'in-progress' => 'In Progress', 'completed' => 'Completed' ];
    foreach ( $defaults as $slug => $name ) {
        if ( ! term_exists( $slug, SNN_RM_TAX_STATUS ) ) {
            wp_insert_term( $name, SNN_RM_TAX_STATUS, [ 'slug' => $slug ] );
        }
    }
}

// ============================================================
// 3. DATA HELPERS
// ============================================================

function snn_rm_vote_count( $post_id ) {
    return (int) get_post_meta( $post_id, '_snn_rm_votes', true );
}

function snn_rm_voters( $post_id ) {
    $v = get_post_meta( $post_id, '_snn_rm_voters', true );
    return is_array( $v ) ? array_map( 'intval', $v ) : [];
}

function snn_rm_user_voted( $post_id, $user_id ) {
    return $user_id && in_array( (int) $user_id, snn_rm_voters( $post_id ), true );
}

/** Published items grouped by column index; items whose status matches no column go to 'other'. */
function snn_rm_grouped_items( $filters = [], $statuses = [ 'publish' ] ) {
    $s = snn_rm_settings();

    $args = [
        'post_type'      => SNN_RM_PT,
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ];
    $tax_query = [];
    if ( ! empty( $filters['type'] ) ) {
        $tax_query[] = [ 'taxonomy' => SNN_RM_TAX_TYPE, 'field' => 'slug', 'terms' => array_map( 'trim', explode( ',', $filters['type'] ) ) ];
    }
    if ( ! empty( $filters['tag'] ) ) {
        $tax_query[] = [ 'taxonomy' => SNN_RM_TAX_TAG, 'field' => 'slug', 'terms' => array_map( 'trim', explode( ',', $filters['tag'] ) ) ];
    }
    if ( $tax_query ) $args['tax_query'] = $tax_query;

    $posts = get_posts( $args );
    update_object_term_cache( wp_list_pluck( $posts, 'ID' ), SNN_RM_PT );
    update_postmeta_cache( wp_list_pluck( $posts, 'ID' ) );

    $slug_to_col = [];
    foreach ( $s['columns'] as $i => $col ) {
        if ( $col['status'] !== '' ) $slug_to_col[ $col['status'] ] = $i;
    }

    $groups = [ 0 => [], 1 => [], 2 => [], 'other' => [] ];
    foreach ( $posts as $p ) {
        $col   = 'other';
        $terms = get_the_terms( $p, SNN_RM_TAX_STATUS );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                if ( isset( $slug_to_col[ $t->slug ] ) ) { $col = $slug_to_col[ $t->slug ]; break; }
            }
        }
        $groups[ $col ][] = $p;
    }

    foreach ( $groups as &$list ) {
        usort( $list, function ( $a, $b ) use ( $s ) {
            switch ( $s['sort'] ) {
                case 'newest': return strcmp( $b->post_date_gmt, $a->post_date_gmt );
                case 'oldest': return strcmp( $a->post_date_gmt, $b->post_date_gmt );
                case 'title':  return strcasecmp( $a->post_title, $b->post_title );
                case 'manual': return ( $a->menu_order <=> $b->menu_order ) ?: strcmp( $b->post_date_gmt, $a->post_date_gmt );
                default:       return ( snn_rm_vote_count( $b->ID ) <=> snn_rm_vote_count( $a->ID ) ) ?: strcmp( $b->post_date_gmt, $a->post_date_gmt );
            }
        } );
    }
    unset( $list );

    return $groups;
}

function snn_rm_excerpt( $post ) {
    if ( has_excerpt( $post ) ) return get_the_excerpt( $post );
    $words = (int) snn_rm_settings()['excerpt_words'];
    if ( $words <= 0 ) return '';
    return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), $words );
}

/** Column index (0-2) for a post, or -1. Used to colour the detail view. */
function snn_rm_post_column( $post_id ) {
    $terms = get_the_terms( $post_id, SNN_RM_TAX_STATUS );
    if ( ! $terms || is_wp_error( $terms ) ) return -1;
    foreach ( snn_rm_settings()['columns'] as $i => $col ) {
        foreach ( $terms as $t ) {
            if ( $t->slug === $col['status'] ) return $i;
        }
    }
    return -1;
}

function snn_rm_is_public_item( $post ) {
    $post = get_post( $post );
    return $post && $post->post_type === SNN_RM_PT && $post->post_status === 'publish' && ! post_password_required( $post );
}

function snn_rm_comments_allowed( $post ) {
    return snn_rm_settings()['comments'] && comments_open( $post );
}

// ============================================================
// 4. COMMENT HTML SANITISING
// ============================================================

/** Allow only the formatting the comment editor can produce. */
function snn_rm_sanitize_html( $html ) {
    $html = wp_kses( (string) $html, [
        'p' => [], 'div' => [], 'br' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'span' => [ 'style' => true ],
    ] );

    // Inside style="", keep only a validated colour and font-size.
    $html = preg_replace_callback( '/\sstyle="([^"]*)"/i', function ( $m ) {
        $keep = [];
        foreach ( explode( ';', $m[1] ) as $decl ) {
            if ( strpos( $decl, ':' ) === false ) continue;
            [ $prop, $val ] = array_map( 'trim', explode( ':', $decl, 2 ) );
            $prop = strtolower( $prop );
            if ( $prop === 'color' && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\))$/i', $val ) ) {
                $keep[] = 'color:' . $val;
            } elseif ( $prop === 'font-size' && preg_match( '/^((1\d|2\d|3[0-2])px|small|medium|large|x-large|xx-large)$/i', $val ) ) {
                $keep[] = 'font-size:' . $val;
            }
        }
        return $keep ? ' style="' . esc_attr( implode( ';', $keep ) ) . '"' : '';
    }, $html );

    // Trim empty paragraphs at the start/end.
    $html = preg_replace( '#^(\s*<(p|div)>(\s|&nbsp;|<br\s*/?>)*</\2>)+|(<(p|div)>(\s|&nbsp;|<br\s*/?>)*</\5>\s*)+$#i', '', $html );

    return trim( $html );
}

function snn_rm_html_is_empty( $html ) {
    return trim( str_replace( "\xc2\xa0", ' ', html_entity_decode( wp_strip_all_tags( $html ) ) ) ) === '';
}

// ============================================================
// 5. FRONT-END RENDERING
// ============================================================

function snn_rm_icon( $name ) {
    $icons = [
        'thumb'   => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M2 21h4V9H2v12zm20-11a2 2 0 0 0-2-2h-6.3l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L13.17 1 6.59 7.59C6.22 7.95 6 8.45 6 9v10a2 2 0 0 0 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>',
        'comment' => '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm0 14H5.17L4 17.17V4h16v12z"/></svg>',
        'close'   => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
    ];
    return $icons[ $name ] ?? '';
}

function snn_rm_vote_button( $post_id, $extra_class = '' ) {
    if ( ! snn_rm_settings()['votes'] ) return '';
    $voted = snn_rm_user_voted( $post_id, get_current_user_id() );
    return sprintf(
        '<button type="button" class="snn-rm-vote%s%s" data-id="%d" aria-pressed="%s" title="%s">%s<span class="snn-rm-vote-count">%d</span></button>',
        $voted ? ' is-voted' : '',
        $extra_class ? ' ' . esc_attr( $extra_class ) : '',
        (int) $post_id,
        $voted ? 'true' : 'false',
        esc_attr( $voted ? 'Remove your vote' : 'Vote for this' ),
        snn_rm_icon( 'thumb' ),
        snn_rm_vote_count( $post_id )
    );
}

function snn_rm_term_pills( $post_id, $tax, $class ) {
    $terms = get_the_terms( $post_id, $tax );
    if ( ! $terms || is_wp_error( $terms ) ) return '';
    $out = '<ul class="' . esc_attr( $class ) . '">';
    foreach ( $terms as $t ) {
        $out .= '<li><span class="snn-rm-dot"></span>' . esc_html( $t->name ) . '</li>';
    }
    return $out . '</ul>';
}

function snn_rm_card_html( $post ) {
    $url   = get_permalink( $post );
    $count = snn_rm_settings()['comments'] ? (int) get_comments_number( $post ) : 0;

    ob_start(); ?>
    <article class="snn-rm-card" data-id="<?= (int) $post->ID ?>">
        <div class="snn-rm-card-top">
            <h3 class="snn-rm-card-title"><a href="<?= esc_url( $url ) ?>" class="snn-rm-open" data-id="<?= (int) $post->ID ?>"><?= esc_html( get_the_title( $post ) ) ?></a></h3>
            <?= snn_rm_vote_button( $post->ID ) ?>
        </div>
        <?php $ex = snn_rm_excerpt( $post ); if ( $ex !== '' ) : ?>
            <p class="snn-rm-card-excerpt"><?= esc_html( $ex ) ?></p>
        <?php endif; ?>
        <?= snn_rm_term_pills( $post->ID, SNN_RM_TAX_TYPE, 'snn-rm-types' ) ?>
        <?= snn_rm_term_pills( $post->ID, SNN_RM_TAX_TAG, 'snn-rm-tags' ) ?>
        <?php if ( $count ) : ?>
            <a href="<?= esc_url( $url ) ?>#comments" class="snn-rm-card-comments snn-rm-open" data-id="<?= (int) $post->ID ?>"><?= snn_rm_icon( 'comment' ) ?> <?= $count ?></a>
        <?php endif; ?>
    </article>
    <?php
    return ob_get_clean();
}

// ------------------------------------------------------------
// [snn_learn_roadmap type="" tag=""]
// ------------------------------------------------------------
add_shortcode( 'snn_learn_roadmap', 'snn_rm_shortcode' );
function snn_rm_shortcode( $atts ) {
    if ( ! snn_rm_enabled() ) {
        return current_user_can( 'manage_options' ) ? '<p><em>Roadmap is disabled — enable it under SNN Learn → Roadmap.</em></p>' : '';
    }

    $atts   = shortcode_atts( [ 'type' => '', 'tag' => '' ], $atts, 'snn_learn_roadmap' );
    $s      = snn_rm_settings();
    $groups = snn_rm_grouped_items( $atts );
    $widths = implode( ' ', array_map( fn( $c ) => snn_rm_sanitize_width( $c['width'] ), $s['columns'] ) );

    ob_start();
    echo snn_rm_mark_assets(); // phpcs:ignore ?>
    <div class="snn-rm" style="--snn-rm-grid:<?= esc_attr( $widths ) ?>" data-open="<?= esc_attr( $s['open_mode'] ) ?>">
        <?php foreach ( $s['columns'] as $i => $col ) : ?>
            <section class="snn-rm-col" style="--snn-rm-c:<?= esc_attr( sanitize_hex_color( $col['color'] ) ?: '#6b7280' ) ?>">
                <header class="snn-rm-col-head">
                    <span class="snn-rm-dot"></span>
                    <h2><?= esc_html( $col['title'] ) ?></h2>
                    <span class="snn-rm-col-count"><?= count( $groups[ $i ] ) ?></span>
                </header>
                <div class="snn-rm-col-body">
                    <?php foreach ( $groups[ $i ] as $p ) echo snn_rm_card_html( $p ); ?>
                    <?php if ( ! $groups[ $i ] ) : ?><p class="snn-rm-empty">Nothing here yet.</p><?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ------------------------------------------------------------
// Detail view (modal body, and the panel appended on single pages)
// ------------------------------------------------------------

function snn_rm_detail_html( $post, $full = true ) {
    $post = get_post( $post );
    $s    = snn_rm_settings();
    $ci   = snn_rm_post_column( $post->ID );
    $col  = $ci >= 0 ? $s['columns'][ $ci ] : null;
    $c    = $col ? ( sanitize_hex_color( $col['color'] ) ?: '#6b7280' ) : '#6b7280';

    ob_start(); ?>
    <div class="snn-rm-detail" style="--snn-rm-c:<?= esc_attr( $c ) ?>" data-id="<?= (int) $post->ID ?>">
        <?php if ( $full ) : ?>
            <div class="snn-rm-detail-meta">
                <?php if ( $col ) : ?><span class="snn-rm-status"><span class="snn-rm-dot"></span><?= esc_html( $col['title'] ) ?></span><?php endif; ?>
                <?= snn_rm_term_pills( $post->ID, SNN_RM_TAX_TYPE, 'snn-rm-types' ) ?>
            </div>
            <h2 class="snn-rm-detail-title" id="snn-rm-modal-title"><?= esc_html( get_the_title( $post ) ) ?></h2>
            <?php if ( has_post_thumbnail( $post ) ) : ?>
                <div class="snn-rm-detail-thumb"><?= get_the_post_thumbnail( $post, 'large' ) ?></div>
            <?php endif; ?>
            <div class="snn-rm-detail-content">
                <?php
                $GLOBALS['post'] = $post;
                setup_postdata( $post );
                echo apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput
                wp_reset_postdata();
                ?>
            </div>
            <?= snn_rm_term_pills( $post->ID, SNN_RM_TAX_TAG, 'snn-rm-tags' ) ?>
        <?php endif; ?>

        <div class="snn-rm-detail-bar">
            <?= snn_rm_vote_button( $post->ID, 'is-large' ) ?>
            <?php if ( $s['votes'] && ! is_user_logged_in() ) : ?>
                <span class="snn-rm-hint"><a href="<?= esc_url( wp_login_url( get_permalink( $post ) ) ) ?>">Log in</a> to vote and comment.</span>
            <?php endif; ?>
            <?php if ( current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $post->ID ) ) : ?>
                <a class="snn-rm-edit-item" href="<?= esc_url( get_edit_post_link( $post->ID ) ) ?>">Edit item</a>
            <?php endif; ?>
        </div>

        <?php if ( snn_rm_comments_allowed( $post ) ) echo snn_rm_comments_html( $post ); ?>
    </div>
    <?php
    return ob_get_clean();
}

// Single roadmap page: append vote bar + comments after the content.
add_filter( 'the_content', function ( $content ) {
    if ( ! snn_rm_enabled() || ! is_singular( SNN_RM_PT ) || ! in_the_loop() || ! is_main_query() ) return $content;
    if ( post_password_required() ) return $content;
    static $done = false;
    if ( $done ) return $content;
    $done = true;
    return $content . snn_rm_mark_assets() . snn_rm_detail_html( get_the_ID(), false );
}, 20 );

// ------------------------------------------------------------
// Comments
// ------------------------------------------------------------

function snn_rm_edit_deadline( $comment ) {
    $hours = (int) snn_rm_settings()['edit_hours'];
    if ( $hours <= 0 ) return 0;
    return strtotime( $comment->comment_date_gmt . ' UTC' ) + $hours * HOUR_IN_SECONDS;
}

function snn_rm_is_moderator() {
    return current_user_can( 'moderate_comments' );
}

function snn_rm_can_modify( $comment, $user_id = null ) {
    $user_id = $user_id ?? get_current_user_id();
    if ( $user_id && snn_rm_is_moderator() ) return true;
    if ( ! $user_id || (int) $comment->user_id !== (int) $user_id ) return false;
    $deadline = snn_rm_edit_deadline( $comment );
    return $deadline && time() < $deadline;
}

function snn_rm_comment_html( $c, $replies = [], $can_reply = false ) {
    $mine     = snn_rm_can_modify( $c );
    $edited   = get_comment_meta( $c->comment_ID, '_snn_rm_edited', true );
    $time     = strtotime( $c->comment_date_gmt . ' UTC' );
    $is_reply = (int) $c->comment_parent > 0;

    ob_start(); ?>
    <li class="snn-rm-c<?= $is_reply ? ' is-reply' : '' ?><?= $c->comment_approved !== '1' ? ' is-pending' : '' ?>" id="comment-<?= (int) $c->comment_ID ?>" data-id="<?= (int) $c->comment_ID ?>"<?= $mine && ! snn_rm_is_moderator() ? ' data-until="' . (int) snn_rm_edit_deadline( $c ) . '"' : '' ?>>
        <img class="snn-rm-avatar" src="<?= esc_url( get_avatar_url( $c, [ 'size' => 64 ] ) ) ?>" alt="" width="32" height="32" loading="lazy">
        <div class="snn-rm-c-main">
            <div class="snn-rm-c-head">
                <strong><?= esc_html( get_comment_author( $c ) ) ?></strong>
                <time datetime="<?= esc_attr( gmdate( 'c', $time ) ) ?>" title="<?= esc_attr( get_comment_date( '', $c ) . ' ' . get_comment_time( '', false, true, $c ) ) ?>"><?= esc_html( sprintf( '%s ago', human_time_diff( $time ) ) ) ?></time>
                <?php if ( $edited ) : ?><span class="snn-rm-edited">(edited)</span><?php endif; ?>
                <?php if ( $c->comment_approved !== '1' ) : ?><span class="snn-rm-pending">Awaiting moderation</span><?php endif; ?>
            </div>
            <div class="snn-rm-c-body"><?= snn_rm_sanitize_html( $c->comment_content ) ?></div>
            <div class="snn-rm-c-actions">
                <?php if ( $can_reply && ! $is_reply ) : ?><button type="button" class="snn-rm-act-reply">Reply</button><?php endif; ?>
                <?php if ( $mine ) : ?>
                    <button type="button" class="snn-rm-act-edit">Edit</button>
                    <button type="button" class="snn-rm-act-delete">Delete</button>
                <?php endif; ?>
            </div>
            <?php if ( ! $is_reply ) : ?>
                <ol class="snn-rm-replies"><?php foreach ( $replies as $r ) echo snn_rm_comment_html( $r ); ?></ol>
            <?php endif; ?>
        </div>
    </li>
    <?php
    return ob_get_clean();
}

function snn_rm_comments_html( $post ) {
    $post    = get_post( $post );
    $user_id = get_current_user_id();

    $args = [ 'post_id' => $post->ID, 'status' => 'approve', 'type' => 'comment', 'orderby' => 'comment_date_gmt', 'order' => 'ASC' ];
    if ( $user_id ) $args['include_unapproved'] = [ $user_id ];
    $all = get_comments( $args );

    // Build a one-level tree: deeper replies (e.g. from wp-admin) attach to their top-level ancestor.
    $by_id = [];
    foreach ( $all as $c ) $by_id[ $c->comment_ID ] = $c;
    $top = []; $replies = [];
    foreach ( $all as $c ) {
        $root = $c;
        $guard = 0;
        while ( $root->comment_parent && isset( $by_id[ $root->comment_parent ] ) && $guard++ < 20 ) $root = $by_id[ $root->comment_parent ];
        if ( $root === $c ) {
            if ( ! $c->comment_parent ) $top[] = $c; // orphaned replies (parent deleted) are skipped
        } elseif ( ! $root->comment_parent ) {
            $replies[ $root->comment_ID ][] = $c;
        }
    }

    ob_start(); ?>
    <section class="snn-rm-comments" id="comments" data-post="<?= (int) $post->ID ?>">
        <h3 class="snn-rm-comments-title">Comments <span class="snn-rm-comments-count"><?= count( $all ) ?></span></h3>
        <ol class="snn-rm-clist">
            <?php foreach ( $top as $c ) echo snn_rm_comment_html( $c, $replies[ $c->comment_ID ] ?? [], (bool) $user_id ); ?>
        </ol>
        <?php if ( ! $top ) : ?><p class="snn-rm-no-comments">No comments yet. Start the conversation.</p><?php endif; ?>
        <?php if ( $user_id ) : ?>
            <div class="snn-rm-composer" data-parent="0"></div>
        <?php else : ?>
            <p class="snn-rm-login-note"><a href="<?= esc_url( wp_login_url( get_permalink( $post ) ) ) ?>">Log in</a> to join the discussion.</p>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

// ============================================================
// 6. FRONT-END AJAX
// ============================================================

function snn_rm_ajax_guard( $need_login = true ) {
    if ( ! snn_rm_enabled() ) wp_send_json_error( [ 'message' => 'Roadmap is disabled.' ], 404 );
    if ( $need_login && ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Please log in first.', 'login' => true ], 401 );
    if ( $need_login ) check_ajax_referer( 'snn_rm', 'nonce' );
}

function snn_rm_ajax_post() {
    $post = get_post( absint( $_POST['post_id'] ?? $_GET['post_id'] ?? 0 ) );
    if ( ! snn_rm_is_public_item( $post ) ) wp_send_json_error( [ 'message' => 'Item not found.' ], 404 );
    return $post;
}

// Detail (public)
add_action( 'wp_ajax_snn_rm_detail',        'snn_rm_ajax_detail' );
add_action( 'wp_ajax_nopriv_snn_rm_detail', 'snn_rm_ajax_detail' );
function snn_rm_ajax_detail() {
    snn_rm_ajax_guard( false );
    $post = snn_rm_ajax_post();
    wp_send_json_success( [ 'html' => snn_rm_detail_html( $post ), 'url' => get_permalink( $post ) ] );
}

// Vote toggle
add_action( 'wp_ajax_snn_rm_vote',        'snn_rm_ajax_vote' );
add_action( 'wp_ajax_nopriv_snn_rm_vote', 'snn_rm_ajax_vote' );
function snn_rm_ajax_vote() {
    snn_rm_ajax_guard();
    if ( ! snn_rm_settings()['votes'] ) wp_send_json_error( [ 'message' => 'Voting is off.' ], 403 );
    $post    = snn_rm_ajax_post();
    $user_id = get_current_user_id();

    $voters = snn_rm_voters( $post->ID );
    $voted  = in_array( $user_id, $voters, true );
    $voters = $voted ? array_values( array_diff( $voters, [ $user_id ] ) ) : array_merge( $voters, [ $user_id ] );

    update_post_meta( $post->ID, '_snn_rm_voters', $voters );
    update_post_meta( $post->ID, '_snn_rm_votes', count( $voters ) );

    wp_send_json_success( [ 'voted' => ! $voted, 'count' => count( $voters ) ] );
}

// New comment / reply
add_action( 'wp_ajax_snn_rm_comment',        'snn_rm_ajax_comment' );
add_action( 'wp_ajax_nopriv_snn_rm_comment', 'snn_rm_ajax_comment' );
function snn_rm_ajax_comment() {
    snn_rm_ajax_guard();
    $post = snn_rm_ajax_post();
    if ( ! snn_rm_comments_allowed( $post ) ) wp_send_json_error( [ 'message' => 'Comments are closed.' ], 403 );

    $content = snn_rm_sanitize_html( wp_unslash( $_POST['content'] ?? '' ) );
    if ( snn_rm_html_is_empty( $content ) ) wp_send_json_error( [ 'message' => 'Write something first.' ] );
    if ( mb_strlen( wp_strip_all_tags( $content ) ) > 5000 || strlen( $content ) > 20000 ) wp_send_json_error( [ 'message' => 'Comment is too long (5000 characters max).' ] );

    // One level of replies only: replying to a reply attaches to its parent.
    $parent = absint( $_POST['parent'] ?? 0 );
    if ( $parent ) {
        $pc = get_comment( $parent );
        if ( ! $pc || (int) $pc->comment_post_ID !== $post->ID || ( $pc->comment_approved !== '1' && (int) $pc->user_id !== get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => 'The comment you replied to no longer exists.' ] );
        }
        if ( $pc->comment_parent ) $parent = (int) $pc->comment_parent;
    }

    $user = wp_get_current_user();
    $data = [
        'comment_post_ID'      => $post->ID,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_author_url'   => $user->user_url,
        'comment_content'      => $content,
        'comment_parent'       => $parent,
        'comment_type'         => 'comment',
        'user_id'              => $user->ID,
        'comment_author_IP'    => preg_replace( '/[^0-9a-fA-F:., ]/', '', $_SERVER['REMOTE_ADDR'] ?? '' ),
        'comment_agent'        => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 254 ),
        'comment_date'         => current_time( 'mysql' ),
        'comment_date_gmt'     => current_time( 'mysql', true ),
    ];

    // Core moderation, blocklist, duplicate and flood checks.
    $approved = wp_allow_comment( $data, true );
    if ( is_wp_error( $approved ) ) wp_send_json_error( [ 'message' => wp_strip_all_tags( $approved->get_error_message() ) ] );
    if ( in_array( $approved, [ 'spam', 'trash' ], true ) ) wp_send_json_error( [ 'message' => 'Your comment could not be posted.' ] );

    $data['comment_approved'] = $approved;
    $id = wp_insert_comment( wp_slash( $data ) );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'Could not save the comment.' ] );

    do_action( 'comment_post', $id, $approved, $data ); // moderator / author notifications

    wp_send_json_success( [
        'html'     => snn_rm_comment_html( get_comment( $id ), [], true ),
        'parent'   => $parent,
        'approved' => $approved === 1 || $approved === '1',
    ] );
}

function snn_rm_ajax_own_comment() {
    $c = get_comment( absint( $_POST['comment_id'] ?? 0 ) );
    if ( ! $c || ! snn_rm_is_public_item( $c->comment_post_ID ) ) wp_send_json_error( [ 'message' => 'Comment not found.' ], 404 );
    if ( ! snn_rm_can_modify( $c ) ) wp_send_json_error( [ 'message' => 'The edit window for this comment has closed.' ], 403 );
    return $c;
}

// Edit own comment within the edit window
add_action( 'wp_ajax_snn_rm_comment_edit', function () {
    snn_rm_ajax_guard();
    $c = snn_rm_ajax_own_comment();

    $content = snn_rm_sanitize_html( wp_unslash( $_POST['content'] ?? '' ) );
    if ( snn_rm_html_is_empty( $content ) ) wp_send_json_error( [ 'message' => 'Comment cannot be empty.' ] );
    if ( mb_strlen( wp_strip_all_tags( $content ) ) > 5000 || strlen( $content ) > 20000 ) wp_send_json_error( [ 'message' => 'Comment is too long (5000 characters max).' ] );

    // Direct update: wp_update_comment() would run kses for non-admins and strip the editor's formatting.
    global $wpdb;
    $wpdb->update( $wpdb->comments, [ 'comment_content' => $content ], [ 'comment_ID' => $c->comment_ID ] );
    clean_comment_cache( $c->comment_ID );
    update_comment_meta( $c->comment_ID, '_snn_rm_edited', time() );
    do_action( 'edit_comment', (int) $c->comment_ID, (array) get_comment( $c->comment_ID ) );

    wp_send_json_success( [ 'html' => snn_rm_sanitize_html( $content ) ] );
} );

// Delete own comment within the edit window (only if nobody replied yet)
add_action( 'wp_ajax_snn_rm_comment_delete', function () {
    snn_rm_ajax_guard();
    $c       = snn_rm_ajax_own_comment();
    $replies = get_comments( [ 'parent' => $c->comment_ID, 'status' => 'all', 'fields' => 'ids' ] );
    if ( $replies && ! snn_rm_is_moderator() ) {
        wp_send_json_error( [ 'message' => 'This comment has replies, so it can only be edited.' ] );
    }
    // Moderators removing a thread take its replies with it.
    foreach ( $replies as $rid ) wp_delete_comment( $rid );
    wp_delete_comment( $c->comment_ID );
    wp_send_json_success();
} );

// ============================================================
// 7. FRONT-END ASSETS (printed inline, only on pages that use them)
// ============================================================

/** Returns the inline <style> the first time it is needed on a page (and queues the footer script). */
function snn_rm_mark_assets() {
    if ( did_action( 'snn_rm_assets_marked' ) ) return '';
    do_action( 'snn_rm_assets_marked' );
    add_action( 'wp_footer', 'snn_rm_print_js', 50 );
    return '<style id="snn-rm-css">' . snn_rm_front_css() . '</style>';
}

function snn_rm_print_js() {
    $cfg = [
        'ajax'     => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'snn_rm' ),
        'loggedIn' => is_user_logged_in(),
        'loginUrl' => wp_login_url( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ) ),
    ];
    echo '<script id="snn-rm-js">window.snnRM=' . wp_json_encode( $cfg ) . ';' . snn_rm_front_js() . '</script>'; // phpcs:ignore
}

// Single roadmap pages: print the CSS in <head> up front.
add_action( 'wp_head', function () {
    if ( snn_rm_enabled() && is_singular( SNN_RM_PT ) ) echo snn_rm_mark_assets(); // phpcs:ignore
} );

function snn_rm_front_css() {
    return <<<'CSS'
.snn-rm{display:grid;grid-template-columns:var(--snn-rm-grid,1fr 1fr 1fr);gap:24px;align-items:start}
@media(max-width:900px){.snn-rm{grid-template-columns:1fr}}
.snn-rm *{box-sizing:border-box}
.snn-rm-col{background:#ececec;border:1px solid #e3e3e3;border-radius:10px;overflow:hidden;min-width:0}
.snn-rm-col-head{display:flex;align-items:center;gap:10px;background:#fff;padding:18px 18px;border-bottom:1px solid #e3e3e3}
.snn-rm-col-head h2{margin:0;font-size:15px;font-weight:700;line-height:1.3;color:#111;flex:1}
.snn-rm-col-count{font-size:12px;font-weight:600;color:#6b7280;background:#f3f4f6;border-radius:99px;padding:2px 9px}
.snn-rm-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--snn-rm-c);flex:none}
.snn-rm-col-body{padding:18px;display:flex;flex-direction:column;gap:22px;min-height:120px}
.snn-rm-empty{margin:0;color:#9ca3af;font-size:14px;text-align:center;padding:20px 0}
.snn-rm-card{position:relative;background:#fff;border-radius:10px;padding:18px 16px 20px;box-shadow:0 1px 2px rgba(0,0,0,.04);transition:box-shadow .15s}
.snn-rm-card:hover{box-shadow:0 6px 18px rgba(0,0,0,.08)}
.snn-rm-card-top{display:flex;align-items:flex-start;gap:12px}
.snn-rm-card-title{flex:1;margin:0;font-size:18px;line-height:1.35;font-weight:600}
.snn-rm-card-title a{color:var(--snn-rm-c);text-decoration:none}
.snn-rm-card-title a:hover{text-decoration:underline}
.snn-rm-card-excerpt{margin:16px 0 0;font-size:14.5px;line-height:1.8;color:#1f2937}
.snn-rm-vote{display:inline-flex;align-items:center;gap:5px;flex:none;border:1px solid transparent;background:transparent;color:var(--snn-rm-c);font:inherit;font-size:15px;font-weight:600;padding:4px 8px;border-radius:8px;cursor:pointer;line-height:1;transition:background .15s,transform .1s}
.snn-rm-vote svg{opacity:.45;transition:opacity .15s}
.snn-rm-vote:hover{background:color-mix(in srgb,var(--snn-rm-c) 10%,transparent)}
.snn-rm-vote.is-voted svg{opacity:1}
.snn-rm-vote:active{transform:scale(.94)}
.snn-rm-vote.is-large{border-color:color-mix(in srgb,var(--snn-rm-c) 35%,transparent);padding:8px 14px;font-size:16px}
.snn-rm-vote.is-large.is-voted{background:var(--snn-rm-c);color:#fff}
.snn-rm-types,.snn-rm-tags{list-style:none;margin:16px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:9px}
.snn-rm-tags li,.snn-rm-types li{display:inline-flex;align-items:center;gap:9px;font-size:13px;line-height:1.2;padding:6px 10px;border-radius:4px;background:color-mix(in srgb,var(--snn-rm-c) 9%,#fff);color:var(--snn-rm-c);margin:0}
.snn-rm-types li{background:var(--snn-rm-c);color:#fff;font-weight:600;font-size:12px}
.snn-rm-types li .snn-rm-dot{display:none}
.snn-rm-tags .snn-rm-dot{width:9px;height:9px}
.snn-rm-card-comments{display:inline-flex;align-items:center;gap:5px;margin-top:14px;font-size:13px;color:#6b7280;text-decoration:none}
/* modal */
.snn-rm-modal{position:fixed;inset:0;z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding:5vh 16px;background:rgba(17,24,39,.55);overflow-y:auto;opacity:0;transition:opacity .18s}
.snn-rm-modal.is-open{opacity:1}
.snn-rm-modal-panel{position:relative;width:100%;max-width:780px;background:#fff;border-radius:14px;padding:36px 40px 40px;box-shadow:0 25px 60px rgba(0,0,0,.25);transform:translateY(12px);transition:transform .18s}
.snn-rm-modal.is-open .snn-rm-modal-panel{transform:none}
@media(max-width:600px){.snn-rm-modal-panel{padding:26px 18px 28px}}
.snn-rm-modal-close{position:absolute;top:14px;right:14px;border:0;background:#f3f4f6;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#374151}
.snn-rm-modal-close:hover{background:#e5e7eb}
.snn-rm-loading{padding:60px 0;text-align:center;color:#9ca3af}
html.snn-rm-lock{overflow:hidden}
/* detail */
.snn-rm-detail{--snn-rm-c:#6b7280}
.snn-rm-detail-meta{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.snn-rm-detail-meta .snn-rm-types{margin:0}
.snn-rm-status{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#374151}
.snn-rm-detail-title{margin:14px 40px 0 0;font-size:28px;line-height:1.25;color:var(--snn-rm-c)}
.snn-rm-detail-thumb{margin-top:22px}.snn-rm-detail-thumb img{width:100%;height:auto;border-radius:10px;display:block}
.snn-rm-detail-content{margin-top:20px;font-size:16px;line-height:1.75;color:#1f2937}
.snn-rm-detail-content img,.snn-rm-detail-content video,.snn-rm-detail-content iframe{max-width:100%;height:auto}
.snn-rm-detail-content iframe{aspect-ratio:16/9;width:100%}
.snn-rm-detail-bar{display:flex;align-items:center;flex-wrap:wrap;gap:14px;margin-top:28px;padding:16px 0;border-top:1px solid #f0f0f0;border-bottom:1px solid #f0f0f0}
.snn-rm-hint{font-size:14px;color:#6b7280}
.snn-rm-edit-item{margin-left:auto;font-size:13px;font-weight:600;color:#6b7280;text-decoration:none;border:1px solid #e5e7eb;border-radius:8px;padding:6px 12px}
.snn-rm-edit-item:hover{color:#111;border-color:#9ca3af}
.snn-rm-detail > .snn-rm-detail-bar:first-child{margin-top:32px}
/* comments */
.snn-rm-comments{margin-top:28px}
.snn-rm-comments-title{margin:0 0 16px;font-size:18px;display:flex;align-items:center;gap:8px}
.snn-rm-comments-count{font-size:12px;background:#f3f4f6;color:#6b7280;border-radius:99px;padding:2px 9px}
.snn-rm-login-note,.snn-rm-no-comments{color:#6b7280;font-size:14px}
.snn-rm-clist,.snn-rm-replies{list-style:none;margin:0;padding:0}
.snn-rm-clist{margin-bottom:8px}
.snn-rm-comments > .snn-rm-composer{margin-top:18px}
.snn-rm-c{display:flex;gap:12px;margin:0 0 20px;padding:0}
.snn-rm-replies{margin-top:14px}
.snn-rm-replies .snn-rm-c{margin-bottom:14px}
.snn-rm-avatar{width:32px;height:32px;border-radius:50%;flex:none;object-fit:cover}
.snn-rm-c-main{flex:1;min-width:0}
.snn-rm-c-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px;font-size:14px}
.snn-rm-c-head time,.snn-rm-edited{font-size:12px;color:#9ca3af}
.snn-rm-pending{font-size:11px;background:#fef3c7;color:#92400e;border-radius:4px;padding:1px 6px}
.snn-rm-c.is-pending .snn-rm-c-body{opacity:.7}
.snn-rm-c-body{margin-top:4px;font-size:15px;line-height:1.65;color:#1f2937;overflow-wrap:anywhere}
.snn-rm-c-body p,.snn-rm-c-body div{margin:0 0 .5em}
.snn-rm-c-body ul,.snn-rm-c-body ol{margin:.3em 0 .6em 1.3em;padding:0}
.snn-rm-c-body ul{list-style:disc}.snn-rm-c-body ol{list-style:decimal}
.snn-rm-c-actions{display:flex;gap:14px;margin-top:4px}
.snn-rm-c-actions button{border:0;background:none;padding:0;font:inherit;font-size:12.5px;font-weight:600;color:#6b7280;cursor:pointer}
.snn-rm-c-actions button:hover{color:#111}
.snn-rm-c-actions .snn-rm-act-delete:hover{color:#dc2626}
.snn-rm-c-main > .snn-rm-composer{margin-top:10px}
/* editor */
.snn-rm-ed{border:1px solid #d1d5db;border-radius:10px;background:#fff;overflow:hidden;transition:border-color .15s,box-shadow .15s}
.snn-rm-ed:focus-within{border-color:#9ca3af;box-shadow:0 0 0 3px rgba(156,163,175,.2)}
.snn-rm-ed-bar{display:flex;flex-wrap:wrap;align-items:center;gap:2px;padding:6px;border-bottom:1px solid #f0f0f0;background:#fafafa}
.snn-rm-ed-bar button,.snn-rm-ed-bar select{height:30px;min-width:30px;border:0;background:transparent;border-radius:6px;cursor:pointer;font:inherit;font-size:14px;color:#374151;display:inline-flex;align-items:center;justify-content:center;padding:0 6px}
.snn-rm-ed .snn-rm-ed-bar select{width:auto!important;max-width:none;flex:0 0 auto;margin:0;font-size:13px;padding:0 4px;border:0;box-shadow:none;background-color:transparent;line-height:30px}
.snn-rm-ed .snn-rm-ed-bar > *{flex:0 0 auto;margin:0}
.snn-rm-ed-bar button:hover,.snn-rm-ed-bar select:hover{background:#eef0f3}
.snn-rm-ed-bar button.is-on{background:#e5e7eb;color:#111}
.snn-rm-ed-sep{width:1px;height:18px;background:#e5e7eb;margin:0 4px}
.snn-rm-ed-colors{position:relative}
.snn-rm-ed-swatches{position:absolute;top:34px;left:0;z-index:5;display:none;grid-template-columns:repeat(4,24px);gap:6px;padding:8px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 8px 20px rgba(0,0,0,.12)}
.snn-rm-ed-colors.is-open .snn-rm-ed-swatches{display:grid}
.snn-rm-ed-swatches button{width:24px;height:24px;min-width:0;border-radius:50%;padding:0;border:2px solid #fff;box-shadow:0 0 0 1px #d1d5db}
.snn-rm-ed-area{min-height:90px;max-height:360px;overflow-y:auto;padding:12px 14px;font-size:15px;line-height:1.6;outline:none;color:#1f2937}
.snn-rm-ed-area:empty:before{content:attr(data-placeholder);color:#9ca3af;pointer-events:none}
.snn-rm-ed-area ul{list-style:disc;margin:0 0 0 1.3em;padding:0}.snn-rm-ed-area ol{list-style:decimal;margin:0 0 0 1.3em;padding:0}
.snn-rm-ed-area p,.snn-rm-ed-area div{margin:0}
.snn-rm-ed-foot{display:flex;align-items:center;gap:10px;padding:8px 10px;border-top:1px solid #f0f0f0}
.snn-rm-ed-msg{flex:1;font-size:13px;color:#dc2626}
.snn-rm-ed-msg.is-ok{color:#15803d}
.snn-rm-btn{border:0;border-radius:8px;padding:8px 16px;font:inherit;font-size:14px;font-weight:600;cursor:pointer;background:#111827;color:#fff}
.snn-rm-btn:disabled{opacity:.5;cursor:default}
.snn-rm-btn.is-ghost{background:transparent;color:#374151}
.snn-rm-btn.is-ghost:hover{background:#f3f4f6}
/* toast */
.snn-rm-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:100001;background:#111827;color:#fff;padding:12px 18px;border-radius:10px;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.snn-rm-toast a{color:#93c5fd}
CSS;
}

function snn_rm_front_js() {
    return <<<'JS'
(function(){
if(window.snnRMReady)return;window.snnRMReady=true;
var C=window.snnRM||{};
var COLORS=['#111827','#dc2626','#ea580c','#ca8a04','#16a34a','#0891b2','#2563eb','#9333ea'];
var SIZES={'2':'13px','5':'18px','6':'22px'};
var MARK='#010101';

function post(action,data){
  var fd=new FormData();fd.append('action',action);fd.append('nonce',C.nonce);
  for(var k in data)fd.append(k,data[k]);
  return fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){
    if(!j||!j.success){var e=new Error((j&&j.data&&j.data.message)||'Something went wrong.');e.login=j&&j.data&&j.data.login;throw e}
    return j.data;
  });
}
var toastTimer;
function toast(html){
  var t=document.querySelector('.snn-rm-toast');
  if(!t){t=document.createElement('div');t.className='snn-rm-toast';t.setAttribute('role','status');document.body.appendChild(t)}
  t.innerHTML=html;clearTimeout(toastTimer);toastTimer=setTimeout(function(){t.remove()},4000);
}
function needLogin(){toast('Please <a href="'+C.loginUrl+'">log in</a> to vote and comment.')}
function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML}

/* ---------- editor ---------- */
function unwrap(el){var p=el.parentNode;while(el.firstChild)p.insertBefore(el.firstChild,el);p.removeChild(el)}
function normalize(area){
  area.querySelectorAll('font').forEach(function(f){
    var st='',c=(f.getAttribute('color')||'').toLowerCase(),sz=f.getAttribute('size');
    if(c&&c!==MARK)st+='color:'+c+';';
    if(sz&&SIZES[sz])st+='font-size:'+SIZES[sz]+';';
    if(!st){unwrap(f);return}
    var s=document.createElement('span');s.setAttribute('style',st);
    while(f.firstChild)s.appendChild(f.firstChild);f.parentNode.replaceChild(s,f);
  });
  area.querySelectorAll('span').forEach(function(s){if(!s.getAttribute('style'))unwrap(s)});
}
function makeEditor(host,opts){
  opts=opts||{};
  var ed=document.createElement('div');ed.className='snn-rm-ed';
  var sw=COLORS.map(function(c){return '<button type="button" data-color="'+c+'" style="background:'+c+'" title="'+c+'"></button>'}).join('')+'<button type="button" data-color="'+MARK+'" title="Default colour" style="background:linear-gradient(135deg,#fff 45%,#dc2626 45%,#dc2626 55%,#fff 55%)"></button>';
  ed.innerHTML='<div class="snn-rm-ed-bar" role="toolbar" aria-label="Formatting">'+
    '<button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>'+
    '<button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>'+
    '<button type="button" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>'+
    '<button type="button" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>'+
    '<span class="snn-rm-ed-sep"></span>'+
    '<button type="button" data-cmd="insertUnorderedList" title="Bullet list"><svg width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M4 10.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm0-6a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm0 12a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM7 19h14v-2H7v2zm0-6h14v-2H7v2zm0-8v2h14V5H7z"/></svg></button>'+
    '<button type="button" data-cmd="insertOrderedList" title="Numbered list"><svg width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1zm1-9h1V4H2v1h1v3zm-1 3h1.8L2 13.1v.9h3v-1H3.2L5 10.9V10H2v1zm5-6v2h14V5H7zm0 14h14v-2H7v2zm0-6h14v-2H7v2z"/></svg></button>'+
    '<span class="snn-rm-ed-sep"></span>'+
    '<span class="snn-rm-ed-colors"><button type="button" class="snn-rm-ed-colorbtn" title="Text colour"><svg width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M11 3 5.5 17h2.25l1.12-3h6.25l1.12 3h2.25L13 3h-2zm-1.38 9L12 5.67 14.38 12H9.62z"/><rect x="3" y="19" width="18" height="3" fill="#dc2626"/></svg></button><span class="snn-rm-ed-swatches">'+sw+'</span></span>'+
    '<select title="Text size" aria-label="Text size"><option value="">Size</option><option value="2">Small</option><option value="7">Normal</option><option value="5">Large</option><option value="6">Huge</option></select>'+
    '<span class="snn-rm-ed-sep"></span>'+
    '<button type="button" data-cmd="removeFormat" title="Clear formatting"><svg width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M3.27 5 2 6.27l6.97 6.97L6.5 19h3l1.57-3.66L16.73 21 18 19.73 3.55 5.27 3.27 5zM6 5v.18L8.82 8h2.4l-.72 1.68 2.1 2.1L14.21 8H20V5H6z"/></svg></button>'+
    '</div><div class="snn-rm-ed-area" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="'+esc(opts.placeholder||'Share your thoughts…')+'"></div>'+
    '<div class="snn-rm-ed-foot"><span class="snn-rm-ed-msg" aria-live="polite"></span>'+(opts.cancel?'<button type="button" class="snn-rm-btn is-ghost snn-rm-ed-cancel">Cancel</button>':'')+'<button type="button" class="snn-rm-btn snn-rm-ed-submit">'+esc(opts.submit||'Post comment')+'</button></div>';
  host.appendChild(ed);
  var area=ed.querySelector('.snn-rm-ed-area'),msg=ed.querySelector('.snn-rm-ed-msg'),btn=ed.querySelector('.snn-rm-ed-submit');
  if(opts.html)area.innerHTML=opts.html;
  var saved=null;
  function save(){var s=window.getSelection();if(s.rangeCount&&area.contains(s.anchorNode))saved=s.getRangeAt(0).cloneRange()}
  function restore(){area.focus();if(saved){var s=window.getSelection();s.removeAllRanges();s.addRange(saved)}}
  function exec(cmd,val){restore();try{document.execCommand('styleWithCSS',false,false)}catch(e){}document.execCommand(cmd,false,val);normalize(area);save();state()}
  function state(){ed.querySelectorAll('[data-cmd]').forEach(function(b){var c=b.dataset.cmd;if(c==='removeFormat')return;var on=false;try{on=document.queryCommandState(c)}catch(e){}b.classList.toggle('is-on',on)})}
  area.addEventListener('keyup',function(){save();state()});
  area.addEventListener('mouseup',function(){save();state()});
  area.addEventListener('input',function(){msg.textContent=''});
  area.addEventListener('paste',function(e){e.preventDefault();var t=(e.clipboardData||window.clipboardData).getData('text/plain');document.execCommand('insertText',false,t)});
  area.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();btn.click()}});
  ed.querySelector('.snn-rm-ed-bar').addEventListener('mousedown',function(e){if(e.target.closest('button'))e.preventDefault()});
  ed.querySelectorAll('[data-cmd]').forEach(function(b){b.addEventListener('click',function(){exec(b.dataset.cmd)})});
  var colors=ed.querySelector('.snn-rm-ed-colors');
  colors.querySelector('.snn-rm-ed-colorbtn').addEventListener('click',function(){colors.classList.toggle('is-open')});
  colors.querySelectorAll('[data-color]').forEach(function(b){b.addEventListener('click',function(){exec('foreColor',b.dataset.color);colors.classList.remove('is-open')})});
  document.addEventListener('click',function(e){if(!colors.contains(e.target))colors.classList.remove('is-open')});
  var sel=ed.querySelector('select');
  sel.addEventListener('change',function(){if(sel.value)exec('fontSize',sel.value);sel.value=''});
  var api={el:ed,area:area,
    html:function(){normalize(area);return area.innerHTML},
    empty:function(){return !area.textContent.replace(/ /g,' ').trim()},
    clear:function(){area.innerHTML=''},
    error:function(t,ok){msg.textContent=t||'';msg.classList.toggle('is-ok',!!ok)},
    busy:function(b){btn.disabled=b},
    focus:function(){area.focus();var r=document.createRange();r.selectNodeContents(area);r.collapse(false);var s=window.getSelection();s.removeAllRanges();s.addRange(r);save()}
  };
  btn.addEventListener('click',function(){
    if(api.empty()){api.error('Write something first.');return}
    api.busy(true);api.error('');
    Promise.resolve(opts.onSubmit(api)).catch(function(e){api.error(e.message);if(e.login)needLogin()}).then(function(){api.busy(false)});
  });
  if(opts.cancel)ed.querySelector('.snn-rm-ed-cancel').addEventListener('click',opts.cancel);
  return api;
}

/* ---------- comments ---------- */
function bump(section,d){var n=section.querySelector('.snn-rm-comments-count');if(n)n.textContent=Math.max(0,(parseInt(n.textContent,10)||0)+d)}
function initComments(root){
  root.querySelectorAll('.snn-rm-comments').forEach(function(sec){
    if(sec.dataset.ready)return;sec.dataset.ready='1';
    var postId=sec.dataset.post,host=sec.querySelector('.snn-rm-composer[data-parent="0"]');
    if(host)makeEditor(host,{onSubmit:function(api){
      return post('snn_rm_comment',{post_id:postId,parent:0,content:api.html()}).then(function(d){
        var list=sec.querySelector('.snn-rm-clist');list.insertAdjacentHTML('beforeend',d.html);
        var none=sec.querySelector('.snn-rm-no-comments');if(none)none.remove();
        api.clear();bump(sec,1);api.error(d.approved?'Posted.':'Posted — awaiting moderation.',true);
        list.lastElementChild.scrollIntoView({block:'nearest',behavior:'smooth'});
      });
    }});
    sec.addEventListener('click',function(e){
      var b=e.target.closest('button');if(!b)return;
      var li=b.closest('.snn-rm-c');if(!li)return;
      var main=li.querySelector(':scope > .snn-rm-c-main'),id=li.dataset.id;
      if(b.classList.contains('snn-rm-act-reply')){
        var ex=main.querySelector(':scope > .snn-rm-composer');if(ex){ex.remove();return}
        var h=document.createElement('div');h.className='snn-rm-composer';
        main.querySelector(':scope > .snn-rm-c-actions').after(h);
        var ed=makeEditor(h,{submit:'Reply',placeholder:'Write a reply…',cancel:function(){h.remove()},onSubmit:function(api){
          return post('snn_rm_comment',{post_id:postId,parent:id,content:api.html()}).then(function(d){
            main.querySelector(':scope > .snn-rm-replies').insertAdjacentHTML('beforeend',d.html);h.remove();bump(sec,1);
          });
        }});ed.focus();
      }
      if(b.classList.contains('snn-rm-act-edit')){
        var body=main.querySelector(':scope > .snn-rm-c-body');if(body.hidden)return;
        body.hidden=true;var eh=document.createElement('div');eh.className='snn-rm-composer';body.after(eh);
        var ed2=makeEditor(eh,{html:body.innerHTML,submit:'Save',cancel:function(){eh.remove();body.hidden=false},onSubmit:function(api){
          return post('snn_rm_comment_edit',{comment_id:id,content:api.html()}).then(function(d){
            body.innerHTML=d.html;body.hidden=false;eh.remove();
            var head=main.querySelector(':scope > .snn-rm-c-head');if(!head.querySelector('.snn-rm-edited'))head.insertAdjacentHTML('beforeend','<span class="snn-rm-edited">(edited)</span>');
          });
        }});ed2.focus();
      }
      if(b.classList.contains('snn-rm-act-delete')){
        var nr=li.querySelectorAll('.snn-rm-replies .snn-rm-c').length;
        if(!confirm(nr?'Delete this comment and its '+nr+' repl'+(nr>1?'ies':'y')+'?':'Delete this comment?'))return;
        b.disabled=true;
        post('snn_rm_comment_delete',{comment_id:id}).then(function(){li.remove();bump(sec,-1-nr)}).catch(function(err){b.disabled=false;toast(esc(err.message))});
      }
    });
  });
  // Hide edit/delete once the window closes while the page is open.
  root.querySelectorAll('.snn-rm-c[data-until]').forEach(function(li){
    var ms=li.dataset.until*1000-Date.now();
    var hide=function(){li.querySelectorAll(':scope > .snn-rm-c-main > .snn-rm-c-actions .snn-rm-act-edit, :scope > .snn-rm-c-main > .snn-rm-c-actions .snn-rm-act-delete').forEach(function(x){x.remove()})};
    if(ms<=0)hide();else if(ms<2147483647)setTimeout(hide,ms);
  });
}

/* ---------- votes ---------- */
document.addEventListener('click',function(e){
  var b=e.target.closest('.snn-rm-vote');if(!b)return;
  e.preventDefault();e.stopPropagation();
  if(!C.loggedIn){needLogin();return}
  if(b.disabled)return;b.disabled=true;
  post('snn_rm_vote',{post_id:b.dataset.id}).then(function(d){
    document.querySelectorAll('.snn-rm-vote[data-id="'+b.dataset.id+'"]').forEach(function(x){
      x.classList.toggle('is-voted',d.voted);x.setAttribute('aria-pressed',d.voted);
      x.title=d.voted?'Remove your vote':'Vote for this';x.querySelector('.snn-rm-vote-count').textContent=d.count;
    });
  }).catch(function(err){if(err.login)needLogin();else toast(esc(err.message))}).then(function(){b.disabled=false});
});

/* ---------- modal ---------- */
var modal,lastFocus,boardUrl;
function closeModal(){
  if(!modal)return;modal.classList.remove('is-open');document.documentElement.classList.remove('snn-rm-lock');
  var m=modal;setTimeout(function(){m.remove()},180);modal=null;
  if(boardUrl){history.replaceState(null,'',boardUrl);boardUrl=null}
  if(lastFocus)lastFocus.focus();
}
function openModal(id,href,hash){
  lastFocus=document.activeElement;
  modal=document.createElement('div');modal.className='snn-rm-modal';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-labelledby','snn-rm-modal-title');
  modal.innerHTML='<div class="snn-rm-modal-panel"><button type="button" class="snn-rm-modal-close" aria-label="Close"></button><div class="snn-rm-loading">Loading…</div></div>';
  modal.querySelector('.snn-rm-modal-close').innerHTML='<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
  document.body.appendChild(modal);document.documentElement.classList.add('snn-rm-lock');
  requestAnimationFrame(function(){modal&&modal.classList.add('is-open')});
  var m=modal;
  m.addEventListener('mousedown',function(e){if(e.target===m)closeModal()});
  m.querySelector('.snn-rm-modal-close').addEventListener('click',closeModal);
  m.querySelector('.snn-rm-modal-close').focus();
  boardUrl=location.href;history.replaceState(null,'',href);
  fetch(C.ajax+'?action=snn_rm_detail&post_id='+encodeURIComponent(id),{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){
    if(m!==modal)return;
    var l=m.querySelector('.snn-rm-loading');
    if(!j.success){l.textContent=(j.data&&j.data.message)||'Could not load this item.';return}
    l.insertAdjacentHTML('afterend',j.data.html);l.remove();initComments(m);
    if(hash==='#comments'){var c=m.querySelector('.snn-rm-comments');if(c)c.scrollIntoView()}
  }).catch(function(){if(m===modal)m.querySelector('.snn-rm-loading').textContent='Could not load this item.'});
}
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal){var open=modal.querySelector('.snn-rm-ed-colors.is-open');if(open)open.classList.remove('is-open');else closeModal()}});
document.addEventListener('click',function(e){
  var a=e.target.closest('a.snn-rm-open');if(!a)return;
  var board=a.closest('.snn-rm');if(!board||board.dataset.open!=='modal')return;
  if(e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
  e.preventDefault();openModal(a.dataset.id,a.href,a.hash);
});

initComments(document);
})();
JS;
}

// ============================================================
// 8. ADMIN — MENU, SAVE, AJAX
// ============================================================

add_action( 'admin_menu', function () {
    add_submenu_page( 'snn-learn', 'Roadmap', 'Roadmap', 'manage_options', 'snn-learn-roadmap', 'snn_rm_admin_page' );
}, 20 );

add_action( 'admin_init', function () {
    if ( ! isset( $_POST['snn_rm_nonce'] ) || ! current_user_can( 'manage_options' ) ) return;
    check_admin_referer( 'snn_rm_save', 'snn_rm_nonce' );

    $old = snn_rm_settings();
    $in  = wp_unslash( $_POST );

    $cols = [];
    foreach ( snn_rm_defaults()['columns'] as $i => $def ) {
        $c = (array) ( $in['snn_rm_col'][ $i ] ?? [] );
        $cols[ $i ] = [
            'title'  => sanitize_text_field( $c['title'] ?? '' ) ?: $def['title'],
            'status' => sanitize_title( $c['status'] ?? '' ),
            'color'  => sanitize_hex_color( $c['color'] ?? '' ) ?: $def['color'],
            'width'  => snn_rm_sanitize_width( $c['width'] ?? '1fr' ),
        ];
    }

    $new = [
        'enabled'       => empty( $in['snn_rm_enabled'] ) ? 0 : 1,
        'votes'         => empty( $in['snn_rm_votes'] ) ? 0 : 1,
        'comments'      => empty( $in['snn_rm_comments'] ) ? 0 : 1,
        'edit_hours'    => max( 0, min( 8760, (int) ( $in['snn_rm_edit_hours'] ?? 24 ) ) ),
        'open_mode'     => ( $in['snn_rm_open_mode'] ?? '' ) === 'page' ? 'page' : 'modal',
        'sort'          => in_array( $in['snn_rm_sort'] ?? '', [ 'votes', 'newest', 'oldest', 'manual', 'title' ], true ) ? $in['snn_rm_sort'] : 'votes',
        'excerpt_words' => max( 0, min( 200, (int) ( $in['snn_rm_excerpt_words'] ?? 30 ) ) ),
        'columns'       => $cols,
    ];

    // First save of the page may not include column fields (feature was off) — keep previous ones.
    if ( empty( $in['snn_rm_col'] ) ) {
        foreach ( [ 'votes', 'comments', 'edit_hours', 'open_mode', 'sort', 'excerpt_words', 'columns' ] as $k ) $new[ $k ] = $old[ $k ];
    }

    update_option( SNN_RM_OPTION, $new );
    if ( $new['enabled'] !== (int) $old['enabled'] ) {
        update_option( 'snn_learn_roadmap_flush', 1 );
    }

    wp_safe_redirect( add_query_arg( [ 'page' => 'snn-learn-roadmap', 'saved' => 1 ], admin_url( 'admin.php' ) ) );
    exit;
} );

function snn_rm_admin_ajax_guard() {
    check_ajax_referer( 'snn_rm_admin', 'nonce' );
    if ( ! current_user_can( 'edit_others_posts' ) || ! snn_rm_enabled() ) wp_send_json_error( [ 'message' => 'Not allowed.' ], 403 );
}

// Drag a card to another column → replace its status term.
add_action( 'wp_ajax_snn_rm_admin_move', function () {
    snn_rm_admin_ajax_guard();
    $post = get_post( absint( $_POST['post_id'] ?? 0 ) );
    if ( ! $post || $post->post_type !== SNN_RM_PT ) wp_send_json_error( [ 'message' => 'Item not found.' ] );

    $slug = sanitize_title( wp_unslash( $_POST['status'] ?? '' ) );
    if ( $slug === '' ) {
        wp_set_object_terms( $post->ID, [], SNN_RM_TAX_STATUS );
    } else {
        $term = get_term_by( 'slug', $slug, SNN_RM_TAX_STATUS );
        if ( ! $term ) wp_send_json_error( [ 'message' => 'That status does not exist.' ] );
        wp_set_object_terms( $post->ID, [ (int) $term->term_id ], SNN_RM_TAX_STATUS );
    }

    // Persist the manual order of the target column when provided.
    $order = array_filter( array_map( 'absint', explode( ',', (string) ( $_POST['order'] ?? '' ) ) ) );
    foreach ( array_values( $order ) as $i => $id ) {
        if ( get_post_type( $id ) === SNN_RM_PT ) wp_update_post( [ 'ID' => $id, 'menu_order' => $i ] );
    }

    wp_send_json_success();
} );

// Quick-add an item into a column.
add_action( 'wp_ajax_snn_rm_admin_add', function () {
    snn_rm_admin_ajax_guard();
    $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
    if ( $title === '' ) wp_send_json_error( [ 'message' => 'Give the item a title.' ] );

    $publish = ! empty( $_POST['publish'] ) && current_user_can( 'publish_posts' );
    $id = wp_insert_post( [ 'post_type' => SNN_RM_PT, 'post_title' => $title, 'post_status' => $publish ? 'publish' : 'draft', 'comment_status' => 'open' ], true );
    if ( is_wp_error( $id ) ) wp_send_json_error( [ 'message' => $id->get_error_message() ] );

    $slug = sanitize_title( wp_unslash( $_POST['status'] ?? '' ) );
    if ( $slug && ( $term = get_term_by( 'slug', $slug, SNN_RM_TAX_STATUS ) ) ) {
        wp_set_object_terms( $id, [ (int) $term->term_id ], SNN_RM_TAX_STATUS );
    }

    wp_send_json_success( [ 'html' => snn_rm_admin_card_html( get_post( $id ) ) ] );
} );

// ============================================================
// 9. ADMIN — PAGE
// ============================================================

function snn_rm_admin_card_html( $post ) {
    $terms = get_the_terms( $post, SNN_RM_TAX_TAG );
    $tags  = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : [];
    $types = get_the_terms( $post, SNN_RM_TAX_TYPE );
    ob_start(); ?>
    <div class="snn-rmb-card" draggable="true" data-id="<?= (int) $post->ID ?>">
        <div class="snn-rmb-card-top">
            <a href="<?= esc_url( get_edit_post_link( $post->ID ) ) ?>" class="snn-rmb-title"><?= esc_html( get_the_title( $post ) ?: '(no title)' ) ?></a>
            <span class="snn-rmb-votes" title="Votes">▲ <?= snn_rm_vote_count( $post->ID ) ?></span>
        </div>
        <div class="snn-rmb-meta">
            <?php if ( $post->post_status !== 'publish' ) : ?><span class="snn-rmb-badge is-<?= esc_attr( $post->post_status ) ?>"><?= esc_html( get_post_status_object( $post->post_status )->label ?? $post->post_status ) ?></span><?php endif; ?>
            <?php if ( $types && ! is_wp_error( $types ) ) foreach ( $types as $t ) : ?><span class="snn-rmb-badge"><?= esc_html( $t->name ) ?></span><?php endforeach; ?>
            <span title="Comments">💬 <?= (int) get_comments_number( $post ) ?></span>
            <?php if ( $tags ) : ?><span class="snn-rmb-tags" title="<?= esc_attr( implode( ', ', $tags ) ) ?>"><?= count( $tags ) ?> tags</span><?php endif; ?>
        </div>
        <div class="snn-rmb-links">
            <a href="<?= esc_url( get_edit_post_link( $post->ID ) ) ?>">Edit</a>
            <?php if ( $post->post_status === 'publish' ) : ?><a href="<?= esc_url( get_permalink( $post ) ) ?>" target="_blank">View</a><?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function snn_rm_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $s       = snn_rm_settings();
    $enabled = $s['enabled'] && post_type_exists( SNN_RM_PT );
    if ( $enabled ) snn_rm_ensure_default_terms();

    $status_terms = $enabled ? get_terms( [ 'taxonomy' => SNN_RM_TAX_STATUS, 'hide_empty' => false ] ) : [];
    if ( is_wp_error( $status_terms ) ) $status_terms = [];

    $card  = 'background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)';
    $h2    = 'margin:0 0 18px;font-size:16px;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;padding-bottom:14px';
    $label = 'display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151';
    $input = 'width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px';
    ?>
    <div class="wrap snn-rm-admin" style="max-width:1280px">
        <h1>SNN Learn &mdash; Roadmap</h1>

        <?php if ( isset( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>Roadmap settings saved.</strong></p></div>
        <?php endif; ?>

        <form method="post" action="" id="snn-rm-form" style="margin-top:24px">
            <?php wp_nonce_field( 'snn_rm_save', 'snn_rm_nonce' ); ?>

            <!-- Enable -->
            <div style="<?= $card ?>;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                <label style="display:flex;align-items:center;gap:12px;cursor:pointer;flex:1;min-width:260px">
                    <input type="checkbox" name="snn_rm_enabled" value="1" <?= checked( 1, $s['enabled'], false ) ?> style="width:20px;height:20px;border-radius:5px">
                    <div>
                        <div style="font-weight:700;font-size:15px;color:#111827">Enable Roadmap</div>
                        <div style="font-size:12px;color:#6b7280;margin-top:2px">Registers the <code>roadmap</code> post type with <code>roadmap-tag</code>, <code>roadmap-status</code> and <code>roadmap-type</code>, plus voting and comments.</div>
                    </div>
                </label>
                <?php submit_button( 'Save Changes', 'primary', 'submit', false ); ?>
            </div>

            <?php if ( ! $enabled ) : ?>
                <div style="<?= $card ?>;text-align:center;color:#6b7280;padding:48px 28px">
                    <div style="font-size:34px;margin-bottom:8px">🗺️</div>
                    <p style="font-size:14px;margin:0">The roadmap is off. Tick <strong>Enable Roadmap</strong> and save to set up your board.</p>
                    <p style="font-size:12px;margin:8px 0 0">Existing <code>roadmap</code> posts and terms are picked up automatically.</p>
                </div>
            <?php else : ?>

            <!-- Board -->
            <div style="<?= $card ?>">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;<?= $h2 ?>">
                    <span style="flex:1">Board <span style="font-weight:400;color:#6b7280;font-size:13px">&mdash; drag cards between columns to change their status</span></span>
                    <a class="button" href="<?= esc_url( admin_url( 'edit.php?post_type=' . SNN_RM_PT ) ) ?>">All items</a>
                    <a class="button" href="<?= esc_url( admin_url( 'edit-tags.php?taxonomy=' . SNN_RM_TAX_TAG . '&post_type=' . SNN_RM_PT ) ) ?>">Tags</a>
                    <a class="button" href="<?= esc_url( admin_url( 'edit-tags.php?taxonomy=' . SNN_RM_TAX_STATUS . '&post_type=' . SNN_RM_PT ) ) ?>">Statuses</a>
                    <a class="button" href="<?= esc_url( admin_url( 'edit-tags.php?taxonomy=' . SNN_RM_TAX_TYPE . '&post_type=' . SNN_RM_PT ) ) ?>">Types</a>
                    <a class="button button-primary" href="<?= esc_url( admin_url( 'post-new.php?post_type=' . SNN_RM_PT ) ) ?>">+ Add Item</a>
                </div>

                <?php
                $groups = snn_rm_grouped_items( [], [ 'publish', 'draft', 'pending', 'future', 'private' ] );
                $widths = implode( ' ', array_map( fn( $c ) => snn_rm_sanitize_width( $c['width'] ), $s['columns'] ) );
                ?>
                <div class="snn-rmb" id="snn-rmb" style="--snn-rm-grid:<?= esc_attr( $widths ) ?>">
                    <?php foreach ( $s['columns'] as $i => $col ) : ?>
                        <div class="snn-rmb-col" data-col="<?= $i ?>" data-status="<?= esc_attr( $col['status'] ) ?>" style="--c:<?= esc_attr( $col['color'] ) ?>">
                            <div class="snn-rmb-head"><span class="snn-rmb-dot"></span><strong class="snn-rmb-coltitle"><?= esc_html( $col['title'] ) ?></strong><span class="snn-rmb-count"><?= count( $groups[ $i ] ) ?></span></div>
                            <div class="snn-rmb-list">
                                <?php foreach ( $groups[ $i ] as $p ) echo snn_rm_admin_card_html( $p ); ?>
                            </div>
                            <div class="snn-rmb-add">
                                <input type="text" placeholder="+ Quick add item…" aria-label="Quick add item to <?= esc_attr( $col['title'] ) ?>">
                                <label title="Publish right away instead of saving as draft"><input type="checkbox" checked> Publish</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( $groups['other'] ) : ?>
                    <div class="snn-rmb-col snn-rmb-other" data-col="other" data-status="" style="--c:#9ca3af;margin-top:18px">
                        <div class="snn-rmb-head"><span class="snn-rmb-dot"></span><strong>No matching status</strong><span class="snn-rmb-count"><?= count( $groups['other'] ) ?></span>
                            <span style="font-weight:400;color:#6b7280;font-size:12px;margin-left:8px">Hidden on the front end — drag into a column to show them.</span></div>
                        <div class="snn-rmb-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">
                            <?php foreach ( $groups['other'] as $p ) echo snn_rm_admin_card_html( $p ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Columns -->
            <div style="<?= $card ?>">
                <h2 style="<?= $h2 ?>">Columns</h2>
                <p style="margin:-6px 0 18px;font-size:13px;color:#6b7280">Each column shows the items with its status. Widths are CSS grid tracks: <code>1fr</code>, <code>2fr</code>, <code>320px</code>&hellip; &mdash; the board above previews changes live.</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px">
                    <?php foreach ( $s['columns'] as $i => $col ) : ?>
                        <div class="snn-rm-colset" data-col="<?= $i ?>" style="border:1px solid #e5e7eb;border-radius:10px;padding:16px;border-top:4px solid <?= esc_attr( $col['color'] ) ?>">
                            <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#9ca3af;margin-bottom:12px">Column <?= $i + 1 ?></div>

                            <label style="<?= $label ?>">Title</label>
                            <input type="text" name="snn_rm_col[<?= $i ?>][title]" value="<?= esc_attr( $col['title'] ) ?>" data-live="title" style="<?= $input ?>;margin-bottom:14px">

                            <label style="<?= $label ?>">Shows status</label>
                            <select name="snn_rm_col[<?= $i ?>][status]" style="<?= $input ?>;margin-bottom:14px;max-width:none">
                                <option value="">— none —</option>
                                <?php foreach ( $status_terms as $t ) : ?>
                                    <option value="<?= esc_attr( $t->slug ) ?>" <?= selected( $col['status'], $t->slug, false ) ?>><?= esc_html( $t->name ) ?> (<?= (int) $t->count ?>)</option>
                                <?php endforeach; ?>
                                <?php if ( $col['status'] && ! in_array( $col['status'], wp_list_pluck( $status_terms, 'slug' ), true ) ) : ?>
                                    <option value="<?= esc_attr( $col['status'] ) ?>" selected><?= esc_html( $col['status'] ) ?> (missing)</option>
                                <?php endif; ?>
                            </select>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                                <div>
                                    <label style="<?= $label ?>">Color</label>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <input type="color" name="snn_rm_col[<?= $i ?>][color]" value="<?= esc_attr( $col['color'] ) ?>" data-live="color" style="width:42px;height:34px;padding:2px;border:1px solid #d1d5db;border-radius:8px;cursor:pointer">
                                        <code class="snn-rm-hex" style="font-size:12px"><?= esc_html( $col['color'] ) ?></code>
                                    </div>
                                </div>
                                <div>
                                    <label style="<?= $label ?>">Width</label>
                                    <input type="text" name="snn_rm_col[<?= $i ?>][width]" value="<?= esc_attr( $col['width'] ) ?>" data-live="width" list="snn-rm-widths" style="<?= $input ?>;font-family:monospace">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <datalist id="snn-rm-widths"><option value="1fr"><option value="2fr"><option value="3fr"><option value="4fr"><option value="300px"><option value="400px"></datalist>
                <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px;color:#6b7280">
                    Width presets:
                    <button type="button" class="button button-small snn-rm-preset" data-w="1fr,1fr,1fr">1 : 1 : 1</button>
                    <button type="button" class="button button-small snn-rm-preset" data-w="3fr,3fr,4fr">3 : 3 : 4</button>
                    <button type="button" class="button button-small snn-rm-preset" data-w="1fr,1fr,2fr">1 : 1 : 2</button>
                    <button type="button" class="button button-small snn-rm-preset" data-w="2fr,1fr,1fr">2 : 1 : 1</button>
                </div>
            </div>

            <!-- Behaviour -->
            <div style="<?= $card ?>">
                <h2 style="<?= $h2 ?>">Behaviour</h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:22px">
                    <div>
                        <label style="<?= $label ?>">Clicking a title opens</label>
                        <select name="snn_rm_open_mode" style="<?= $input ?>;max-width:none">
                            <option value="modal" <?= selected( $s['open_mode'], 'modal', false ) ?>>A popup on the board</option>
                            <option value="page" <?= selected( $s['open_mode'], 'page', false ) ?>>The item's own page</option>
                        </select>
                    </div>
                    <div>
                        <label style="<?= $label ?>">Sort cards by</label>
                        <select name="snn_rm_sort" style="<?= $input ?>;max-width:none">
                            <?php foreach ( [ 'votes' => 'Most votes', 'newest' => 'Newest first', 'oldest' => 'Oldest first', 'manual' => 'Manual order (drag on board)', 'title' => 'Title A–Z' ] as $k => $v ) : ?>
                                <option value="<?= $k ?>" <?= selected( $s['sort'], $k, false ) ?>><?= esc_html( $v ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="<?= $label ?>">Excerpt length (words)</label>
                        <input type="number" min="0" max="200" name="snn_rm_excerpt_words" value="<?= (int) $s['excerpt_words'] ?>" style="<?= $input ?>">
                        <p style="font-size:11px;color:#9ca3af;margin:4px 0 0">Used when an item has no manual excerpt. 0 hides it.</p>
                    </div>
                    <div>
                        <label style="<?= $label ?>">Comment edit window (hours)</label>
                        <input type="number" min="0" max="8760" name="snn_rm_edit_hours" value="<?= (int) $s['edit_hours'] ?>" style="<?= $input ?>">
                        <p style="font-size:11px;color:#9ca3af;margin:4px 0 0">How long members can edit or delete their own comments. 0 disables.</p>
                    </div>
                </div>
                <div style="display:flex;gap:28px;flex-wrap:wrap;margin-top:22px;padding-top:18px;border-top:1px solid #f3f4f6">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600"><input type="checkbox" name="snn_rm_votes" value="1" <?= checked( 1, $s['votes'], false ) ?>> Allow up-votes (logged-in members)</label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600"><input type="checkbox" name="snn_rm_comments" value="1" <?= checked( 1, $s['comments'], false ) ?>> Allow comments &amp; replies (logged-in members)</label>
                </div>
            </div>

            <!-- Shortcode -->
            <div style="<?= $card ?>">
                <h2 style="<?= $h2 ?>">Shortcode</h2>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <code id="snn-rm-sc" style="font-size:14px;padding:8px 12px;background:#f3f4f6;border-radius:8px">[snn_learn_roadmap]</code>
                    <button type="button" class="button" id="snn-rm-copy">Copy</button>
                </div>
                <p style="font-size:13px;color:#6b7280;margin:14px 0 0;line-height:1.7">
                    Optional filters by slug (comma-separated): <code>[snn_learn_roadmap type="live-stream"]</code>, <code>[snn_learn_roadmap tag="wordpress,bricks-builder"]</code>.<br>
                    Item pages live at <code><?= esc_html( home_url( '/' . SNN_RM_PT . '/item-slug/' ) ) ?></code> and show the vote button and comments under the content.
                </p>
            </div>

            <?php submit_button( 'Save Changes' ); ?>
            <?php endif; ?>
        </form>
    </div>

    <?php if ( $enabled ) : ?>
    <style>
        .snn-rmb{display:grid;grid-template-columns:var(--snn-rm-grid);gap:16px;align-items:start}
        @media(max-width:960px){.snn-rmb{grid-template-columns:1fr}}
        .snn-rmb-col{background:#f3f4f6;border-radius:10px;padding:10px;min-width:0}
        .snn-rmb-head{display:flex;align-items:center;gap:8px;padding:6px 6px 12px;font-size:14px}
        .snn-rmb-head strong{flex:0 1 auto}
        .snn-rmb-dot{width:10px;height:10px;border-radius:50%;background:var(--c);flex:none}
        .snn-rmb-count{margin-left:auto;font-size:12px;background:#fff;color:#6b7280;border-radius:99px;padding:1px 9px}
        .snn-rmb-other .snn-rmb-count{margin-left:8px}
        .snn-rmb-list{min-height:60px;display:flex;flex-direction:column;gap:10px;border-radius:8px;transition:background .15s}
        .snn-rmb-list.is-over{background:color-mix(in srgb,var(--c) 14%,transparent);outline:2px dashed color-mix(in srgb,var(--c) 50%,transparent);outline-offset:2px}
        .snn-rmb-card{background:#fff;border-radius:8px;padding:12px 12px 10px;border-left:3px solid var(--c);box-shadow:0 1px 2px rgba(0,0,0,.06);cursor:grab}
        .snn-rmb-card.is-dragging{opacity:.4}
        .snn-rmb-card.is-saving{opacity:.6;pointer-events:none}
        .snn-rmb-card-top{display:flex;gap:8px;align-items:flex-start}
        .snn-rmb-title{flex:1;font-weight:600;font-size:13.5px;line-height:1.4;color:#111827;text-decoration:none}
        .snn-rmb-title:hover{color:#2271b1}
        .snn-rmb-votes{font-size:12px;font-weight:600;color:var(--c);white-space:nowrap}
        .snn-rmb-meta{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;margin-top:8px;font-size:11.5px;color:#6b7280}
        .snn-rmb-badge{background:#eef2f7;color:#374151;border-radius:4px;padding:1px 6px;font-weight:600}
        .snn-rmb-badge.is-draft,.snn-rmb-badge.is-pending{background:#fef3c7;color:#92400e}
        .snn-rmb-badge.is-private{background:#fee2e2;color:#991b1b}
        .snn-rmb-links{display:flex;gap:12px;margin-top:8px;font-size:12px;opacity:0;transition:opacity .15s}
        .snn-rmb-card:hover .snn-rmb-links,.snn-rmb-card:focus-within .snn-rmb-links{opacity:1}
        .snn-rmb-add{display:flex;align-items:center;gap:8px;margin-top:10px}
        .snn-rmb-add input[type=text]{flex:1;min-width:0;border:1px dashed #cbd5e1;background:transparent;border-radius:8px;padding:7px 10px;font-size:13px}
        .snn-rmb-add input[type=text]:focus{background:#fff;border-style:solid}
        .snn-rmb-add label{font-size:11.5px;color:#6b7280;display:flex;align-items:center;gap:4px;white-space:nowrap}
    </style>
    <script>
    (function(){
        var ajax = <?= wp_json_encode( admin_url( 'admin-ajax.php' ) ) ?>, nonce = <?= wp_json_encode( wp_create_nonce( 'snn_rm_admin' ) ) ?>;
        var sortManual = <?= wp_json_encode( $s['sort'] === 'manual' ) ?>;
        var board = document.getElementById('snn-rmb');

        function post(action, data){
            var fd = new FormData(); fd.append('action', action); fd.append('nonce', nonce);
            Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
            return fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
                if (!j.success) throw new Error((j.data && j.data.message) || 'Request failed');
                return j.data;
            });
        }
        function recount(){
            document.querySelectorAll('.snn-rmb-col').forEach(function(c){
                var n = c.querySelector('.snn-rmb-count'); if (n) n.textContent = c.querySelectorAll('.snn-rmb-card').length;
            });
        }

        // ---- drag & drop ----
        var dragging = null, from = null, fromNext = null;
        document.addEventListener('dragstart', function(e){
            var card = e.target.closest && e.target.closest('.snn-rmb-card'); if (!card) return;
            dragging = card; from = card.parentNode; fromNext = card.nextSibling;
            card.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', card.dataset.id); } catch(x){}
        });
        document.addEventListener('dragend', function(){
            if (dragging) dragging.classList.remove('is-dragging');
            document.querySelectorAll('.snn-rmb-list.is-over').forEach(function(l){ l.classList.remove('is-over'); });
        });
        function afterEl(list, y){
            var els = [].slice.call(list.querySelectorAll('.snn-rmb-card:not(.is-dragging)')), best = null, off = -Infinity;
            els.forEach(function(el){ var b = el.getBoundingClientRect(), d = y - b.top - b.height/2; if (d < 0 && d > off){ off = d; best = el; } });
            return best;
        }
        document.querySelectorAll('.snn-rmb-list').forEach(function(list){
            list.addEventListener('dragover', function(e){
                if (!dragging) return; e.preventDefault(); list.classList.add('is-over');
                if (list.style.display !== 'grid') { var a = afterEl(list, e.clientY); a ? list.insertBefore(dragging, a) : list.appendChild(dragging); }
                else if (dragging.parentNode !== list) list.appendChild(dragging);
            });
            list.addEventListener('dragleave', function(e){ if (!list.contains(e.relatedTarget)) list.classList.remove('is-over'); });
            list.addEventListener('drop', function(e){
                e.preventDefault(); list.classList.remove('is-over'); if (!dragging) return;
                var card = dragging, col = list.closest('.snn-rmb-col'), origin = from, originNext = fromNext; dragging = null;
                if (list === origin && !sortManual) return; // same column, order not persisted
                card.style.setProperty('--c', getComputedStyle(col).getPropertyValue('--c'));
                card.classList.add('is-saving'); recount();
                var order = sortManual ? [].map.call(list.querySelectorAll('.snn-rmb-card'), function(c){ return c.dataset.id; }).join(',') : '';
                post('snn_rm_admin_move', {post_id: card.dataset.id, status: col.dataset.status, order: order})
                    .then(function(){ card.classList.remove('is-saving'); })
                    .catch(function(err){ alert(err.message); origin.insertBefore(card, originNext); card.classList.remove('is-saving'); card.style.removeProperty('--c'); recount(); });
            });
        });

        // ---- quick add ----
        document.querySelectorAll('.snn-rmb-add input[type=text]').forEach(function(inp){
            inp.addEventListener('keydown', function(e){
                if (e.key !== 'Enter') return; e.preventDefault();
                var title = inp.value.trim(); if (!title) return;
                var col = inp.closest('.snn-rmb-col'), pub = inp.parentNode.querySelector('input[type=checkbox]').checked;
                inp.disabled = true;
                post('snn_rm_admin_add', {title: title, status: col.dataset.status, publish: pub ? 1 : ''}).then(function(d){
                    col.querySelector('.snn-rmb-list').insertAdjacentHTML('afterbegin', d.html); inp.value = ''; recount();
                }).catch(function(err){ alert(err.message); }).then(function(){ inp.disabled = false; inp.focus(); });
            });
        });

        // ---- live preview of column settings ----
        function syncWidths(){
            var w = [].map.call(document.querySelectorAll('[data-live=width]'), function(i){ return i.value.trim() || '1fr'; }).join(' ');
            board.style.setProperty('--snn-rm-grid', w);
        }
        document.querySelectorAll('.snn-rm-colset').forEach(function(set){
            var i = set.dataset.col, col = board.querySelector('.snn-rmb-col[data-col="'+i+'"]');
            set.querySelector('[data-live=title]').addEventListener('input', function(){ col.querySelector('.snn-rmb-coltitle').textContent = this.value; });
            set.querySelector('[data-live=color]').addEventListener('input', function(){
                col.style.setProperty('--c', this.value); set.style.borderTopColor = this.value; set.querySelector('.snn-rm-hex').textContent = this.value;
                col.querySelectorAll('.snn-rmb-card').forEach(function(c){ c.style.removeProperty('--c'); });
            });
            set.querySelector('[data-live=width]').addEventListener('input', syncWidths);
        });
        document.querySelectorAll('.snn-rm-preset').forEach(function(b){
            b.addEventListener('click', function(){
                var w = b.dataset.w.split(',');
                document.querySelectorAll('[data-live=width]').forEach(function(inp, i){ inp.value = w[i]; });
                syncWidths();
            });
        });

        // ---- copy shortcode ----
        document.getElementById('snn-rm-copy').addEventListener('click', function(){
            var b = this; navigator.clipboard.writeText(document.getElementById('snn-rm-sc').textContent).then(function(){ b.textContent = 'Copied!'; setTimeout(function(){ b.textContent = 'Copy'; }, 1500); });
        });
    })();
    </script>
    <?php endif;
}
