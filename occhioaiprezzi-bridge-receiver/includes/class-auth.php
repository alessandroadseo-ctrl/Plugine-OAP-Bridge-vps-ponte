<?php
defined('ABSPATH') || exit;
final class OAP_Auth {
    public static function secret(): string {
        $secret = OAP_Support::cfg('OAP_BRIDGE_TOKEN', '');
        if (!is_string($secret) || strlen($secret) < 32) { throw new OAP_Failure('oap_not_configured', 503); }
        return $secret;
    }
    public static function permission(WP_REST_Request $r) {
        try {
            if (!is_ssl()) { throw new OAP_Failure('oap_https_required', 403); }
            $auth = (string)$r->get_header('authorization');
            if (!preg_match('/\ABearer ([^\s]+)\z/', $auth, $m) || !hash_equals(self::secret(), $m[1])) {
                throw new OAP_Failure('oap_unauthorized', 401);
            }
            if (strlen((string)$r->get_body()) > 1048576 || (int) $r->get_header('content-length') > 1048576) {
                throw new OAP_Failure('oap_request_too_large', 413);
            }
            return true; // Il bearer dedicato autorizza solo queste tre rotte.
        } catch (Throwable $e) { return OAP_Support::error($e); }
    }
    public static function proof(string $gtin, ?int $expires = null): string {
        $payload = 'oap-wp-1:PUT:pages:' . $gtin . ':' . ($expires ?? time() + 120) . ':' . hash('sha256', home_url());
        return base64_encode($payload) . '.' . hash_hmac('sha256', $payload, self::secret());
    }
    public static function verify($proof, string $gtin): void {
        if (!is_string($proof) || strlen($proof) > 512) { throw new OAP_Failure('oap_invalid_proof', 403); }
        $parts = explode('.', $proof);
        $raw = count($parts) === 2 ? base64_decode($parts[0], true) : false;
        $bits = $raw !== false ? explode(':', $raw) : [];
        $expiry = isset($bits[4]) && ctype_digit($bits[4]) ? (int) $bits[4] : 0;
        if ($expiry <= time() || $expiry > time() + 120 || !hash_equals(self::proof($gtin, $expiry), $proof)) {
            throw new OAP_Failure('oap_invalid_proof', 403);
        }
    }
}
