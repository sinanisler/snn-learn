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

define( 'SNN_MEDIA_DB_VERSION', '1.1' );

// ============================================================
// 1. SETTINGS
// ============================================================

function snn_media_defaults() {
    return [
        // Uploads
        'allowed_extensions'   => 'mp4,mov,m4v,webm,mkv,avi,wmv,flv,ts,mts,m2ts',
        'chunk_size_mb'        => 2,
        'max_file_mb'          => 5120,

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
        r2_key varchar(512) DEFAULT NULL,
        r2_url text DEFAULT NULL,
        r2_mp3_url text DEFAULT NULL,
        r2_vtt_url text DEFAULT NULL,
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

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
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

/** Shape one DB row into the JSON structure the admin UI consumes. */
function snn_media_row_to_array( $row ) {
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
        'r2_status'     => $row->r2_status,
        'r2_url'        => $row->r2_url,
        'r2_mp3_url'    => $row->r2_mp3_url,
        'r2_vtt_url'    => $row->r2_vtt_url,
        'r2_synced_at'  => $row->r2_synced_at ? date_i18n( 'Y-m-d H:i', (int) $row->r2_synced_at ) : '',
        // The URL a lesson should point at: R2 when synced, local otherwise.
        'playback_url'  => $row->r2_url ? $row->r2_url : ( $local_exists ? $base_url . rawurlencode( $row->filename ) : '' ),
        'error_msg'     => $row->error_msg,
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
    foreach ( [ 'mp3_file' => 'r2_mp3_url', 'vtt_file' => 'r2_vtt_url' ] as $field => $url_field ) {
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
        'r2_url'     => $update['r2_url'],
        'r2_mp3_url' => $update['r2_mp3_url'] ?? $row->r2_mp3_url,
        'r2_vtt_url' => $update['r2_vtt_url'] ?? $row->r2_vtt_url,
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

    register_rest_route( 'snn-learn/v1', '/media/run-queue', array_merge( $auth, [
        'methods'  => 'POST',
        'callback' => function () {
            snn_media_run_queue();
            return rest_ensure_response( [ 'success' => true ] );
        },
    ] ) );
} );

/** GET /media/items — paginated library listing. */
function snn_media_rest_items( WP_REST_Request $request ) {
    global $wpdb;
    $table = snn_media_table();

    $per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
    $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
    $search   = trim( (string) $request->get_param( 'search' ) );
    $offset   = ( $page - 1 ) * $per_page;

    if ( '' !== $search ) {
        $like  = '%' . $wpdb->esc_like( $search ) . '%';
        $total = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT COUNT(*) FROM $table WHERE original_name LIKE %s OR filename LIKE %s", $like, $like
        ) );
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT * FROM $table WHERE original_name LIKE %s OR filename LIKE %s
             ORDER BY uploaded_at DESC, id DESC LIMIT %d OFFSET %d",
            $like, $like, $per_page, $offset
        ) );
    } else {
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB
        $rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT * FROM $table ORDER BY uploaded_at DESC, id DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );
    }

    return rest_ensure_response( [
        'success'  => true,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'items'    => array_map( 'snn_media_row_to_array', $rows ),
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
        'mp3_status'    => snn_media_get( 'mp3_auto' ) ? 'queued' : 'pending',
        'vtt_status'    => snn_media_get( 'vtt_auto' ) ? 'queued' : 'pending',
        'r2_status'     => snn_media_get( 'r2_auto_sync' ) ? 'queued' : 'pending',
    ] );

    if ( ! $inserted ) {
        @unlink( $final );
        return new WP_Error( 'snn_media_db', 'The file uploaded but could not be recorded in the library.', [ 'status' => 500 ] );
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
    foreach ( array_filter( [ $row->filename, $row->mp3_file, $row->vtt_file ] ) as $name ) {
        $path = $dir . basename( $name );
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    global $wpdb;
    $wpdb->delete( snn_media_table(), [ 'id' => $row->id ] ); // phpcs:ignore WordPress.DB

    return rest_ensure_response( [ 'success' => true, 'id' => $row->id ] );
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

        <!-- ---------- Drop zone ---------- -->
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

        <!-- ---------- Active queue ---------- -->
        <div id="snn-queue-wrap" class="snn-card" hidden>
            <div class="snn-card-head">
                <h2>Upload queue</h2>
                <span id="snn-queue-summary" class="snn-muted"></span>
            </div>
            <div id="snn-queue" class="snn-queue"></div>
        </div>

        <!-- ---------- Pipeline log ---------- -->
        <details class="snn-card snn-log-card">
            <summary>Processing log</summary>
            <div id="snn-log" class="snn-log"></div>
        </details>

        <!-- ---------- Library ---------- -->
        <div class="snn-card">
            <div class="snn-card-head">
                <h2>Library <span id="snn-total" class="snn-count"></span></h2>
                <div class="snn-card-actions">
                    <input type="search" id="snn-search" class="snn-input" placeholder="Search filename&hellip;">
                    <button type="button" id="snn-refresh" class="snn-btn snn-btn-ghost">Refresh</button>
                    <button type="button" id="snn-process-all" class="snn-btn">Process pending</button>
                </div>
            </div>
            <div id="snn-items" class="snn-items">
                <p class="snn-muted snn-empty">Loading&hellip;</p>
            </div>
            <div id="snn-pagination" class="snn-pagination"></div>
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
            'r2_jurisdiction', 'stt_model', 'stt_language',
            'mp3_bitrate', 'mp3_sample_rate',
        ];
        foreach ( $text_fields as $f ) {
            if ( isset( $_POST[ 'snn_' . $f ] ) ) {
                snn_media_set( $f, sanitize_text_field( wp_unslash( $_POST[ 'snn_' . $f ] ) ) );
            }
        }

        $number_fields = [ 'chunk_size_mb', 'max_file_mb', 'vtt_max_words', 'vtt_max_seconds' ];
        foreach ( $number_fields as $f ) {
            if ( isset( $_POST[ 'snn_' . $f ] ) ) {
                snn_media_set( $f, max( 0, (int) $_POST[ 'snn_' . $f ] ) );
            }
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

        foreach ( [ 'r2_auto_sync', 'r2_delete_local', 'mp3_auto', 'vtt_auto', 'cron_enabled' ] as $f ) {
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
