<?php
// Disposable WordPress only. No production fixture or outbound request.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
function deletion_check($ok, $label) { if (!$ok) { throw new RuntimeException('FAIL: '.$label); } WP_CLI::log('PASS: '.$label); }
function deletion_request($method, $gtin, $p=null, $token=null, $key=null) {
    $r = new WP_REST_Request($method, '/oap-bridge/v1/pages/'.$gtin);
    $r->set_header('Authorization', 'Bearer '.($token ?? OAP_BRIDGE_TOKEN));
    if ($p !== null) {
        $r->set_header('Content-Type', 'application/json');
        $r->set_header('Idempotency-Key', $key ?? ($method === 'DELETE' ? 'delete:'.$gtin.':'.$p['version'] : $p['content_hash'].':'.$p['version']));
        $r->set_body(wp_json_encode($p));
    }
    return rest_do_request($r);
}
function deletion_bundle($gtin, $slug) {
    return ['protocol'=>'oap-wp-1','operation'=>'create','gtin'=>$gtin,'slug'=>$slug,'version'=>1,'content_hash'=>hash('sha256',$gtin),
        'html'=>'<p>Private offers</p>','offers'=>[['price'=>123]],'seo'=>['title'=>'Private SEO','canonical'=>OAP_Support::canonical($slug),'indexable'=>true,'modified_at'=>1],
        'state'=>'active','redirects'=>['alias-'.$slug],'removal_policy'=>'temporary'];
}
global $wpdb;
$db = new OAP_Storage(); $gtin='00000000000871'; $other='00000000000872';
foreach ([$gtin,$other] as $id) { foreach ([$db->pages,$db->tombstones,$db->slugs,$db->affiliates] as $table) { $wpdb->delete($table,['gtin'=>$id]); } }
$dir=OAP_Storage::directory(); @mkdir($dir.'/media/products',0700,true);
$hashes=[];
foreach (['unique','shared','protected','native'] as $kind) { $hashes[$kind]=hash('sha256','deletion-test-'.$kind); file_put_contents($dir.'/media/products/'.$hashes[$kind].'.png', 'test-private-image'); }
$p=deletion_bundle($gtin,'deletion-unit');
foreach ($hashes as $hash) { $p['html'].='<img src="'.OAP_Media::url('products',$hash).'" alt="test">'; }
$code=str_repeat('q',43); $p['affiliate_destinations']=[['code'=>$code,'destination_url'=>'https://8.8.8.8/test']];
deletion_check(deletion_request('PUT',$gtin,$p)->get_status()===201,'delete fixture publication');
$artifact=$db->page($gtin)['artifact'];
$second=deletion_bundle($other,'deletion-survivor'); $second['html']='<img src="'.OAP_Media::url('products',$hashes['shared']).'" alt="shared">';
$second['seo']['canonical']=OAP_Support::canonical('deletion-unit');
deletion_check(deletion_request('PUT',$other,$second)->get_status()===201,'shared reference publication');
$native=wp_insert_post(['post_type'=>'page','post_title'=>'Native survivor','post_status'=>'publish','post_content'=>OAP_Media::url('products',$hashes['native'])]);
$delete=['protocol'=>'oap-wp-1','operation'=>'delete','gtin'=>$gtin,'version'=>2,
    'media_policy'=>['delete_products'=>array_values($hashes),'protected_products'=>[$hashes['protected']]]];
deletion_check(deletion_request('DELETE',$gtin,$delete,'wrong')->get_status()===401,'delete authentication');
deletion_check(deletion_request('DELETE',$gtin,$delete,null,'wrong')->get_status()===400,'delete idempotency key');
$same=$delete; $same['version']=1;
deletion_check(deletion_request('DELETE',$gtin,$same)->get_status()===409,'same publication version cannot delete');
$wpdb->update($db->pages,['owner'=>'foreign'],['gtin'=>$gtin]);
deletion_check(deletion_request('DELETE',$gtin,$delete)->get_status()===409,'foreign owner protected');
$wpdb->update($db->pages,['owner'=>'oap-wp-1'],['gtin'=>$gtin]);
$wpdb->query("DROP TRIGGER IF EXISTS oap_delete_test_failure");
$wpdb->query("CREATE TRIGGER oap_delete_test_failure BEFORE DELETE ON `{$db->affiliates}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated deletion rollback'");
try {
    deletion_check(deletion_request('DELETE',$gtin,$delete)->get_status()===503,'SQL failure rejected');
    deletion_check($db->page($gtin) && !$db->tombstone($gtin) && is_file($dir.'/'.$artifact.'.json'),'SQL rollback retains complete publication');
} finally { $wpdb->query("DROP TRIGGER IF EXISTS oap_delete_test_failure"); }
$historical=OAP_Storage::artifact(array_merge($p,['version'=>99,'html'=>'<p>old private offer</p>']));
$purges=[]; add_action('oap_bridge_purge_url',static function($url) use (&$purges) {$purges[]=$url;});
deletion_check(deletion_request('DELETE',$gtin,$delete)->get_status()===200,'hard deletion succeeds');
deletion_check(!$db->page($gtin) && !$db->one($wpdb->prepare("SELECT code FROM `{$db->affiliates}` WHERE gtin=%s",$gtin)),'page and affiliate rows physically removed');
deletion_check(!is_file($dir.'/'.$historical.'.json'),'historical HTML and offers removed');
deletion_check(OAP_Affiliate_Redirects::response('/go/?id='.$code,'GET')['status']===404,'deleted affiliate redirect cannot resolve');
deletion_check(!is_file($dir.'/'.$artifact.'.json') && !is_file($dir.'/media/products/'.$hashes['unique'].'.png'),'private document offers SEO and unique image removed');
foreach (['shared','protected','native'] as $kind) { deletion_check(is_file($dir.'/media/products/'.$hashes[$kind].'.png'),$kind.' image survives'); }
deletion_check(get_post($native)->post_title==='Native survivor','native WordPress content survives');
$tomb=$db->tombstone($gtin); deletion_check(array_keys($tomb)===['gtin','version','slug'] && (int)$tomb['version']===2,'minimal tombstone only');
deletion_check(in_array(OAP_Support::canonical('alias-deletion-unit'),$purges,true) && in_array(home_url('/wp-sitemap-oap-1.xml'),$purges,true),'aliases and sitemap cache purged');
deletion_check(in_array(OAP_Support::canonical('deletion-survivor'),$purges,true),'dependent canonical cache purged');
deletion_check(deletion_request('DELETE',$gtin,$delete)->get_data()['operation']==='deleted','lost ACK repeated delete');
deletion_check(deletion_request('GET',$gtin)->get_data()['version']===2,'GET reconciles tombstone');
deletion_check(deletion_request('PUT',$gtin,$p)->get_status()===409,'old publication cannot resurrect');
deletion_check(OAP_Deletion::canonical($db,$second['seo']['canonical'],$second['slug'])===OAP_Support::canonical($second['slug']),'existing canonical no longer targets deleted product');
require_once WP_PLUGIN_DIR.'/occhioaiprezzi-bridge-receiver/includes/class-sitemap.php';
foreach ((new OAP_Sitemap())->get_url_list(1) as $entry) { deletion_check($entry['loc']!==OAP_Support::canonical('deletion-unit'),'deleted canonical excluded from sitemap'); }
$p['version']=3; $p['operation']='update';
deletion_check(deletion_request('PUT',$gtin,$p)->get_status()===201 && !$db->tombstone($gtin),'higher version republishes');
deletion_check(deletion_request('DELETE',$gtin,$delete)->get_status()===409 && $db->page($gtin),'late deletion cannot remove republication');
$delete['version']=4;
// Cleanup failure occurs after DB commit: retry must finish without a full page.
$unique=$dir.'/media/products/'.$hashes['unique'].'.png'; symlink('/nonexistent-oap-test',$unique);
deletion_check(deletion_request('DELETE',$gtin,$delete)->get_status()===503 && !$db->page($gtin) && $db->tombstone($gtin),'cleanup failure leaves committed tombstone');
unlink($unique);
deletion_check(deletion_request('DELETE',$gtin,$delete)->get_status()===200,'cleanup failure retry succeeds');
$delete['version']=5; deletion_check(deletion_request('DELETE',$gtin,$delete)->get_status()===200,'newer deletion advances tombstone');
$delete['version']=4; deletion_check(deletion_request('DELETE',$gtin,$delete)->get_status()===409,'old deletion rejected');
wp_delete_post($native,true);
WP_CLI::success('Deletion suite complete');
