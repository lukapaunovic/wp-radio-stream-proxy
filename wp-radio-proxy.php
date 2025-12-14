<?php
/**
 * Plugin Name: wpradio PHP Stream Proxy
 * Description: Secure PHP stream proxy with ICY stripping + HTTP/0.9 support + upstream Content-Type passthrough
 * Author: Luka / Codex
 */

if (!defined('ABSPATH')) exit;

/* Canonical/redirect guards */
add_filter('redirect_canonical', function ($redirect) {
    return isset($_GET['wpradio_php_stream']) ? false : $redirect;
}, 10);

if (isset($_GET['wpradio_php_stream'])) {
    remove_action('template_redirect', 'redirect_canonical');
    remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
}

/* Secret */
const wpradio_STREAM_PROXY_SECRET = 'put-a-long-random-secret-here-CHANGE-ME';

/* Helpers */
function wpradio_stream_get_source_url(int $post_id): ?string {
    $v = get_post_meta($post_id, 'stream_url', true);
    $v = trim((string)$v);
    return $v !== '' ? $v : null;
}
function wpradio_stream_make_sig(int $station_id, string $source_url): string {
    return hash_hmac('sha256', $station_id . '|' . $source_url, wpradio_STREAM_PROXY_SECRET);
}
function wpradio_stream_verify_sig(int $station_id, string $source_url, string $sig): bool {
    return hash_equals(wpradio_stream_make_sig($station_id, $source_url), (string)$sig);
}

/* Replace WP Radio stream URLs */
function wpradio_stream_filter_stream_url(string $url, $post_id = null): string {
    $post_id = (int)$post_id;
    if ($post_id <= 0) return $url;

    $src = wpradio_stream_get_source_url($post_id);
    if (!$src) return $url;

    $base = home_url('/');
    
    return $base . '?' . http_build_query([
        'wpradio_php_stream' => 1,
        'station' => $post_id,
        'sig' => wpradio_stream_make_sig($post_id, $src),
    ]);
}
add_filter('wp_radio/stream_url', 'wpradio_stream_filter_stream_url', 99, 2);
add_filter('wp_radio_station_stream', 'wpradio_stream_filter_stream_url', 99, 2);
add_filter('wp_radio_player_stream', 'wpradio_stream_filter_stream_url', 99, 2);

/* MAIN handler */
function wpradio_stream_handle_request(): void {
    if (empty($_GET['wpradio_php_stream'])) return;

    nocache_headers();

    $station_id = (int)($_GET['station'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');

    if ($station_id <= 0 || $sig === '') {
        status_header(400);
        exit('Bad request');
    }

    $source_url = wpradio_stream_get_source_url($station_id);
    if (!$source_url) {
        status_header(404);
        exit('Stream not found');
    }

    if (!wpradio_stream_verify_sig($station_id, $source_url, $sig)) {
        status_header(403);
        exit('Invalid signature');
    }

    ignore_user_abort(true);
    set_time_limit(0);
    ini_set('max_execution_time', '0');
    ini_set('output_buffering', '0');
    ini_set('zlib.output_compression', '0');

    while (ob_get_level() > 0) ob_end_flush();

    $ch = curl_init($source_url);
    if (!$ch) {
        status_header(502);
        exit('Upstream init failed');
    }

    $upHeaders = [
        'User-Agent: WinampMPEG/5.8',
        'Accept: */*',
        'Icy-MetaData: 1',      // always ask upstream for icy (some require it)
        'Connection: close',
    ];

    $metaInt = 0;
    $bytesUntilMeta = 0;
    $buffer = '';

    $upContentType = '';   // capture upstream content-type
    $sentClientHeaders = false;

    $sendClientHeaders = function() use (&$sentClientHeaders, &$upContentType, $station_id) {
        if ($sentClientHeaders) return;
        $sentClientHeaders = true;

        $ct = $upContentType !== '' ? $upContentType : 'audio/mpeg';

        header('Content-Type: ' . $ct);
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Accept-Ranges: none');
        header('X-wpradio-Stream-Station: ' . $station_id);
    };

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_HTTPHEADER => $upHeaders,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,

        // IMPORTANT for HTTP/0.9 / weird shoutcast endpoints
        CURLOPT_HTTP09_ALLOWED => true,

        // Keepalive (helps long-lived streams)
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_TCP_KEEPIDLE  => 30,
        CURLOPT_TCP_KEEPINTVL => 15,

        // Read headers (works for both HTTP and ICY responses)
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$metaInt, &$bytesUntilMeta, &$upContentType) {
            $l = trim($line);

            // content-type passthrough
            if (stripos($l, 'content-type:') === 0) {
                $upContentType = trim(substr($l, 13));
            }

            // icy-metaint
            if (stripos($l, 'icy-metaint:') === 0) {
                $metaInt = (int)trim(substr($l, 12));
                if ($metaInt > 0) $bytesUntilMeta = $metaInt;
            }

            return strlen($line);
        },

        // Stream + strip ICY metadata blocks
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$metaInt, &$bytesUntilMeta, &$buffer, $sendClientHeaders) {
            // Make sure headers go out right before first bytes
            $sendClientHeaders();

            $buffer .= $data;

            // No metaint -> just passthrough
            if ($metaInt <= 0) {
                echo $buffer;
                $buffer = '';
                flush();
                return strlen($data);
            }

            while ($buffer !== '') {
                if ($bytesUntilMeta > 0) {
                    $take = min($bytesUntilMeta, strlen($buffer));
                    echo substr($buffer, 0, $take);
                    $buffer = substr($buffer, $take);
                    $bytesUntilMeta -= $take;
                    flush();
                    continue;
                }

                // Need metadata length byte
                if (strlen($buffer) < 1) break;

                $metaLen = ord($buffer[0]) * 16;
                $need = 1 + $metaLen;

                if (strlen($buffer) < $need) break;

                // drop metadata block
                $buffer = substr($buffer, $need);
                $bytesUntilMeta = $metaInt;
            }

            return strlen($data);
        },
    ]);

    curl_exec($ch);
    curl_close($ch);
    exit;
}

add_action('init', 'wpradio_stream_handle_request', 0);
