<?php
defined('ABSPATH') || exit;
final class OAP_Product_Pages {
    public static function validate(array $p, string $gtin): array {
        foreach (['protocol','gtin','slug','version','content_hash','html','seo','state','redirects','removal_policy','eligibility_proof'] as $key) {
            if (!array_key_exists($key, $p)) { throw new OAP_Failure('oap_missing_field'); }
        }
        if ($p['protocol'] !== 'oap-wp-1' || $p['gtin'] !== $gtin || !is_int($p['version']) || $p['version'] < 1
            || !is_string($p['content_hash']) || !preg_match('/\A[a-f0-9]{64}\z/', $p['content_hash'])
            || !in_array($p['state'], ['active','inactive'], true) || !in_array($p['removal_policy'], ['gone','temporary'], true)
            || !is_string($p['html']) || !is_array($p['seo']) || !is_array($p['redirects']) || !array_is_list($p['redirects']) || count($p['redirects']) > 500) {
            throw new OAP_Failure('oap_invalid_payload');
        }
        OAP_Support::slug($p['slug']);
        foreach ($p['redirects'] as $s) { OAP_Support::slug($s); }
        $p['redirects'] = array_values(array_unique($p['redirects']));
        OAP_Auth::verify($p['eligibility_proof'], $gtin);
        if (str_contains($p['html'], '<?')) { throw new OAP_Failure('oap_unsafe_html'); }
        $processor = new WP_HTML_Tag_Processor($p['html']);
        while ($processor->next_tag()) {
            if (in_array($processor->get_tag(), ['SCRIPT','STYLE','IFRAME','OBJECT','EMBED','SVG','MATH','FORM','INPUT','LINK','META','BASE'], true)
                || $processor->get_attribute_names_with_prefix('on')) { throw new OAP_Failure('oap_unsafe_html'); }
            foreach (['href', 'src'] as $attr) {
                $url = $processor->get_attribute($attr);
                if ($url !== null) { OAP_Support::url($url); }
            }
        }
        $allowed = [];
        foreach (['article','section','div','span','p','h1','h2','h3','strong','em','ul','ol','li','br','table','caption','thead','tbody','tr','th','td'] as $tag) {
            $allowed[$tag] = ['class'=>true];
        }
        $allowed['a'] = ['href'=>true,'rel'=>true,'title'=>true];
        $allowed['img'] = ['src'=>true,'alt'=>true,'loading'=>true,'width'=>true,'height'=>true];
        $p['html'] = wp_kses($p['html'], $allowed, ['http','https']);
        $seo = $p['seo'];
        if (!isset($seo['indexable'], $seo['modified_at']) || !is_bool($seo['indexable']) || !is_int($seo['modified_at']) || $seo['modified_at'] < 0
            || (isset($seo['gtin']) && $seo['gtin'] !== $gtin)) { throw new OAP_Failure('oap_invalid_seo'); }
        foreach (['title','description','canonical','h1','brand','image','category'] as $field) {
            if (isset($seo[$field]) && (!is_string($seo[$field]) || strlen($seo[$field]) > 4096)) { throw new OAP_Failure('oap_invalid_seo'); }
        }
        if ($p['state'] === 'active') {
            if (empty($seo['title']) || ($seo['canonical'] ?? '') !== OAP_Support::canonical($p['slug'])) { throw new OAP_Failure('oap_invalid_canonical'); }
            OAP_Support::url($seo['canonical']);
        }
        if (!empty($seo['image'])) { OAP_Support::url($seo['image']); }
        if (isset($seo['jsonld']) && (!is_array($seo['jsonld']) || array_is_list($seo['jsonld']))) { throw new OAP_Failure('oap_invalid_jsonld'); }
        if (isset($seo['jsonld'])) { self::validate_jsonld($seo['jsonld']); }
        unset($p['eligibility_proof']);
        return $p;
    }
    private static function validate_jsonld(array $node, int $depth = 0): void {
        if ($depth > 20) { throw new OAP_Failure('oap_invalid_jsonld'); }
        foreach ($node as $key => $value) {
            if (in_array($key, ['url','image','@context','availability'], true) && is_string($value)) { OAP_Support::url($value); }
            if (is_array($value)) { self::validate_jsonld($value, $depth + 1); }
        }
    }
    public static function ack(string $gtin, ?array $row, string $operation = 'managed'): array {
        if ($row && $row['owner'] !== 'oap-wp-1') { throw new OAP_Failure('oap_foreign_owner', 409); }
        return ['gtin'=>$gtin, 'version'=>$row ? (int)$row['version'] : 0, 'installed_hash'=>$row['installed_hash'] ?? '',
            'remote_id'=>$row ? 'oap:' . $gtin : '', 'ownership'=>$row ? 'bridge' : 'absent', 'operation'=>$row ? $operation : 'absent'];
    }
    public static function put(array $p, string $gtin): array {
        global $wpdb;
        if (OAP_Support::cfg('OAP_BRIDGE_WRITES_ENABLED', false) !== true) { throw new OAP_Failure('oap_writes_disabled', 503); }
        $proof = $p['eligibility_proof'] ?? null;
        $p = self::validate($p, $gtin); $keys = OAP_Eligibility::keys(); $db = new OAP_Storage();
        $result = $db->locked(static function ($db) use ($p, $gtin, $keys, $proof, $wpdb) {
            OAP_Auth::verify($proof, $gtin);
            $row = $db->page($gtin);
            self::ack($gtin, $row);
            if ($row && $p['version'] < (int)$row['version']) { throw new OAP_Failure('oap_remote_ahead', 409); }
            if ($row && $p['version'] === (int)$row['version']) {
                if (!hash_equals($row['installed_hash'], $p['content_hash'])) { throw new OAP_Failure('oap_version_conflict', 409); }
                return self::ack($gtin, $row, 'unchanged');
            }
            if (OAP_Eligibility::present($db, $gtin, $keys) && (!$row || $p['state'] === 'active')) { throw new OAP_Failure('oap_woocommerce_present', 409); }
            if ($row && hash_equals($row['installed_hash'], $p['content_hash'])) {
                $db->query($wpdb->prepare("UPDATE `{$db->pages}` SET version=%d WHERE gtin=%s", $p['version'], $gtin));
                $row['version'] = $p['version'];
                return self::ack($gtin, $row, 'cursor_advanced');
            }
            $slugs = array_values(array_unique(array_merge($p['redirects'], [$p['slug']], $row ? [$row['slug']] : [])));
            foreach ($slugs as $slug) {
                $claim = $db->one($wpdb->prepare("SELECT gtin FROM `{$db->slugs}` WHERE slug=%s", $slug));
                if ($claim && $claim['gtin'] !== $gtin) { throw new OAP_Failure('oap_slug_conflict', 409); }
                // Fail closed on native content; receiver never assumes ownership of posts.
                if ($db->one($wpdb->prepare("SELECT ID FROM `{$wpdb->posts}` WHERE post_name=%s AND post_type NOT IN ('revision','attachment') LIMIT 1", $slug))) {
                    throw new OAP_Failure('oap_foreign_page', 409);
                }
            }
            $artifact = OAP_Storage::artifact($p);
            foreach ($slugs as $slug) {
                $db->query($wpdb->prepare("INSERT INTO `{$db->slugs}` (slug,gtin) VALUES (%s,%s) ON DUPLICATE KEY UPDATE gtin=VALUES(gtin)", $slug, $gtin));
            }
            $db->query($wpdb->prepare("INSERT INTO `{$db->pages}` (gtin,owner,version,installed_hash,slug,artifact,state,indexable,modified_at)
                VALUES (%s,'oap-wp-1',%d,%s,%s,%s,%s,%d,%d) ON DUPLICATE KEY UPDATE version=VALUES(version),installed_hash=VALUES(installed_hash),
                slug=VALUES(slug),artifact=VALUES(artifact),state=VALUES(state),indexable=VALUES(indexable),modified_at=VALUES(modified_at)",
                $gtin,$p['version'],$p['content_hash'],$p['slug'],$artifact,$p['state'],($p['state']==='active' && $p['seo']['indexable'])?1:0,$p['seo']['modified_at']));
            return self::ack($gtin, $db->page($gtin), $row ? 'updated' : 'created');
        });
        if (in_array($result['operation'], ['created','updated'], true)) {
            // Integrate site-specific CDN invalidation without a full-catalog purge.
            do_action('oap_bridge_page_changed', $gtin, OAP_Support::canonical($p['slug']), $p['redirects']);
        }
        return $result;
    }
}
