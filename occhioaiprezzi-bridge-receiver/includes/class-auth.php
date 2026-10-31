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
            return true; // Il bearer dedicato autorizza solo le rotte private.
        } catch (Throwable $e) { return OAP_Support::error($e); }
    }
}
