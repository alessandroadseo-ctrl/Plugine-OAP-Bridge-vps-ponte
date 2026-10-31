<?php
// Only the disposable WordPress test DB. The test suite may load real WooCommerce fixtures.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(),PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
function check($ok, $name) { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } WP_CLI::log('PASS: '.$name); }
function req($method,$path,$data=null,$token=null) {
    $r = new WP_REST_Request($method,'/oap-bridge/v1/'.$path);
    $r->set_header('Authorization','Bearer '.($token??OAP_BRIDGE_TOKEN));
    if ($data!==null) { $r->set_header('Content-Type','application/json'); $r->set_header('Idempotency-Key',($data['content_hash']??'').':'.($data['version']??'')); $r->set_body(wp_json_encode($data)); }
    return rest_do_request($r);
}
function bundle($gtin,$slug='test-prodotto',$version=1,$hash='a',$operation='create') {
    return ['protocol'=>'oap-wp-1','operation'=>$operation,'gtin'=>$gtin,'slug'=>$slug,'version'=>$version,'content_hash'=>str_repeat($hash,64),
    'html'=>"<article><h1>Titolo scelto dalla VPS</h1>\n<p>Offerta VPS</p><a href=\"https://shop.test/item\" rel=\"sponsored nofollow\">Acquista</a></article>",
    'seo'=>['title'=>'Titolo SEO VPS','description'=>'Descrizione VPS','canonical'=>OAP_Support::page_url($slug),'h1'=>'Titolo scelto dalla VPS','gtin'=>$gtin,'indexable'=>true,'modified_at'=>1700000000,'jsonld'=>['@context'=>'https://schema.org','@type'=>'Product','name'=>'</script><script>alert(1)</script>']],
    'state'=>'active','redirects'=>[],'removal_policy'=>'temporary'];
}
global $wpdb;
$db=new OAP_Storage(); $wpdb->query("DELETE FROM {$db->slugs}"); $wpdb->query("DELETE FROM {$db->pages}");
$gtin='00012345678905';
check(req('GET',"pages/$gtin")->get_data()['version']===0,'GET reports absence without choosing an operation');
$r=req('GET',"gtins/$gtin/eligibility"); check($r->get_status()===410 && $r->get_data()['code']==='oap_eligibility_moved_to_vps','retired eligibility never fabricates permission');
$p=bundle($gtin); $missing=$p; unset($missing['operation']); check(req('PUT',"pages/$gtin",$missing)->get_status()===400,'missing VPS operation rejected');
$bad=$p; $bad['operation']='upsert'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'automatic upsert is not allowed');
$bad=$p; $bad['operation']='update'; check(req('PUT',"pages/$gtin",$bad)->get_data()['code']==='oap_page_absent' && !$db->page($gtin),'update cannot create an absent page');
$r=req('PUT',"pages/$gtin",$p); check($r->get_status()===201 && $r->get_data()['operation']==='created','explicit create without eligibility proof');
$legacy=$p; $legacy['eligibility_proof']='expired-or-another-gtin';
check(req('PUT',"pages/$gtin",$legacy)->get_status()===200,'legacy proof is inert and never gates a VPS command');
$id=$r->get_data()['remote_id']; $artifact=$db->page($gtin)['artifact']; $mtime=filemtime(OAP_Storage::directory()."/$artifact.json");
$saved=OAP_Storage::read($artifact); check($saved['html']===$p['html'] && $saved['seo']===$p['seo'],'HTML bytes and SEO values stored unchanged');
$r=req('PUT',"pages/$gtin",$p); check($r->get_status()===200 && $r->get_data()['operation']==='unchanged','create retry after lost ACK is idempotent');
check($db->page($gtin)['artifact']===$artifact && filemtime(OAP_Storage::directory()."/$artifact.json")===$mtime,'retry preserves artifact and lastmod');
$conflict=$p; $conflict['content_hash']=str_repeat('b',64); check(req('PUT',"pages/$gtin",$conflict)->get_status()===409,'same version different hash');
$bad=$p; $bad['version']=2; check(req('PUT',"pages/$gtin",$bad)->get_data()['code']==='oap_page_exists' && (int)$db->page($gtin)['version']===1,'create cannot overwrite an existing identity');
$p=bundle($gtin,'nuovo-prodotto',2,'b','update'); $p['redirects']=['test-prodotto'];
$p['seo']['canonical']='https://receiver.test/canonical-deciso-dalla-vps/';
$r=req('PUT',"pages/$gtin",$p); check($r->get_status()===200 && $r->get_data()['remote_id']===$id && $r->get_data()['operation']==='updated','explicit update keeps the same remote identity');
check(OAP_Storage::read($db->page($gtin)['artifact'])['seo']['canonical']===$p['seo']['canonical'],'canonical is not regenerated from slug');
$old=$p; $old['version']=1; check(req('PUT',"pages/$gtin",$old)->get_data()['code']==='oap_remote_ahead','obsolete version');
$cursor=$p; $cursor['version']=3; $before=$db->page($gtin)['artifact']; check(req('PUT',"pages/$gtin",$cursor)->get_data()['operation']==='cursor_advanced' && $db->page($gtin)['artifact']===$before,'same hash advances only version cursor');
$wpdb->update($db->pages,['owner'=>'other'],['gtin'=>$gtin]); $p['version']=4;
check(req('PUT',"pages/$gtin",$p)->get_data()['code']==='oap_foreign_owner','foreign owner rejected'); $wpdb->update($db->pages,['owner'=>'oap-wp-1'],['gtin'=>$gtin]);
check(req('GET',"pages/$gtin",null,'wrong')->get_status()===401,'wrong token');
$r=new WP_REST_Request('GET',"/oap-bridge/v1/pages/$gtin"); check(rest_do_request($r)->get_status()===401,'missing token');
unset($_SERVER['HTTPS']); check(req('GET',"pages/$gtin")->get_status()===403,'HTTP rejected'); $_SERVER['HTTPS']='on';
$bad=$p; $bad['html']='<script>alert(1)</script>'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'script HTML rejected');
$bad=$p; $bad['html']='<img src="https://image.test/x" onerror="alert(1)">'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'event handler rejected');
$bad=$p; $bad['html']='<h1 style="color:red">Rewrite me</h1>'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'unsafe markup is rejected instead of rewritten');
$bad=$p; $bad['html']='<a href="javascript:alert(1)">x</a>'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'unsafe URL rejected');
$bad=$p; $bad['seo']['canonical']='javascript:alert(1)'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'unsafe SEO URL rejected');
$bad=$p; $bad['redirects']=['https://evil.test']; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'open redirect rejected');
$bad=$p; $bad['html']=str_repeat('x',1048577); check(req('PUT',"pages/$gtin",$bad)->get_status()===413,'request size limit');
$native=wp_insert_post(['post_title'=>'Foreign','post_name'=>'native-page','post_type'=>'page','post_status'=>'publish']);
$bad=bundle('00000000000003','native-page'); check(req('PUT','pages/00000000000003',$bad)->get_data()['code']==='oap_foreign_page' && get_post($native)->post_title==='Foreign','native page ownership protected'); wp_delete_post($native,true);
$bad=bundle('00000000000004','test-prodotto'); check(req('PUT','pages/00000000000004',$bad)->get_data()['code']==='oap_slug_conflict','global historical alias cannot be taken by another GTIN');
require_once WP_PLUGIN_DIR.'/occhioaiprezzi-bridge-receiver/includes/class-sitemap.php'; $site=new OAP_Sitemap();
check(count($site->get_url_list(1))===1 && $site->get_url_list(1)[0]['loc']===$p['seo']['canonical'] && $site->get_url_list(1)[0]['lastmod']==='2023-11-14T22:13:20Z','sitemap uses received canonical and bundle lastmod');
$p=bundle($gtin,'nuovo-prodotto',4,'c','update'); $p['state']='inactive'; $p['html']='<h1>Messaggio di sospensione VPS</h1>'; $p['seo']=['title'=>'Sospeso dalla VPS','gtin'=>$gtin,'indexable'=>false,'modified_at'=>1700000001];
check(req('PUT',"pages/$gtin",$p)->get_status()===200 && OAP_Storage::read($db->page($gtin)['artifact'])['html']===$p['html'],'inactive applies the content received from VPS'); check($site->get_url_list(1)===[],'inactive state excluded from sitemap');
$bad=$p; $bad['seo']['indexable']=true; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'contradictory inactive/indexable payload rejected structurally');
$p=bundle($gtin,'nuovo-prodotto',5,'d','update'); $p['seo']['indexable']=false; check(req('PUT',"pages/$gtin",$p)->get_status()===200 && $site->get_url_list(1)===[],'VPS controls indexable even for active pages');
// Real Woo fixtures exist solely to prove the receiver makes no inventory decisions.
if (class_exists('WooCommerce')) {
    $product=new WC_Product_Simple(); $product->set_name('Woo product'); $pid=$product->save(); update_post_meta($pid,'_global_unique_id',$gtin);
    $parent=new WC_Product_Variable(); $parent->set_name('Variable'); $parentid=$parent->save();
    $variation=new WC_Product_Variation(); $variation->set_parent_id($parentid); $vid=$variation->save(); update_post_meta($vid,'_global_unique_id','00012345678907');
    $p=bundle($gtin,'nuovo-prodotto',6,'e','update');
    check(req('PUT',"pages/$gtin",$p)->get_status()===200 && count($site->get_url_list(1))===1,'Woo GTIN cannot veto VPS update or sitemap');
    $p=bundle('00012345678907','vps-authorized-variation'); check(req('PUT','pages/00012345678907',$p)->get_status()===201,'Woo variation cannot veto VPS create');
    check(get_post_meta($pid,'_global_unique_id',true)===$gtin && get_post_meta($vid,'_global_unique_id',true)==='00012345678907','receiver does not mutate Woo metadata');
    wp_delete_post($vid,true); wp_delete_post($parentid,true); wp_delete_post($pid,true);
}
check(!str_contains(OAP_Support::json(['name'=>'</script>']),'</script>'),'JSON-LD script boundary escaped');
check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$db->pages} WHERE gtin='$gtin'")===1,'one identity per GTIN');
WP_CLI::success('Receiver-only integration suite complete');
