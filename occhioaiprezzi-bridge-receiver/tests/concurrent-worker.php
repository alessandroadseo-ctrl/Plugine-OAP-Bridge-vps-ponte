<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME!=='oap_test' || wp_parse_url(home_url(),PHP_URL_HOST)!=='receiver.test') { throw new RuntimeException('Isolated tests only'); }
$mode=$args[0]??'put'; $db=new OAP_Storage(); $gtin='00000000000999';
if ($mode==='reset') { global $wpdb; $wpdb->delete($db->slugs,['gtin'=>$gtin]); $wpdb->delete($db->pages,['gtin'=>$gtin]); return; }
if ($mode==='hold') {
    $db->locked(function($db) {
        echo "LOCKED\n"; flush();
        usleep(2000000);
        if (OAP_Eligibility::present($db,'00000000000998',OAP_Eligibility::keys())) { throw new RuntimeException('Woo write crossed lock'); }
    });
    echo "PASS: Woo writer excluded from installation window\n"; return;
}
if ($mode==='seed') { $p=new WC_Product_Simple(); $p->set_name('Concurrent WC'); echo $p->save(); return; }
if ($mode==='unavailable') {
    if (class_exists('WooCommerce')) { throw new RuntimeException('Skip WooCommerce for this test'); }
    try { OAP_Eligibility::keys(); throw new RuntimeException('Inventory incorrectly authorized'); }
    catch (OAP_Failure $e) { if ($e->status!==503) throw $e; echo "PASS: WooCommerce unavailable fails closed\n"; }
    return;
}
$p=['protocol'=>'oap-wp-1','gtin'=>$gtin,'slug'=>'concurrent-product','version'=>1,'content_hash'=>str_repeat('f',64),'html'=>'<h1>Concurrent</h1>',
'seo'=>['title'=>'Concurrent','canonical'=>OAP_Support::canonical('concurrent-product'),'indexable'=>true,'modified_at'=>1700000000],
'state'=>'active','redirects'=>[],'removal_policy'=>'temporary','eligibility_proof'=>OAP_Auth::proof($gtin)];
echo wp_json_encode(OAP_Product_Pages::put($p,$gtin))."\n";
