<?php
// Run with WP-CLI eval-file against a disposable WordPress + WooCommerce DB only.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(),PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
function check($ok, $name) { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } WP_CLI::log('PASS: '.$name); }
function req($method,$path,$data=null,$token=null) {
    $r = new WP_REST_Request($method,'/oap-bridge/v1/'.$path);
    $r->set_header('Authorization','Bearer '.($token??OAP_BRIDGE_TOKEN));
    if ($data!==null) { $r->set_header('Content-Type','application/json'); $r->set_header('Idempotency-Key',($data['content_hash']??'').':'.($data['version']??'')); $r->set_body(wp_json_encode($data)); }
    return rest_do_request($r);
}
function bundle($gtin,$slug='test-prodotto',$version=1,$hash='a') {
    $e=req('GET',"gtins/$gtin/eligibility")->get_data();
    return ['protocol'=>'oap-wp-1','gtin'=>$gtin,'slug'=>$slug,'version'=>$version,'content_hash'=>str_repeat($hash,64),
    'html'=>'<article><h1>Prodotto</h1><p>Offerta</p><a href="https://shop.test/item" rel="sponsored nofollow">Acquista</a></article>',
    'seo'=>['title'=>'Titolo SEO','description'=>'Descrizione','canonical'=>OAP_Support::canonical($slug),'h1'=>'Prodotto','gtin'=>$gtin,'indexable'=>true,'modified_at'=>1700000000,'jsonld'=>['@context'=>'https://schema.org','@type'=>'Product','name'=>'</script><script>alert(1)</script>']],
    'state'=>'active','redirects'=>[],'removal_policy'=>'temporary','eligibility_proof'=>$e['proof']??''];
}
global $wpdb;
$db=new OAP_Storage();
$wpdb->query("DELETE FROM {$db->slugs}"); $wpdb->query("DELETE FROM {$db->pages}");
$gtin='00012345678905';
check(class_exists('WooCommerce'),'real WooCommerce loaded');
check(req('GET',"pages/$gtin")->get_data()['version']===0,'absent page reconciliation');
$p=bundle($gtin); $r=req('PUT',"pages/$gtin",$p); check($r->get_status()===201,'new product creation');
$id=$r->get_data()['remote_id']; $artifact=$db->page($gtin)['artifact']; $mtime=filemtime(OAP_Storage::directory()."/$artifact.json");
$r=req('PUT',"pages/$gtin",$p); check($r->get_status()===200 && $r->get_data()['operation']==='unchanged','identical retry after lost ACK');
check($db->page($gtin)['artifact']===$artifact && filemtime(OAP_Storage::directory()."/$artifact.json")===$mtime,'retry preserves artifact and lastmod');
$conflict=$p; $conflict['content_hash']=str_repeat('b',64); check(req('PUT',"pages/$gtin",$conflict)->get_status()===409,'same version different hash');
$p=bundle($gtin,'nuovo-prodotto',2,'b'); $p['redirects']=['test-prodotto'];
$r=req('PUT',"pages/$gtin",$p); check($r->get_status()===200 && $r->get_data()['remote_id']===$id && $r->get_data()['operation']==='updated','update same remote identity');
$old=$p; $old['version']=1; check(req('PUT',"pages/$gtin",$old)->get_data()['code']==='oap_remote_ahead','obsolete version');
$cursor=$p; $cursor['version']=3; $before=$db->page($gtin)['artifact']; check(req('PUT',"pages/$gtin",$cursor)->get_data()['operation']==='cursor_advanced' && $db->page($gtin)['artifact']===$before,'cursor only no public change');
$wpdb->update($db->pages,['owner'=>'other'],['gtin'=>$gtin]); $p['version']=4;
check(req('PUT',"pages/$gtin",$p)->get_data()['code']==='oap_foreign_owner','foreign owner rejected'); $wpdb->update($db->pages,['owner'=>'oap-wp-1'],['gtin'=>$gtin]);
check(req('GET',"pages/$gtin",null,'wrong')->get_status()===401,'wrong token');
$r=new WP_REST_Request('GET',"/oap-bridge/v1/pages/$gtin"); check(rest_do_request($r)->get_status()===401,'missing token');
unset($_SERVER['HTTPS']); check(req('GET',"pages/$gtin")->get_status()===403,'HTTP rejected'); $_SERVER['HTTPS']='on';
$bad=$p; $bad['eligibility_proof']=OAP_Auth::proof($gtin,time()-1); check(req('PUT',"pages/$gtin",$bad)->get_status()===403,'expired proof');
$bad['eligibility_proof']=OAP_Auth::proof('00000000000002'); check(req('PUT',"pages/$gtin",$bad)->get_status()===403,'other GTIN proof');
$bad=$p; $bad['html']='<script>alert(1)</script>'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'script HTML rejected');
$bad=$p; $bad['html']='<img src="https://image.test/x" onerror="alert(1)">'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'event handler rejected');
$bad=$p; $bad['html']='<a href="javascript:alert(1)">x</a>'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'unsafe URL rejected');
$bad=$p; $bad['seo']['canonical']='https://evil.test/path/'; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'external canonical rejected');
$bad=$p; $bad['redirects']=['https://evil.test']; check(req('PUT',"pages/$gtin",$bad)->get_status()===400,'open redirect rejected');
$bad=$p; $bad['html']=str_repeat('x',1048577); check(req('PUT',"pages/$gtin",$bad)->get_status()===413,'request size limit');
$native=wp_insert_post(['post_title'=>'Foreign','post_name'=>'native-page','post_type'=>'page','post_status'=>'publish']);
$bad=bundle('00000000000003','native-page'); check(req('PUT','pages/00000000000003',$bad)->get_data()['code']==='oap_foreign_page','native page ownership protected'); wp_delete_post($native,true);
$bad=bundle('00000000000004','test-prodotto'); check(req('PUT','pages/00000000000004',$bad)->get_data()['code']==='oap_slug_conflict','global historical alias protected');
$product=new WC_Product_Simple(); $product->set_name('Woo product'); $pid=$product->save(); update_post_meta($pid,'_test_gtin','12345678906');
$wc='00012345678906'; check(req('GET',"gtins/$wc/eligibility")->get_data()['woocommerce_present']===true,'Woo simple GTIN normalized');
$bad=bundle($wc,'woo-duplicate'); $bad['eligibility_proof']=OAP_Auth::proof($wc); check(req('PUT',"pages/$wc",$bad)->get_data()['code']==='oap_woocommerce_present','forged eligibility cannot bypass authoritative recheck');
$parent=new WC_Product_Variable(); $parent->set_name('Variable'); $parentid=$parent->save(); $variation=new WC_Product_Variation(); $variation->set_parent_id($parentid); $vid=$variation->save(); update_post_meta($vid,'_test_gtin','00012345678907');
check(req('GET','gtins/00012345678907/eligibility')->get_data()['woocommerce_present']===true,'Woo variation GTIN');
$race=bundle('00012345678908','woo-race'); update_post_meta($pid,'_test_gtin','00012345678908'); check(req('PUT','pages/00012345678908',$race)->get_data()['code']==='oap_woocommerce_present','Woo added after preliminary eligibility');
require_once WP_PLUGIN_DIR.'/occhioaiprezzi-bridge-receiver/includes/class-sitemap.php'; $site=new OAP_Sitemap();
check(count($site->get_url_list(1))===1 && $site->get_url_list(1)[0]['lastmod']==='2023-11-14T22:13:20Z','sitemap canonical and bundle lastmod');
$p=bundle($gtin,'nuovo-prodotto',4,'c'); $p['state']='inactive'; $p['html']=''; $p['seo']=['gtin'=>$gtin,'indexable'=>false,'modified_at'=>1700000001];
check(req('PUT',"pages/$gtin",$p)->get_status()===200,'inactive temporary tombstone'); check($site->get_url_list(1)===[],'inactive excluded from sitemap');
$p=bundle($gtin,'nuovo-prodotto',5,'d'); check(req('PUT',"pages/$gtin",$p)->get_status()===200,'reactivation greater version');
update_post_meta($pid,'_test_gtin',$gtin); check(!OAP_SEO::public_row($db->page($gtin)) && $site->get_url_list(1)===[],'later Woo GTIN suppresses public page and sitemap');
wp_delete_post($vid,true); wp_delete_post($parentid,true); wp_delete_post($pid,true);
check(OAP_SEO::public_row($db->page($gtin)),'Woo removal restores eligibility');
check(!str_contains(OAP_Support::json(['name'=>'</script>']),'</script>'),'JSON-LD script boundary escaped');
check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$db->pages} WHERE gtin='$gtin'")===1,'one identity per GTIN');
WP_CLI::success('Integration suite complete');
