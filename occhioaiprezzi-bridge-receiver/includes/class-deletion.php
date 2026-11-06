<?php
defined('ABSPATH') || exit;

/** Hard deletion only touches Bridge tables and content-addressed private files. */
final class OAP_Deletion {
    public static function ack(array $row): array {
        return ['gtin'=>$row['gtin'], 'version'=>(int)$row['version'], 'installed_hash'=>'',
            'remote_id'=>'oap:'.$row['gtin'], 'ownership'=>'bridge', 'operation'=>'deleted'];
    }

    public static function request($p, string $gtin, string $key): array {
        if (OAP_Support::cfg('OAP_BRIDGE_WRITES_ENABLED', false) !== true) { throw new OAP_Failure('oap_writes_disabled', 503); }
        if (!is_array($p) || count($p) !== 5 || ($p['protocol'] ?? '') !== 'oap-wp-1'
            || ($p['operation'] ?? '') !== 'delete' || ($p['gtin'] ?? '') !== $gtin
            || !is_int($p['version'] ?? null) || $p['version'] < 1 || !is_array($p['media_policy'] ?? null)
            || count($p['media_policy']) !== 2) { throw new OAP_Failure('oap_invalid_payload'); }
        foreach (['delete_products','protected_products'] as $field) {
            $list = $p['media_policy'][$field] ?? null;
            if (!is_array($list) || !array_is_list($list) || count($list) > 500) { throw new OAP_Failure('oap_invalid_media_policy'); }
            foreach ($list as $hash) {
                if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/', $hash)) { throw new OAP_Failure('oap_invalid_media_policy'); }
            }
        }
        if ($key !== 'delete:'.$gtin.':'.$p['version']) { throw new OAP_Failure('oap_invalid_idempotency_key'); }
        global $wpdb;
        $db = new OAP_Storage();
        $result = $db->locked(static function ($db) use ($gtin, $p, $wpdb) {
            $row = $db->page($gtin); OAP_Product_Pages::ack($gtin, $row);
            $old = $db->tombstone($gtin);
            $version = max((int)($row['version'] ?? 0), (int)($old['version'] ?? 0));
            if ($p['version'] < $version) { throw new OAP_Failure('oap_remote_ahead', 409); }
            if ($row && $p['version'] === $version) { throw new OAP_Failure('oap_version_conflict', 409); }
            // Never invent or claim a public URL for an unknown identity.
            if (!$row && !$old) { throw new OAP_Failure('oap_page_absent', 409); }
            $slug = $row['slug'] ?? $old['slug'];
            $db->query($wpdb->prepare("INSERT INTO `{$db->tombstones}` (gtin,version,slug) VALUES (%s,%d,%s)
                ON DUPLICATE KEY UPDATE version=VALUES(version)", $gtin, $p['version'], $slug));
            $db->query($wpdb->prepare("DELETE FROM `{$db->affiliates}` WHERE gtin=%s", $gtin));
            $db->query($wpdb->prepare("DELETE FROM `{$db->pages}` WHERE gtin=%s AND owner='oap-wp-1'", $gtin));
            return ['ack'=>self::ack($db->tombstone($gtin)), 'slug'=>$slug];
        });
        // Invalidate immediately after commit, even if file cleanup later fails.
        // Repeat on every retry, including after a lost ACK or partial purge.
        $claims = $db->rows($wpdb->prepare("SELECT slug FROM `{$db->slugs}` WHERE gtin=%s", $gtin));
        $urls = array_map(static fn($r) => OAP_Support::canonical($r['slug']), $claims);
        foreach ($db->rows("SELECT artifact FROM `{$db->pages}`") as $row) {
            $bundle = OAP_Storage::read($row['artifact']);
            if (self::canonical($db, $bundle['seo']['canonical'], $bundle['slug']) !== $bundle['seo']['canonical']) {
                $urls[] = OAP_Support::canonical($bundle['slug']);
            }
        }
        foreach ($p['media_policy']['delete_products'] as $hash) { $urls[] = OAP_Media::url('products', $hash); }
        // Deletion shifts pagination, so invalidate every affected sitemap chunk,
        // including the former last chunk when the total page count decreases.
        $count = $db->one("SELECT COUNT(*) AS n FROM `{$db->pages}` WHERE state='active' AND indexable=1 AND owner='oap-wp-1'");
        $urls[] = home_url('/wp-sitemap.xml');
        for ($page=1; $page <= (int)ceil((int)$count['n']/1000)+1; $page++) { $urls[] = home_url('/wp-sitemap-oap-'.$page.'.xml'); }
        foreach (array_unique($urls) as $url) {
            self::purge($url);
            if (str_ends_with($url, '/')) { self::purge(rtrim($url, '/')); }
        }
        do_action('oap_bridge_page_changed', $gtin, OAP_Support::canonical($result['slug']), array_column($claims, 'slug'));
        // Commit BEFORE unlinking. Cleanup is retryable after a crash; publication
        // and deletion transactions serialize with this reference check.
        $db->locked(static function ($db) use ($gtin, $p) {
            self::cleanup($db, $gtin, $p['media_policy']);
        });
        return $result['ack'];
    }

    /** Prevent existing cross-product canonicals from pointing at a deleted URL. */
    public static function canonical(OAP_Storage $db, string $url, string $slug): string {
        global $wpdb;
        $base = wp_parse_url(OAP_Support::base()); $parsed = wp_parse_url($url);
        $prefix = rtrim($base['path'], '/').'/';
        if ($parsed && strcasecmp($parsed['host'] ?? '', $base['host']) === 0
            && str_starts_with($parsed['path'] ?? '', $prefix)) {
            $target = trim(rawurldecode(substr($parsed['path'], strlen($prefix))), '/');
            $claim = $db->one($wpdb->prepare("SELECT gtin FROM `{$db->slugs}` WHERE slug=%s", $target));
            if ($claim && $db->tombstone($claim['gtin'])) { return OAP_Support::canonical($slug); }
        }
        return $url;
    }

    private static function purge(string $url): void {
        do_action('oap_bridge_purge_url', $url);
        do_action('litespeed_purge_url', $url);
        if (function_exists('rocket_clean_files')) { rocket_clean_files([$url]); }
        if (function_exists('w3tc_flush_url')) { w3tc_flush_url($url); }
    }

    private static function unlink(string $file): void {
        if (is_link($file)) { throw new OAP_Failure('oap_storage_unavailable', 503); }
        if (is_file($file) && !@unlink($file)) { throw new OAP_Failure('oap_storage_unavailable', 503); }
    }

    private static function cleanup(OAP_Storage $db, string $gtin, array $policy): void {
        global $wpdb;
        $dir = OAP_Storage::directory(); $live = []; $references = '';
        foreach ($db->rows("SELECT artifact FROM `{$db->pages}`") as $row) {
            $live[$row['artifact']] = true;
            $references .= OAP_Support::json(OAP_Storage::read($row['artifact']));
        }
        $garbage = []; $owned = '';
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $hash = basename($file, '.json');
            if (isset($live[$hash]) || !preg_match('/\A[a-f0-9]{64}\z/', $hash) || is_link($file)) { continue; }
            $data = OAP_Storage::read($hash);
            if (($data['protocol'] ?? '') === 'oap-wp-1' && ($data['gtin'] ?? '') === $gtin) {
                $garbage[] = $file; $owned .= OAP_Support::json($data);
            }
        }
        // Explicit VPS2 allowlist; protections override deletion, merchants are never
        // deleted. Hash references in native posts/meta also protect reused assets.
        foreach (array_diff($policy['delete_products'], $policy['protected_products']) as $hash) {
            if (!str_contains($owned, $hash) || str_contains($references, $hash)) { continue; }
            $like = '%'.$wpdb->esc_like($hash).'%';
            if ($db->one($wpdb->prepare("SELECT ID FROM `{$wpdb->posts}` WHERE post_content LIKE %s OR guid LIKE %s LIMIT 1", $like, $like))
                || $db->one($wpdb->prepare("SELECT meta_id FROM `{$wpdb->postmeta}` WHERE meta_value LIKE %s LIMIT 1", $like))) { continue; }
            if (is_link($dir.'/media') || is_link($dir.'/media/products')) { throw new OAP_Failure('oap_storage_unavailable', 503); }
            self::unlink($dir.'/media/products/'.$hash.'.png');
        }
        foreach ($garbage as $file) { self::unlink($file); }
    }
}
