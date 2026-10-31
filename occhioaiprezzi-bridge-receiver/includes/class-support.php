<?php
defined('ABSPATH') || exit;
final class OAP_Failure extends RuntimeException {
    public int $status;
    public function __construct(string $code, int $status = 400) {
        parent::__construct($code); $this->status = $status;
    }
}
final class OAP_Support {
    public static function cfg(string $name, $default = null) { return defined($name) ? constant($name) : $default; }
    public static function gtin($value): string {
        // Il protocollo Ponte normalizza a 14 cifre (non impone checksum GS1).
        if (!is_string($value) || !preg_match('/\A[0-9]{14}\z/', $value)) { throw new OAP_Failure('oap_invalid_gtin'); }
        return $value;
    }
    public static function slug($value): string {
        if (!is_string($value) || strlen($value) > 180 || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value)) {
            throw new OAP_Failure('oap_invalid_slug');
        }
        return $value;
    }
    public static function url($value): string {
        if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            throw new OAP_Failure('oap_invalid_url');
        }
        $u = wp_parse_url($value);
        if (!$u || !in_array($u['scheme'] ?? '', ['http', 'https'], true) || empty($u['host']) || isset($u['user']) || isset($u['pass'])) {
            throw new OAP_Failure('oap_invalid_url');
        }
        return $value;
    }
    public static function base(): string {
        $base = rtrim(self::url(self::cfg('OAP_BRIDGE_PUBLIC_BASE', home_url('/prodotti-ponte'))), '/');
        $u = wp_parse_url($base); $home = wp_parse_url(home_url());
        if ($u['scheme'] !== 'https' || strtolower($u['host']) !== strtolower($home['host']) || ($u['port'] ?? 443) !== ($home['port'] ?? 443)
            || isset($u['query']) || isset($u['fragment']) || empty($u['path']) || $u['path'] === '/') {
            throw new OAP_Failure('oap_invalid_public_base', 503);
        }
        return $base;
    }
    public static function page_url(string $slug): string { return self::base() . '/' . $slug . '/'; }
    public static function json($data): string {
        $value = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return $value;
    }
    public static function error(Throwable $e): WP_Error {
        return new WP_Error($e instanceof OAP_Failure ? $e->getMessage() : 'oap_unavailable', 'OAP Bridge: richiesta non completata.',
            ['status' => $e instanceof OAP_Failure ? $e->status : 503]);
    }
}
