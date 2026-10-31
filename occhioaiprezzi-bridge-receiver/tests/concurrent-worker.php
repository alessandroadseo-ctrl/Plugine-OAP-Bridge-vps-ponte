<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME!=='oap_test' || wp_parse_url(home_url(),PHP_URL_HOST)!=='receiver.test') { throw new RuntimeException('Isolated tests only'); }
$mode=$args[0]??'put'; $db=new OAP_Storage(); $gtin='00000000000999';
if ($mode==='reset') { global $wpdb; foreach ([$gtin,'00000000000997'] as $g) { $wpdb->delete($db->slugs,['gtin'=>$g]); $wpdb->delete($db->pages,['gtin'=>$g]); } return; }
if ($mode==='hold') {
    $db->locked(function($db) { echo "LOCKED\n"; flush(); usleep(3000000); });
    echo "PASS: receiver installation window completed without inventory checks\n"; return;
}
if ($mode==='seed') { echo wp_insert_post(['post_title'=>'Concurrent metadata fixture','post_type'=>'product','post_status'=>'draft']); return; }
$standalone=$mode==='standalone';
if ($standalone && class_exists('WooCommerce')) { throw new RuntimeException('Skip WooCommerce for this test'); }
if ($standalone) { $gtin='00000000000997'; }
$slug=$standalone?'without-woocommerce':'concurrent-product'; $update=$mode==='update';
$p=['protocol'=>'oap-wp-1','operation'=>$update?'update':'create','gtin'=>$gtin,'slug'=>$slug,'version'=>$update?2:1,'content_hash'=>str_repeat($update?'e':'f',64),'html'=>$update?'<h1>Updated by VPS</h1>':'<h1>Concurrent</h1>',
'seo'=>['title'=>'Concurrent','canonical'=>OAP_Support::page_url($slug),'indexable'=>true,'modified_at'=>1700000000],
'state'=>'active','redirects'=>[],'removal_policy'=>'temporary'];
$result=OAP_Product_Pages::put($p,$gtin);
if ($standalone) {
    if ($result['operation']!=='created') { throw new RuntimeException('Standalone create failed'); }
    echo "PASS: receiver writes successfully without WooCommerce loaded\n";
} else { echo wp_json_encode($result)."\n"; }
