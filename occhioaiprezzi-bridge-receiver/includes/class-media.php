<?php
defined('ABSPATH') || exit;

/**
 * Immutable raster transfer: VPS2 pushes already validated PNG bytes.
 * Public assets are served from the receiver's private storage, not from VPS2.
 */
final class OAP_Media {
    private const NAME = '/\\A[a-f0-9]{64}\\z/D';
    private static function directory(string $kind): string {
        if (!in_array($kind, ['products', 'merchants'], true)) {
            throw new OAP_Failure('oap_media_kind', 400);
        }
        $dir = OAP_Storage::directory() . '/media/' . $kind;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new OAP_Failure('oap_storage_unavailable', 503);
        }
        if (is_link($dir) || !is_dir($dir)) {
            throw new OAP_Failure('oap_storage_unavailable', 503);
        }
        return $dir;
    }

    public static function url(string $kind, string $hash): string {
        return home_url('/oap-media/' . $kind . '/' . $hash . '.png');
    }

    public static function register(): void {
        register_rest_route('oap-bridge/v1', '/media/(?P<kind>products|merchants)/(?P<hash>[a-f0-9]{64})', [
            'methods' => 'PUT',
            'permission_callback' => [self::class, 'permission'],
            'callback' => [self::class, 'upload'],
        ]);
    }

    public static function permission(WP_REST_Request $request) {
        try {
            if (!is_ssl()) { throw new OAP_Failure('oap_https_required', 403); }
            $header = (string)$request->get_header('authorization');
            if (!preg_match('/\\ABearer ([^\\s]+)\\z/i', $header, $matches) || !OAP_Auth::matches($matches[1])) {
                throw new OAP_Failure('oap_unauthorized', 401);
            }
            $kind = (string)$request->get_param('kind');
            $limit = $kind === 'products' ? 8388608 : 524288;
            if ((int)$request->get_header('content-length') > $limit) {
                throw new OAP_Failure('oap_request_too_large', 413);
            }
            return true;
        } catch (Throwable $error) { return OAP_Support::error($error); }
    }

    public static function upload(WP_REST_Request $request) {
        try {
            $kind = (string)$request->get_param('kind');
            $hash = (string)$request->get_param('hash');
            if (!preg_match(self::NAME, $hash)) { throw new OAP_Failure('oap_media_hash'); }
            if (strtolower(trim(explode(';', (string)$request->get_header('content-type'))[0])) !== 'image/png') {
                throw new OAP_Failure('oap_media_format', 415);
            }
            $bytes = $request->get_body();
            $limit = $kind === 'products' ? 8388608 : 524288;
            if (!is_string($bytes) || strlen($bytes) < 8 || strlen($bytes) > $limit) {
                throw new OAP_Failure('oap_request_too_large', 413);
            }
            if (!hash_equals($hash, hash('sha256', $bytes))) {
                throw new OAP_Failure('oap_media_hash', 422);
            }
            $info = @getimagesizefromstring($bytes);
            if (!$info || ($info['mime'] ?? '') !== 'image/png') {
                throw new OAP_Failure('oap_media_format', 415);
            }
            $dir = self::directory($kind);
            $path = $dir . '/' . $hash . '.png';
            if (is_link($path)) { throw new OAP_Failure('oap_storage_unavailable', 503); }
            if (!is_file($path)) {
                $temp = @tempnam($dir, '.oap-media-');
                if (!$temp) { throw new OAP_Failure('oap_storage_unavailable', 503); }
                try {
                    $stream = @fopen($temp, 'wb');
                    if (!$stream) { throw new OAP_Failure('oap_storage_unavailable', 503); }
                    try {
                        if (@fwrite($stream, $bytes) !== strlen($bytes) || !@fflush($stream)) {
                            throw new OAP_Failure('oap_storage_unavailable', 503);
                        }
                        if (function_exists('fsync') && !@fsync($stream)) {
                            throw new OAP_Failure('oap_storage_unavailable', 503);
                        }
                    } finally { fclose($stream); }
                    @chmod($temp, 0600);
                    if (!@rename($temp, $path)) { throw new OAP_Failure('oap_storage_unavailable', 503); }
                } finally { if (is_file($temp)) { @unlink($temp); } }
            }
            if (!is_file($path) || !hash_equals($hash, (string)@hash_file('sha256', $path))) {
                throw new OAP_Failure('oap_artifact_integrity', 503);
            }
            $response = new WP_REST_Response(['url' => self::url($kind, $hash), 'hash' => $hash], 200);
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        } catch (Throwable $error) { return OAP_Support::error($error); }
    }

    public static function serve(): void {
        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $base = wp_parse_url(home_url('/oap-media/'), PHP_URL_PATH);
        if (!is_string($path) || !is_string($base) || !str_starts_with($path, $base)) { return; }
        $relative = substr($path, strlen($base));
        if (!preg_match('/\\A(products|merchants)\\/([a-f0-9]{64})\\.png\\z/D', $relative, $parts)) {
            status_header(404); exit;
        }
        try {
            $file = self::directory($parts[1]) . '/' . $parts[2] . '.png';
            if (is_link($file) || !is_file($file)) { status_header(404); exit; }
            $bytes = @file_get_contents($file);
            if (!is_string($bytes) || !hash_equals($parts[2], hash('sha256', $bytes))) {
                status_header(404); exit;
            }
            status_header(200);
            header('Content-Type: image/png');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Content-Length: ' . strlen($bytes));
            echo $bytes;
            exit;
        } catch (Throwable $error) { status_header(503); exit; }
    }
}
