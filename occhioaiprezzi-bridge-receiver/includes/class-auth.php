<?php
defined('ABSPATH') || exit;
final class OAP_Auth {
    public static function secret(): string {
        $secret = OAP_Support::cfg('OAP_BRIDGE_TOKEN', '');
        if (!is_string($secret) || strlen($secret) < 32) { throw new OAP_Failure('oap_not_configured', 503); }
        return $secret;
    }
    const TOKEN_OPTION = 'oap_bridge_token_sha256';

    public static function matches(string $token): bool {
        // Explicit deployment configuration takes precedence over the admin option.
        if (defined('OAP_BRIDGE_TOKEN')) { return hash_equals(self::secret(), $token); }
        $digest = get_option(self::TOKEN_OPTION, '');
        if (!is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
            throw new OAP_Failure('oap_not_configured', 503);
        }
        return strlen($token) >= 32 && hash_equals($digest, hash('sha256', $token));
    }

    public static function save_token(string $token): void {
        if (strlen($token) < 32 || strlen($token) > 4096 || preg_match('/\s/', $token)) {
            throw new OAP_Failure('oap_invalid_token');
        }
        update_option(self::TOKEN_OPTION, hash('sha256', $token), false);
    }

    public static function permission(WP_REST_Request $r) {
        try {
            if (!is_ssl()) { throw new OAP_Failure('oap_https_required', 403); }
            $auth = (string)$r->get_header('authorization');
            if (!preg_match('/\ABearer ([^\s]+)\z/i', $auth, $m) || !self::matches($m[1])) {
                throw new OAP_Failure('oap_unauthorized', 401);
            }
            if (strlen((string)$r->get_body()) > 1048576 || (int) $r->get_header('content-length') > 1048576) {
                throw new OAP_Failure('oap_request_too_large', 413);
            }
            return true; // Il bearer dedicato autorizza solo le rotte private.
        } catch (Throwable $e) { return OAP_Support::error($e); }
    }
}
