<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
function regen_check($ok, $name) { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } WP_CLI::log('PASS: '.$name); }
if (!defined('OAP_REGENERATION_POLL_TOKEN')) { define('OAP_REGENERATION_POLL_TOKEN', str_repeat('p',48)); }
if (!defined('OAP_REGENERATION_WORKER_ID')) { define('OAP_REGENERATION_WORKER_ID', 'vps2-test'); }
function regen_req($action, $data, $token = null, $method = 'POST', $mime = 'application/json') {
    $r = new WP_REST_Request($method, '/oap-bridge/v1/regeneration/'.$action);
    $r->set_header('Authorization','Bearer '.($token ?? OAP_REGENERATION_POLL_TOKEN));
    $r->set_header('Content-Type',$mime); $r->set_body(is_string($data) ? $data : wp_json_encode($data));
    return rest_do_request($r);
}
function regen_poll($campaign, $seq = 0, $observed = null) {
    return ['protocol'=>OAP_Regeneration_Client::PROTOCOL,'worker_id'=>OAP_REGENERATION_WORKER_ID,
        'last_sequence'=>$seq,'observed_at'=>$observed ?? time(),'campaign'=>$campaign];
}
function regen_campaign($state = 'running') {
    return ['campaign_id'=>'00000000-0000-0000-0000-000000000001','state'=>$state,'created_at'=>time()-30,
        'completed_at'=>null,'checkpoint'=>'00012345678905','total'=>100,'examined'=>40,'queued'=>70,
        'updated'=>10,'unchanged'=>5,'superseded'=>3,'errors'=>4,'remaining'=>80,'to_examine'=>60,'awaiting_publication'=>20];
}
function regen_ack($command, $campaign, $outcome = 'applied', $error = null) {
    $r = ['protocol'=>OAP_Regeneration_Client::PROTOCOL,'worker_id'=>OAP_REGENERATION_WORKER_ID,
        'outcome'=>$outcome,'error_code'=>$error,'processed_at'=>time(),'campaign'=>$campaign];
    foreach (['command_id','idempotency_key','sequence'] as $key) { $r[$key]=$command[$key]; }
    $r['receipt_hash'] = str_repeat('a',64); return $r;
}
global $wpdb;
$db = new OAP_Regeneration_Store();
// Test DB only. Production has no reset/delete API.
$wpdb->query("DELETE FROM {$db->commands}"); $wpdb->query("DELETE FROM {$db->workers}");
delete_option(OAP_Regeneration_Admin::PENDING);
$pages = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}oap_pages ORDER BY gtin", ARRAY_A);
$admin = get_user_by('login','oap_test_admin'); wp_set_current_user($admin->ID);
$nonce = wp_create_nonce('oap_regeneration');
foreach (['start','pause','resume'] as $action) {
    regen_check(OAP_Regeneration_Admin::authorize('POST',$action,$nonce) === true, 'admin POST '.$action);
    regen_check(is_wp_error(OAP_Regeneration_Admin::authorize('GET',$action,$nonce)), 'GET cannot mutate '.$action);
}
regen_check(OAP_Regeneration_Admin::authorize('GET','status',$nonce) === true, 'admin status');
regen_check(is_wp_error(OAP_Regeneration_Admin::authorize('POST','start','invalid')), 'invalid nonce denied');
foreach (['editor','subscriber'] as $role) {
    $id = wp_insert_user(['user_login'=>'regen_'.$role,'user_pass'=>wp_generate_password(),'role'=>$role]);
    wp_set_current_user($id);
    regen_check(is_wp_error(OAP_Regeneration_Admin::authorize('POST','start',wp_create_nonce('oap_regeneration'))), $role.' denied with own nonce');
    wp_delete_user($id);
}
wp_set_current_user(0);
regen_check(is_wp_error(OAP_Regeneration_Admin::authorize('GET','status',$nonce)), 'anonymous denied');
wp_set_current_user($admin->ID);
$network = 0;
$deny = static function () use (&$network) { $network++; return new WP_Error('blocked','must never call VPS2'); };
add_filter('pre_http_request',$deny);
$idle = ['state'=>'idle','campaign_id'=>null]; $poll = regen_poll($idle);
regen_check(OAP_Regeneration_Admin::control('status')['stale'], 'no worker snapshot is unavailable');
regen_check(is_wp_error(OAP_Regeneration_Admin::control('start',wp_generate_uuid4())), 'cannot start without worker');
foreach (['wrong',OAP_BRIDGE_TOKEN] as $token) { regen_check(regen_req('poll',$poll,$token)->get_status() === 401, 'private poll rejects wrong/publication token'); }
unset($_SERVER['HTTPS']); regen_check(regen_req('poll',$poll)->get_status() === 403, 'TLS required'); $_SERVER['HTTPS']='on';
regen_check(regen_req('poll',$poll,null,'GET')->get_status() === 404, 'GET poll absent');
regen_check(regen_req('poll',$poll,null,'POST','text/plain')->get_status() === 415, 'JSON required');
regen_check(regen_req('poll','{')->get_status() === 400, 'malformed JSON');
regen_check(regen_req('poll',['padding'=>str_repeat('x',65537)])->get_status() === 413, 'bounded request');
$bad = $poll; $bad['worker_id']='different'; regen_check(regen_req('poll',$bad)->get_status() === 403, 'token bound to worker');
$bad = $poll; $bad['last_sequence']=true; regen_check(regen_req('poll',$bad)->get_status() === 400, 'strict integer cursor');
$bad = $poll; $bad['protocol']='other'; regen_check(regen_req('poll',$bad)->get_status() === 403, 'protocol mismatch');
$r = regen_req('poll',$poll); regen_check($r->get_status() === 200 && $r->get_data()['command'] === null, 'empty poll never starts');
$key = wp_generate_uuid4();
$r = OAP_Regeneration_Admin::control('start',$key);
regen_check(!is_wp_error($r) && $r['pending'] && !$r['confirmed'], 'admin persists intent, not success');
$command = regen_req('poll',$poll)->get_data()['command'];
regen_check($command['sequence'] === 1 && $command['idempotency_key'] === $key && $command['action'] === 'start', 'shared command contract');
regen_check(!is_wp_error(OAP_Regeneration_Admin::control('start',$key)), 'lost AJAX response reuses intent');
regen_check(is_wp_error(OAP_Regeneration_Admin::control('pause',$key)), 'same key different action rejected');
regen_check(is_wp_error(OAP_Regeneration_Admin::control('start',wp_generate_uuid4())), 'double click second key blocked');
$restarted = new OAP_Regeneration_Store();
regen_check(json_decode($restarted->pending(OAP_REGENERATION_WORKER_ID)['payload'],true) === $command, 'command survives new store instance');
regen_check(regen_req('poll',$poll)->get_data()['command'] === $command, 'lost poll response redelivers identical command');
$running = regen_campaign(); $advanced = regen_poll($running,1);
regen_check(regen_req('poll',$advanced)->get_data()['command'] === $command, 'last_sequence does not imply ACK');
$ack = regen_ack($command,$running);
$bad=$ack; $bad['idempotency_key']=wp_generate_uuid4(); regen_check(regen_req('ack',$bad)->get_status()===409,'ACK must match pending identity');
$bad=$ack; $bad['campaign']['state']='paused'; regen_check(regen_req('ack',$bad)->get_status()===409,'applied ACK must match action');
$r = new WP_REST_Request('POST','/oap-bridge/v1/regeneration/ack'); $r->set_header('Content-Type','application/json'); $r->set_body(wp_json_encode($ack));
regen_check(rest_do_request($r)->get_status()===401,'admin cookie without bearer cannot ACK');
regen_check(regen_req('ack',$ack,OAP_BRIDGE_TOKEN)->get_status()===401,'publication token cannot ACK');
$before=OAP_Regeneration_Admin::control('status')['campaign'];
$r=regen_req('ack',$ack); regen_check($r->get_status()===200 && $r->get_data()['receipt_hash']===$ack['receipt_hash'], 'receipt persisted before confirmation');
regen_check(regen_req('ack',array_reverse($ack,true))->get_data()==$r->get_data(), 'lost ACK response/reordered fields replay original confirmation');
$bad=$ack; $bad['receipt_hash']=str_repeat('b',64); regen_check(regen_req('ack',$bad)->get_status()===409,'changed receipt conflicts');
$bad=$ack; $bad['campaign']['errors']=5; regen_check(regen_req('ack',$bad)->get_status()===409,'changed body with same hash conflicts');
regen_check(OAP_Regeneration_Admin::control('status')['campaign']===$before,'historical receipt never overwrites poll snapshot');
regen_check(regen_req('poll',$advanced)->get_data()['command']===null,'confirmed command not redelivered');
regen_check(is_wp_error(OAP_Regeneration_Admin::control('start',wp_generate_uuid4())), 'old delayed double start blocked after ACK');
regen_check(!is_wp_error(OAP_Regeneration_Admin::control('pause',wp_generate_uuid4(),$running['campaign_id'],1)), 'pause queued');
$pause=regen_req('poll',$advanced)->get_data()['command']; regen_check($pause['sequence']===2,'consecutive sequence');
$paused=$running; $paused['state']='paused'; $ack2=regen_ack($pause,$paused);
regen_check(regen_req('ack',$ack2)->get_status()===200,'pause ACK');
regen_check(!OAP_Regeneration_Admin::control('status')['synchronized'], 'ACK requires subsequent fresh poll');
regen_req('poll',regen_poll($paused,2));
regen_check(!is_wp_error(OAP_Regeneration_Admin::control('resume',wp_generate_uuid4(),$running['campaign_id'],2)), 'resume queued');
$resume=regen_req('poll',regen_poll($paused,2))->get_data()['command'];
$rejected=regen_ack($resume,$paused,'rejected','command_expired');
regen_check(regen_req('ack',$rejected)->get_status()===200 && !$restarted->pending(OAP_REGENERATION_WORKER_ID),'rejected/expired consumes sequence and persists');
regen_check(regen_req('ack',$ack)->get_status()===200,'historical ACK replay after later commands');
regen_req('poll',regen_poll($paused,3));
$stale=regen_poll($paused,3,time()-1000); $stale['received_at']=time()-1000;
$db->locked(OAP_REGENERATION_WORKER_ID,static function($db) use($stale) { $db->save_snapshot(OAP_REGENERATION_WORKER_ID,$stale); });
$r=OAP_Regeneration_Admin::control('status');
regen_check($r['stale'] && $r['campaign']===$paused,'outage retains snapshot and marks stale');
regen_check(is_wp_error(OAP_Regeneration_Admin::control('resume',wp_generate_uuid4(),$running['campaign_id'],3)),'outage disables new commands');
regen_req('poll',regen_poll($paused,3,time()-999));
$r=OAP_Regeneration_Admin::control('status');
regen_check($r['stale'] && time()-$r['received_at']<5,'fresh receipt of old observation remains stale');
$rows=$wpdb->get_results("SELECT * FROM {$db->commands} ORDER BY sequence",ARRAY_A);
OAP_Regeneration_Store::activate();
regen_check($rows===$wpdb->get_results("SELECT * FROM {$db->commands} ORDER BY sequence",ARRAY_A),'additive migration preserves commands and receipts');
regen_check(OAP_Regeneration_Client::campaign($running)===$running,'VPS2 transient errors and awaiting counters accepted');
$complete=$running; $complete['state']='completed'; $complete['remaining']=0; $complete['to_examine']=0; $complete['awaiting_publication']=0;
$complete['examined']=100; $complete['updated']=90; $complete['unchanged']=5; $complete['superseded']=3; $complete['errors']=2; $complete['completed_at']=time();
regen_check(OAP_Regeneration_Client::campaign($complete)===$complete,'completed with terminal errors accepted');
$bad=$running; $bad['token']='secret';
try { OAP_Regeneration_Client::campaign($bad); regen_check(false,'extra fields rejected'); } catch (OAP_Failure $e) { regen_check(true,'extra fields rejected'); }
$name='oap_regen_'.substr(hash('sha256',DB_NAME.$wpdb->prefix),0,40);
$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$name));
try { regen_check(is_wp_error(OAP_Regeneration_Admin::control('status')),'concurrent DB connection blocked'); }
finally { $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$name)); $other->close(); }
$issued=$wpdb->get_var("SELECT issued FROM {$db->workers} LIMIT 1");
try { $db->locked(OAP_REGENERATION_WORKER_ID,static function($db) { $db->query("UPDATE {$db->workers} SET issued=999"); throw new RuntimeException('crash'); }); } catch(RuntimeException $e) {}
regen_check($wpdb->get_var("SELECT issued FROM {$db->workers} LIMIT 1")===$issued,'transaction rolls back on crash');
update_option(OAP_Regeneration_Admin::PENDING,['key'=>'legacy']);
regen_check(is_wp_error(OAP_Regeneration_Admin::control('start',wp_generate_uuid4())) && get_option(OAP_Regeneration_Admin::PENDING),'legacy uncertainty preserved and blocked');
delete_option(OAP_Regeneration_Admin::PENDING);
regen_check($network===0,'no WordPress to VPS2 HTTP'); remove_filter('pre_http_request',$deny);
regen_check($pages===$wpdb->get_results("SELECT * FROM {$wpdb->prefix}oap_pages ORDER BY gtin",ARRAY_A),'product DB unchanged');
ob_start(); OAP_Regeneration_Admin::render(); $html=ob_get_clean();
regen_check(!str_contains($html,OAP_REGENERATION_POLL_TOKEN) && !str_contains($html,'oap-regen-size'),'no browser secret or unsupported batch selector');
$wpdb->query("DELETE FROM {$db->commands}"); $wpdb->query("DELETE FROM {$db->workers}");
WP_CLI::success('Regeneration polling suite complete');
