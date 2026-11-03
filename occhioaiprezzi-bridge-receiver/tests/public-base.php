<?php
// Fixture for public_base.py; only the disposable WordPress test installation.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'receiver.test') {
    throw new RuntimeException('Isolated tests only');
}
global $wpdb;
$db = new OAP_Storage();
$gtin = '00000000000991';
$mode = $args[0] ?? 'inspect';
if ($mode === 'create') {
    $parent = wp_insert_post(['post_title'=>'Route root', 'post_name'=>'prodotti', 'post_type'=>'page', 'post_status'=>'publish']);
    $child = wp_insert_post(['post_title'=>'Native route fixture', 'post_name'=>'native-route-fixture', 'post_parent'=>$parent, 'post_type'=>'page', 'post_status'=>'publish']);
    update_option('oap_public_base_test_posts', [$parent, $child]);
    OAP_Product_Pages::put([
        'protocol'=>'oap-wp-1', 'operation'=>'create', 'gtin'=>$gtin,
        'slug'=>'published-before-base-change', 'version'=>1, 'content_hash'=>str_repeat('9',64),
        'html'=>'<article><h1>Existing product fixture</h1><a href="https://receiver.test/compara?id=991">Offerta</a></article>',
        'seo'=>['title'=>'Existing product fixture', 'description'=>'Unchanged VPS document',
            'canonical'=>OAP_Support::canonical('published-before-base-change'), 'indexable'=>true, 'modified_at'=>1700000000],
        'state'=>'active', 'redirects'=>[], 'removal_policy'=>'temporary',
    ], $gtin);
} elseif ($mode === 'cleanup') {
    $wpdb->delete($db->slugs, ['gtin'=>$gtin]);
    $wpdb->delete($db->pages, ['gtin'=>$gtin]);
    foreach (get_option('oap_public_base_test_posts', []) as $id) { wp_delete_post($id, true); }
    delete_option('oap_public_base_test_posts');
    return;
}
$row = $db->page($gtin);
echo wp_json_encode(['row'=>$row, 'bundle'=>OAP_Storage::read($row['artifact']),
    'claims'=>$db->rows($wpdb->prepare("SELECT * FROM `{$db->slugs}` WHERE gtin=%s ORDER BY slug", $gtin)),
    'posts'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}")]);
