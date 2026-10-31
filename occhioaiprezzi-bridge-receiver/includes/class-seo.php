<?php
defined('ABSPATH') || exit;
final class OAP_SEO {
    public static function serve(): void {
        // An invalid bridge configuration must never turn unrelated WP pages into 503s.
        $candidate = wp_parse_url((string)OAP_Support::cfg('OAP_BRIDGE_PUBLIC_BASE', home_url('/prodotti-ponte')), PHP_URL_PATH);
        $requested = wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!$candidate || $candidate === '/' || !is_string($requested) || !str_starts_with($requested, rtrim($candidate,'/') . '/')) { return; }
        try {
            $basepath = wp_parse_url(OAP_Support::base(), PHP_URL_PATH);
            $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (!is_string($path) || !str_starts_with($path, rtrim($basepath, '/') . '/')) { return; }
            if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) { status_header(405); header('Allow: GET, HEAD'); exit; }
            $slug = trim(substr($path, strlen($basepath)), '/'); OAP_Support::slug($slug);
            global $wpdb;
            $db = new OAP_Storage();
            $claim = $db->one($wpdb->prepare("SELECT gtin FROM `{$db->slugs}` WHERE slug=%s", $slug));
            if (!$claim) { return; }
            $row = $db->page($claim['gtin']);
            if (!$row || $row['owner'] !== 'oap-wp-1') { return; }
            $bundle = OAP_Storage::read($row['artifact']);
            $active = $row['state'] === 'active';
            $gone = !$active && $row['state'] === 'inactive' && $bundle['removal_policy'] === 'gone';
            if ($slug !== $row['slug'] && !in_array($slug, $bundle['redirects'], true)) {
                status_header(404); nocache_headers(); header('X-Robots-Tag: noindex'); exit;
            }
            // Tombstone risolto direttamente su qualunque alias: niente catene.
            if (!$gone && ($slug !== $row['slug'] || $path !== $basepath . '/' . $slug . '/')) {
                wp_safe_redirect(OAP_Support::page_url($row['slug']), 301, 'OAP Bridge'); exit;
            }
            status_header($gone ? 410 : 200);
            nocache_headers(); // Escludere anche reverse proxy/CDN dalla cache di queste rotte.
            header('Content-Type: text/html; charset=UTF-8');
            header("Content-Security-Policy: default-src 'none'; img-src https: http:; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
            header('X-Content-Type-Options: nosniff');
            $indexable = $active && (bool)$row['indexable'];
            header('X-Robots-Tag: ' . ($indexable ? 'index, follow' : 'noindex, nofollow'));
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') { exit; }
            // Documento nativo autonomo: non esegue wp_head/the_content/shortcode;
            // nessuna duplicazione di canonical o schema da tema/plugin SEO.
            $seo = $bundle['seo'];
            echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
            if (isset($seo['title'])) { echo '<title>' . esc_html($seo['title']) . '</title>'; }
            echo '<meta name="robots" content="' . ($indexable ? 'index,follow' : 'noindex,nofollow') . '">';
            if (isset($seo['description'])) { echo '<meta name="description" content="' . esc_attr($seo['description']) . '">'; }
            if (isset($seo['canonical'])) { echo '<link rel="canonical" href="' . esc_url($seo['canonical']) . '">'; }
            if (!empty($seo['jsonld'])) {
                echo '<script type="application/ld+json">' . OAP_Support::json($seo['jsonld']) . '</script>';
            }
            echo '<link rel="stylesheet" href="' . esc_url(plugins_url('../assets/oap-1.css', __FILE__)) . '"></head><body><main>';
            echo $bundle['html']; // Includes inactive content exactly as prepared by the VPS.
            echo '</main></body></html>'; exit;
        } catch (OAP_Failure $e) {
            // Malformed/unrecognized public paths stay in WP's normal 404 handling.
            if ($e->status < 500) { return; }
            status_header(503); nocache_headers(); header('X-Robots-Tag: noindex'); exit;
        } catch (Throwable $e) { status_header(503); nocache_headers(); header('X-Robots-Tag: noindex'); exit; }
    }
}
