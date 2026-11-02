<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
function admin_check($ok, $name) { if (!$ok) { throw new RuntimeException('FAIL: ' . $name); } WP_CLI::log('PASS: ' . $name); }
function admin_output(array $query): string { $_GET = $query; ob_start(); OAP_Admin::render(); return ob_get_clean(); }
global $wpdb;
$db = new OAP_Storage();
$before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$original = $db->rows("SELECT * FROM `{$db->pages}` ORDER BY gtin");
$fixtures = [];
try {
    for ($i=1; $i<=25; ++$i) {
        $gtin = '9999999999' . sprintf('%04d', $i); $fixtures[] = $gtin;
        $doc = ['html'=>'<h1>Preview</h1><script>alert(1)</script><img src="https://outside.test/x" onerror="alert(2)">', 'seo'=>['h1'=>'Catalogo Prodotto ' . $i . ' <script>bad</script>', 'title'=>'SEO', 'canonical'=>'https://different.test/canonical']];
        $hash = OAP_Storage::artifact($doc);
        $wpdb->insert($db->pages, ['gtin'=>$gtin,'owner'=>'oap-wp-1','version'=>42,'installed_hash'=>str_repeat('a',64),'slug'=>'admin-prodotto-'.$i,'artifact'=>$hash,'state'=>$i%2 ? 'active':'inactive','indexable'=>0,'modified_at'=>1700000000]);
    }
    $first = OAP_Admin::listing('Catalogo', 1); $second = OAP_Admin::listing('Catalogo', 2);
    admin_check($first['matched']===25 && count($first['items'])===20 && count($second['items'])===5, 'pagination and title search across artifacts');
    admin_check(!array_intersect(array_column($first['items'],'gtin'), array_column($second['items'],'gtin')), 'pages do not overlap');
    admin_check(OAP_Admin::listing('99999999990025', 99)['page']===1, 'GTIN search clamps out of range page');
    admin_check(OAP_Admin::listing("%' OR 1=1 --", 1)['matched']===0, 'search metacharacters are literal');
    admin_check(OAP_Admin::listing('no-match', -2)['items']===[], 'empty search results');
    $admin = get_user_by('login','oap_test_admin'); wp_set_current_user($admin->ID);
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    global $menu, $submenu; $menu=[]; $submenu=[]; OAP_Admin::register();
    admin_check($submenu[OAP_Admin::PAGE][0][0]==='Prodotti' && $submenu[OAP_Admin::PAGE][0][1]==='manage_options', 'administrator menu and submenu');
    $out = admin_output(['s'=>'Catalogo','paged'=>2]);
    admin_check(str_contains($out,'Attive: ' . $first['counts']['active']) && str_contains($out,'Disattivate: ' . $first['counts']['inactive']), 'state counters');
    admin_check(str_contains($out,'s=Catalogo') && str_contains($out,'Pagina 2 di 2'), 'search persists in pagination');
    $out = admin_output(['gtin'=>$fixtures[0]]);
    admin_check(str_contains($out,esc_url(OAP_Support::canonical('admin-prodotto-1'))) && !str_contains($out,'href="https://different.test'), 'public link uses installed route instead of SEO canonical');
    admin_check(str_contains($out,'&lt;script&gt;bad&lt;/script&gt;') && !str_contains($out,'<script>'), 'metadata and titles escaped');
    admin_check(str_contains($out,'sandbox=""') && str_contains($out,'default-src') && !str_contains($out,'allow-scripts'), 'preview isolated and resource loads blocked');
    admin_check(str_contains($out,esc_html('"version": "42"')) && !str_contains($out,'name="action"'), 'current index version and no mutation controls');
    admin_check(str_contains(admin_output(['gtin'=>'99999999999999']), 'Pagina non trovata'), 'missing detail');
    $wpdb->update($db->pages,['artifact'=>str_repeat('0',64)],['gtin'=>$fixtures[0]]);
    admin_check(str_contains(admin_output(['gtin'=>$fixtures[0]]),'Artefatto non disponibile'), 'unreadable artifact handled');
    $wpdb->update($db->pages,['owner'=>'foreign'],['gtin'=>$fixtures[0]]);
    admin_check(str_contains(admin_output(['gtin'=>$fixtures[0]]),'Pagina non trovata'), 'foreign owner hidden');
    add_filter('wp_die_handler', static function () { return static function ($message, $title, $args) { throw new RuntimeException('denied:' . $args['response']); }; });
    foreach (['editor','subscriber',null] as $role) {
        $user = $role ? self_dummy_user($role) : 0; wp_set_current_user($user);
        foreach ([[], ['gtin'=>$fixtures[1]]] as $query) {
            try { admin_output($query); throw new RuntimeException('access granted'); }
            catch (RuntimeException $e) { if (ob_get_level()) { ob_end_clean(); } admin_check($e->getMessage()==='denied:403', 'list/detail denied for ' . ($role ?? 'anonymous')); }
        }
        if ($user) { wp_delete_user($user); }
    }
    admin_check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}")===$before, 'no wp_posts duplication');
} finally {
    foreach ($fixtures as $gtin) { $wpdb->delete($db->pages,['gtin'=>$gtin]); }
    $_GET=[];
}
admin_check($db->rows("SELECT * FROM `{$db->pages}` ORDER BY gtin")===$original, 'existing receiver rows unchanged');
WP_CLI::success('Admin suite complete');
function self_dummy_user($role) { $id=wp_insert_user(['user_login'=>'oap_admin_test_'.$role,'user_pass'=>wp_generate_password(),'role'=>$role]); if (is_wp_error($id)) { throw new RuntimeException('fixture user'); } return $id; }
