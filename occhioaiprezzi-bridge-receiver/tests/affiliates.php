<?php
// Disposable WordPress only. No production data or credentials.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
function affiliate_check($ok, $name) { if (!$ok) { throw new RuntimeException('FAIL: ' . $name); } WP_CLI::log('PASS: ' . $name); }
function affiliate_req(array $p) {
    $r = new WP_REST_Request('PUT', '/oap-bridge/v1/pages/' . $p['gtin']);
    $r->set_header('Authorization', 'Bearer ' . OAP_BRIDGE_TOKEN);
    $r->set_header('Content-Type', 'application/json');
    $r->set_header('Idempotency-Key', $p['content_hash'] . ':' . $p['version']);
    $r->set_body(wp_json_encode($p));
    return rest_do_request($r);
}
function affiliate_bundle($gtin, $code) {
    $slug = 'affiliate-test-' . $gtin;
    return ['protocol'=>'oap-wp-1', 'gtin'=>$gtin, 'slug'=>$slug, 'version'=>1, 'content_hash'=>str_repeat('a',64),
        'html'=>'<article><h1>Test</h1><a href="' . home_url('/go/?id=' . $code) . '" target="_blank" rel="noopener noreferrer sponsored nofollow">Confronta</a></article>',
        'seo'=>['title'=>'Test','canonical'=>OAP_Support::canonical($slug),'indexable'=>true,'modified_at'=>1700000000],
        'state'=>'active','redirects'=>[],'removal_policy'=>'temporary','operation'=>'create'];
}
global $wpdb;
$db = new OAP_Storage();
$gtins = ['03253561929809','03253561756726'];
foreach ($gtins as $gtin) {
    $wpdb->delete($db->affiliates, ['gtin'=>$gtin]); $wpdb->delete($db->slugs, ['gtin'=>$gtin]); $wpdb->delete($db->pages, ['gtin'=>$gtin]);
}
$posts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$a = str_repeat('A',43); $b = str_repeat('B',43); $c = str_repeat('C',43);
$url = 'https://93.184.215.14/offer?tag=original&x=%2f&x=%2F&empty=&plus=a+b#tracking';
$p = affiliate_bundle($gtins[0], $a);
$p['affiliate_destinations'] = [['code'=>$a,'destination_url'=>$url], ['code'=>$b,'destination_url'=>'https://93.184.215.14/old']];
affiliate_check(affiliate_req($p)->get_status()===201, 'create affiliate associations');
affiliate_check(OAP_Affiliate_Redirects::response('/go/?id='.$a,'GET') === ['status'=>302,'location'=>$url], '302 exact original URL bytes');
foreach (['/compara', '/compara/', '/compara?id='.$a, '/compara/QUALSIASI-CODICE', '/compara/?id='.$a,
    '/compara/'.$a, '/', '/wp-json/', '/go/child', '/go//', '/gopher', '/GO/'] as $outside) {
    foreach (['GET', 'HEAD', 'POST', 'OPTIONS'] as $method) {
        affiliate_check(OAP_Affiliate_Redirects::response($outside, $method) === null, 'unrelated request untouched: '.$method.' '.$outside);
    }
}
foreach (['/go', '/go/'] as $path) {
    foreach (['GET', 'HEAD'] as $method) {
        affiliate_check(OAP_Affiliate_Redirects::response($path.'?id='.$a, $method) === ['status'=>302,'location'=>$url], 'exact path and method '.$method.' '.$path);
        foreach (['', '?id=', '?id=short', '?id='.str_repeat('A',42), '?id='.str_repeat('A',44), '?id='.str_repeat('A',42).'.',
            '?id='.$a.'&x=1', '?id='.$a.'&id='.$a, '?id[]='.$a, '?id='.$a.'&', '?id='.$a.';x=1', '?%69d='.$a,
            '?id=%41'.str_repeat('A',42), '?id='.$a.'%0a', '?id='.$a.'&url=https://evil.org/'] as $query) {
            affiliate_check(OAP_Affiliate_Redirects::response($path.$query, $method) === ['status'=>404], 'malformed query rejected: '.$method.' '.$query);
        }
    }
    foreach (['POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'] as $method) {
        foreach (['', '?id='.$a, '?id=bad'] as $query) {
            affiliate_check(OAP_Affiliate_Redirects::response($path.$query, $method) === ['status'=>405], 'method rejected before query: '.$method);
        }
    }
}
// Revalidate stored destinations on lookup as protection against corrupted/unsafe rows.
foreach (['https://occhioaiprezzi.com/', 'https://sub.receiver.test/', 'http://93.184.215.14/', 'https://127.0.0.1/',
    'https://[::1]/', '//evil.org/', 'https://user@evil.org/', "https://evil.org/\r\nLocation: bad"] as $unsafe) {
    $wpdb->update($db->affiliates, ['destination_url'=>$unsafe], ['code'=>$a]);
    affiliate_check(OAP_Affiliate_Redirects::response('/go/?id='.$a, 'GET') === ['status'=>404], 'unsafe stored destination cannot redirect');
}
$wpdb->update($db->affiliates, ['destination_url'=>$url], ['code'=>$a]);
affiliate_check(affiliate_req($p)->get_data()['operation']==='unchanged', 'idempotent retry');
affiliate_check(!str_contains(OAP_Support::json(OAP_Storage::read($db->page($gtins[0])['artifact'])), $url), 'private artifact contains fingerprints only');
affiliate_check(!str_contains(OAP_Support::json(affiliate_req($p)->get_data()), $url), 'ACK never exposes affiliate URLs');
affiliate_check(OAP_Affiliate_Redirects::response('/go/?id='.str_repeat('a',43),'GET')['status']===404, 'codes are case sensitive');
affiliate_check(!OAP_Affiliate_Redirects::public_host('https://oap-receiver-db/'), 'DNS resolving to internal container address rejected');
foreach ([null, [['code'=>$a,'destination_url'=>$url],['code'=>$a,'destination_url'=>$url]], ['not-a-list'=>[]]] as $invalid) {
    $bad=$p; $bad['affiliate_destinations']=$invalid;
    affiliate_check(affiliate_req($bad)->get_status()===400, 'invalid affiliate list rejected');
}

$bad=$p; $bad['affiliate_destinations'][0]['destination_url']='https://93.184.215.14/tampered';
affiliate_check(affiliate_req($bad)->get_status()===409, 'same hash cannot hide destination change');
$p['version']=2; $p['operation']='update'; $p['content_hash']=str_repeat('b',64);
$p['affiliate_destinations']=[['code'=>$a,'destination_url'=>'https://93.184.215.14/new?tag=next'],['code'=>$c,'destination_url'=>$url]];
affiliate_check(affiliate_req($p)->get_status()===200, 'update and insert destinations');
affiliate_check(OAP_Affiliate_Redirects::response('/go/?id='.$a,'HEAD')['location']==='https://93.184.215.14/new?tag=next', 'HEAD updated destination');
affiliate_check(OAP_Affiliate_Redirects::response('/go/?id='.$b,'GET')['status']===404, 'removed code revoked');
affiliate_check(OAP_Affiliate_Redirects::response('/go/?id='.str_repeat('Z',43),'GET')['status']===404, 'unknown code 404');
$old=$p; unset($old['affiliate_destinations']); $old['version']=3;
affiliate_check(affiliate_req($old)->get_data()['operation']==='cursor_advanced', 'old payload cursor compatible');
$old['version']=4; $old['content_hash']=str_repeat('c',64);
affiliate_check(affiliate_req($old)->get_status()===200 && OAP_Affiliate_Redirects::response('/go/?id='.$c,'GET')['status']===302, 'old payload update preserves destinations');
$second=affiliate_bundle($gtins[1], $b);
affiliate_check(affiliate_req($second)->get_status()===201, 'legacy published product without destinations');
$second['version']=2; $second['operation']='update'; $second['content_hash']=str_repeat('d',64);
$second['affiliate_destinations']=[['code'=>$b,'destination_url'=>$url]];
affiliate_check(affiliate_req($second)->get_status()===409 && (int)$db->page($gtins[1])['version']===1, 'revoked code ownership and atomic rollback');
$second['affiliate_destinations']=[['code'=>str_repeat('D',43),'destination_url'=>$url]];
affiliate_check(affiliate_req($second)->get_status()===200, 'add associations to previously published product');
foreach (['http://93.184.215.14/x','https://localhost/x','https://127.0.0.1/x','https://10.0.0.1/x','https://[::1]/x','https://2130706433/x','https://user:pass@93.184.215.14/x','https://receiver.test/x','https://sub.occhioaiprezzi.it/x',"https://93.184.215.14/x\r\nLocation: bad"] as $unsafe) {
    $bad=$second; $bad['version']=3; $bad['content_hash']=str_repeat('e',64); $bad['affiliate_destinations'][0]['destination_url']=$unsafe;
    affiliate_check(affiliate_req($bad)->get_status()===400, 'unsafe destination rejected: '.str_replace(["\r","\n"],'', $unsafe));
}
$before=$db->page($gtins[0]); $empty=$old; $empty['version']=5; $empty['content_hash']=str_repeat('f',64); $empty['affiliate_destinations']=[];
affiliate_check(affiliate_req($empty)->get_status()===200 && OAP_Affiliate_Redirects::response('/go/?id='.$a,'GET')['status']===404, 'empty list revokes all product codes');
affiliate_check(OAP_Affiliate_Redirects::response('/go/?id='.str_repeat('D',43),'GET')['status']===302, 'other product unaffected');
affiliate_check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}")===$posts, 'no WordPress posts created or duplicated');
affiliate_check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$db->pages} WHERE gtin IN ('03253561929809','03253561756726')")===2, 'one product per GTIN');
affiliate_check(OAP_Storage::read($db->page($gtins[0])['artifact'])['html']===$p['html'] && $before['slug']===$db->page($gtins[0])['slug'], 'HTML CTA and slug preserved exactly');
affiliate_check(!str_contains($p['html'], $url), 'affiliate destination absent from public HTML');
$wpdb->query("CREATE TRIGGER oap_test_affiliate_failure BEFORE INSERT ON {$db->affiliates} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected test failure'");
try {
    $fail=$second; $fail['version']=3; $fail['content_hash']=str_repeat('e',64); $fail['affiliate_destinations'][0]['destination_url']='https://93.184.215.14/fail';
    affiliate_check(affiliate_req($fail)->get_status()===503 && (int)$db->page($gtins[1])['version']===2 && OAP_Affiliate_Redirects::response('/go/?id='.str_repeat('D',43),'GET')['location']===$url, 'SQL failure rolls back revocation and product version');
} finally { $wpdb->query('DROP TRIGGER oap_test_affiliate_failure'); }
WP_CLI::success('Affiliate suite complete');
