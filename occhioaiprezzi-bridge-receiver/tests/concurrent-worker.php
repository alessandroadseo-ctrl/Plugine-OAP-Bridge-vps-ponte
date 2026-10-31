<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME!=='oap_test' || wp_parse_url(home_url(),PHP_URL_HOST)!=='receiver.test') { throw new RuntimeException('Isolated tests only'); }
$mode=$args[0]??'put'; $db=new OAP_Storage(); $gtin='00000000000999';
if ($mode==='reset') { global $wpdb; $wpdb->delete($db->slugs,['gtin'=>$gtin]); $wpdb->delete($db->pages,['gtin'=>$gtin]); $wpdb->delete($db->slugs,['gtin'=>'00000000000997']); $wpdb->delete($db->pages,['gtin'=>'00000000000997']); return; }
$p=['protocol'=>'oap-wp-1','gtin'=>$gtin,'slug'=>'concurrent-product','version'=>1,'content_hash'=>str_repeat('f',64),'html'=>'<h1>Concurrent</h1>',
'seo'=>['title'=>'Concurrent','canonical'=>OAP_Support::canonical('concurrent-product'),'indexable'=>true,'modified_at'=>1700000000],
'state'=>'active','redirects'=>[],'removal_policy'=>'temporary','operation'=>'create'];
if ($mode === 'slug-race') { $gtin='00000000000997'; $p['gtin']=$gtin; }
if ($mode === 'conflict') { $p['slug']='competing-product'; $p['content_hash']=str_repeat('e',64); }
try { echo wp_json_encode(OAP_Product_Pages::put($p,$gtin))."\n"; }
catch (OAP_Failure $e) { echo wp_json_encode(['error'=>$e->getMessage(),'status'=>$e->status])."\n"; }

