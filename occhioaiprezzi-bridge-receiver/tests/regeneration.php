<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
function regen_check($ok, $name) { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } WP_CLI::log('PASS: '.$name); }
define('OAP_REGENERATION_URL', 'https://vps2.example.test');
define('OAP_REGENERATION_TOKEN', str_repeat('t', 48));
$admin = get_user_by('login', 'oap_test_admin');
wp_set_current_user($admin->ID);
$nonce = wp_create_nonce('oap_regeneration');
regen_check(OAP_Regeneration_Admin::authorize('GET','status',$nonce) === true, 'admin status permission');
foreach (['start','pause','resume','retry'] as $cmd) {
    regen_check(OAP_Regeneration_Admin::authorize('POST',$cmd,$nonce) === true, 'admin POST '.$cmd);
    regen_check(is_wp_error(OAP_Regeneration_Admin::authorize('GET',$cmd,$nonce)), 'GET cannot mutate '.$cmd);
}
regen_check(is_wp_error(OAP_Regeneration_Admin::authorize('POST','start','invalid')), 'invalid nonce');
foreach (['editor','subscriber'] as $role) {
    $id = wp_insert_user(['user_login'=>'regen_'.$role,'user_pass'=>wp_generate_password(),'role'=>$role]);
    wp_set_current_user($id);
    regen_check(is_wp_error(OAP_Regeneration_Admin::authorize('GET','status',$nonce)), $role.' denied');
    wp_delete_user($id);
}
wp_set_current_user(0);
regen_check(is_wp_error(OAP_Regeneration_Admin::authorize('POST','start',$nonce)), 'anonymous denied');
wp_set_current_user($admin->ID);
$state = ['id'=>'none','state'=>'idle','total'=>0,'examined'=>0,'updated'=>0,'unchanged'=>0,'errors'=>0,'remaining'=>0,'started_at'=>null,'updated_at'=>null];
$mode = 'ok'; $calls = []; $receipts = [];
$mock = static function ($pre, $args, $url) use (&$state,&$mode,&$calls,&$receipts) {
    $command = basename($url);
    $calls[] = [$command,$args];
    regen_check($args['headers']['Authorization'] === 'Bearer '.OAP_REGENERATION_TOKEN, 'server authentication');
    regen_check($args['sslverify'] === true && $args['redirection'] === 0 && $args['timeout'] === 12, 'TLS and timeout');
    regen_check($args['method'] === ($command === 'status' ? 'GET' : 'POST'), 'upstream HTTP method');
    if ($mode === 'timeout') return new WP_Error('timeout', 'secret must not escape');
    if ($mode === 'http') return ['response'=>['code'=>503], 'body'=>'secret'];
    if ($mode === 'json') return ['response'=>['code'=>200], 'body'=>'not json'];
    $key = $args['headers']['Idempotency-Key'] ?? '';
    if ($command !== 'status') {
        if (!isset($receipts[$key])) {
            $state['id'] = 'campaign-1';
            $state['state'] = $command === 'pause' ? 'paused' : 'running';
            $state['started_at'] = $state['updated_at'] = '2026-11-05T10:00:00Z';
            $state['total'] = $state['remaining'] = 100;
            $receipts[$key] = ['ok'=>true,'request_id'=>$key,'campaign'=>$state];
        }
        $body = $receipts[$key];
    } else { $body = ['ok'=>true,'campaign'=>$state]; }
    if ($mode === 'ack') $body['request_id'] = 'wrong';
    return ['response'=>['code'=>200], 'body'=>wp_json_encode($body)];
};
add_filter('pre_http_request', $mock, 10, 3);
delete_option(OAP_Regeneration_Admin::CACHE);
delete_option(OAP_Regeneration_Admin::PENDING);
try {
    $snapshot = OAP_Regeneration_Admin::control('status');
    regen_check(!$snapshot['stale'] && $snapshot['campaign']['state'] === 'idle', 'valid status');
    foreach (['timeout','http','json'] as $mode) {
        $r = OAP_Regeneration_Admin::control('status');
        regen_check($r['stale'] && $r['campaign'] === $snapshot['campaign'] && !$r['confirmed'], 'retain snapshot on '.$mode);
        regen_check(!str_contains(wp_json_encode($r), 'secret'), 'errors redact sensitive details');
    }
    $mode = 'ok';
    $r = OAP_Regeneration_Admin::control('start',25);
    regen_check($r['confirmed'] && $r['campaign']['state'] === 'running', 'start confirmation');
    $count = count($receipts);
    regen_check(is_wp_error(OAP_Regeneration_Admin::control('start',25)) && count($receipts) === $count, 'active campaign prevents start');
    $mode = 'ack';
    $r = OAP_Regeneration_Admin::control('pause');
    regen_check(!$r['confirmed'] && get_option(OAP_Regeneration_Admin::PENDING), 'invalid ACK retained pending');
    $key = get_option(OAP_Regeneration_Admin::PENDING)['key'];
    $mode = 'ok';
    $r = OAP_Regeneration_Admin::control('status');
    regen_check($r['pending'] && get_option(OAP_Regeneration_Admin::PENDING), 'GET status never replays commands');
    $r = OAP_Regeneration_Admin::control('retry');
    regen_check(!$r['stale'] && $r['campaign']['state'] === 'paused' && !get_option(OAP_Regeneration_Admin::PENDING), 'lost ACK reconciles via idempotent replay');
    regen_check(count($receipts) === 2 && isset($receipts[$key]), 'replay did not create a new command');
    regen_check(OAP_Regeneration_Admin::control('resume')['confirmed'], 'resume confirmed');
    $bad = $state; $bad['examined'] = 101;
    regen_check(is_wp_error(OAP_Regeneration_Client::validate($bad)), 'inconsistent counters rejected');
    $bad = $state; $bad['state'] = 'unknown';
    regen_check(is_wp_error(OAP_Regeneration_Client::validate($bad)), 'unknown state rejected');
    $state['token'] = 'never expose';
    regen_check(!isset(OAP_Regeneration_Client::validate($state)['token']), 'snapshot allowlist');
    global $wpdb;
    $name = 'oap_regen_' . substr(hash('sha256', DB_NAME . $wpdb->prefix), 0, 40);
    $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name));
    try { regen_check(is_wp_error(OAP_Regeneration_Admin::control('start')), 'concurrent connection denied'); }
    finally { $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $name)); $other->close(); }
} finally {
    remove_filter('pre_http_request', $mock, 10);
    delete_option(OAP_Regeneration_Admin::CACHE);
    delete_option(OAP_Regeneration_Admin::PENDING);
}
WP_CLI::success('Regeneration suite complete');
