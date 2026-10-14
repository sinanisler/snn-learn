<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// COMMUNITY
// ------------------------------------------------------------
// A simple members-only forum: topics in categories, one-level
// threaded replies, a small rich-text editor and image uploads.
// Images are converted to WebP in the browser before they are sent;
// the server checks every file again and stores only WebP in
// wp-content/uploads/snn-learn-uploads/.
// Everything is off by default: nobody sees the forum and nobody can
// post until SNN Learn → Community switches it on.
// Shortcode: [snn_learn_community category=""]
// ============================================================

const SNN_CM_PT         = 'snn_community';
const SNN_CM_TAX        = 'snn-community-category';
const SNN_CM_OPTION     = 'snn_learn_community';
const SNN_CM_DIR        = 'snn-learn-uploads';
const SNN_CM_MAX_BYTES  = 5242880;    // 5 MB per image, checked on the server too
const SNN_CM_MAX_PIXELS = 25000000;   // decompression-bomb guard (GD needs ~4 bytes per pixel)
const SNN_CM_MAX_SIDE   = 10000;      // no single side longer than this
const SNN_CM_HARD_WIDTH = 2560;       // widest image the server will store

// ============================================================
// 1. SETTINGS
// ============================================================

function snn_cm_defaults() {
    return [
        // Access
        'enabled'       => 0,
        'visibility'    => 'members', // members | public (public: guests can read, never post)
        // Posting — off by default so the site stays closed until you open it
        'allow_topics'  => 0,
        'allow_replies' => 0,
        'moderate'      => 1,         // new topics and replies wait for approval
        'edit_hours'    => 24,        // members can edit/delete their own replies for this long
        'max_length'    => 5000,      // characters per topic or reply
        'post_rate'     => 5,         // topics + replies per member per 10 minutes
        // Images
        'allow_images'  => 1,
        'max_images'    => 5,         // per topic or reply
        'image_width'   => 1600,      // the browser resizes to this width before upload
        'image_quality' => 80,        // WebP quality 40–100
        'upload_rate'   => 30,        // uploads per member per 10 minutes
        // Display
        'per_page'      => 20,
    ];
}

function snn_cm_settings() {
    static $cache = null;
    if ( null !== $cache ) return $cache;

    $saved = get_option( SNN_CM_OPTION, [] );
    return $cache = array_merge( snn_cm_defaults(), is_array( $saved ) ? $saved : [] );
}

function snn_cm_enabled() {
    return (bool) snn_cm_settings()['enabled'];
}

// ============================================================
// 2. POST TYPE & CATEGORIES
// ============================================================

add_action( 'init', 'snn_cm_register', 20 );
function snn_cm_register() {
    if ( ! snn_cm_enabled() ) return;

    if ( ! post_type_exists( SNN_CM_PT ) ) {
        register_post_type( SNN_CM_PT, [
            'labels'              => [
                'name'          => 'Community Topics',
                'singular_name' => 'Community Topic',
                'add_new'       => 'Add Topic',
                'add_new_item'  => 'Add Community Topic',
                'edit_item'     => 'Edit Topic',
                'search_items'  => 'Search Topics',
                'not_found'     => 'No topics found',
                'menu_name'     => 'Community Topics',
            ],
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => 'snn-learn',
            'show_in_rest'        => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'supports'            => [ 'title', 'editor', 'author', 'comments' ],
        ] );
    }

    if ( ! taxonomy_exists( SNN_CM_TAX ) ) {
        register_taxonomy( SNN_CM_TAX, SNN_CM_PT, [
            'labels'            => [
                'name'          => 'Community Categories',
                'singular_name' => 'Community Category',
                'menu_name'     => 'Categories',
            ],
            'hierarchical'      => false,
            'public'            => false,
            'show_ui'           => true,
            'show_in_menu'      => 'snn-learn',
            'show_in_rest'      => false,
            'show_admin_column' => true,
            'rewrite'           => false,
        ] );
    }
    register_taxonomy_for_object_type( SNN_CM_TAX, SNN_CM_PT );
}

// Topics accept comments by default. Whether members may actually reply is a setting.
add_filter( 'get_default_comment_status', function ( $status, $post_type ) {
    return $post_type === SNN_CM_PT ? 'open' : $status;
}, 10, 2 );

// Topics never take comments through WordPress's own comment form (wp-comments-post.php).
// That endpoint would accept guests and skip the community's checks: membership, the replies
// switch, moderation, sanitising and rate limits. All replies go through snn_cm_ajax_reply().
add_filter( 'comments_open', function ( $open, $post_id ) {
    $post = get_post( $post_id );
    return ( $post && $post->post_type === SNN_CM_PT ) ? false : $open;
}, 10, 2 );

/** Create one starter category so the sidebar is never empty. */
function snn_cm_ensure_default_terms() {
    if ( ! taxonomy_exists( SNN_CM_TAX ) || term_exists( 'general', SNN_CM_TAX ) ) return;
    if ( get_terms( [ 'taxonomy' => SNN_CM_TAX, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ] ) ) return;
    wp_insert_term( 'General', SNN_CM_TAX, [ 'slug' => 'general' ] );
}

// ============================================================
// 3. PERMISSIONS & HELPERS
// ============================================================

/**
 * Who counts as a member. Default: any logged-in user.
 * Hook this to your membership plugin to require a paid plan:
 *   add_filter( 'snn_community_is_member', fn( $ok, $user_id ) => $ok && my_user_has_plan( $user_id ), 10, 2 );
 */
function snn_cm_is_member( $user_id = null ) {
    $user_id = $user_id ?? get_current_user_id();
    return (bool) apply_filters( 'snn_community_is_member', $user_id > 0, $user_id );
}

function snn_cm_is_moderator() {
    return current_user_can( 'moderate_comments' );
}

function snn_cm_can_view() {
    if ( ! snn_cm_enabled() ) return false;
    return snn_cm_settings()['visibility'] === 'public' || snn_cm_is_member();
}

function snn_cm_can_post_topic() {
    return snn_cm_enabled() && (bool) snn_cm_settings()['allow_topics'] && snn_cm_is_member();
}

function snn_cm_can_reply() {
    return snn_cm_enabled() && (bool) snn_cm_settings()['allow_replies'] && snn_cm_is_member();
}

/**
 * Images need the images switch, some form of posting (no point storing files nobody can attach),
 * and GD with WebP support on the server.
 */
function snn_cm_images_allowed() {
    $s = snn_cm_settings();
    return (bool) $s['allow_images']
        && ( (bool) $s['allow_topics'] || (bool) $s['allow_replies'] )
        && function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromwebp' );
}

/** Sign-up page for guests. Reuses the roadmap's filterable register URL. */
function snn_cm_register_url() {
    return function_exists( 'snn_rm_register_url' ) ? snn_rm_register_url() : home_url( '/register/' );
}

/** Published topics, plus pending ones for their author and for moderators. */
function snn_cm_topic_visible( $post ) {
    $post = get_post( $post );
    if ( ! $post || $post->post_type !== SNN_CM_PT ) return false;
    if ( $post->post_status === 'publish' ) return true;
    return $post->post_status === 'pending'
        && ( snn_cm_is_moderator() || ( is_user_logged_in() && (int) $post->post_author === get_current_user_id() ) );
}

/** Edit window for own replies. Moderators can always edit. */
function snn_cm_edit_deadline( $comment ) {
    $hours = (int) snn_cm_settings()['edit_hours'];
    if ( $hours <= 0 ) return 0;
    return strtotime( $comment->comment_date_gmt . ' UTC' ) + $hours * HOUR_IN_SECONDS;
}

function snn_cm_can_modify( $comment, $user_id = null ) {
    $user_id = $user_id ?? get_current_user_id();
    if ( $user_id && snn_cm_is_moderator() ) return true;
    if ( ! $user_id || (int) $comment->user_id !== (int) $user_id ) return false;
    $deadline = snn_cm_edit_deadline( $comment );
    return $deadline && time() < $deadline;
}

/** Simple per-member rate limit: at most $limit actions per $window seconds of activity. */
function snn_cm_rate_ok( $bucket, $limit, $window = 600 ) {
    $key = 'snn_cm_rl_' . $bucket . '_' . get_current_user_id();
    $n   = (int) get_transient( $key );
    if ( $n >= (int) $limit ) return false;
    set_transient( $key, $n + 1, $window );
    return true;
}

function snn_cm_page_url() {
    // Relative links keep the visitor on the page that holds the shortcode.
    return '?snn_topic=';
}

// ============================================================
// 4. IMAGE STORAGE  (wp-content/uploads/snn-learn-uploads/)
// ============================================================

/** Where the images live. Read-only: rendering pages must never write files. */
function snn_cm_upload_paths() {
    $up = wp_upload_dir();
    return [
        'path' => trailingslashit( $up['basedir'] ) . SNN_CM_DIR,
        'url'  => trailingslashit( $up['baseurl'] ) . SNN_CM_DIR,
    ];
}

/** Creates the folder and its guard files when an image is about to be written. */
function snn_cm_upload_dir() {
    $dir = snn_cm_upload_paths();
    $path = $dir['path'];

    if ( ! is_dir( $path ) ) {
        wp_mkdir_p( $path );
    }
    // Keep directory listings and script execution out of the folder.
    if ( ! file_exists( $path . '/index.php' ) ) {
        file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" );
    }
    if ( ! file_exists( $path . '/.htaccess' ) ) {
        file_put_contents( $path . '/.htaccess',
            "Options -Indexes\n" .
            "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n" .
            "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
        );
    }
    return $dir;
}

function snn_cm_upload_url_prefix() {
    return snn_cm_upload_paths()['url'] . '/';
}

/** Only files this plugin wrote: snn-<32 hex>.webp. Anything else is rejected. */
function snn_cm_is_own_image_url( $url ) {
    return strpos( $url, snn_cm_upload_url_prefix() ) === 0
        && (bool) preg_match( '#/snn-[a-f0-9]{32}\.webp$#', $url );
}

/**
 * Validate one uploaded image and store it as WebP.
 *
 * Fast path: the browser already sent a WebP within the width cap, so it is moved as-is.
 * Anything else (JPEG, PNG, GIF, or a WebP that is too wide) is decoded with GD and
 * re-encoded. Re-encoding also drops EXIF/GPS metadata and any hidden payload.
 *
 * @return array|WP_Error { url, width, height, bytes }
 */
function snn_cm_process_upload( $file ) {
    if ( ! is_array( $file ) || ! isset( $file['error'] ) ) {
        return new WP_Error( 'snn_cm', 'No image received.' );
    }
    if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
        return new WP_Error( 'snn_cm', 'The upload failed. Please try again.' );
    }

    $tmp = (string) ( $file['tmp_name'] ?? '' );
    if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
        return new WP_Error( 'snn_cm', 'Invalid upload.' );
    }

    // Measure the real file, never the size the client claims.
    $size = (int) filesize( $tmp );
    if ( $size <= 0 || $size > SNN_CM_MAX_BYTES ) {
        return new WP_Error( 'snn_cm', 'Each image must be 5 MB or smaller.' );
    }

    $info = @getimagesize( $tmp );
    if ( ! is_array( $info ) || empty( $info[2] ) ) {
        return new WP_Error( 'snn_cm', 'That file is not a readable image.' );
    }

    $types = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_GIF  => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];
    $type = (int) $info[2];
    if ( ! isset( $types[ $type ] ) ) {
        return new WP_Error( 'snn_cm', 'Only JPG, PNG, GIF and WebP images are allowed.' );
    }

    // Sniff the bytes a second time. The header and the content must agree.
    $finfo = finfo_open( FILEINFO_MIME_TYPE );
    $mime  = $finfo ? finfo_file( $finfo, $tmp ) : '';
    if ( $finfo ) finfo_close( $finfo );
    if ( $mime !== $types[ $type ] ) {
        return new WP_Error( 'snn_cm', 'The file contents do not match an image type.' );
    }

    $w = (int) $info[0];
    $h = (int) $info[1];
    if ( $w < 1 || $h < 1 || $w > SNN_CM_MAX_SIDE || $h > SNN_CM_MAX_SIDE || $w * $h > SNN_CM_MAX_PIXELS ) {
        return new WP_Error( 'snn_cm', 'That image is too large in pixels.' );
    }

    if ( ! snn_cm_images_allowed() ) {
        return new WP_Error( 'snn_cm', 'This server cannot save WebP images.' );
    }

    $dir  = snn_cm_upload_dir();
    $name = 'snn-' . bin2hex( random_bytes( 16 ) ) . '.webp';
    $dest = $dir['path'] . '/' . $name;

    if ( $type === IMAGETYPE_WEBP && $w <= SNN_CM_HARD_WIDTH ) {
        if ( ! move_uploaded_file( $tmp, $dest ) ) {
            return new WP_Error( 'snn_cm', 'Could not save the image.' );
        }
        $out_w = $w;
        $out_h = $h;
    } else {
        $img = snn_cm_gd_load( $tmp, $type );
        if ( ! $img ) {
            return new WP_Error( 'snn_cm', 'The server could not read that image.' );
        }
        $size_out = snn_cm_gd_write_webp( $img, $dest, SNN_CM_HARD_WIDTH, (int) snn_cm_settings()['image_quality'] );
        if ( ! $size_out ) {
            return new WP_Error( 'snn_cm', 'The server could not save that image as WebP.' );
        }
        [ $out_w, $out_h ] = $size_out;
    }

    @chmod( $dest, 0644 );

    return [
        'url'    => $dir['url'] . '/' . $name,
        'width'  => $out_w,
        'height' => $out_h,
        'bytes'  => (int) filesize( $dest ),
    ];
}

function snn_cm_gd_load( $path, $type ) {
    switch ( $type ) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg( $path ); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng( $path ); break;
        case IMAGETYPE_GIF:  $img = @imagecreatefromgif( $path ); break;
        case IMAGETYPE_WEBP: $img = @imagecreatefromwebp( $path ); break;
        default:             $img = false;
    }
    if ( ! $img ) return false;

    // Palette images (GIF, PNG-8) keep their transparency once they are truecolour with alpha.
    imagepalettetotruecolor( $img );
    imagealphablending( $img, false );
    imagesavealpha( $img, true );
    return $img;
}

/**
 * Downscale to $max_w if needed, then write WebP. Transparency is kept:
 * the canvas is alpha-enabled and nothing is flattened onto a background.
 * @return array|false [ width, height ]
 */
function snn_cm_gd_write_webp( $img, $dest, $max_w, $quality ) {
    $w = imagesx( $img );
    $h = imagesy( $img );

    if ( $w > $max_w ) {
        $nw  = $max_w;
        $nh  = max( 1, (int) round( $h * $max_w / $w ) );
        $out = imagecreatetruecolor( $nw, $nh );
        imagealphablending( $out, false );
        imagesavealpha( $out, true );
        imagefill( $out, 0, 0, imagecolorallocatealpha( $out, 0, 0, 0, 127 ) );
        imagecopyresampled( $out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h );
        imagedestroy( $img );
        $img = $out;
        $w   = $nw;
        $h   = $nh;
    }

    $quality = max( 40, min( 100, $quality ) );
    $ok      = imagewebp( $img, $dest, $quality );
    imagedestroy( $img );

    return $ok ? [ $w, $h ] : false;
}

/**
 * Delete image files that only the removed content used.
 * $names are file names collected before deletion; a file stays if any other post or comment still links it.
 */
function snn_cm_forget_images( $names, $skip_post_id = 0, $skip_comment_id = 0 ) {
    global $wpdb;
    $dir = snn_cm_upload_dir()['path'];

    foreach ( array_unique( $names ) as $name ) {
        $like = '%' . $wpdb->esc_like( $name ) . '%';
        $used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT (SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND ID != %d)
                  + (SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_content LIKE %s AND comment_ID != %d)",
            $like, (int) $skip_post_id, $like, (int) $skip_comment_id
        ) );
        $file = $dir . '/' . basename( $name );
        if ( ! $used && is_file( $file ) ) {
            wp_delete_file( $file );
        }
    }
}

/** Collect the image file names used in a piece of content. */
function snn_cm_image_names( $html ) {
    preg_match_all( '#snn-[a-f0-9]{32}\.webp#', (string) $html, $m );
    return $m[0];
}

/**
 * Daily: delete images that were uploaded more than a day ago and that no topic or reply uses.
 * Covers pictures added to a post that was then abandoned or never submitted.
 */
add_action( 'snn_cm_cleanup_images', 'snn_cm_cleanup_orphan_images' );
function snn_cm_cleanup_orphan_images() {
    $dir = snn_cm_upload_paths()['path'];
    if ( ! is_dir( $dir ) ) return;

    $cutoff = time() - DAY_IN_SECONDS;
    foreach ( glob( $dir . '/snn-*.webp' ) ?: [] as $file ) {
        if ( filemtime( $file ) > $cutoff ) continue;
        snn_cm_forget_images( [ basename( $file ) ] );
    }
}

/** Keep the daily job in step with the on/off switch. */
function snn_cm_sync_schedule() {
    $next = wp_next_scheduled( 'snn_cm_cleanup_images' );
    if ( snn_cm_enabled() && ! $next ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'snn_cm_cleanup_images' );
    } elseif ( ! snn_cm_enabled() && $next ) {
        wp_clear_scheduled_hook( 'snn_cm_cleanup_images' );
    }
}
add_action( 'init', 'snn_cm_sync_schedule', 30 );

// Images in a deleted topic or reply: collect before deletion, check after.
$GLOBALS['snn_cm_pending_images'] = [];

add_action( 'before_delete_post', function ( $post_id ) {
    if ( get_post_type( $post_id ) !== SNN_CM_PT ) return;
    $names = snn_cm_image_names( get_post_field( 'post_content', $post_id ) );
    foreach ( get_comments( [ 'post_id' => $post_id, 'status' => 'all', 'fields' => 'ids' ] ) as $cid ) {
        $c = get_comment( $cid );
        $names = array_merge( $names, snn_cm_image_names( $c ? $c->comment_content : '' ) );
    }
    $GLOBALS['snn_cm_pending_images'][ 'p' . $post_id ] = $names;
} );

add_action( 'deleted_post', function ( $post_id ) {
    $key = 'p' . $post_id;
    if ( empty( $GLOBALS['snn_cm_pending_images'][ $key ] ) ) return;
    snn_cm_forget_images( $GLOBALS['snn_cm_pending_images'][ $key ], $post_id );
    unset( $GLOBALS['snn_cm_pending_images'][ $key ] );
} );

add_action( 'delete_comment', function ( $comment_id ) {
    $c = get_comment( $comment_id );
    if ( ! $c ) return;
    $GLOBALS['snn_cm_pending_images'][ 'c' . $comment_id ] = snn_cm_image_names( $c->comment_content );
} );

add_action( 'deleted_comment', function ( $comment_id ) {
    $key = 'c' . $comment_id;
    if ( empty( $GLOBALS['snn_cm_pending_images'][ $key ] ) ) return;
    snn_cm_forget_images( $GLOBALS['snn_cm_pending_images'][ $key ], 0, $comment_id );
    unset( $GLOBALS['snn_cm_pending_images'][ $key ] );
} );

// ============================================================
// 5. CONTENT SANITISING
// ============================================================

/**
 * Allow only what the community editor can produce.
 * Images must be our own WebP files; anything else is dropped.
 */
function snn_cm_sanitize_html( $html ) {
    $html = (string) $html;

    // Images: keep only our own uploads, rebuilt with safe attributes.
    $html = preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) {
        if ( ! preg_match( '/\ssrc="([^"]+)"/i', $m[0], $s ) ) return '';
        $src = html_entity_decode( $s[1], ENT_QUOTES );
        if ( ! snn_cm_is_own_image_url( $src ) ) return '';

        $style = '';
        if ( preg_match( '/\sstyle="([^"]*)"/i', $m[0], $st ) && preg_match( '/width:\s*(25|50|75|100)%/i', $st[1], $w ) ) {
            $style = ' style="width:' . $w[1] . '%"';
        }
        return '<img src="' . esc_url( $src ) . '" alt="" loading="lazy" decoding="async" class="snn-cm-img"' . $style . '>';
    }, $html );

    // Links: http(s) only, always safe to open in a new tab.
    $html = preg_replace_callback( '/<a\b[^>]*>/i', function ( $m ) {
        if ( ! preg_match( '/\bhref="([^"]+)"/i', $m[0], $h ) ) return '';
        $href = html_entity_decode( $h[1], ENT_QUOTES );
        if ( ! preg_match( '#^https?://#i', $href ) ) return '';
        return '<a href="' . esc_url( $href ) . '" rel="nofollow ugc noopener" target="_blank">';
    }, $html );

    $html = wp_kses( $html, [
        'p' => [], 'div' => [], 'br' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'span'  => [ 'style' => true ],
        'img'   => [ 'src' => true, 'alt' => true, 'loading' => true, 'decoding' => true, 'class' => true, 'style' => true ],
        'a'     => [ 'href' => true, 'rel' => true, 'target' => true ],
    ] );

    // Inside style="", keep only a validated colour and font size.
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
            } elseif ( $prop === 'width' && preg_match( '/^(25|50|75|100)%$/', $val ) ) {
                $keep[] = 'width:' . $val;
            }
        }
        return $keep ? ' style="' . esc_attr( implode( ';', $keep ) ) . '"' : '';
    }, $html );

    // Trim empty paragraphs at the start and end.
    $html = preg_replace( '#^(\s*<(p|div)>(\s|&nbsp;|<br\s*/?>)*</\2>)+|(<(p|div)>(\s|&nbsp;|<br\s*/?>)*</\5>\s*)+$#i', '', $html );

    return trim( $html );
}

function snn_cm_html_is_empty( $html ) {
    return trim( str_replace( "\xc2\xa0", ' ', html_entity_decode( wp_strip_all_tags( $html ) ) ) ) === '';
}

/** Error message for content that is too long, too empty or has too many images, or '' when it is fine. */
function snn_cm_check_content( $html ) {
    $s = snn_cm_settings();
    // A picture on its own is a valid post.
    if ( snn_cm_html_is_empty( $html ) && ! preg_match( '/<img\b/i', $html ) ) return 'Write something first.';
    if ( mb_strlen( wp_strip_all_tags( $html ) ) > (int) $s['max_length'] ) {
        return sprintf( 'Keep it under %d characters.', (int) $s['max_length'] );
    }
    $images = preg_match_all( '/<img\b/i', $html );
    if ( $images && ! snn_cm_images_allowed() ) return 'Image uploads are turned off.';
    if ( $images > (int) $s['max_images'] ) {
        return sprintf( 'Up to %d images per post.', (int) $s['max_images'] );
    }
    return '';
}

// ============================================================
// 6. DATA HELPERS & RENDERING
// ============================================================

/** Category colours cycle through this palette, keyed by term ID. */
function snn_cm_term_color( $term_id ) {
    static $palette = [ '#6d5bd0', '#e11d48', '#ea580c', '#16a34a', '#0891b2', '#2563eb', '#c026d3', '#ca8a04' ];
    return $palette[ (int) $term_id % count( $palette ) ];
}

function snn_cm_first_term( $post ) {
    $terms = get_the_terms( $post, SNN_CM_TAX );
    return ( $terms && ! is_wp_error( $terms ) ) ? reset( $terms ) : null;
}

/** Newest-activity first. Topics without a timestamp (rare) fall back to publish date. */
function snn_cm_query_topics( $category, $page, $per_page ) {
    $args = [
        'post_type'        => SNN_CM_PT,
        'post_status'      => 'publish',
        'posts_per_page'   => $per_page + 1,   // one extra row tells us whether there is a next page
        'paged'            => max( 1, $page ),
        'no_found_rows'    => true,
        'ignore_sticky_posts' => true,
        'meta_key'         => '_snn_cm_last',
        'orderby'          => [ 'meta_value_num' => 'DESC', 'ID' => 'DESC' ],
    ];
    if ( $category !== '' ) {
        $args['tax_query'] = [ [ 'taxonomy' => SNN_CM_TAX, 'field' => 'slug', 'terms' => $category ] ];
    }
    $posts = get_posts( $args );
    $has_more = count( $posts ) > $per_page;
    return [ 'posts' => array_slice( $posts, 0, $per_page ), 'has_more' => $has_more ];
}

function snn_cm_time_ago( $gmt ) {
    $ts = is_numeric( $gmt ) ? (int) $gmt : strtotime( $gmt . ' UTC' );
    return sprintf( '%s ago', human_time_diff( $ts ) );
}

function snn_cm_avatar( $user_id_or_comment, $size = 40 ) {
    return sprintf(
        '<img class="snn-cm-avatar" src="%s" alt="" width="%d" height="%d" loading="lazy">',
        esc_url( get_avatar_url( $user_id_or_comment, [ 'size' => $size * 2 ] ) ),
        (int) $size,
        (int) $size
    );
}

function snn_cm_icon( $name ) {
    $icons = [
        'comment' => '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm0 14H5.17L4 17.17V4h16v12z"/></svg>',
        'back'    => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>',
        'lock'    => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M18 8h-1V6A5 5 0 0 0 7 6v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2zM9 6a3 3 0 0 1 6 0v2H9V6zm9 14H6V10h12v10zm-6-3a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>',
    ];
    return $icons[ $name ] ?? '';
}

/** One topic row in the list. */
function snn_cm_row_html( $post ) {
    $cat      = snn_cm_first_term( $post );
    $author   = get_the_author_meta( 'display_name', $post->post_author );
    $replies  = (int) get_comments_number( $post );
    $last     = (int) get_post_meta( $post->ID, '_snn_cm_last', true ) ?: strtotime( $post->post_date_gmt . ' UTC' );
    $pending  = $post->post_status === 'pending';

    ob_start(); ?>
    <a class="snn-cm-row<?= $pending ? ' is-pending' : '' ?>" href="<?= esc_attr( snn_cm_page_url() . (int) $post->ID ) ?>" data-id="<?= (int) $post->ID ?>">
        <?= snn_cm_avatar( (int) $post->post_author, 40 ) ?>
        <span class="snn-cm-row-main">
            <span class="snn-cm-row-title"><?= esc_html( get_the_title( $post ) ?: '(no title)' ) ?></span>
            <span class="snn-cm-row-meta">
                <strong><?= esc_html( $author ) ?></strong>
                <?php if ( $cat ) : ?><span class="snn-cm-pill" style="--c:<?= esc_attr( snn_cm_term_color( $cat->term_id ) ) ?>"><?= esc_html( $cat->name ) ?></span><?php endif; ?>
                <?php if ( $pending ) : ?><span class="snn-cm-pending">Awaiting moderation</span><?php endif; ?>
            </span>
        </span>
        <span class="snn-cm-row-side">
            <span class="snn-cm-row-count" title="Replies"><?= snn_cm_icon( 'comment' ) ?> <?= $replies ?></span>
            <time class="snn-cm-row-time"><?= esc_html( snn_cm_time_ago( $last ) ) ?></time>
        </span>
    </a>
    <?php
    return ob_get_clean();
}

/** Category sidebar. $active is the slug in use; '' means all discussions. */
function snn_cm_sidebar_html( $active = '' ) {
    $terms = get_terms( [ 'taxonomy' => SNN_CM_TAX, 'hide_empty' => false, 'orderby' => 'name' ] );
    if ( is_wp_error( $terms ) ) $terms = [];
    $total = (int) wp_count_posts( SNN_CM_PT )->publish;

    ob_start(); ?>
    <button type="button" class="snn-cm-cat<?= $active === '' ? ' is-active' : '' ?>" data-slug="">
        <span class="snn-cm-cat-icon" aria-hidden="true">&#9776;</span>
        <span class="snn-cm-cat-name">All discussions</span>
        <span class="snn-cm-cat-count"><?= $total ?></span>
    </button>
    <?php foreach ( $terms as $t ) : ?>
    <button type="button" class="snn-cm-cat<?= $active === $t->slug ? ' is-active' : '' ?>" data-slug="<?= esc_attr( $t->slug ) ?>" style="--c:<?= esc_attr( snn_cm_term_color( $t->term_id ) ) ?>">
        <span class="snn-cm-dot" aria-hidden="true"></span>
        <span class="snn-cm-cat-name"><?= esc_html( $t->name ) ?></span>
        <span class="snn-cm-cat-count"><?= (int) $t->count ?></span>
    </button>
    <?php endforeach;
    return ob_get_clean();
}

/** Comments visible to this viewer, arranged as top-level comments with one level of replies. */
function snn_cm_comment_tree( $post_id ) {
    $args = [ 'post_id' => $post_id, 'status' => 'approve', 'type' => 'comment', 'orderby' => 'comment_date_gmt', 'order' => 'ASC' ];
    if ( snn_cm_is_moderator() ) {
        $args['status'] = 'all';   // moderators see the whole queue
    } elseif ( get_current_user_id() ) {
        $args['include_unapproved'] = [ get_current_user_id() ];
    }
    $all = get_comments( $args );

    $by_id = [];
    foreach ( $all as $c ) $by_id[ $c->comment_ID ] = $c;

    $top = [];
    $replies = [];
    foreach ( $all as $c ) {
        // Deeper replies (added in wp-admin) attach to their top-level ancestor.
        $root  = $c;
        $guard = 0;
        while ( $root->comment_parent && isset( $by_id[ $root->comment_parent ] ) && $guard++ < 20 ) {
            $root = $by_id[ $root->comment_parent ];
        }
        if ( $root === $c ) {
            if ( ! $c->comment_parent ) $top[] = $c;
        } elseif ( ! $root->comment_parent ) {
            $replies[ $root->comment_ID ][] = $c;
        }
    }
    return [ 'all' => $all, 'top' => $top, 'replies' => $replies ];
}

function snn_cm_comment_html( $c, $replies = [], $can_reply = false ) {
    $mine     = snn_cm_can_modify( $c ) && ( get_current_user_id() === (int) $c->user_id || snn_cm_is_moderator() );
    $edited   = get_comment_meta( $c->comment_ID, '_snn_cm_edited', true );
    $time     = strtotime( $c->comment_date_gmt . ' UTC' );
    $is_reply = (int) $c->comment_parent > 0;
    $deadline = $mine ? snn_cm_edit_deadline( $c ) : 0;

    ob_start(); ?>
    <li class="snn-cm-c<?= $is_reply ? ' is-reply' : '' ?><?= $c->comment_approved !== '1' ? ' is-pending' : '' ?>" id="comment-<?= (int) $c->comment_ID ?>" data-id="<?= (int) $c->comment_ID ?>"<?= $mine && ! snn_cm_is_moderator() && $deadline ? ' data-until="' . (int) $deadline . '"' : '' ?>>
        <?= snn_cm_avatar( $c, 36 ) ?>
        <div class="snn-cm-c-main">
            <div class="snn-cm-c-head">
                <strong><?= esc_html( get_comment_author( $c ) ) ?></strong>
                <time datetime="<?= esc_attr( gmdate( 'c', $time ) ) ?>"><?= esc_html( snn_cm_time_ago( $time ) ) ?></time>
                <?php if ( $edited ) : ?><span class="snn-cm-edited">(edited)</span><?php endif; ?>
                <?php if ( $c->comment_approved !== '1' ) : ?><span class="snn-cm-pending">Awaiting moderation</span><?php endif; ?>
            </div>
            <div class="snn-cm-c-body"><?= snn_cm_sanitize_html( $c->comment_content ) ?></div>
            <div class="snn-cm-c-actions">
                <?php if ( $can_reply && ! $is_reply ) : ?><button type="button" class="snn-cm-act-reply">Reply</button><?php endif; ?>
                <?php if ( $mine ) : ?>
                    <button type="button" class="snn-cm-act-edit">Edit</button>
                    <button type="button" class="snn-cm-act-delete">Delete</button>
                <?php endif; ?>
            </div>
            <?php if ( ! $is_reply && $replies ) : ?>
                <ol class="snn-cm-replies"><?php foreach ( $replies as $r ) echo snn_cm_comment_html( $r ); ?></ol>
            <?php endif; ?>
        </div>
    </li>
    <?php
    return ob_get_clean();
}

/** The open topic: first post, replies and the composer. */
function snn_cm_thread_html( $post ) {
    $post    = get_post( $post );
    $cat     = snn_cm_first_term( $post );
    $tree    = snn_cm_comment_tree( $post->ID );
    $count   = count( $tree['all'] );
    $pending = $post->post_status === 'pending';
    $can_reply = snn_cm_can_reply();

    ob_start(); ?>
    <div class="snn-cm-thread-inner" data-id="<?= (int) $post->ID ?>">
        <button type="button" class="snn-cm-back"><?= snn_cm_icon( 'back' ) ?> All discussions</button>

        <header class="snn-cm-thread-head">
            <?php if ( $cat ) : ?><span class="snn-cm-pill" style="--c:<?= esc_attr( snn_cm_term_color( $cat->term_id ) ) ?>"><?= esc_html( $cat->name ) ?></span><?php endif; ?>
            <h2 class="snn-cm-thread-title"><?= esc_html( get_the_title( $post ) ) ?></h2>
            <div class="snn-cm-meta">
                <?= snn_cm_avatar( (int) $post->post_author, 28 ) ?>
                <strong><?= esc_html( get_the_author_meta( 'display_name', $post->post_author ) ) ?></strong>
                <span><?= esc_html( snn_cm_time_ago( strtotime( $post->post_date_gmt . ' UTC' ) ) ) ?></span>
                <?php if ( $pending ) : ?><span class="snn-cm-pending">Awaiting moderation. Only you and moderators can see this.</span><?php endif; ?>
            </div>
        </header>

        <article class="snn-cm-topic-body"><div class="snn-cm-body"><?= snn_cm_sanitize_html( $post->post_content ) ?></div></article>

        <section class="snn-cm-comments" data-post="<?= (int) $post->ID ?>">
            <h3 class="snn-cm-comments-title">Replies <span class="snn-cm-comments-count"><?= $count ?></span></h3>
            <ol class="snn-cm-clist">
                <?php foreach ( $tree['top'] as $c ) echo snn_cm_comment_html( $c, $tree['replies'][ $c->comment_ID ] ?? [], $can_reply ); ?>
            </ol>
            <?php if ( ! $tree['top'] ) : ?><p class="snn-cm-empty">No replies yet.</p><?php endif; ?>

            <?php if ( $can_reply ) : ?>
                <div class="snn-cm-composer" data-parent="0"></div>
            <?php elseif ( ! is_user_logged_in() && snn_cm_settings()['allow_replies'] ) : ?>
                <p class="snn-cm-login-note"><a href="<?= esc_url( wp_login_url( snn_cm_current_url() ) ) ?>">Log in</a> to join the discussion.</p>
            <?php endif; ?>
        </section>
    </div>
    <?php
    return ob_get_clean();
}

function snn_cm_current_url() {
    $host = $_SERVER['HTTP_HOST'] ?? parse_url( home_url(), PHP_URL_HOST );
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
}

/** Shown to guests when the forum is members-only. Mirrors the roadmap's locked box. */
function snn_cm_locked_html() {
    $n = (int) wp_count_posts( SNN_CM_PT )->publish;
    ob_start(); ?>
    <div class="snn-cm snn-cm-locked-wrap">
        <div class="snn-cm-locked">
            <div class="snn-cm-locked-icon"><?= snn_cm_icon( 'lock' ) ?></div>
            <p class="snn-cm-locked-title"><?= $n ? esc_html( sprintf( _n( '%s topic from members so far.', '%s topics from members so far.', $n ), number_format_i18n( $n ) ) ) : 'The community is for members.' ?></p>
            <p class="snn-cm-locked-text">Log in or register to read the discussions, share images and join in.</p>
            <div class="snn-cm-locked-actions">
                <a class="snn-cm-btn" href="<?= esc_url( snn_cm_register_url() ) ?>">Register</a>
                <a class="snn-cm-btn is-ghost" href="<?= esc_url( wp_login_url( snn_cm_current_url() ) ) ?>">Log in</a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ============================================================
// 7. FRONT-END SHORTCODE & ASSETS
// ============================================================

/**
 * [snn_learn_community category=""]
 * category: optional slug to open on that category.
 */
add_shortcode( 'snn_learn_community', 'snn_cm_shortcode' );
function snn_cm_shortcode( $atts ) {
    if ( ! snn_cm_enabled() ) {
        return current_user_can( 'manage_options' ) ? '<p><em>Community is disabled. Enable it under SNN Learn → Community.</em></p>' : '';
    }

    $atts = shortcode_atts( [ 'category' => '' ], $atts, 'snn_learn_community' );

    // Guests on a members-only forum get the locked box only: no config, no nonce, no category names.
    if ( ! snn_cm_can_view() ) {
        snn_cm_enqueue_locked();
        return snn_cm_locked_html();
    }
    snn_cm_enqueue();

    $s        = snn_cm_settings();
    $category = sanitize_title( $atts['category'] );
    $page     = snn_cm_query_topics( $category, 1, (int) $s['per_page'] );
    $can_post = snn_cm_can_post_topic();
    $can_reply = snn_cm_can_reply();

    ob_start(); ?>
    <div class="snn-cm" data-cat="<?= esc_attr( $category ) ?>" data-can-post="<?= $can_post ? 1 : 0 ?>" data-can-reply="<?= $can_reply ? 1 : 0 ?>">
        <aside class="snn-cm-side" aria-label="Categories">
            <div class="snn-cm-side-list"><?= snn_cm_sidebar_html( $category ) ?></div>
        </aside>

        <section class="snn-cm-main">
            <div class="snn-cm-view" data-view="list">
                <div class="snn-cm-bar">
                    <h2 class="snn-cm-heading">Latest topics</h2>
                    <?php if ( $can_post ) : ?>
                        <button type="button" class="snn-cm-btn snn-cm-new">New topic</button>
                    <?php endif; ?>
                </div>

                <?php if ( ! is_user_logged_in() && $s['visibility'] === 'public' ) : ?>
                    <p class="snn-cm-guest-note"><a href="<?= esc_url( wp_login_url( snn_cm_current_url() ) ) ?>">Log in</a> or <a href="<?= esc_url( snn_cm_register_url() ) ?>">register</a> to post.</p>
                <?php endif; ?>

                <div class="snn-cm-list" aria-live="polite">
                    <?php foreach ( $page['posts'] as $p ) echo snn_cm_row_html( $p ); ?>
                </div>
                <p class="snn-cm-empty snn-cm-empty-list"<?= $page['posts'] ? ' hidden' : '' ?>>No topics here yet.</p>
                <div class="snn-cm-more-wrap"<?= $page['has_more'] ? '' : ' hidden' ?>><button type="button" class="snn-cm-btn is-ghost snn-cm-more">Load more</button></div>
            </div>

            <div class="snn-cm-view snn-cm-thread" data-view="thread" hidden></div>
        </section>
    </div>
    <?php
    return ob_get_clean();
}

function snn_cm_asset_version() {
    $dir = plugin_dir_path( __FILE__ );
    return (string) max( (int) @filemtime( $dir . 'assets/css/snn-community.css' ), (int) @filemtime( $dir . 'assets/js/snn-community.js' ) );
}

/** Stylesheet only: the locked box needs the design but no script and no config. */
function snn_cm_enqueue_locked() {
    wp_enqueue_style( 'snn-community', plugin_dir_url( __FILE__ ) . 'assets/css/snn-community.css', [], snn_cm_asset_version() );
}

/** Stylesheet, script and config (window.SNN_CM). Once per page. */
function snn_cm_enqueue() {
    static $done = false;
    if ( $done ) return;
    $done = true;

    $base = plugin_dir_url( __FILE__ );
    $ver  = snn_cm_asset_version();

    wp_enqueue_style( 'snn-community', $base . 'assets/css/snn-community.css', [], $ver );
    wp_enqueue_script( 'snn-community', $base . 'assets/js/snn-community.js', [], $ver, true );

    $s   = snn_cm_settings();
    $cfg = [
        'ajax'        => admin_url( 'admin-ajax.php' ),
        'nonce'       => wp_create_nonce( 'snn_cm' ),
        'loggedIn'    => is_user_logged_in(),
        'canPost'     => snn_cm_can_post_topic(),
        'canReply'    => snn_cm_can_reply(),
        'images'      => snn_cm_images_allowed(),
        'maxImages'   => (int) $s['max_images'],
        'imageWidth'  => max( 320, min( 4000, (int) $s['image_width'] ) ),
        'imageQuality'=> max( 40, min( 100, (int) $s['image_quality'] ) ),
        'maxBytes'    => SNN_CM_MAX_BYTES,
        'maxLength'   => (int) $s['max_length'],
        'registerUrl' => snn_cm_register_url(),
        'loginUrl'    => wp_login_url( snn_cm_current_url() ),
        'categories'  => array_values( array_map( function ( $t ) {
            return [ 'slug' => $t->slug, 'name' => $t->name, 'color' => snn_cm_term_color( $t->term_id ) ];
        }, (array) get_terms( [ 'taxonomy' => SNN_CM_TAX, 'hide_empty' => false, 'orderby' => 'name' ] ) ) ),
    ];
    wp_add_inline_script( 'snn-community', 'window.SNN_CM = ' . wp_json_encode( $cfg ) . ';', 'before' );
}

// ============================================================
// 8. AJAX
// ============================================================

/**
 * Shared guard. Reads need the viewer to be allowed to see the forum.
 * Writes need a member. Nonce failures stop the request in check_ajax_referer().
 */
function snn_cm_ajax_guard( $write = false ) {
    check_ajax_referer( 'snn_cm', 'nonce' );
    if ( ! snn_cm_enabled() ) wp_send_json_error( [ 'message' => 'Community is disabled.' ], 404 );

    $allowed = $write ? snn_cm_is_member() : snn_cm_can_view();
    if ( ! $allowed ) wp_send_json_error( [ 'message' => 'Please log in first.', 'login' => true ], 401 );
}

function snn_cm_ajax_topic_from_request() {
    $post = get_post( absint( $_POST['post_id'] ?? 0 ) );
    if ( ! snn_cm_topic_visible( $post ) ) wp_send_json_error( [ 'message' => 'Topic not found.' ], 404 );
    return $post;
}

// List (paged, optionally by category)
add_action( 'wp_ajax_snn_cm_list',        'snn_cm_ajax_list' );
add_action( 'wp_ajax_nopriv_snn_cm_list', 'snn_cm_ajax_list' );
function snn_cm_ajax_list() {
    snn_cm_ajax_guard();
    $s        = snn_cm_settings();
    $category = sanitize_title( wp_unslash( $_POST['category'] ?? '' ) );
    $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
    $result   = snn_cm_query_topics( $category, $page, (int) $s['per_page'] );

    $html = '';
    foreach ( $result['posts'] as $p ) $html .= snn_cm_row_html( $p );

    wp_send_json_success( [
        'html'     => $html,
        'has_more' => $result['has_more'],
        'sidebar'  => $page === 1 ? snn_cm_sidebar_html( $category ) : null,
    ] );
}

// One topic with its replies
add_action( 'wp_ajax_snn_cm_thread',        'snn_cm_ajax_thread' );
add_action( 'wp_ajax_nopriv_snn_cm_thread', 'snn_cm_ajax_thread' );
function snn_cm_ajax_thread() {
    snn_cm_ajax_guard();
    $post = snn_cm_ajax_topic_from_request();
    wp_send_json_success( [ 'html' => snn_cm_thread_html( $post ), 'title' => get_the_title( $post ) ] );
}

// New topic
add_action( 'wp_ajax_snn_cm_topic', 'snn_cm_ajax_topic' );
function snn_cm_ajax_topic() {
    snn_cm_ajax_guard( true );
    if ( ! snn_cm_can_post_topic() ) wp_send_json_error( [ 'message' => 'Starting topics is turned off.' ], 403 );

    $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
    if ( mb_strlen( $title ) < 3 || mb_strlen( $title ) > 150 ) {
        wp_send_json_error( [ 'message' => 'Titles need 3 to 150 characters.' ], 400 );
    }

    $content = snn_cm_sanitize_html( wp_unslash( $_POST['content'] ?? '' ) );
    if ( $err = snn_cm_check_content( $content ) ) wp_send_json_error( [ 'message' => $err ], 400 );

    if ( ! snn_cm_rate_ok( 'post', (int) snn_cm_settings()['post_rate'] ) ) {
        wp_send_json_error( [ 'message' => 'You are posting quickly. Please wait a few minutes.' ], 429 );
    }

    $term = get_term_by( 'slug', sanitize_title( wp_unslash( $_POST['category'] ?? '' ) ), SNN_CM_TAX );
    if ( ! $term ) $term = get_term_by( 'slug', 'general', SNN_CM_TAX );
    if ( ! $term ) {
        $any  = get_terms( [ 'taxonomy' => SNN_CM_TAX, 'hide_empty' => false, 'number' => 1 ] );
        $term = ( is_array( $any ) && $any ) ? $any[0] : null;
    }
    if ( ! $term ) wp_send_json_error( [ 'message' => 'No category is set up yet.' ], 500 );

    $pending = (bool) snn_cm_settings()['moderate'] && ! snn_cm_is_moderator();
    $id = wp_insert_post( [
        'post_type'      => SNN_CM_PT,
        'post_title'     => $title,
        'post_content'   => wp_slash( $content ),
        'post_status'    => $pending ? 'pending' : 'publish',
        'post_author'    => get_current_user_id(),
        'comment_status' => 'open',
        'ping_status'    => 'closed',
    ], true );
    if ( is_wp_error( $id ) ) wp_send_json_error( [ 'message' => 'Could not save the topic.' ], 500 );

    wp_set_object_terms( $id, [ (int) $term->term_id ], SNN_CM_TAX );
    update_post_meta( $id, '_snn_cm_last', time() );
    do_action( 'snn_community_topic_created', $id, get_current_user_id(), $pending );

    wp_send_json_success( [ 'id' => $id, 'pending' => $pending ] );
}

// New reply (one level deep)
add_action( 'wp_ajax_snn_cm_reply', 'snn_cm_ajax_reply' );
function snn_cm_ajax_reply() {
    snn_cm_ajax_guard( true );
    if ( ! snn_cm_can_reply() ) wp_send_json_error( [ 'message' => 'Replies are turned off.' ], 403 );

    $post = snn_cm_ajax_topic_from_request();

    $content = snn_cm_sanitize_html( wp_unslash( $_POST['content'] ?? '' ) );
    if ( $err = snn_cm_check_content( $content ) ) wp_send_json_error( [ 'message' => $err ], 400 );

    if ( ! snn_cm_rate_ok( 'post', (int) snn_cm_settings()['post_rate'] ) ) {
        wp_send_json_error( [ 'message' => 'You are posting quickly. Please wait a few minutes.' ], 429 );
    }

    // Replying to a reply attaches to its top-level comment.
    $parent = absint( $_POST['parent'] ?? 0 );
    if ( $parent ) {
        $pc = get_comment( $parent );
        if ( ! $pc || (int) $pc->comment_post_ID !== (int) $post->ID || ( $pc->comment_approved !== '1' && (int) $pc->user_id !== get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => 'The comment you replied to no longer exists.' ], 404 );
        }
        if ( $pc->comment_parent ) $parent = (int) $pc->comment_parent;
    }

    $user = wp_get_current_user();
    $data = [
        'comment_post_ID'      => $post->ID,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_author_url'   => '',
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
    if ( is_wp_error( $approved ) ) wp_send_json_error( [ 'message' => wp_strip_all_tags( $approved->get_error_message() ) ], 400 );
    if ( in_array( $approved, [ 'spam', 'trash' ], true ) ) wp_send_json_error( [ 'message' => 'Your reply could not be posted.' ], 403 );

    // Moderation on: everything waits, moderators excepted.
    // Moderation off: replies go live. Spam and blocklist hits were already refused above.
    // (WordPress holds first-time commenters by default; that would hide the thread from everyone else.)
    $approved = ( snn_cm_settings()['moderate'] && ! snn_cm_is_moderator() ) ? 0 : 1;

    $data['comment_approved'] = $approved;
    $id = wp_insert_comment( wp_slash( $data ) );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'Could not save the reply.' ], 500 );

    if ( (string) $approved === '1' ) update_post_meta( $post->ID, '_snn_cm_last', time() );
    do_action( 'comment_post', $id, $approved, $data );

    wp_send_json_success( [
        'html'     => snn_cm_comment_html( get_comment( $id ), [], true ),
        'parent'   => $parent,
        'approved' => (string) $approved === '1',
    ] );
}

function snn_cm_ajax_own_comment() {
    $c = get_comment( absint( $_POST['comment_id'] ?? 0 ) );
    if ( ! $c || ! snn_cm_topic_visible( $c->comment_post_ID ) ) {
        wp_send_json_error( [ 'message' => 'Reply not found.' ], 404 );
    }
    if ( ! snn_cm_can_modify( $c ) ) wp_send_json_error( [ 'message' => 'The edit window for this reply has closed.' ], 403 );
    return $c;
}

// Edit own reply inside the window
add_action( 'wp_ajax_snn_cm_comment_edit', function () {
    snn_cm_ajax_guard( true );
    $c = snn_cm_ajax_own_comment();

    $content = snn_cm_sanitize_html( wp_unslash( $_POST['content'] ?? '' ) );
    if ( $err = snn_cm_check_content( $content ) ) wp_send_json_error( [ 'message' => $err ], 400 );

    // Direct update: wp_update_comment() would run kses for members and strip the editor's formatting.
    // The stored HTML is sanitised above and again on every render.
    global $wpdb;
    $wpdb->update( $wpdb->comments, [ 'comment_content' => $content ], [ 'comment_ID' => $c->comment_ID ] );
    clean_comment_cache( $c->comment_ID );
    update_comment_meta( $c->comment_ID, '_snn_cm_edited', time() );
    do_action( 'edit_comment', (int) $c->comment_ID, (array) get_comment( $c->comment_ID ) );

    wp_send_json_success( [ 'html' => snn_cm_sanitize_html( $content ) ] );
} );

// Delete own reply inside the window (only while nobody has replied to it)
add_action( 'wp_ajax_snn_cm_comment_delete', function () {
    snn_cm_ajax_guard( true );
    $c       = snn_cm_ajax_own_comment();
    $replies = get_comments( [ 'parent' => $c->comment_ID, 'status' => 'all', 'fields' => 'ids' ] );
    if ( $replies && ! snn_cm_is_moderator() ) {
        wp_send_json_error( [ 'message' => 'This reply has answers, so it can only be edited.' ], 409 );
    }
    foreach ( $replies as $rid ) wp_delete_comment( $rid, true );
    wp_delete_comment( $c->comment_ID, true );
    wp_send_json_success();
} );

// Image upload: validate, convert/store, return the URL
add_action( 'wp_ajax_snn_cm_upload',        'snn_cm_ajax_upload' );
add_action( 'wp_ajax_nopriv_snn_cm_upload', 'snn_cm_ajax_upload' );
function snn_cm_ajax_upload() {
    snn_cm_ajax_guard( true );
    if ( ! snn_cm_images_allowed() ) wp_send_json_error( [ 'message' => 'Image uploads are turned off.' ], 403 );

    if ( ! snn_cm_rate_ok( 'upload', (int) snn_cm_settings()['upload_rate'] ) ) {
        wp_send_json_error( [ 'message' => 'Too many uploads. Please wait a few minutes.' ], 429 );
    }

    $result = snn_cm_process_upload( $_FILES['file'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification
    if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );

    wp_send_json_success( $result );
}

// ============================================================
// 9. ADMIN — SETTINGS PAGE
// ============================================================

add_action( 'admin_menu', function () {
    add_submenu_page( 'snn-learn', 'Community', 'Community', 'manage_options', 'snn-learn-community', 'snn_cm_admin_page' );
}, 20 );

add_action( 'admin_init', function () {
    if ( ! isset( $_POST['snn_cm_nonce'] ) || ! current_user_can( 'manage_options' ) ) return;
    check_admin_referer( 'snn_cm_save', 'snn_cm_nonce' );

    $old = snn_cm_settings();
    $in  = wp_unslash( $_POST );
    $int = fn( $key, $def, $min, $max ) => max( $min, min( $max, (int) ( $in[ $key ] ?? $def ) ) );

    $new = [
        'enabled'       => empty( $in['snn_cm_enabled'] ) ? 0 : 1,
        'visibility'    => ( $in['snn_cm_visibility'] ?? '' ) === 'public' ? 'public' : 'members',
        'allow_topics'  => empty( $in['snn_cm_allow_topics'] ) ? 0 : 1,
        'allow_replies' => empty( $in['snn_cm_allow_replies'] ) ? 0 : 1,
        'moderate'      => empty( $in['snn_cm_moderate'] ) ? 0 : 1,
        'edit_hours'    => $int( 'snn_cm_edit_hours', 24, 0, 8760 ),
        'max_length'    => $int( 'snn_cm_max_length', 5000, 100, 20000 ),
        'post_rate'     => $int( 'snn_cm_post_rate', 5, 1, 100 ),
        'allow_images'  => empty( $in['snn_cm_allow_images'] ) ? 0 : 1,
        'max_images'    => $int( 'snn_cm_max_images', 5, 1, 10 ),
        'image_width'   => $int( 'snn_cm_image_width', 1600, 320, 2560 ),
        'image_quality' => $int( 'snn_cm_image_quality', 80, 40, 100 ),
        'upload_rate'   => $int( 'snn_cm_upload_rate', 30, 1, 500 ),
        'per_page'      => $int( 'snn_cm_per_page', 20, 5, 100 ),
    ];

    update_option( SNN_CM_OPTION, $new );
    if ( $new['enabled'] ) snn_cm_ensure_default_terms();

    wp_safe_redirect( add_query_arg( [ 'page' => 'snn-learn-community', 'saved' => 1 ], admin_url( 'admin.php' ) ) );
    exit;
} );

function snn_cm_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $s = snn_cm_settings();

    $card  = 'background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)';
    $h2    = 'margin:0 0 18px;font-size:16px;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;padding-bottom:14px';
    $label = 'display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151';
    $input = 'width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;max-width:220px';
    $check = 'display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:600;color:#111827;cursor:pointer';
    $hint  = 'margin:4px 0 0;font-size:12px;color:#6b7280;font-weight:400';
    ?>
    <div class="wrap snn-cm-admin" style="max-width:960px">
        <h1>SNN Learn &mdash; Community</h1>

        <?php if ( isset( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>Community settings saved.</strong></p></div>
        <?php endif; ?>

        <p style="font-size:13px;color:#4b5563;max-width:760px;line-height:1.6">
            A members-only forum. Nothing is visible and nobody can post until you switch it on. Topics and categories are managed under
            <a href="<?= esc_url( admin_url( 'edit.php?post_type=' . SNN_CM_PT ) ) ?>">Community Topics</a>.
        </p>

        <form method="post" action="" style="margin-top:20px">
            <?php wp_nonce_field( 'snn_cm_save', 'snn_cm_nonce' ); ?>

            <!-- Access -->
            <div style="<?= $card ?>">
                <h2 style="<?= $h2 ?>">Access</h2>
                <label style="<?= $check ?>;margin-bottom:20px">
                    <input type="checkbox" name="snn_cm_enabled" value="1" <?= checked( 1, $s['enabled'], false ) ?> style="width:20px;height:20px;margin-top:-2px">
                    <span>Enable Community <span style="display:block;font-weight:400;color:#6b7280;margin-top:2px">Registers the community topics and categories and turns on the shortcode.</span></span>
                </label>

                <label style="<?= $label ?>">Who can see the topics?</label>
                <select name="snn_cm_visibility" style="<?= $input ?>;max-width:360px">
                    <option value="members" <?= selected( $s['visibility'], 'members', false ) ?>>Members only (logged-in users) &mdash; default</option>
                    <option value="public" <?= selected( $s['visibility'], 'public', false ) ?>>Everyone, including guests (guests still cannot post)</option>
                </select>
                <p style="<?= $hint ?>">Guests who cannot see the forum get a register / log-in box instead. To require a paid plan, use the <code>snn_community_is_member</code> filter.</p>
            </div>

            <!-- Posting -->
            <div style="<?= $card ?>">
                <h2 style="<?= $h2 ?>">Posting</h2>
                <div style="display:grid;gap:14px;margin-bottom:22px">
                    <label style="<?= $check ?>"><input type="checkbox" name="snn_cm_allow_topics" value="1" <?= checked( 1, $s['allow_topics'], false ) ?> style="margin-top:2px"> <span>Members can start new topics <span style="display:block;font-weight:400;color:#6b7280">Off by default.</span></span></label>
                    <label style="<?= $check ?>"><input type="checkbox" name="snn_cm_allow_replies" value="1" <?= checked( 1, $s['allow_replies'], false ) ?> style="margin-top:2px"> <span>Members can comment and reply <span style="display:block;font-weight:400;color:#6b7280">Off by default.</span></span></label>
                    <label style="<?= $check ?>"><input type="checkbox" name="snn_cm_moderate" value="1" <?= checked( 1, $s['moderate'], false ) ?> style="margin-top:2px"> <span>Hold new topics and replies for approval <span style="display:block;font-weight:400;color:#6b7280">On by default. Moderators skip the queue. Off: posts go live at once (spam filters still apply).</span></span></label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:22px">
                    <div>
                        <label style="<?= $label ?>">Edit or delete own reply for (hours)</label>
                        <input type="number" min="0" max="8760" name="snn_cm_edit_hours" value="<?= (int) $s['edit_hours'] ?>" style="<?= $input ?>">
                        <p style="<?= $hint ?>">0 turns member edits off.</p>
                    </div>
                    <div>
                        <label style="<?= $label ?>">Max characters per post</label>
                        <input type="number" min="100" max="20000" name="snn_cm_max_length" value="<?= (int) $s['max_length'] ?>" style="<?= $input ?>">
                    </div>
                    <div>
                        <label style="<?= $label ?>">Posts per member per 10 min</label>
                        <input type="number" min="1" max="100" name="snn_cm_post_rate" value="<?= (int) $s['post_rate'] ?>" style="<?= $input ?>">
                        <p style="<?= $hint ?>">Topics and replies together. Spam guard.</p>
                    </div>
                </div>
            </div>

            <!-- Images -->
            <div style="<?= $card ?>">
                <h2 style="<?= $h2 ?>">Images</h2>
                <label style="<?= $check ?>;margin-bottom:22px"><input type="checkbox" name="snn_cm_allow_images" value="1" <?= checked( 1, $s['allow_images'], false ) ?> style="margin-top:2px"> <span>Members can attach images to topics and replies <span style="display:block;font-weight:400;color:#6b7280">Only works when posting is on. Needs GD with WebP support on the server.</span></span></label>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:22px">
                    <div>
                        <label style="<?= $label ?>">Max images per post</label>
                        <input type="number" min="1" max="10" name="snn_cm_max_images" value="<?= (int) $s['max_images'] ?>" style="<?= $input ?>">
                    </div>
                    <div>
                        <label style="<?= $label ?>">Browser resize width (px)</label>
                        <input type="number" min="320" max="2560" name="snn_cm_image_width" value="<?= (int) $s['image_width'] ?>" style="<?= $input ?>">
                        <p style="<?= $hint ?>">Wider images are scaled down in the browser before upload.</p>
                    </div>
                    <div>
                        <label style="<?= $label ?>">WebP quality (40&ndash;100)</label>
                        <input type="number" min="40" max="100" name="snn_cm_image_quality" value="<?= (int) $s['image_quality'] ?>" style="<?= $input ?>">
                        <p style="<?= $hint ?>">80 is a good balance. Lower is smaller.</p>
                    </div>
                    <div>
                        <label style="<?= $label ?>">Uploads per member per 10 min</label>
                        <input type="number" min="1" max="500" name="snn_cm_upload_rate" value="<?= (int) $s['upload_rate'] ?>" style="<?= $input ?>">
                    </div>
                </div>

                <div style="margin-top:22px;padding:14px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;font-size:13px;color:#374151;line-height:1.7">
                    <strong>How images are handled</strong><br>
                    1. The browser checks the file (JPG, PNG, GIF or WebP, 5 MB max), resizes it and converts it to WebP. Transparency is kept.<br>
                    2. The server checks it again: real file type, real size, pixel limit. Anything that is not a valid image is refused.<br>
                    3. Only WebP is stored, in <code><?= esc_html( snn_cm_upload_paths()['path'] ) ?></code>, with random file names and no metadata.<br>
                    4. Pictures that no topic or reply uses are deleted after 24 hours. Deleting a topic or reply removes its pictures unless another post still uses them.
                    <?php if ( ! function_exists( 'imagewebp' ) ) : ?><br><span style="color:#b91c1c"><strong>Warning:</strong> this server's PHP GD build has no WebP support, so uploads are off.</span><?php endif; ?>
                </div>
            </div>

            <!-- Display -->
            <div style="<?= $card ?>">
                <h2 style="<?= $h2 ?>">Display</h2>
                <label style="<?= $label ?>">Topics per page</label>
                <input type="number" min="5" max="100" name="snn_cm_per_page" value="<?= (int) $s['per_page'] ?>" style="<?= $input ?>">
                <p style="<?= $hint ?>">More topics load with a &ldquo;Load more&rdquo; button.</p>
            </div>

            <!-- Shortcode -->
            <div style="<?= $card ?>">
                <h2 style="<?= $h2 ?>">Shortcode</h2>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <code id="snn-cm-sc" style="font-size:14px;padding:8px 12px;background:#f3f4f6;border-radius:8px">[snn_learn_community]</code>
                    <button type="button" class="button" id="snn-cm-copy">Copy</button>
                </div>
                <p style="font-size:13px;color:#6b7280;margin:14px 0 0;line-height:1.7">
                    Optional: <code>[snn_learn_community category="general"]</code> opens one category first.
                    Topics open in place with a link like <code>?snn_topic=123</code>.
                </p>
                <p style="font-size:13px;margin:14px 0 0">
                    <a class="button" href="<?= esc_url( admin_url( 'edit.php?post_type=' . SNN_CM_PT ) ) ?>">Topics</a>
                    <a class="button" href="<?= esc_url( admin_url( 'edit-tags.php?taxonomy=' . SNN_CM_TAX . '&post_type=' . SNN_CM_PT ) ) ?>">Categories</a>
                </p>
            </div>

            <?php submit_button( 'Save Changes' ); ?>
        </form>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('snn-cm-copy');
        if (!btn) return;
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(document.getElementById('snn-cm-sc').textContent).then(function () {
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
            });
        });
    })();
    </script>
    <?php
}
