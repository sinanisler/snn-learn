<?php
/**
 * SNN Learn — Media Library module.
 *
 * A self-contained video media library:
 *   • chunked drag & drop uploads (sequential queue, one file at a time)
 *   • Cloudflare R2 sync (automatic after upload, manual button, or cron)
 *   • MP4 → low-bitrate MP3 via ffmpeg.wasm (in the browser, no server ffmpeg)
 *   • automatic WebVTT subtitles via the OpenRouter speech-to-text API
 *
 * Loaded from snn-learn.php. Everything here is namespaced `snn_media_*` and
 * stores its options under the `snn_learn_media_` prefix so the module can be
 * reasoned about — and removed — on its own.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SNN_MEDIA_DB_VERSION', '1.2' );

// ============================================================
// 1. SETTINGS
// ============================================================

function snn_media_defaults() {
    return [
        // Uploads
        'allowed_extensions'   => 'mp4,mov,m4v,webm,mkv,avi,wmv,flv,ts,mts,m2ts',
        'chunk_size_mb'        => 2,
        'max_file_mb'          => 5120,

        // Poster frame grabbed in the browser
        'thumb_auto'           => 1,
        'thumb_seek'           => 1.0,
        'thumb_width'          => 480,

        // Cloudflare R2
        'r2_account_id'        => '',
        'r2_bucket'            => '',
        'r2_access_key_id'     => '',
        'r2_secret_access_key' => '',
        'r2_public_url'        => '',
        'r2_jurisdiction'      => '',
        'r2_auto_sync'         => 0,
        'r2_delete_local'      => 0,

        // ffmpeg.wasm → MP3
        'mp3_auto'             => 1,
        'mp3_bitrate'          => '32k',
        'mp3_sample_rate'      => '22050',

        // OpenRouter speech-to-text → VTT
        'openrouter_api_key'   => '',
        'stt_model'            => 'openai/whisper-1',
        // Text generation (course field writing) — a chat model, not Whisper.
        'ai_model'             => 'anthropic/claude-sonnet-4.5',
        'stt_language'         => '',
        'vtt_auto'             => 1,
        'vtt_max_words'        => 12,
        'vtt_max_seconds'      => 5,
        'vtt_pause_gap'        => 0.8,

        // Background processing
        'cron_enabled'         => 1,
    ];
}

function snn_media_get( $key ) {
    $defaults = snn_media_defaults();
    $default  = $defaults[ $key ] ?? '';
    $value    = get_option( 'snn_learn_media_' . $key, null );

    if ( null === $value ) {
        return $default;
    }
    return $value;
}

function snn_media_set( $key, $value ) {
    return update_option( 'snn_learn_media_' . $key, $value );
}

/** Extensions accepted by the uploader, normalised to a lowercase list. */
function snn_media_allowed_extensions() {
    $raw = (string) snn_media_get( 'allowed_extensions' );
    $out = array_filter( array_map(
        // Trim whitespace before the dot, or " .mov " keeps its dot and matches nothing.
        function ( $e ) { return ltrim( strtolower( trim( $e ) ), '.' ); },
        explode( ',', $raw )
    ) );
    return array_values( array_unique( $out ) );
}

function snn_media_mime_for_ext( $ext ) {
    $map = [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'mov'  => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv'  => 'video/x-matroska',
        'avi'  => 'video/x-msvideo',
        'wmv'  => 'video/x-ms-wmv',
        'flv'  => 'video/x-flv',
        'ts'   => 'video/mp2t',
        'mts'  => 'video/mp2t',
        'm2ts' => 'video/mp2t',
        'mp3'  => 'audio/mpeg',
        'vtt'  => 'text/vtt',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];
    return $map[ strtolower( $ext ) ] ?? 'application/octet-stream';
}

// ============================================================
// 2. STORAGE PATHS
// ============================================================

/** Absolute path to the media directory, created and hardened on first use. */
function snn_media_dir() {
    // Listing a page of the library calls this once per row, so do the
    // filesystem work only on the first call of each request.
    static $dir = null;
    if ( null !== $dir ) {
        return $dir;
    }

    $uploads = wp_upload_dir();
    $dir     = trailingslashit( $uploads['basedir'] ) . 'snn-learn-media/';

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    snn_media_harden_dir( $dir );

    return $dir;
}

function snn_media_url() {
    $uploads = wp_upload_dir();
    return trailingslashit( $uploads['baseurl'] ) . 'snn-learn-media/';
}

/** Block PHP execution and directory listing inside the media folder. */
function snn_media_harden_dir( $dir ) {
    $htaccess = $dir . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        $rules  = "# Block script execution in the SNN Learn media folder\n";
        $rules .= "<FilesMatch \"\\.(?:php[0-9]?|phtml|phar|pl|py|rb|sh|cgi|bash)$\">\n";
        $rules .= "    Order Allow,Deny\n";
        $rules .= "    Deny from all\n";
        $rules .= "</FilesMatch>\n";
        $rules .= "Options -ExecCGI -Indexes\n";
        @file_put_contents( $htaccess, $rules );
    }
    $index = $dir . 'index.php';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }
}

/** Directory holding in-flight chunked uploads. */
function snn_media_temp_dir() {
    $dir = snn_media_dir() . 'tmp/';
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
        @file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
    }
    return $dir;
}

// ============================================================
// 3. DATABASE
// ============================================================

function snn_media_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_learn_media';
}

function snn_media_tags_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_learn_media_tags';
}

function snn_media_tag_map_table() {
    global $wpdb;
    return $wpdb->prefix . 'snn_learn_media_tag_map';
}

function snn_media_create_table() {
    global $wpdb;
    $table   = snn_media_table();
    $charset = $wpdb->get_charset_collate();

    // dbDelta rules: lowercase types, exactly one space between column name and
    // type, two spaces before (id) in PRIMARY KEY.
    $sql = "CREATE TABLE $table (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        filename varchar(255) NOT NULL,
        original_name varchar(255) NOT NULL,
        extension varchar(16) NOT NULL DEFAULT '',
        mime varchar(100) NOT NULL DEFAULT '',
        filesize bigint unsigned NOT NULL DEFAULT 0,
        duration float DEFAULT NULL,
        uploaded_by bigint unsigned NOT NULL DEFAULT 0,
        uploaded_at int unsigned NOT NULL DEFAULT 0,
        mp3_file varchar(255) DEFAULT NULL,
        mp3_size bigint unsigned NOT NULL DEFAULT 0,
        mp3_status varchar(20) NOT NULL DEFAULT 'pending',
        vtt_file varchar(255) DEFAULT NULL,
        vtt_status varchar(20) NOT NULL DEFAULT 'pending',
        thumb_file varchar(255) DEFAULT NULL,
        thumb_status varchar(20) NOT NULL DEFAULT 'pending',
        r2_key varchar(512) DEFAULT NULL,
        r2_url text DEFAULT NULL,
        r2_mp3_url text DEFAULT NULL,
        r2_vtt_url text DEFAULT NULL,
        r2_thumb_url text DEFAULT NULL,
        r2_status varchar(20) NOT NULL DEFAULT 'pending',
        r2_synced_at int unsigned DEFAULT NULL,
        local_deleted tinyint(1) NOT NULL DEFAULT 0,
        error_msg text DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_filename (filename),
        KEY idx_uploaded_at (uploaded_at),
        KEY idx_mp3_status (mp3_status),
        KEY idx_vtt_status (vtt_status),
        KEY idx_r2_status (r2_status)
    ) $charset;";

    // Tags are their own vocabulary: a tag can be renamed or recoloured without
    // touching a single media row, and lives on after the videos wearing it go.
    $tags_sql = "CREATE TABLE " . snn_media_tags_table() . " (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        name varchar(120) NOT NULL,
        slug varchar(140) NOT NULL,
        color varchar(16) NOT NULL DEFAULT '#2563eb',
        created_at int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_slug (slug)
    ) $charset;";

    $map_sql = "CREATE TABLE " . snn_media_tag_map_table() . " (
        media_id bigint unsigned NOT NULL,
        tag_id bigint unsigned NOT NULL,
        PRIMARY KEY  (media_id,tag_id),
        KEY idx_tag_id (tag_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    dbDelta( $tags_sql );
    dbDelta( $map_sql );
}

add_action( 'plugins_loaded', function () {
    if ( get_option( 'snn_learn_media_db_version' ) !== SNN_MEDIA_DB_VERSION ) {
        snn_media_create_table();

        // ffmpeg.wasm is always loaded from the copies bundled with the plugin.
        // Earlier versions stored CDN URLs here, and a stored value always wins
        // over a default — so installs that saved those would have kept loading
        // cross-origin (and failing to construct the worker) forever. Delete
        // them rather than leaving dead rows that silently override the code.
        foreach ( [ 'ffmpeg_base_url', 'ffmpeg_core_url', 'mp3_max_source_mb' ] as $retired ) {
            delete_option( 'snn_learn_media_' . $retired );
        }

        update_option( 'snn_learn_media_db_version', SNN_MEDIA_DB_VERSION );
    }
} );

/**
 * Tags attached to a set of media ids, keyed by media id.
 *
 * One query for a whole page of the library rather than one per row — the
 * listing endpoint renders 25–200 items and N+1 here is felt immediately.
 *
 * @param int[] $ids
 * @return array<int, array>
 */
function snn_media_tags_for( array $ids ) {
    $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
    if ( ! $ids ) {
        return [];
    }

    global $wpdb;
    $map  = snn_media_tag_map_table();
    $tags = snn_media_tags_table();
    $in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
        "SELECT m.media_id, t.id, t.name, t.slug, t.color
           FROM $map m
           INNER JOIN $tags t ON t.id = m.tag_id
          WHERE m.media_id IN ($in)
          ORDER BY t.name ASC",
        $ids
    ) );

    $out = [];
    foreach ( $rows as $row ) {
        $out[ (int) $row->media_id ][] = [
            'id'    => (int) $row->id,
            'name'  => $row->name,
            'slug'  => $row->slug,
            'color' => $row->color,
        ];
    }
    return $out;
}

/** Every tag, with how many videos wear it. */
function snn_media_all_tags() {
    global $wpdb;
    $tags = snn_media_tags_table();
    $map  = snn_media_tag_map_table();

    $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
        "SELECT t.id, t.name, t.slug, t.color, COUNT(m.media_id) AS uses
           FROM $tags t
           LEFT JOIN $map m ON m.tag_id = t.id
          GROUP BY t.id, t.name, t.slug, t.color
          ORDER BY t.name ASC"
    );

    return array_map( function ( $row ) {
        return [
            'id'    => (int) $row->id,
            'name'  => $row->name,
            'slug'  => $row->slug,
            'color' => $row->color,
            'uses'  => (int) $row->uses,
        ];
    }, $rows ?: [] );
}

/** Normalises a colour to `#rrggbb`, falling back to the default blue. */
function snn_media_sanitize_color( $color ) {
    $color = trim( (string) $color );
    if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
        return strtolower( $color );
    }
    if ( preg_match( '/^#([0-9a-fA-F]{3})$/', $color, $m ) ) {
        $c = strtolower( $m[1] );
        return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
    }
    return '#2563eb';
}

/** Replaces an item's tags with exactly the given ids. Returns the tag rows. */
function snn_media_set_item_tags( $media_id, array $tag_ids ) {
    global $wpdb;
    $map      = snn_media_tag_map_table();
    $media_id = (int) $media_id;
    $tag_ids  = array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ) ) ) );

    $wpdb->delete( $map, [ 'media_id' => $media_id ] ); // phpcs:ignore WordPress.DB

    foreach ( $tag_ids as $tag_id ) {
        // Insert through the tags table so a stale id from the browser cannot
        // create a mapping row pointing at nothing.
        $exists = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT id FROM ' . snn_media_tags_table() . ' WHERE id = %d', $tag_id
        ) );
        if ( $exists ) {
            $wpdb->insert( $map, [ 'media_id' => $media_id, 'tag_id' => $exists ] ); // phpcs:ignore WordPress.DB
        }
    }

    $tags = snn_media_tags_for( [ $media_id ] );
    return $tags[ $media_id ] ?? [];
}

/** Shape one DB row into the JSON structure the admin UI consumes. */
function snn_media_row_to_array( $row, $tags = null ) {
    $base_url = snn_media_url();
    $dir      = snn_media_dir();

    $local_exists = ! $row->local_deleted && file_exists( $dir . $row->filename );

    return [
        'id'            => (int) $row->id,
        'filename'      => $row->filename,
        'original_name' => $row->original_name,
        'extension'     => $row->extension,
        'filesize'      => (int) $row->filesize,
        'filesize_h'    => size_format( (int) $row->filesize, 1 ),
        'duration'      => $row->duration ? (float) $row->duration : null,
        'uploaded_at'   => (int) $row->uploaded_at,
        'uploaded_date' => $row->uploaded_at ? date_i18n( 'Y-m-d H:i', (int) $row->uploaded_at ) : '',
        'uploaded_by'   => $row->uploaded_by ? get_the_author_meta( 'display_name', (int) $row->uploaded_by ) : '',
        'local_exists'  => (bool) $local_exists,
        'local_url'     => $local_exists ? $base_url . rawurlencode( $row->filename ) : '',
        'mp3_file'      => $row->mp3_file,
        'mp3_size'      => (int) $row->mp3_size,
        'mp3_size_h'    => $row->mp3_size ? size_format( (int) $row->mp3_size, 1 ) : '',
        'mp3_status'    => $row->mp3_status,
        'mp3_url'       => $row->mp3_file && file_exists( $dir . $row->mp3_file ) ? $base_url . rawurlencode( $row->mp3_file ) : '',
        'vtt_file'      => $row->vtt_file,
        'vtt_status'    => $row->vtt_status,
        'vtt_url'       => $row->vtt_file && file_exists( $dir . $row->vtt_file ) ? $base_url . rawurlencode( $row->vtt_file ) : '',
        'thumb_file'    => $row->thumb_file,
        'thumb_status'  => $row->thumb_status,
        'thumb_url'     => $row->thumb_file && file_exists( $dir . $row->thumb_file ) ? $base_url . rawurlencode( $row->thumb_file ) : '',
        'r2_thumb_url'  => $row->r2_thumb_url,
        'r2_status'     => $row->r2_status,
        'r2_url'        => $row->r2_url,
        'r2_mp3_url'    => $row->r2_mp3_url,
        'r2_vtt_url'    => $row->r2_vtt_url,
        'r2_synced_at'  => $row->r2_synced_at ? date_i18n( 'Y-m-d H:i', (int) $row->r2_synced_at ) : '',
        // The URL a lesson should point at: R2 when synced, local otherwise.
        'playback_url'  => $row->r2_url ? $row->r2_url : ( $local_exists ? $base_url . rawurlencode( $row->filename ) : '' ),
        // The poster to show in the grid: R2 when synced, local otherwise.
        'poster_url'    => $row->r2_thumb_url
            ? $row->r2_thumb_url
            : ( $row->thumb_file && file_exists( $dir . $row->thumb_file ) ? $base_url . rawurlencode( $row->thumb_file ) : '' ),
        'error_msg'     => $row->error_msg,
        'tags'          => null === $tags ? ( snn_media_tags_for( [ (int) $row->id ] )[ (int) $row->id ] ?? [] ) : $tags,
    ];
}

function snn_media_find( $id ) {
    global $wpdb;
    $table = snn_media_table();
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
}

function snn_media_update( $id, $data ) {
    global $wpdb;
    return $wpdb->update( snn_media_table(), $data, [ 'id' => (int) $id ] ); // phpcs:ignore WordPress.DB
}

// ============================================================
// 4. CLOUDFLARE R2 (S3 API, SigV4)
// ============================================================

/** Returns the resolved R2 config, or null when any required field is blank. */
function snn_media_r2_config() {
    $required = [ 'r2_account_id', 'r2_bucket', 'r2_access_key_id', 'r2_secret_access_key', 'r2_public_url' ];
    foreach ( $required as $k ) {
        if ( '' === trim( (string) snn_media_get( $k ) ) ) {
            return null;
        }
    }

    $account = trim( (string) snn_media_get( 'r2_account_id' ) );
    $jur     = trim( (string) snn_media_get( 'r2_jurisdiction' ) );
    $host    = $account . ( $jur ? '.' . $jur : '' ) . '.r2.cloudflarestorage.com';

    return [
        'account_id' => $account,
        'bucket'     => trim( (string) snn_media_get( 'r2_bucket' ) ),
        'access_key' => trim( (string) snn_media_get( 'r2_access_key_id' ) ),
        'secret_key' => trim( (string) snn_media_get( 'r2_secret_access_key' ) ),
        'public_url' => rtrim( trim( (string) snn_media_get( 'r2_public_url' ) ), '/' ),
        'host'       => $host,
    ];
}

function snn_media_r2_configured() {
    return null !== snn_media_r2_config();
}

/**
 * Builds the SigV4 Authorization header for one R2 request.
 *
 * @return array Headers to pass to cURL.
 */
function snn_media_r2_sign( $config, $method, $uri, $payload_hash, $extra_headers = [] ) {
    $host      = $config['host'];
    $now       = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
    $amz_date  = $now->format( 'Ymd\THis\Z' );
    $datestamp = $now->format( 'Ymd' );
    $region    = 'auto';
    $service   = 's3';

    // Canonical headers must be sorted by lowercased header name.
    $headers = array_change_key_case( $extra_headers, CASE_LOWER );
    $headers['host']                 = $host;
    $headers['x-amz-content-sha256'] = $payload_hash;
    $headers['x-amz-date']           = $amz_date;
    ksort( $headers );

    $canon_headers = '';
    foreach ( $headers as $k => $v ) {
        $canon_headers .= $k . ':' . trim( (string) $v ) . "\n";
    }
    $signed_headers = implode( ';', array_keys( $headers ) );

    $canon_request = "{$method}\n{$uri}\n\n{$canon_headers}\n{$signed_headers}\n{$payload_hash}";
    $cred_scope    = "{$datestamp}/{$region}/{$service}/aws4_request";
    $str_to_sign   = "AWS4-HMAC-SHA256\n{$amz_date}\n{$cred_scope}\n" . hash( 'sha256', $canon_request );

    $k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $config['secret_key'], true );
    $k_region  = hash_hmac( 'sha256', $region, $k_date, true );
    $k_service = hash_hmac( 'sha256', $service, $k_region, true );
    $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
    $signature = hash_hmac( 'sha256', $str_to_sign, $k_signing );

    $auth = "AWS4-HMAC-SHA256 Credential={$config['access_key']}/{$cred_scope}, "
          . "SignedHeaders={$signed_headers}, Signature={$signature}";

    $out = [ "Authorization: {$auth}" ];
    foreach ( $headers as $k => $v ) {
        if ( 'host' === $k ) {
            continue; // cURL sets Host itself.
        }
        $out[] = $k . ': ' . $v;
    }
    return $out;
}

/** Streams a local file to R2 with a signed PUT. */
function snn_media_r2_put( $config, $key, $file_path, $content_type ) {
    if ( ! file_exists( $file_path ) ) {
        return [ 'ok' => false, 'code' => 0, 'error' => 'Local file not found: ' . basename( $file_path ) ];
    }

    $encoded_key  = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
    $uri          = '/' . $config['bucket'] . '/' . $encoded_key;
    $url          = 'https://' . $config['host'] . $uri;
    $payload_hash = hash_file( 'sha256', $file_path );

    $headers   = snn_media_r2_sign( $config, 'PUT', $uri, $payload_hash, [ 'content-type' => $content_type ] );
    // Some proxies mishandle 100-continue on large bodies; skip the handshake.
    $headers[] = 'Expect:';

    $fp = fopen( $file_path, 'rb' );
    if ( ! $fp ) {
        return [ 'ok' => false, 'code' => 0, 'error' => 'Could not read local file.' ];
    }

    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fp,
        CURLOPT_INFILESIZE     => filesize( $file_path ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 1800,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ] );
    $body      = curl_exec( $ch );
    $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $curl_err  = curl_error( $ch );
    curl_close( $ch );
    fclose( $fp );

    return [
        'ok'    => ( $http_code >= 200 && $http_code < 300 ),
        'code'  => $http_code,
        'error' => $curl_err ? $curl_err : (string) $body,
    ];
}

/** Deletes one object from R2. */
function snn_media_r2_delete( $config, $key ) {
    $encoded_key = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
    $uri         = '/' . $config['bucket'] . '/' . $encoded_key;
    $url         = 'https://' . $config['host'] . $uri;

    // SHA-256 of the empty string.
    $payload_hash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    $headers      = snn_media_r2_sign( $config, 'DELETE', $uri, $payload_hash );

    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
    ] );
    $body      = curl_exec( $ch );
    $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $curl_err  = curl_error( $ch );
    curl_close( $ch );

    return [
        'ok'    => ( $http_code >= 200 && $http_code < 300 ) || 404 === $http_code,
        'code'  => $http_code,
        'error' => $curl_err ? $curl_err : (string) $body,
    ];
}

// ============================================================
// 5. WEBVTT BUILDING
// ============================================================

function snn_media_vtt_timestamp( $seconds ) {
    $seconds = max( 0, (float) $seconds );
    $hours   = (int) floor( $seconds / 3600 );
    $minutes = (int) floor( ( $seconds - $hours * 3600 ) / 60 );
    $secs    = $seconds - $hours * 3600 - $minutes * 60;
    return sprintf( '%02d:%02d:%06.3f', $hours, $minutes, $secs );
}

/**
 * Groups Whisper word timestamps into readable cues.
 *
 * A cue is closed on a speech pause, on a maximum duration, or on a maximum
 * word count — whichever comes first.
 */
function snn_media_words_to_cues( $words ) {
    $max_seconds = (float) snn_media_get( 'vtt_max_seconds' );
    $max_words   = (int) snn_media_get( 'vtt_max_words' );
    $pause_gap   = (float) snn_media_get( 'vtt_pause_gap' );

    $cues      = [];
    $current   = [];
    $cue_start = null;
    $prev_end  = null;

    foreach ( $words as $word ) {
        $text  = trim( (string) ( $word['word'] ?? $word['text'] ?? '' ) );
        $start = isset( $word['start'] ) ? (float) $word['start'] : null;
        $end   = isset( $word['end'] ) ? (float) $word['end'] : null;

        if ( '' === $text || null === $start || null === $end ) {
            continue;
        }

        $is_pause = ( null !== $prev_end && ( $start - $prev_end ) >= $pause_gap );

        if ( null === $cue_start ) {
            $cue_start = $start;
        }
        $current[] = $text;

        $too_long = ( $end - $cue_start ) >= $max_seconds;
        $too_many = count( $current ) >= $max_words;

        if ( $is_pause && count( $current ) > 1 ) {
            // The pause belongs *before* this word — close the cue without it.
            array_pop( $current );
            $cues[]    = [ 'start' => $cue_start, 'end' => $prev_end, 'text' => implode( ' ', $current ) ];
            $current   = [ $text ];
            $cue_start = $start;
        } elseif ( $is_pause || $too_long || $too_many ) {
            $cues[]    = [ 'start' => $cue_start, 'end' => $end, 'text' => implode( ' ', $current ) ];
            $current   = [];
            $cue_start = null;
        }

        $prev_end = $end;
    }

    if ( $current && null !== $cue_start ) {
        $cues[] = [ 'start' => $cue_start, 'end' => $prev_end, 'text' => implode( ' ', $current ) ];
    }

    return $cues;
}

/** Falls back to Whisper's own segments when word timestamps are unavailable. */
function snn_media_segments_to_cues( $segments ) {
    $cues = [];
    foreach ( $segments as $segment ) {
        $text = trim( (string) ( $segment['text'] ?? '' ) );
        if ( '' === $text ) {
            continue;
        }
        $cues[] = [
            'start' => (float) ( $segment['start'] ?? 0 ),
            'end'   => (float) ( $segment['end'] ?? 0 ),
            'text'  => $text,
        ];
    }
    return $cues;
}

function snn_media_cues_to_vtt( $cues ) {
    $vtt = "WEBVTT\n\n";
    foreach ( $cues as $i => $cue ) {
        $vtt .= ( $i + 1 ) . "\n";
        $vtt .= snn_media_vtt_timestamp( $cue['start'] ) . ' --> ' . snn_media_vtt_timestamp( $cue['end'] ) . "\n";
        $vtt .= $cue['text'] . "\n\n";
    }
    return $vtt;
}

// ============================================================
// 6. OPENROUTER SPEECH-TO-TEXT
// ============================================================

/**
 * Transcribes an audio file through OpenRouter's OpenAI-compatible
 * /audio/transcriptions endpoint and writes the resulting WebVTT next to it.
 *
 * @return array|WP_Error
 */
function snn_media_transcribe_to_vtt( $row ) {
    $api_key = trim( (string) snn_media_get( 'openrouter_api_key' ) );
    if ( '' === $api_key ) {
        return new WP_Error( 'snn_media_no_key', 'No OpenRouter API key is configured.' );
    }

    $dir = snn_media_dir();
    if ( ! $row->mp3_file || ! file_exists( $dir . $row->mp3_file ) ) {
        return new WP_Error(
            'snn_media_no_audio',
            'No MP3 audio track for this video yet. Generate the MP3 first — subtitles are transcribed from it.'
        );
    }

    $audio_path = $dir . $row->mp3_file;

    $fields = [
        'file'                      => new CURLFile( $audio_path, 'audio/mpeg', $row->mp3_file ),
        'model'                     => (string) snn_media_get( 'stt_model' ),
        'response_format'           => 'verbose_json',
        'timestamp_granularities[]' => 'word',
    ];
    $language = trim( (string) snn_media_get( 'stt_language' ) );
    if ( '' !== $language ) {
        $fields['language'] = $language;
    }

    $ch = curl_init();
    curl_setopt_array( $ch, [
        CURLOPT_URL            => 'https://openrouter.ai/api/v1/audio/transcriptions',
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 900,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'HTTP-Referer: ' . home_url(),
            'X-Title: SNN Learn Media Library',
        ],
        CURLOPT_POSTFIELDS     => $fields,
    ] );
    $response  = curl_exec( $ch );
    $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $curl_err  = curl_error( $ch );
    curl_close( $ch );

    if ( $curl_err ) {
        return new WP_Error( 'snn_media_stt_curl', 'Network error contacting OpenRouter: ' . $curl_err );
    }
    if ( 200 !== $http_code ) {
        return new WP_Error(
            'snn_media_stt_http',
            'OpenRouter returned HTTP ' . $http_code . ': ' . mb_substr( (string) $response, 0, 500 )
        );
    }

    $data = json_decode( (string) $response, true );
    if ( JSON_ERROR_NONE !== json_last_error() ) {
        return new WP_Error( 'snn_media_stt_json', 'Could not parse the OpenRouter response.' );
    }

    if ( ! empty( $data['words'] ) && is_array( $data['words'] ) ) {
        $cues = snn_media_words_to_cues( $data['words'] );
    } elseif ( ! empty( $data['segments'] ) && is_array( $data['segments'] ) ) {
        $cues = snn_media_segments_to_cues( $data['segments'] );
    } elseif ( ! empty( $data['text'] ) ) {
        // Last resort: a single cue spanning the whole track.
        $cues = [ [ 'start' => 0, 'end' => (float) ( $data['duration'] ?? 0 ), 'text' => trim( $data['text'] ) ] ];
    } else {
        return new WP_Error( 'snn_media_stt_empty', 'The transcription came back empty.' );
    }

    if ( ! $cues ) {
        return new WP_Error( 'snn_media_stt_empty', 'The transcription produced no subtitle cues.' );
    }

    $vtt_file = pathinfo( $row->filename, PATHINFO_FILENAME ) . '.vtt';
    $vtt_path = $dir . $vtt_file;

    if ( false === file_put_contents( $vtt_path, snn_media_cues_to_vtt( $cues ) ) ) {
        return new WP_Error( 'snn_media_vtt_write', 'Could not write the .vtt file.' );
    }
    @chmod( $vtt_path, 0644 );

    return [
        'vtt_file' => $vtt_file,
        'vtt_size' => filesize( $vtt_path ),
        'cues'     => count( $cues ),
        'duration' => isset( $data['duration'] ) ? (float) $data['duration'] : null,
    ];
}

/**
 * Finds the media row a stored field URL points at.
 *
 * Field meta holds a plain playback URL so the front end stays dumb, which
 * means going the other way — URL back to library row — needs a lookup. R2 and
 * local URLs are both matched, and the basename is the last resort so a URL
 * that was typed by hand still resolves.
 */
function snn_media_find_by_url( $url ) {
    global $wpdb;

    $url = trim( (string) $url );
    if ( '' === $url ) {
        return null;
    }

    $table = snn_media_table();

    $row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
        "SELECT * FROM $table WHERE r2_url = %s LIMIT 1",
        $url
    ) );
    if ( $row ) {
        return $row;
    }

    $filename = rawurldecode( basename( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ) );
    if ( '' === $filename ) {
        return null;
    }

    return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
        "SELECT * FROM $table WHERE filename = %s LIMIT 1",
        $filename
    ) );
}

/** The .vtt text for a media row, from disk when possible, else over HTTP. */
function snn_media_vtt_text( $row ) {
    if ( ! $row ) {
        return new WP_Error( 'snn_media_no_row', 'That video is not in the media library.' );
    }

    $path = snn_media_dir() . $row->vtt_file;
    if ( $row->vtt_file && file_exists( $path ) ) {
        $text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( false !== $text ) {
            return $text;
        }
    }

    if ( $row->r2_vtt_url ) {
        $response = wp_remote_get( $row->r2_vtt_url, [ 'timeout' => 30 ] );
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            return wp_remote_retrieve_body( $response );
        }
    }

    return new WP_Error(
        'snn_media_no_vtt',
        'That video has no subtitles yet. Generate the .vtt in the Media Library first — the transcript is what these fields are written from.'
    );
}

/**
 * Condenses raw VTT into timestamped transcript lines.
 *
 * Whisper emits a cue every few seconds, which triples the token count for no
 * benefit. Merging into fixed windows keeps timestamps accurate enough to place
 * a chapter mark while cutting the transcript roughly in half.
 *
 * @param string $vtt     Raw WebVTT.
 * @param int    $window  Seconds of speech to merge into one line.
 */
function snn_media_vtt_to_transcript( $vtt, $window = 15 ) {
    $lines  = preg_split( '/\r\n|\r|\n/', (string) $vtt );
    $blocks = [];
    $start  = null;
    $buffer = [];

    $flush = function () use ( &$blocks, &$start, &$buffer ) {
        if ( null !== $start && $buffer ) {
            $blocks[] = snn_media_vtt_timestamp_short( $start ) . ' ' . implode( ' ', $buffer );
        }
        $start  = null;
        $buffer = [];
    };

    $cue_start = null;

    foreach ( $lines as $line ) {
        $line = trim( $line );

        if ( '' === $line || 'WEBVTT' === strtoupper( $line ) || is_numeric( $line ) ) {
            continue;
        }

        // "00:00:12.500 --> 00:00:15.000" — the cue's start time.
        if ( false !== strpos( $line, '-->' ) ) {
            $parts     = explode( '-->', $line );
            $cue_start = snn_media_vtt_seconds( trim( $parts[0] ) );

            if ( null === $start ) {
                $start = $cue_start;
            } elseif ( $cue_start - $start >= $window ) {
                $flush();
                $start = $cue_start;
            }
            continue;
        }

        if ( null !== $cue_start ) {
            $buffer[] = preg_replace( '/<[^>]*>/', '', $line );
        }
    }

    $flush();

    return implode( "\n", $blocks );
}

/** "00:01:02.500" → 62.5 seconds. */
function snn_media_vtt_seconds( $stamp ) {
    if ( ! preg_match( '/(\d+):(\d{2}):(\d{2})[.,](\d{1,3})|(\d+):(\d{2})[.,](\d{1,3})/', $stamp, $m ) ) {
        return 0.0;
    }

    // Group 3 is only set by the h:mm:ss alternative, which is what separates
    // "01:02.500" (a minute in) from "01:02:00.000" (an hour in).
    if ( isset( $m[3] ) && '' !== $m[3] ) {
        return (float) ( (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] ) + (int) str_pad( $m[4], 3, '0' ) / 1000;
    }

    return (float) ( (int) $m[5] * 60 + (int) $m[6] ) + (int) str_pad( $m[7], 3, '0' ) / 1000;
}

/** Seconds → "MM:SS", or "H:MM:SS" past the hour — the format chapters use. */
function snn_media_vtt_timestamp_short( $seconds ) {
    $seconds = (int) round( max( 0, (float) $seconds ) );
    $hours   = (int) floor( $seconds / 3600 );
    $minutes = (int) floor( ( $seconds % 3600 ) / 60 );
    $secs    = $seconds % 60;

    return $hours > 0
        ? sprintf( '%d:%02d:%02d', $hours, $minutes, $secs )
        : sprintf( '%02d:%02d', $minutes, $secs );
}

/**
 * One OpenRouter chat completion, returning the decoded JSON the model produced.
 *
 * The caller supplies a JSON Schema; response_format pins the model to it so
 * the result can be trusted to match the field's shape without reparsing prose.
 *
 * @return array|WP_Error
 */
function snn_media_openrouter_json( $system, $user, $schema ) {
    $api_key = trim( (string) snn_media_get( 'openrouter_api_key' ) );
    if ( '' === $api_key ) {
        return new WP_Error( 'snn_media_no_key', 'No OpenRouter API key is configured. Add one in Media Settings.' );
    }

    $model = trim( (string) snn_media_get( 'ai_model' ) );
    if ( '' === $model ) {
        return new WP_Error( 'snn_media_no_model', 'No text generation model is configured. Set one in Media Settings.' );
    }

    $response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
        'timeout' => 180,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => home_url(),
            'X-Title'       => 'SNN Learn Course Fields',
        ],
        'body'    => wp_json_encode( [
            'model'           => $model,
            'temperature'     => 0.3,
            'messages'        => [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user ],
            ],
            'response_format' => [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'snn_field',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'snn_media_ai_http', 'Could not reach OpenRouter: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( 200 !== $code ) {
        return new WP_Error( 'snn_media_ai_http', 'OpenRouter returned HTTP ' . $code . ': ' . mb_substr( $body, 0, 300 ) );
    }

    $data    = json_decode( $body, true );
    $content = $data['choices'][0]['message']['content'] ?? '';

    if ( '' === $content ) {
        return new WP_Error( 'snn_media_ai_empty', 'The model returned nothing. Try again, or pick a different model.' );
    }

    $parsed = json_decode( $content, true );
    if ( JSON_ERROR_NONE !== json_last_error() ) {
        return new WP_Error( 'snn_media_ai_json', 'The model did not return valid JSON. This usually means the model does not support structured output — try another one.' );
    }

    return $parsed;
}

// ============================================================
// 7. R2 SYNC PIPELINE
// ============================================================

/**
 * Pushes a media row's video (and its MP3/VTT siblings) to R2 and records the
 * resulting public URLs.
 *
 * @return array|WP_Error
 */
function snn_media_sync_to_r2( $row ) {
    $config = snn_media_r2_config();
    if ( ! $config ) {
        return new WP_Error( 'snn_media_r2_unconfigured', 'Cloudflare R2 is not configured yet. Add your credentials in Media Settings.' );
    }

    $dir        = snn_media_dir();
    $video_path = $dir . $row->filename;

    if ( ! file_exists( $video_path ) ) {
        return new WP_Error( 'snn_media_no_local', 'The local video file is missing, so there is nothing to upload.' );
    }

    snn_media_update( $row->id, [ 'r2_status' => 'syncing', 'error_msg' => null ] );

    $video_key = $row->filename;
    $result    = snn_media_r2_put( $config, $video_key, $video_path, snn_media_mime_for_ext( $row->extension ) );

    if ( ! $result['ok'] ) {
        $message = 'R2 upload failed (HTTP ' . $result['code'] . '): ' . mb_substr( $result['error'], 0, 400 );
        snn_media_update( $row->id, [ 'r2_status' => 'error', 'error_msg' => $message ] );
        return new WP_Error( 'snn_media_r2_failed', $message );
    }

    $update = [
        'r2_key'       => $video_key,
        'r2_url'       => $config['public_url'] . '/' . snn_media_encode_key( $video_key ),
        'r2_status'    => 'synced',
        'r2_synced_at' => time(),
        'error_msg'    => null,
    ];

    // Siblings are best-effort: a failed .mp3 or .vtt must not fail the video.
    foreach ( [ 'mp3_file' => 'r2_mp3_url', 'vtt_file' => 'r2_vtt_url', 'thumb_file' => 'r2_thumb_url' ] as $field => $url_field ) {
        $name = $row->$field;
        if ( ! $name || ! file_exists( $dir . $name ) ) {
            continue;
        }
        $key = $name;
        $res = snn_media_r2_put( $config, $key, $dir . $name, snn_media_mime_for_ext( pathinfo( $name, PATHINFO_EXTENSION ) ) );
        if ( $res['ok'] ) {
            $update[ $url_field ] = $config['public_url'] . '/' . snn_media_encode_key( $key );
        }
    }

    // Optionally reclaim local disk once the video is safely in the bucket. The
    // .mp3 stays: transcription reads it, and it is small.
    if ( snn_media_get( 'r2_delete_local' ) ) {
        @unlink( $video_path );
        $update['local_deleted'] = 1;
    }

    snn_media_update( $row->id, $update );

    return [
        'r2_url'       => $update['r2_url'],
        'r2_mp3_url'   => $update['r2_mp3_url'] ?? $row->r2_mp3_url,
        'r2_vtt_url'   => $update['r2_vtt_url'] ?? $row->r2_vtt_url,
        'r2_thumb_url' => $update['r2_thumb_url'] ?? $row->r2_thumb_url,
    ];
}

/** rawurlencode each path segment but keep the slashes readable. */
function snn_media_encode_key( $key ) {
    return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
}

/** Removes a row's objects from R2. Best effort — never blocks a local delete. */
function snn_media_purge_from_r2( $row ) {
    $config = snn_media_r2_config();
    if ( ! $config ) {
        return;
    }
    $keys = [ $row->r2_key ? $row->r2_key : $row->filename ];
    if ( $row->mp3_file ) {
        $keys[] = $row->mp3_file;
    }
    if ( $row->vtt_file ) {
        $keys[] = $row->vtt_file;
    }
    if ( $row->thumb_file ) {
        $keys[] = $row->thumb_file;
    }
    foreach ( array_unique( $keys ) as $key ) {
        snn_media_r2_delete( $config, $key );
    }
}

// ============================================================
// 8. SCHEDULED BACKGROUND PROCESSING
// ============================================================

add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['snn_media_five_minutes'] ) ) {
        $schedules['snn_media_five_minutes'] = [
            'interval' => 300,
            'display'  => 'Every 5 Minutes (SNN Media)',
        ];
    }
    return $schedules;
} );

add_action( 'init', function () {
    $enabled   = (int) snn_media_get( 'cron_enabled' );
    $scheduled = wp_next_scheduled( 'snn_media_process_queue' );

    if ( $enabled && ! $scheduled ) {
        wp_schedule_event( time() + 60, 'snn_media_five_minutes', 'snn_media_process_queue' );
    } elseif ( ! $enabled && $scheduled ) {
        wp_unschedule_event( $scheduled, 'snn_media_process_queue' );
    }
} );

/**
 * Works through whatever the browser could not do on its own.
 *
 * MP3 encoding is deliberately absent here: it runs in the browser through
 * ffmpeg.wasm, so the queue only picks up transcription and R2 sync — both of
 * which are plain server-side HTTP calls.
 */
function snn_media_run_queue() {
    global $wpdb;
    $table = snn_media_table();

    if ( snn_media_get( 'vtt_auto' ) && trim( (string) snn_media_get( 'openrouter_api_key' ) ) !== '' ) {
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
            "SELECT * FROM $table
             WHERE mp3_status = 'done' AND vtt_status IN ('pending','queued')
             ORDER BY uploaded_at ASC LIMIT 2"
        );
        foreach ( $rows as $row ) {
            snn_media_update( $row->id, [ 'vtt_status' => 'processing' ] );
            $result = snn_media_transcribe_to_vtt( $row );
            if ( is_wp_error( $result ) ) {
                snn_media_update( $row->id, [ 'vtt_status' => 'error', 'error_msg' => $result->get_error_message() ] );
            } else {
                snn_media_update( $row->id, [
                    'vtt_file'   => $result['vtt_file'],
                    'vtt_status' => 'done',
                    'duration'   => $result['duration'] ?: $row->duration,
                    'error_msg'  => null,
                ] );
            }
        }
    }

    if ( snn_media_get( 'r2_auto_sync' ) && snn_media_r2_configured() ) {
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
            "SELECT * FROM $table
             WHERE r2_status IN ('pending','queued') AND local_deleted = 0
             ORDER BY uploaded_at ASC LIMIT 2"
        );
        foreach ( $rows as $row ) {
            // Give the browser a chance to finish the MP3/VTT first so they ride
            // along with the video instead of needing a second sync — but only
            // for a while, so a tab that was never opened cannot stall the queue.
            $grace_over = ( time() - (int) $row->uploaded_at ) > HOUR_IN_SECONDS;
            if ( ! $grace_over && snn_media_row_still_processing( $row ) ) {
                continue;
            }
            snn_media_sync_to_r2( $row );
        }
    }

    snn_media_clean_temp_dir();
}
add_action( 'snn_media_process_queue', 'snn_media_run_queue' );

/** Removes `.part` files from uploads that were abandoned mid-flight. */
function snn_media_clean_temp_dir() {
    $parts = glob( snn_media_temp_dir() . '*.part' );
    if ( ! $parts ) {
        return;
    }
    foreach ( $parts as $part ) {
        if ( ( time() - filemtime( $part ) ) > DAY_IN_SECONDS ) {
            @unlink( $part );
        }
    }
}

/** True while an MP3 or VTT is still expected for this row. */
function snn_media_row_still_processing( $row ) {
    if ( snn_media_get( 'mp3_auto' ) && in_array( $row->mp3_status, [ 'pending', 'queued', 'processing' ], true ) ) {
        return true;
    }
    if ( snn_media_get( 'vtt_auto' ) && in_array( $row->vtt_status, [ 'pending', 'queued', 'processing' ], true ) ) {
        return true;
    }
    return false;
}

// ============================================================
// 9. REST API
// ============================================================

function snn_media_permission() {
    return current_user_can( 'manage_options' );
}

add_action( 'rest_api_init', function () {

    $auth = [ 'permission_callback' => 'snn_media_permission' ];

    register_rest_route( 'snn-learn/v1', '/media/items', array_merge( $auth, [
        'methods'  => 'GET',
        'callback' => 'snn_media_rest_items',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/chunk', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => 'snn_media_rest_chunk',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/mp3', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => 'snn_media_rest_store_mp3',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/mp3-status', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => 'snn_media_rest_mp3_status',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/transcribe', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => 'snn_media_rest_transcribe',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/r2-sync', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => 'snn_media_rest_r2_sync',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/item', array_merge( $auth, [
        'methods'  => 'DELETE',
        'callback' => 'snn_media_rest_delete',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/thumb', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => 'snn_media_rest_store_thumb',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/tags', array_merge( $auth, [
        'methods'  => 'GET',
        'callback' => function () {
            return rest_ensure_response( [ 'success' => true, 'tags' => snn_media_all_tags() ] );
        },
    ] ) );

    // One route, two verbs — POST creates or edits a tag, DELETE removes it.
    register_rest_route( 'snn-learn/v1', '/media/tag', [
        array_merge( $auth, [ 'methods' => 'POST', 'callback' => 'snn_media_rest_save_tag' ] ),
        array_merge( $auth, [ 'methods' => 'DELETE', 'callback' => 'snn_media_rest_delete_tag' ] ),
    ] );

    register_rest_route( 'snn-learn/v1', '/media/item-tags', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => 'snn_media_rest_item_tags',
    ] ) );

    register_rest_route( 'snn-learn/v1', '/media/run-queue', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => function () {
            snn_media_run_queue();
            return rest_ensure_response( [ 'success' => true ] );
        },
    ] ) );
} );

/** GET /media/items — paginated library listing, optionally filtered by tag. */
function snn_media_rest_items( WP_REST_Request $request ) {
    global $wpdb;
    $table = snn_media_table();
    $map   = snn_media_tag_map_table();

    $per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
    $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
    $search   = trim( (string) $request->get_param( 'search' ) );
    $tag_id   = (int) $request->get_param( 'tag' );
    $offset   = ( $page - 1 ) * $per_page;

    // Built as fragments so search and tag can be combined freely.
    $from  = "FROM $table t";
    $where = [];
    $args  = [];

    if ( $tag_id > 0 ) {
        $from   .= " INNER JOIN $map tm ON tm.media_id = t.id";
        $where[] = 'tm.tag_id = %d';
        $args[]  = $tag_id;
    }
    if ( '' !== $search ) {
        $like    = '%' . $wpdb->esc_like( $search ) . '%';
        $where[] = '(t.original_name LIKE %s OR t.filename LIKE %s)';
        $args[]  = $like;
        $args[]  = $like;
    }

    $clause = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

    $total = $args
        ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $from$clause", $args ) ) // phpcs:ignore WordPress.DB
        : (int) $wpdb->get_var( "SELECT COUNT(*) $from$clause" ); // phpcs:ignore WordPress.DB

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
        "SELECT t.* $from$clause ORDER BY t.uploaded_at DESC, t.id DESC LIMIT %d OFFSET %d",
        array_merge( $args, [ $per_page, $offset ] )
    ) );

    // One tag query for the whole page instead of one per row.
    $tags_by_id = snn_media_tags_for( wp_list_pluck( $rows ?: [], 'id' ) );

    $items = array_map( function ( $row ) use ( $tags_by_id ) {
        return snn_media_row_to_array( $row, $tags_by_id[ (int) $row->id ] ?? [] );
    }, $rows ?: [] );

    return rest_ensure_response( [
        'success'  => true,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'items'    => $items,
        'tags'     => snn_media_all_tags(),
    ] );
}

/**
 * POST /media/chunk — receives one slice of a file.
 *
 * Slices arrive strictly in order and are appended to a `.part` file. The last
 * slice renames the part into place and creates the library row.
 */
function snn_media_rest_chunk( WP_REST_Request $request ) {
    @set_time_limit( 0 );

    $files = $request->get_file_params();
    if ( empty( $files['chunk'] ) ) {
        return new WP_Error( 'snn_media_no_chunk', 'No chunk data was received.', [ 'status' => 400 ] );
    }

    $chunk = $files['chunk'];
    if ( UPLOAD_ERR_OK !== $chunk['error'] ) {
        return new WP_Error( 'snn_media_chunk_error', 'The server rejected the chunk (PHP upload error ' . $chunk['error'] . ').', [ 'status' => 400 ] );
    }

    $params        = $request->get_body_params();
    $chunk_index   = (int) ( $params['chunk_index'] ?? 0 );
    $total_chunks  = max( 1, (int) ( $params['total_chunks'] ?? 1 ) );
    $file_id       = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( $params['file_id'] ?? '' ) );
    $tag_ids       = array_filter( array_map( 'intval', explode( ',', (string) ( $params['tag_ids'] ?? '' ) ) ) );
    $original_name = sanitize_file_name( (string) ( $params['original_name'] ?? 'video' ) );

    if ( '' === $file_id ) {
        return new WP_Error( 'snn_media_bad_id', 'Invalid upload id.', [ 'status' => 400 ] );
    }

    $extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
    $allowed   = snn_media_allowed_extensions();
    if ( '' === $extension || ! in_array( $extension, $allowed, true ) ) {
        return new WP_Error(
            'snn_media_bad_type',
            sprintf( '".%s" files are not allowed. Permitted types: %s', $extension, implode( ', ', $allowed ) ),
            [ 'status' => 400 ]
        );
    }

    $temp_dir  = snn_media_temp_dir();
    $part_path = $temp_dir . $file_id . '.part';

    // Chunk 0 truncates any stale part left over from an abandoned attempt.
    $handle = fopen( $part_path, 0 === $chunk_index ? 'wb' : 'ab' );
    if ( ! $handle ) {
        return new WP_Error( 'snn_media_tmp_write', 'Could not open the temporary upload file for writing.', [ 'status' => 500 ] );
    }

    $source = fopen( $chunk['tmp_name'], 'rb' );
    if ( ! $source ) {
        fclose( $handle );
        return new WP_Error( 'snn_media_tmp_read', 'Could not read the uploaded chunk.', [ 'status' => 500 ] );
    }
    while ( ! feof( $source ) ) {
        fwrite( $handle, fread( $source, 262144 ) );
    }
    fclose( $source );
    fclose( $handle );

    if ( $chunk_index < $total_chunks - 1 ) {
        return rest_ensure_response( [
            'success'     => true,
            'done'        => false,
            'chunk_index' => $chunk_index,
            'received'    => filesize( $part_path ),
        ] );
    }

    // ---- Final chunk: promote the part file into the library ----
    $max_bytes = (int) snn_media_get( 'max_file_mb' ) * 1024 * 1024;
    if ( $max_bytes > 0 && filesize( $part_path ) > $max_bytes ) {
        @unlink( $part_path );
        return new WP_Error( 'snn_media_too_big', 'That file is larger than the configured maximum of ' . size_format( $max_bytes ) . '.', [ 'status' => 400 ] );
    }

    $dir       = snn_media_dir();
    $base      = sanitize_title( pathinfo( $original_name, PATHINFO_FILENAME ) );
    $base      = $base ? $base : 'video';
    $filename  = $base . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.' . $extension;
    $filename  = strtolower( $filename );
    $final     = $dir . $filename;

    if ( ! rename( $part_path, $final ) ) {
        @unlink( $part_path );
        return new WP_Error( 'snn_media_finalize', 'Could not move the finished upload into the media folder.', [ 'status' => 500 ] );
    }
    @chmod( $final, 0644 );

    global $wpdb;
    $inserted = $wpdb->insert( snn_media_table(), [ // phpcs:ignore WordPress.DB
        'filename'      => $filename,
        'original_name' => $original_name,
        'extension'     => $extension,
        'mime'          => snn_media_mime_for_ext( $extension ),
        'filesize'      => filesize( $final ),
        'uploaded_by'   => get_current_user_id(),
        'uploaded_at'   => time(),
        'thumb_status'  => snn_media_get( 'thumb_auto' ) ? 'queued' : 'pending',
        'mp3_status'    => snn_media_get( 'mp3_auto' ) ? 'queued' : 'pending',
        'vtt_status'    => snn_media_get( 'vtt_auto' ) ? 'queued' : 'pending',
        'r2_status'     => snn_media_get( 'r2_auto_sync' ) ? 'queued' : 'pending',
    ] );

    if ( ! $inserted ) {
        @unlink( $final );
        return new WP_Error( 'snn_media_db', 'The file uploaded but could not be recorded in the library.', [ 'status' => 500 ] );
    }

    // Tags chosen in the uploader apply to everything dropped in that session.
    if ( $tag_ids ) {
        snn_media_set_item_tags( $wpdb->insert_id, $tag_ids );
    }

    $row = snn_media_find( $wpdb->insert_id );

    return rest_ensure_response( [
        'success' => true,
        'done'    => true,
        'item'    => snn_media_row_to_array( $row ),
    ] );
}

/** POST /media/mp3 — stores the MP3 that ffmpeg.wasm produced in the browser. */
function snn_media_rest_store_mp3( WP_REST_Request $request ) {
    @set_time_limit( 0 );

    $params = $request->get_body_params();
    $id     = (int) ( $params['id'] ?? 0 );
    $row    = snn_media_find( $id );

    if ( ! $row ) {
        return new WP_Error( 'snn_media_not_found', 'That media item no longer exists.', [ 'status' => 404 ] );
    }

    $files = $request->get_file_params();
    if ( empty( $files['mp3'] ) || UPLOAD_ERR_OK !== $files['mp3']['error'] ) {
        return new WP_Error( 'snn_media_no_mp3', 'No MP3 data was received.', [ 'status' => 400 ] );
    }

    $dir      = snn_media_dir();
    $mp3_file = pathinfo( $row->filename, PATHINFO_FILENAME ) . '.mp3';
    $mp3_path = $dir . $mp3_file;

    if ( ! @move_uploaded_file( $files['mp3']['tmp_name'], $mp3_path ) ) {
        return new WP_Error( 'snn_media_mp3_write', 'Could not save the MP3 into the media folder.', [ 'status' => 500 ] );
    }
    @chmod( $mp3_path, 0644 );

    $duration = isset( $params['duration'] ) && $params['duration'] ? (float) $params['duration'] : $row->duration;

    snn_media_update( $row->id, [
        'mp3_file'   => $mp3_file,
        'mp3_size'   => filesize( $mp3_path ),
        'mp3_status' => 'done',
        'duration'   => $duration,
        'error_msg'  => null,
    ] );

    return rest_ensure_response( [
        'success' => true,
        'item'    => snn_media_row_to_array( snn_media_find( $row->id ) ),
    ] );
}

/** POST /media/mp3-status — lets the browser report queue/processing/error state. */
function snn_media_rest_mp3_status( WP_REST_Request $request ) {
    $params = $request->get_body_params();
    $id     = (int) ( $params['id'] ?? 0 );
    $status = (string) ( $params['status'] ?? '' );
    $row    = snn_media_find( $id );

    if ( ! $row ) {
        return new WP_Error( 'snn_media_not_found', 'That media item no longer exists.', [ 'status' => 404 ] );
    }
    if ( ! in_array( $status, [ 'pending', 'queued', 'processing', 'error' ], true ) ) {
        return new WP_Error( 'snn_media_bad_status', 'Unknown MP3 status.', [ 'status' => 400 ] );
    }

    $update = [ 'mp3_status' => $status ];
    if ( 'error' === $status ) {
        // Fall back only when the browser genuinely sent nothing — an empty
        // string must not slip through as a blank reason.
        $reason = sanitize_textarea_field( (string) ( $params['message'] ?? '' ) );
        $update['error_msg'] = '' !== trim( $reason )
            ? $reason
            : 'The browser could not convert this file, and reported no reason. Check the processing log on the Media Library screen.';
    }
    snn_media_update( $row->id, $update );

    return rest_ensure_response( [ 'success' => true, 'item' => snn_media_row_to_array( snn_media_find( $row->id ) ) ] );
}

/** POST /media/transcribe — OpenRouter speech-to-text, writes the .vtt. */
function snn_media_rest_transcribe( WP_REST_Request $request ) {
    @set_time_limit( 900 );

    $id  = (int) ( $request->get_body_params()['id'] ?? 0 );
    $row = snn_media_find( $id );
    if ( ! $row ) {
        return new WP_Error( 'snn_media_not_found', 'That media item no longer exists.', [ 'status' => 404 ] );
    }

    snn_media_update( $row->id, [ 'vtt_status' => 'processing', 'error_msg' => null ] );

    $result = snn_media_transcribe_to_vtt( $row );
    if ( is_wp_error( $result ) ) {
        snn_media_update( $row->id, [ 'vtt_status' => 'error', 'error_msg' => $result->get_error_message() ] );
        return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 500 ] );
    }

    snn_media_update( $row->id, [
        'vtt_file'   => $result['vtt_file'],
        'vtt_status' => 'done',
        'duration'   => $result['duration'] ?: $row->duration,
        'error_msg'  => null,
    ] );

    return rest_ensure_response( [
        'success' => true,
        'cues'    => $result['cues'],
        'item'    => snn_media_row_to_array( snn_media_find( $row->id ) ),
    ] );
}

/** POST /media/r2-sync — pushes one item to Cloudflare R2. */
function snn_media_rest_r2_sync( WP_REST_Request $request ) {
    @set_time_limit( 1800 );

    $id  = (int) ( $request->get_body_params()['id'] ?? 0 );
    $row = snn_media_find( $id );
    if ( ! $row ) {
        return new WP_Error( 'snn_media_not_found', 'That media item no longer exists.', [ 'status' => 404 ] );
    }

    $result = snn_media_sync_to_r2( $row );
    if ( is_wp_error( $result ) ) {
        return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 500 ] );
    }

    return rest_ensure_response( [
        'success' => true,
        'item'    => snn_media_row_to_array( snn_media_find( $row->id ) ),
    ] );
}

/** POST /media/thumb — stores the poster frame the browser grabbed. */
function snn_media_rest_store_thumb( WP_REST_Request $request ) {
    $params = $request->get_body_params();
    $id     = (int) ( $params['id'] ?? 0 );
    $row    = snn_media_find( $id );

    if ( ! $row ) {
        return new WP_Error( 'snn_media_not_found', 'That media item no longer exists.', [ 'status' => 404 ] );
    }

    // A browser that cannot decode this container reports the failure instead
    // of leaving the row stuck on "queued" forever.
    $status = (string) ( $params['status'] ?? '' );
    if ( 'error' === $status ) {
        snn_media_update( $row->id, [ 'thumb_status' => 'error' ] );
        return rest_ensure_response( [ 'success' => true, 'item' => snn_media_row_to_array( snn_media_find( $row->id ) ) ] );
    }

    $files = $request->get_file_params();
    if ( empty( $files['thumb'] ) || UPLOAD_ERR_OK !== $files['thumb']['error'] ) {
        return new WP_Error( 'snn_media_no_thumb', 'No thumbnail data was received.', [ 'status' => 400 ] );
    }

    $info = @getimagesize( $files['thumb']['tmp_name'] );
    if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
        return new WP_Error( 'snn_media_bad_thumb', 'The thumbnail was not a readable JPEG.', [ 'status' => 400 ] );
    }

    $dir        = snn_media_dir();
    $thumb_file = pathinfo( $row->filename, PATHINFO_FILENAME ) . '-thumb.jpg';
    $thumb_path = $dir . $thumb_file;

    if ( ! @move_uploaded_file( $files['thumb']['tmp_name'], $thumb_path ) ) {
        return new WP_Error( 'snn_media_thumb_write', 'Could not save the thumbnail into the media folder.', [ 'status' => 500 ] );
    }
    @chmod( $thumb_path, 0644 );

    $update = [ 'thumb_file' => $thumb_file, 'thumb_status' => 'done' ];

    // Once the video is in the bucket its thumbnail belongs there too — both
    // when this is a re-made frame replacing an old one, and when the cron
    // swept the video to R2 before any browser had a chance to grab a frame.
    if ( 'synced' === $row->r2_status || $row->r2_thumb_url ) {
        $config = snn_media_r2_config();
        if ( $config ) {
            $res = snn_media_r2_put( $config, $thumb_file, $thumb_path, 'image/jpeg' );
            if ( $res['ok'] ) {
                $update['r2_thumb_url'] = $config['public_url'] . '/' . snn_media_encode_key( $thumb_file );
            }
        }
    }

    snn_media_update( $row->id, $update );

    return rest_ensure_response( [
        'success' => true,
        'item'    => snn_media_row_to_array( snn_media_find( $row->id ) ),
    ] );
}

/** POST /media/tag — creates a tag, or renames/recolours an existing one. */
function snn_media_rest_save_tag( WP_REST_Request $request ) {
    global $wpdb;
    $params = $request->get_body_params();
    $id     = (int) ( $params['id'] ?? 0 );
    $name   = sanitize_text_field( (string) ( $params['name'] ?? '' ) );
    $color  = snn_media_sanitize_color( $params['color'] ?? '' );
    $table  = snn_media_tags_table();

    $name = trim( preg_replace( '/\s+/', ' ', $name ) );
    if ( '' === $name ) {
        return new WP_Error( 'snn_media_tag_name', 'A tag needs a name.', [ 'status' => 400 ] );
    }
    if ( mb_strlen( $name ) > 120 ) {
        $name = mb_substr( $name, 0, 120 );
    }

    $slug = sanitize_title( $name );
    if ( '' === $slug ) {
        // Names made entirely of characters sanitize_title strips (emoji, some
        // scripts' punctuation) still need a stable unique key.
        $slug = 'tag-' . substr( md5( $name ), 0, 10 );
    }

    $clash = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
        "SELECT id FROM $table WHERE slug = %s AND id <> %d", $slug, $id
    ) );
    if ( $clash ) {
        return new WP_Error( 'snn_media_tag_exists', 'A tag named "' . $name . '" already exists.', [ 'status' => 400 ] );
    }

    if ( $id > 0 ) {
        $updated = $wpdb->update( $table, [ 'name' => $name, 'slug' => $slug, 'color' => $color ], [ 'id' => $id ] ); // phpcs:ignore WordPress.DB
        if ( false === $updated ) {
            return new WP_Error( 'snn_media_tag_save', 'Could not update that tag.', [ 'status' => 500 ] );
        }
    } else {
        $inserted = $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB
            'name'       => $name,
            'slug'       => $slug,
            'color'      => $color,
            'created_at' => time(),
        ] );
        if ( ! $inserted ) {
            return new WP_Error( 'snn_media_tag_save', 'Could not create that tag.', [ 'status' => 500 ] );
        }
        $id = (int) $wpdb->insert_id;
    }

    return rest_ensure_response( [
        'success' => true,
        'id'      => $id,
        'name'    => $name,
        'tags'    => snn_media_all_tags(),
    ] );
}

/** DELETE /media/tag — drops a tag and unhooks it from every video. */
function snn_media_rest_delete_tag( WP_REST_Request $request ) {
    global $wpdb;
    $id = (int) ( $request->get_param( 'id' ) ?: 0 );

    $tag = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
        'SELECT * FROM ' . snn_media_tags_table() . ' WHERE id = %d', $id
    ) );
    if ( ! $tag ) {
        return new WP_Error( 'snn_media_tag_missing', 'That tag no longer exists.', [ 'status' => 404 ] );
    }

    $wpdb->delete( snn_media_tag_map_table(), [ 'tag_id' => $id ] ); // phpcs:ignore WordPress.DB
    $wpdb->delete( snn_media_tags_table(), [ 'id' => $id ] ); // phpcs:ignore WordPress.DB

    return rest_ensure_response( [ 'success' => true, 'id' => $id, 'name' => $tag->name, 'tags' => snn_media_all_tags() ] );
}

/** POST /media/item-tags — replaces one item's tags with the given ids. */
function snn_media_rest_item_tags( WP_REST_Request $request ) {
    $params = $request->get_body_params();
    $id     = (int) ( $params['id'] ?? 0 );
    $row    = snn_media_find( $id );

    if ( ! $row ) {
        return new WP_Error( 'snn_media_not_found', 'That media item no longer exists.', [ 'status' => 404 ] );
    }

    $ids  = array_filter( array_map( 'intval', explode( ',', (string) ( $params['tag_ids'] ?? '' ) ) ) );
    $tags = snn_media_set_item_tags( $row->id, $ids );

    return rest_ensure_response( [
        'success' => true,
        'item'    => snn_media_row_to_array( snn_media_find( $row->id ), $tags ),
    ] );
}

/** DELETE /media/item — removes the video, its derivatives, and its R2 copies. */
function snn_media_rest_delete( WP_REST_Request $request ) {
    $id = (int) ( $request->get_param( 'id' ) ?: 0 );
    $row = snn_media_find( $id );
    if ( ! $row ) {
        return new WP_Error( 'snn_media_not_found', 'That media item no longer exists.', [ 'status' => 404 ] );
    }

    if ( $row->r2_url || $row->r2_key ) {
        snn_media_purge_from_r2( $row );
    }

    $dir = snn_media_dir();
    foreach ( array_filter( [ $row->filename, $row->mp3_file, $row->vtt_file, $row->thumb_file ] ) as $name ) {
        $path = $dir . basename( $name );
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    global $wpdb;
    $wpdb->delete( snn_media_tag_map_table(), [ 'media_id' => $row->id ] ); // phpcs:ignore WordPress.DB
    $wpdb->delete( snn_media_table(), [ 'id' => $row->id ] ); // phpcs:ignore WordPress.DB

    return rest_ensure_response( [ 'success' => true, 'id' => $row->id, 'name' => $row->original_name ] );
}

// ============================================================
// 10. ADMIN — MEDIA LIBRARY PAGE
// ============================================================

/**
 * Locates the bundled ffmpeg.wasm files and reports any that are missing.
 *
 * These are always served from the plugin's own assets folder, and that is not
 * a preference: `ffmpeg.js` starts its worker with
 * `new Worker(new URL('./814.ffmpeg.js', <its own url>))`, and browsers refuse
 * to construct a Worker from another origin no matter what CORS headers that
 * origin sends. Same-origin is the only arrangement that works, so there is
 * deliberately no setting to point this elsewhere.
 *
 * @return array {
 *     @type string $base    Directory holding ffmpeg.js and its worker chunk.
 *     @type string $core    Directory holding ffmpeg-core.js and .wasm.
 *     @type array  $missing Bundled files absent from disk.
 * }
 */
function snn_media_ffmpeg_urls() {
    $dir     = plugin_dir_path( __FILE__ ) . 'assets/ffmpeg/';
    $url     = rtrim( plugin_dir_url( __FILE__ ) . 'assets/ffmpeg', '/' );
    $missing = [];

    foreach ( [ 'ffmpeg.js', '814.ffmpeg.js', 'ffmpeg-core.js', 'ffmpeg-core.wasm' ] as $file ) {
        if ( ! file_exists( $dir . $file ) ) {
            $missing[] = $file;
        }
    }

    return [ 'base' => $url, 'core' => $url, 'missing' => $missing ];
}

/** Everything the browser app needs to run, in one object. */
function snn_media_js_config() {
    $ffmpeg = snn_media_ffmpeg_urls();

    return [
        'restUrl'        => esc_url_raw( rest_url( 'snn-learn/v1/media/' ) ),
        'nonce'          => wp_create_nonce( 'wp_rest' ),
        'chunkSize'      => max( 1, (int) snn_media_get( 'chunk_size_mb' ) ) * 1024 * 1024,
        'maxFileMb'      => (int) snn_media_get( 'max_file_mb' ),
        'allowedExts'    => snn_media_allowed_extensions(),
        'thumbAuto'      => (bool) snn_media_get( 'thumb_auto' ),
        'thumbSeek'      => (float) snn_media_get( 'thumb_seek' ),
        'thumbWidth'     => (int) snn_media_get( 'thumb_width' ),
        'mp3Auto'        => (bool) snn_media_get( 'mp3_auto' ),
        'mp3Bitrate'     => (string) snn_media_get( 'mp3_bitrate' ),
        'mp3SampleRate'  => (string) snn_media_get( 'mp3_sample_rate' ),
        'vttAuto'        => (bool) snn_media_get( 'vtt_auto' ),
        'r2AutoSync'     => (bool) snn_media_get( 'r2_auto_sync' ),
        'r2Configured'   => snn_media_r2_configured(),
        'sttConfigured'  => trim( (string) snn_media_get( 'openrouter_api_key' ) ) !== '',
        'ffmpegBase'     => $ffmpeg['base'],
        'ffmpegCore'     => $ffmpeg['core'],
        'ffmpegMissing'  => $ffmpeg['missing'],
        'settingsUrl'    => admin_url( 'admin.php?page=snn-learn-media-settings' ),
    ];
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( false === strpos( (string) $hook, 'snn-learn-media' ) ) {
        return;
    }
    $base = plugin_dir_url( __FILE__ );
    $ver  = SNN_MEDIA_DB_VERSION . '.' . ( @filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/snn-media.js' ) ?: 0 );

    wp_enqueue_style( 'snn-media', $base . 'assets/css/snn-media.css', [], $ver );

    // Only the library screen runs the uploader.
    if ( false !== strpos( (string) $hook, 'snn-learn-media-settings' ) ) {
        return;
    }
    wp_enqueue_script( 'snn-media', $base . 'assets/js/snn-media.js', [], $ver, true );
    wp_add_inline_script( 'snn-media', 'window.SNN_MEDIA = ' . wp_json_encode( snn_media_js_config() ) . ';', 'before' );
} );

function snn_media_library_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $allowed   = snn_media_allowed_extensions();
    $r2_ready  = snn_media_r2_configured();
    $stt_ready = trim( (string) snn_media_get( 'openrouter_api_key' ) ) !== '';
    ?>
    <div class="snn-media wrap">
        <div class="snn-media-head">
            <h1>SNN Learn &mdash; Media Library</h1>
            <a class="snn-btn snn-btn-ghost" href="<?= esc_url( admin_url( 'admin.php?page=snn-learn-media-settings' ) ) ?>">Media Settings</a>
        </div>

        <?php if ( ! $r2_ready || ! $stt_ready ) : ?>
            <div class="snn-media-notice">
                <strong>Optional features are not configured yet.</strong>
                <?php if ( ! $r2_ready ) : ?>
                    <span>Cloudflare R2 sync is off &mdash; videos stay on this server.</span>
                <?php endif; ?>
                <?php if ( ! $stt_ready ) : ?>
                    <span>No OpenRouter API key &mdash; automatic subtitles are unavailable.</span>
                <?php endif; ?>
                <a href="<?= esc_url( admin_url( 'admin.php?page=snn-learn-media-settings' ) ) ?>">Configure now &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- ---------- Upload panel (collapsed until it is wanted) ---------- -->
        <details id="snn-upload-panel" class="snn-card snn-upload-card">
            <summary>
                <span class="snn-upload-title">Upload videos</span>
                <span class="snn-muted snn-upload-hint">drag &amp; drop or browse &mdash; click to open</span>
            </summary>

            <div class="snn-upload-body">
                <div class="snn-upload-tags">
                    <span class="snn-upload-tags-label">Tag everything uploaded now:</span>
                    <div id="snn-upload-tagpicker" class="snn-tagpicker"></div>
                    <button type="button" id="snn-manage-tags" class="snn-linkbtn">Manage tags</button>
                </div>

                <div id="snn-dropzone" class="snn-dropzone">
                    <div class="snn-dropzone-inner">
                        <div class="snn-dropzone-icon">&#8681;</div>
                        <p class="snn-dropzone-title">Drag &amp; drop videos here</p>
                        <p class="snn-dropzone-sub">
                            or <button type="button" id="snn-browse" class="snn-linkbtn">browse your computer</button>.
                            Files upload one after another, in <?= esc_html( (int) snn_media_get( 'chunk_size_mb' ) ) ?>&nbsp;MB chunks.
                        </p>
                        <p class="snn-dropzone-exts">Allowed: <?= esc_html( implode( ', ', $allowed ) ) ?></p>
                    </div>
                    <input type="file" id="snn-file-input" multiple accept="<?= esc_attr( '.' . implode( ',.', $allowed ) ) ?>" hidden>
                </div>
            </div>
        </details>

        <!-- ---------- Active queue ---------- -->
        <div id="snn-queue-wrap" class="snn-card" hidden>
            <div class="snn-card-head">
                <h2>Upload queue</h2>
                <span id="snn-queue-summary" class="snn-muted"></span>
            </div>
            <div id="snn-queue" class="snn-queue"></div>
        </div>

        <!-- ---------- Library ---------- -->
        <div class="snn-card">
            <div class="snn-card-head">
                <h2>Library <span id="snn-total" class="snn-count"></span></h2>
                <div class="snn-card-actions">
                    <input type="search" id="snn-search" class="snn-input" placeholder="Search filename&hellip;">
                    <button type="button" id="snn-expand-all" class="snn-btn snn-btn-ghost">Expand all</button>
                    <button type="button" id="snn-refresh" class="snn-btn snn-btn-ghost">Refresh</button>
                    <button type="button" id="snn-process-all" class="snn-btn">Process pending</button>
                </div>
            </div>
            <div id="snn-tagfilter" class="snn-tagfilter"></div>
            <div id="snn-items" class="snn-items">
                <p class="snn-muted snn-empty">Loading&hellip;</p>
            </div>
            <div id="snn-pagination" class="snn-pagination"></div>
        </div>

        <!-- ---------- Logs ---------- -->
        <details class="snn-card snn-log-card">
            <summary>
                <span>Logs</span>
                <span class="snn-muted snn-log-hint">the last 100 entries, kept across reloads</span>
            </summary>
            <div class="snn-log-tools">
                <button type="button" id="snn-log-clear" class="snn-btn snn-btn-ghost snn-btn-sm">Clear logs</button>
            </div>
            <div id="snn-log" class="snn-log"></div>
        </details>
    </div>

    <!-- ---------- Tag manager ---------- -->
    <div id="snn-tag-modal" class="snn-modal snn-media" hidden>
        <div class="snn-modal-backdrop" data-close></div>
        <div class="snn-modal-body snn-modal-narrow">
            <button type="button" class="snn-modal-close" data-close aria-label="Close">&times;</button>
            <h3>Tags</h3>
            <p class="snn-help">Rename a tag or change its colour and every video wearing it follows. Deleting one removes it from those videos; the videos themselves stay.</p>
            <div id="snn-tag-list" class="snn-tag-list"></div>
            <form id="snn-tag-new" class="snn-tag-new">
                <input type="text" id="snn-tag-name" class="snn-input" placeholder="New tag name" maxlength="120" required>
                <input type="color" id="snn-tag-color" value="#2563eb" aria-label="Tag colour">
                <button type="submit" class="snn-btn snn-btn-primary">Add tag</button>
            </form>
            <p id="snn-tag-error" class="snn-tag-error" hidden></p>
        </div>
    </div>

    <!-- ---------- Preview modal ---------- -->
    <div id="snn-modal" class="snn-modal" hidden>
        <div class="snn-modal-backdrop" data-close></div>
        <div class="snn-modal-body">
            <button type="button" class="snn-modal-close" data-close aria-label="Close">&times;</button>
            <h3 id="snn-modal-title"></h3>
            <video id="snn-modal-video" controls playsinline crossorigin="anonymous"></video>
            <div id="snn-modal-meta" class="snn-modal-meta"></div>
        </div>
    </div>
    <?php
}

// ============================================================
// 11. ADMIN — MEDIA SETTINGS PAGE
// ============================================================

/** Renders a masked secret input: blank means "keep the stored value". */
function snn_media_secret_field( $key, $label, $help = '' ) {
    $stored = (string) snn_media_get( $key );
    $set    = '' !== trim( $stored );
    ?>
    <div class="snn-field">
        <label for="snn_<?= esc_attr( $key ) ?>"><?= esc_html( $label ) ?></label>
        <input type="password" id="snn_<?= esc_attr( $key ) ?>" name="snn_<?= esc_attr( $key ) ?>"
            value="" autocomplete="new-password"
            placeholder="<?= $set ? esc_attr( '•••••••• saved — leave blank to keep' ) : '' ?>">
        <?php if ( $help ) : ?><p class="snn-help"><?= esc_html( $help ) ?></p><?php endif; ?>
        <?php if ( $set ) : ?>
            <label class="snn-inline"><input type="checkbox" name="snn_<?= esc_attr( $key ) ?>_clear" value="1"> Clear this value</label>
        <?php endif; ?>
    </div>
    <?php
}

function snn_media_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // ---- Save handler ----
    if ( isset( $_POST['snn_media_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_media_nonce'] ) ), 'snn_media_save' ) ) {

        $text_fields = [
            'allowed_extensions', 'r2_account_id', 'r2_bucket', 'r2_public_url',
            'r2_jurisdiction', 'stt_model', 'stt_language', 'ai_model',
            'mp3_bitrate', 'mp3_sample_rate',
        ];
        foreach ( $text_fields as $f ) {
            if ( isset( $_POST[ 'snn_' . $f ] ) ) {
                snn_media_set( $f, sanitize_text_field( wp_unslash( $_POST[ 'snn_' . $f ] ) ) );
            }
        }

        $number_fields = [ 'chunk_size_mb', 'max_file_mb', 'vtt_max_words', 'vtt_max_seconds', 'thumb_width' ];
        foreach ( $number_fields as $f ) {
            if ( isset( $_POST[ 'snn_' . $f ] ) ) {
                snn_media_set( $f, max( 0, (int) $_POST[ 'snn_' . $f ] ) );
            }
        }
        if ( isset( $_POST['snn_thumb_seek'] ) ) {
            snn_media_set( 'thumb_seek', max( 0, (float) $_POST['snn_thumb_seek'] ) );
        }
        if ( isset( $_POST['snn_vtt_pause_gap'] ) ) {
            snn_media_set( 'vtt_pause_gap', max( 0, (float) $_POST['snn_vtt_pause_gap'] ) );
        }

        // Secrets: an empty box keeps whatever is stored; the checkbox clears it.
        foreach ( [ 'r2_secret_access_key', 'r2_access_key_id', 'openrouter_api_key' ] as $f ) {
            if ( ! empty( $_POST[ 'snn_' . $f . '_clear' ] ) ) {
                snn_media_set( $f, '' );
            } elseif ( isset( $_POST[ 'snn_' . $f ] ) && '' !== trim( (string) $_POST[ 'snn_' . $f ] ) ) {
                snn_media_set( $f, sanitize_text_field( wp_unslash( $_POST[ 'snn_' . $f ] ) ) );
            }
        }

        foreach ( [ 'r2_auto_sync', 'r2_delete_local', 'thumb_auto', 'mp3_auto', 'vtt_auto', 'cron_enabled' ] as $f ) {
            snn_media_set( $f, isset( $_POST[ 'snn_' . $f ] ) ? 1 : 0 );
        }

        // Reflect the cron toggle immediately rather than on the next request.
        $scheduled = wp_next_scheduled( 'snn_media_process_queue' );
        if ( snn_media_get( 'cron_enabled' ) && ! $scheduled ) {
            wp_schedule_event( time() + 60, 'snn_media_five_minutes', 'snn_media_process_queue' );
        } elseif ( ! snn_media_get( 'cron_enabled' ) && $scheduled ) {
            wp_unschedule_event( $scheduled, 'snn_media_process_queue' );
        }

        echo '<div class="notice notice-success is-dismissible"><p><strong>Media settings saved.</strong></p></div>';
    }

    // ---- Connection test ----
    if ( isset( $_POST['snn_media_test_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['snn_media_test_nonce'] ) ), 'snn_media_test' ) ) {
        $config = snn_media_r2_config();
        if ( ! $config ) {
            echo '<div class="notice notice-error is-dismissible"><p>R2 is not fully configured yet.</p></div>';
        } else {
            $probe = wp_tempnam( 'snn-r2-probe' );
            file_put_contents( $probe, 'snn-learn r2 connectivity probe ' . gmdate( 'c' ) );
            $key    = '.snn-learn-r2-test.txt';
            $result = snn_media_r2_put( $config, $key, $probe, 'text/plain' );
            @unlink( $probe );

            if ( $result['ok'] ) {
                snn_media_r2_delete( $config, $key );
                echo '<div class="notice notice-success is-dismissible"><p><strong>R2 connection works.</strong> A test object was written to <code>' . esc_html( $config['bucket'] ) . '</code> and removed again.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p><strong>R2 test failed (HTTP ' . esc_html( $result['code'] ) . ').</strong> ' . esc_html( mb_substr( $result['error'], 0, 400 ) ) . '</p></div>';
            }
        }
    }

    $next_cron = wp_next_scheduled( 'snn_media_process_queue' );
    ?>
    <div class="snn-media snn-media-settings wrap">
        <div class="snn-media-head">
            <h1>SNN Learn &mdash; Media Settings</h1>
            <a class="snn-btn snn-btn-ghost" href="<?= esc_url( admin_url( 'admin.php?page=snn-learn-media' ) ) ?>">&larr; Back to Media Library</a>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field( 'snn_media_save', 'snn_media_nonce' ); ?>

            <!-- ============ Uploads ============ -->
            <div class="snn-settings-card">
                <h2>Uploads</h2>
                <div class="snn-grid">
                    <div class="snn-field">
                        <label for="snn_allowed_extensions">Allowed file extensions</label>
                        <input type="text" id="snn_allowed_extensions" name="snn_allowed_extensions"
                            value="<?= esc_attr( snn_media_get( 'allowed_extensions' ) ) ?>">
                        <p class="snn-help">Comma separated, without dots. Anything not listed is rejected before it is written to disk.</p>
                    </div>
                    <div class="snn-field">
                        <label for="snn_chunk_size_mb">Chunk size (MB)</label>
                        <input type="number" min="1" max="64" id="snn_chunk_size_mb" name="snn_chunk_size_mb"
                            value="<?= esc_attr( snn_media_get( 'chunk_size_mb' ) ) ?>">
                        <p class="snn-help">Each chunk is a separate request, so this must stay under your server's <code>upload_max_filesize</code> and <code>post_max_size</code>. 2&ndash;8&nbsp;MB is a safe range.</p>
                    </div>
                    <div class="snn-field">
                        <label for="snn_max_file_mb">Maximum file size (MB)</label>
                        <input type="number" min="0" id="snn_max_file_mb" name="snn_max_file_mb"
                            value="<?= esc_attr( snn_media_get( 'max_file_mb' ) ) ?>">
                        <p class="snn-help">Applies to the reassembled file. 0 disables the limit. R2 accepts up to 5&nbsp;GB per single upload.</p>
                    </div>
                </div>
                <label class="snn-toggle">
                    <input type="checkbox" name="snn_thumb_auto" value="1" <?php checked( snn_media_get( 'thumb_auto' ) ); ?>>
                    <span><strong>Grab a thumbnail automatically</strong> &mdash; the browser seizes one frame from each
                    uploaded video and stores it as a small JPEG, so the library is browsable without previewing.
                    With this off, use the <em>Thumbnail</em> button on an item.</span>
                </label>
                <div class="snn-grid">
                    <div class="snn-field">
                        <label for="snn_thumb_seek">Grab the frame at (seconds)</label>
                        <input type="number" step="0.5" min="0" max="600" id="snn_thumb_seek" name="snn_thumb_seek"
                            value="<?= esc_attr( snn_media_get( 'thumb_seek' ) ) ?>">
                        <p class="snn-help">A second or two in avoids the black frame most videos open on. Shorter videos fall back to their midpoint.</p>
                    </div>
                    <div class="snn-field">
                        <label for="snn_thumb_width">Thumbnail width (px)</label>
                        <input type="number" min="120" max="1920" id="snn_thumb_width" name="snn_thumb_width"
                            value="<?= esc_attr( snn_media_get( 'thumb_width' ) ) ?>">
                        <p class="snn-help">Height follows the video's aspect ratio. 480&nbsp;px stays sharp on a retina screen and weighs about 30&nbsp;KB.</p>
                    </div>
                </div>

                <p class="snn-help snn-note">
                    Server limits in effect right now &mdash;
                    <code>upload_max_filesize: <?= esc_html( ini_get( 'upload_max_filesize' ) ) ?></code>,
                    <code>post_max_size: <?= esc_html( ini_get( 'post_max_size' ) ) ?></code>,
                    <code>max_execution_time: <?= esc_html( ini_get( 'max_execution_time' ) ) ?></code>.
                    Chunking means the video size itself is not bound by these, only the chunk size is.
                </p>
            </div>

            <!-- ============ Cloudflare R2 ============ -->
            <div class="snn-settings-card">
                <h2>Cloudflare R2 sync
                    <span class="snn-badge <?= snn_media_r2_configured() ? 'snn-badge-ok' : 'snn-badge-off' ?>">
                        <?= snn_media_r2_configured() ? 'Configured' : 'Not configured' ?>
                    </span>
                </h2>
                <div class="snn-grid">
                    <div class="snn-field">
                        <label for="snn_r2_account_id">Account ID</label>
                        <input type="text" id="snn_r2_account_id" name="snn_r2_account_id"
                            value="<?= esc_attr( snn_media_get( 'r2_account_id' ) ) ?>">
                    </div>
                    <div class="snn-field">
                        <label for="snn_r2_bucket">Bucket name</label>
                        <input type="text" id="snn_r2_bucket" name="snn_r2_bucket"
                            value="<?= esc_attr( snn_media_get( 'r2_bucket' ) ) ?>">
                    </div>
                    <?php snn_media_secret_field( 'r2_access_key_id', 'Access Key ID' ); ?>
                    <?php snn_media_secret_field( 'r2_secret_access_key', 'Secret Access Key' ); ?>
                    <div class="snn-field">
                        <label for="snn_r2_public_url">Public base URL</label>
                        <input type="url" id="snn_r2_public_url" name="snn_r2_public_url"
                            value="<?= esc_attr( snn_media_get( 'r2_public_url' ) ) ?>"
                            placeholder="https://media.example.com">
                        <p class="snn-help">Your R2 custom domain or <code>pub-….r2.dev</code> address. Videos are served from here once synced.</p>
                    </div>
                    <div class="snn-field">
                        <label for="snn_r2_jurisdiction">Jurisdiction</label>
                        <input type="text" id="snn_r2_jurisdiction" name="snn_r2_jurisdiction"
                            value="<?= esc_attr( snn_media_get( 'r2_jurisdiction' ) ) ?>" placeholder="eu (optional)">
                        <p class="snn-help">Leave blank unless your bucket is jurisdiction-restricted.</p>
                    </div>
                </div>

                <?php $resolved = snn_media_r2_config(); ?>
                <p class="snn-help snn-note">
                    <strong>S3 API endpoint in use:</strong>
                    <code><?= $resolved ? esc_html( 'https://' . $resolved['host'] . '/' . $resolved['bucket'] ) : 'fill in the fields above' ?></code><br>
                    This is built from the Account ID, Jurisdiction and Bucket &mdash; it should match the
                    <em>S3 API</em> line on your bucket's settings page in the Cloudflare dashboard.
                    Objects are stored at the root of the bucket under their generated filename, so a
                    synced video is served from <code><?= esc_html( rtrim( (string) snn_media_get( 'r2_public_url' ), '/' ) ?: 'https://your-domain' ) ?>/&lt;filename&gt;</code>.
                </p>

                <label class="snn-toggle">
                    <input type="checkbox" name="snn_r2_auto_sync" value="1" <?php checked( snn_media_get( 'r2_auto_sync' ) ); ?>>
                    <span><strong>Sync automatically</strong> &mdash; push each video to R2 as soon as its upload (and any MP3/subtitle work) finishes. With this off, use the <em>Sync to R2</em> button on each item.</span>
                </label>
                <label class="snn-toggle">
                    <input type="checkbox" name="snn_r2_delete_local" value="1" <?php checked( snn_media_get( 'r2_delete_local' ) ); ?>>
                    <span><strong>Delete the local video after a successful sync</strong> &mdash; reclaims server disk. The MP3 is kept because transcription reads it.</span>
                </label>
            </div>

            <!-- ============ ffmpeg.wasm ============ -->
            <?php $ffmpeg_urls = snn_media_ffmpeg_urls(); ?>
            <div class="snn-settings-card">
                <h2>Audio extraction (ffmpeg.wasm)
                    <?php if ( $ffmpeg_urls['missing'] ) : ?>
                        <span class="snn-badge snn-badge-err">Files missing</span>
                    <?php else : ?>
                        <span class="snn-badge snn-badge-ok">Self-hosted</span>
                    <?php endif; ?>
                </h2>
                <p class="snn-help snn-note">
                    Conversion runs <strong>entirely in the browser, on the editor's own CPU</strong>. The server never
                    decodes video: the browser encodes a small mono MP3 and uploads only that &mdash; a few MB even for a
                    multi-gigabyte lesson. There is no <code>ffmpeg</code> binary and no <code>exec()</code> anywhere in
                    this plugin. Keep the Media Library tab open while a batch is processing.
                    The source is mounted through WORKERFS and read on demand, so it is never copied into WebAssembly
                    memory and large files convert fine.
                </p>
                <?php if ( $ffmpeg_urls['missing'] ) : ?>
                    <p class="snn-help snn-note" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b">
                        <strong>Missing from <code>assets/ffmpeg/</code>:</strong>
                        <?= esc_html( implode( ', ', $ffmpeg_urls['missing'] ) ) ?>.
                        Re-deploy the plugin's <code>assets/ffmpeg/</code> folder &mdash; MP3 extraction cannot run without it.
                    </p>
                <?php endif; ?>
                <label class="snn-toggle">
                    <input type="checkbox" name="snn_mp3_auto" value="1" <?php checked( snn_media_get( 'mp3_auto' ) ); ?>>
                    <span><strong>Extract MP3 automatically</strong> &mdash; convert right after each upload, while the browser still holds the file (no re-download). With this off, use the <em>Make MP3</em> button.</span>
                </label>
                <div class="snn-grid">
                    <div class="snn-field">
                        <label for="snn_mp3_bitrate">Audio bitrate</label>
                        <input type="text" id="snn_mp3_bitrate" name="snn_mp3_bitrate"
                            value="<?= esc_attr( snn_media_get( 'mp3_bitrate' ) ) ?>" placeholder="32k">
                        <p class="snn-help">Mono. 32k is plenty for speech and keeps transcription uploads small.</p>
                    </div>
                    <div class="snn-field">
                        <label for="snn_mp3_sample_rate">Sample rate (Hz)</label>
                        <input type="text" id="snn_mp3_sample_rate" name="snn_mp3_sample_rate"
                            value="<?= esc_attr( snn_media_get( 'mp3_sample_rate' ) ) ?>" placeholder="22050">
                        <p class="snn-help">22050 Hz covers the speech range. Whisper downsamples to 16000 Hz anyway.</p>
                    </div>
                </div>
                <p class="snn-help">
                    Bundled and served from this domain:
                    <code>@ffmpeg/ffmpeg 0.12.15</code>, <code>@ffmpeg/core 0.12.10</code>.
                    Loading these from a CDN is not offered, because a Worker cannot be constructed from another origin.
                </p>
            </div>

            <!-- ============ Subtitles ============ -->
            <div class="snn-settings-card">
                <h2>Automatic subtitles (OpenRouter)
                    <span class="snn-badge <?= trim( (string) snn_media_get( 'openrouter_api_key' ) ) !== '' ? 'snn-badge-ok' : 'snn-badge-off' ?>">
                        <?= trim( (string) snn_media_get( 'openrouter_api_key' ) ) !== '' ? 'Configured' : 'Not configured' ?>
                    </span>
                </h2>
                <p class="snn-help snn-note">
                    The extracted MP3 is sent to OpenRouter's speech-to-text endpoint and the word timestamps it returns
                    are grouped into a <code>.vtt</code> file stored next to the video. An MP3 must exist first.
                </p>
                <div class="snn-grid">
                    <?php snn_media_secret_field( 'openrouter_api_key', 'OpenRouter API key', 'Created at openrouter.ai/keys.' ); ?>
                    <div class="snn-field">
                        <label for="snn_stt_model">Transcription model</label>
                        <input type="text" id="snn_stt_model" name="snn_stt_model"
                            value="<?= esc_attr( snn_media_get( 'stt_model' ) ) ?>" placeholder="openai/whisper-1">
                        <p class="snn-help">e.g. <code>openai/whisper-1</code>, <code>openai/whisper-large-v3</code>. Word-level timestamps give the best cue timing; models without them fall back to segments.</p>
                    </div>
                    <div class="snn-field">
                        <label for="snn_ai_model">Text generation model</label>
                        <input type="text" id="snn_ai_model" name="snn_ai_model"
                            value="<?= esc_attr( snn_media_get( 'ai_model' ) ) ?>" placeholder="anthropic/claude-sonnet-4.5">
                        <p class="snn-help">
                            Writes chapters, objectives and FAQs from a lesson's <code>.vtt</code> on the
                            <a href="<?= esc_url( admin_url( 'admin.php?page=snn-learn-course-fields' ) ) ?>">Course Fields</a> screen.
                            This is a <strong>chat</strong> model, not Whisper &mdash; and it must support structured JSON output.
                            Check the exact slug on <code>openrouter.ai/models</code>.
                        </p>
                    </div>
                    <div class="snn-field">
                        <label for="snn_stt_language">Language hint</label>
                        <input type="text" id="snn_stt_language" name="snn_stt_language"
                            value="<?= esc_attr( snn_media_get( 'stt_language' ) ) ?>" placeholder="auto-detect">
                        <p class="snn-help">ISO-639-1 code such as <code>en</code> or <code>tr</code>. Blank lets the model detect it.</p>
                    </div>
                </div>
                <label class="snn-toggle">
                    <input type="checkbox" name="snn_vtt_auto" value="1" <?php checked( snn_media_get( 'vtt_auto' ) ); ?>>
                    <span><strong>Transcribe automatically</strong> &mdash; generate subtitles as soon as the MP3 is ready. With this off, use the <em>Make subtitles</em> button.</span>
                </label>
                <h3 class="snn-subhead">Cue shaping</h3>
                <div class="snn-grid">
                    <div class="snn-field">
                        <label for="snn_vtt_max_words">Max words per cue</label>
                        <input type="number" min="1" max="40" id="snn_vtt_max_words" name="snn_vtt_max_words"
                            value="<?= esc_attr( snn_media_get( 'vtt_max_words' ) ) ?>">
                    </div>
                    <div class="snn-field">
                        <label for="snn_vtt_max_seconds">Max seconds per cue</label>
                        <input type="number" min="1" max="20" id="snn_vtt_max_seconds" name="snn_vtt_max_seconds"
                            value="<?= esc_attr( snn_media_get( 'vtt_max_seconds' ) ) ?>">
                    </div>
                    <div class="snn-field">
                        <label for="snn_vtt_pause_gap">Split on a pause of (seconds)</label>
                        <input type="number" step="0.1" min="0" max="5" id="snn_vtt_pause_gap" name="snn_vtt_pause_gap"
                            value="<?= esc_attr( snn_media_get( 'vtt_pause_gap' ) ) ?>">
                    </div>
                </div>
            </div>

            <!-- ============ Background queue ============ -->
            <div class="snn-settings-card">
                <h2>Background queue</h2>
                <label class="snn-toggle">
                    <input type="checkbox" name="snn_cron_enabled" value="1" <?php checked( snn_media_get( 'cron_enabled' ) ); ?>>
                    <span><strong>Run a scheduled sweep every 5 minutes</strong> &mdash; picks up transcription and R2 sync for anything the browser did not finish. MP3 extraction is not included: it needs an open Media Library tab.</span>
                </label>
                <p class="snn-help">
                    <?php if ( $next_cron ) : ?>
                        Next run: <strong><?= esc_html( date_i18n( 'Y-m-d H:i:s', $next_cron ) ) ?></strong>
                        (WP-Cron fires on site traffic, so this is the earliest time, not a guarantee.)
                    <?php else : ?>
                        Not scheduled.
                    <?php endif; ?>
                </p>
            </div>

            <p class="snn-actions">
                <button type="submit" class="snn-btn snn-btn-primary">Save Media Settings</button>
            </p>
        </form>

        <form method="post" action="" class="snn-settings-card snn-test-card">
            <?php wp_nonce_field( 'snn_media_test', 'snn_media_test_nonce' ); ?>
            <h2>Test the R2 connection</h2>
            <p class="snn-help">Writes a tiny object to the bucket and deletes it again, so you find credential problems here rather than mid-upload.</p>
            <button type="submit" class="snn-btn">Run R2 test</button>
        </form>
    </div>
    <?php
}
