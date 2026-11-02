<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || defined('OAP_BRIDGE_TOKEN')) { throw new RuntimeException('Isolated option-token tests only'); }
function verify($ok, $label) { if (!$ok) throw new RuntimeException($label); WP_CLI::log('PASS: '.$label); }
$original = get_option(OAP_Auth::TOKEN_OPTION, false);
try {
    delete_option(OAP_Auth::TOKEN_OPTION);
    try { OAP_Auth::matches(str_repeat('x',32)); throw new RuntimeException('accepted unconfigured'); } catch (OAP_Failure $e) {}
    foreach (['short', str_repeat('a',31), str_repeat('a',32).' ', str_repeat('a',4097)] as $bad) {
        try { OAP_Auth::save_token($bad); throw new RuntimeException('accepted invalid token'); } catch (OAP_Failure $e) {}
    }
    $token = bin2hex(random_bytes(32)); OAP_Auth::save_token($token);
    verify(get_option(OAP_Auth::TOKEN_OPTION) === hash('sha256',$token), 'only token digest persisted');
    verify(OAP_Auth::matches($token) && !OAP_Auth::matches(str_repeat('x',64)), 'secure option-token comparison');
    $r=new WP_REST_Request('GET','/oap-bridge/v1/pages/03253561929809');
    $r->set_header('Authorization','bEaReR '.$token);
    $response=rest_do_request($r);
    verify($response->get_status()===200 && array_keys($response->get_data())===['gtin','version','installed_hash','remote_id','ownership','operation'], 'Bearer option token and exact ACK contract');
    foreach (['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION'] as $source) {
        $headers=rest_get_server()->get_headers([$source=>'Bearer '.$token]);
        $headerRequest=new WP_REST_Request('GET','/oap-bridge/v1/pages/03253561929809');
        $headerRequest->set_headers($headers);
        verify(OAP_Auth::permission($headerRequest)===true, 'native WordPress Authorization source '.$source);
    }
    $r->set_header('Authorization','Bearer wrong'); verify(rest_do_request($r)->get_status()===401, 'wrong option token rejected');
    $next=bin2hex(random_bytes(32)); OAP_Auth::save_token($next);
    verify(!OAP_Auth::matches($token) && OAP_Auth::matches($next), 'rotation revokes previous token');
    wp_set_current_user(1); $_SERVER['REQUEST_METHOD']='GET';
    ob_start(); OAP_Admin::settings(); $html=ob_get_clean();
    verify(!str_contains($html,$next) && !str_contains($html,hash('sha256',$next)) && str_contains($html,'value=""'), 'admin never redisplays token or digest');
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=['_wpnonce'=>wp_create_nonce('oap_bridge_token'),'oap_token'=>bin2hex(random_bytes(32))];
    $_REQUEST=$_POST;
    ob_start(); OAP_Admin::settings(); $savedHtml=ob_get_clean();
    verify(OAP_Auth::matches($_POST['oap_token']) && !str_contains($savedHtml,$_POST['oap_token']), 'admin nonce-protected token save without redisplay');
    $_POST['oap_token']=''; $_REQUEST=$_POST;
    $digest=get_option(OAP_Auth::TOKEN_OPTION);
    ob_start(); OAP_Admin::settings(); ob_end_clean();
    verify(get_option(OAP_Auth::TOKEN_OPTION)===$digest, 'empty admin input preserves token');

} finally {
    if ($original===false) delete_option(OAP_Auth::TOKEN_OPTION); else update_option(OAP_Auth::TOKEN_OPTION,$original,false);
}
