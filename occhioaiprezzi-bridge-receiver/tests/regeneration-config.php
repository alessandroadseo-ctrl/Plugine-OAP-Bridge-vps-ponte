<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(),PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
$mode=$args[0] ?? 'missing';
$token=str_repeat('p',48);
if ($mode==='reused') { $token=OAP_BRIDGE_TOKEN; }
if ($mode==='invalid-token') { $token='short'; }
if ($mode==='old-inbound') { define('OAP_REGENERATION_TOKEN',$token); }
if ($mode!=='missing') {
    define('OAP_REGENERATION_POLL_TOKEN',$token);
    define('OAP_REGENERATION_WORKER_ID',$mode==='invalid-worker' ? 'invalid worker' : 'vps2-test');
}
$valid=false;
try { $valid=OAP_Regeneration_Client::worker()==='vps2-test'; }
catch(OAP_Failure $e) { if ($e->getMessage()!=='oap_regeneration_configuration') { throw $e; } }
if ($valid!==($mode==='valid')) { throw new RuntimeException('Configuration guard failed: '.$mode); }
WP_CLI::log('PASS: dedicated polling configuration '.$mode);
