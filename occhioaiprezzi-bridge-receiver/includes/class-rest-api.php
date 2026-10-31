<?php
defined('ABSPATH') || exit;
final class OAP_REST_API {
    public static function register(): void {
        foreach (['gtins/(?P<gtin>[0-9]+)/eligibility'=>'GET','pages/(?P<gtin>[0-9]+)'=>'GET,PUT'] as $route=>$methods) {
            register_rest_route('oap-bridge/v1', '/' . $route, ['methods'=>$methods, 'permission_callback'=>[OAP_Auth::class,'permission'], 'callback'=>[self::class,'dispatch']]);
        }
    }
    public static function dispatch(WP_REST_Request $r) {
        try {
            $gtin = OAP_Support::gtin($r->get_url_params()['gtin'] ?? null);
            if (str_ends_with($r->get_route(), '/eligibility')) {
                $keys = OAP_Eligibility::keys(); $db = new OAP_Storage();
                $present = $db->locked(static fn($db)=>OAP_Eligibility::present($db, $gtin, $keys));
                $result = ['gtin'=>$gtin,'woocommerce_present'=>$present,'proof'=>$present ? '' : OAP_Auth::proof($gtin)];
            } elseif ($r->get_method() === 'PUT') {
                if (strtolower(trim(explode(';', (string)$r->get_header('content-type'))[0])) !== 'application/json') { throw new OAP_Failure('oap_json_required', 415); }
                $p = json_decode((string)$r->get_body(), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($p) || array_is_list($p) || !is_string($p['content_hash'] ?? null) || !is_int($p['version'] ?? null)) { throw new OAP_Failure('oap_invalid_payload'); }
                if ($r->get_header('idempotency-key') !== ($p['content_hash'] ?? '') . ':' . ($p['version'] ?? '')) { throw new OAP_Failure('oap_invalid_idempotency_key'); }
                $result = OAP_Product_Pages::put($p, $gtin);
            } else {
                $db = new OAP_Storage(); $result = OAP_Product_Pages::ack($gtin, $db->page($gtin));
            }
            $response = new WP_REST_Response($result, ($result['operation'] ?? '') === 'created' ? 201 : 200);
            $response->header('Cache-Control','no-store, private'); return $response;
        } catch (JsonException $e) { return OAP_Support::error(new OAP_Failure('oap_invalid_json')); }
        catch (Throwable $e) { return OAP_Support::error($e); }
    }
}
