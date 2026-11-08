<?php
defined('ABSPATH') || exit;
final class OAP_SEO {
    public static function public_row(array $row): bool {
        return $row['owner'] === 'oap-wp-1' && $row['state'] === 'active';
    }
    private static bool $bridge_view = false;
    private static array $bridge_seo = [];
    private static bool $bridge_indexable = false;

    public static function bridge_title($title) {
        return self::$bridge_view ? (self::$bridge_seo['title'] ?? $title) : $title;
    }

    public static function bridge_robots($robots) {
        if (!self::$bridge_view) { return $robots; }
        if (!self::$bridge_indexable) { $robots['noindex'] = true; $robots['nofollow'] = true; }
        return $robots;
    }

    public static function bridge_head(): void {
        if (!self::$bridge_view) { return; }
        $seo = self::$bridge_seo;
        echo '<meta name="description" content="' . esc_attr($seo['description'] ?? '') . '">';
        echo '<link rel="canonical" href="' . esc_url($seo['canonical']) . '">';
        if (!empty($seo['jsonld'])) {
            echo '<script type="application/ld+json">' . OAP_Support::json($seo['jsonld']) . '</script>';
        }
    }

    public static function bridge_assets(): void {
        if (self::$bridge_view) {
            wp_enqueue_style('oap-bridge-products', plugins_url('../assets/oap-1.css', __FILE__), [], (string)filemtime(__DIR__ . '/../assets/oap-1.css'));
            wp_enqueue_script('oap-bridge-shipping-toggle', plugins_url('../assets/offer-shipping-toggle.js', __FILE__), [], (string)filemtime(__DIR__ . '/../assets/offer-shipping-toggle.js'), true);
        }
    }

    public static function serve(): void {
        // An invalid bridge configuration must never turn unrelated WP pages into 503s.
        $candidate = wp_parse_url((string)OAP_Support::cfg('OAP_BRIDGE_PUBLIC_BASE', home_url('/prodotti')), PHP_URL_PATH);
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
            if ($db->tombstone($claim['gtin'])) {
                // No theme, old HTML, SEO, canonical or redirect survives deletion.
                status_header(410); nocache_headers();
                header('X-Robots-Tag: noindex, nofollow');
                header('Content-Type: text/plain; charset=UTF-8');
                if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') { echo 'Gone'; }
                exit;
            }
            $row = $db->page($claim['gtin']);
            if (!$row || $row['owner'] !== 'oap-wp-1') { return; }
            $bundle = OAP_Storage::read($row['artifact']);
            $bundle['seo']['canonical'] = OAP_Deletion::canonical($db, $bundle['seo']['canonical'], $row['slug']);
            // Historical claims reserve ownership, but only VPS-supplied aliases redirect.
            if ($slug !== $row['slug'] && !in_array($slug, $bundle['redirects'], true)) { return; }
            $active = self::public_row($row);
            $gone = !$active && $row['state'] === 'inactive' && $bundle['removal_policy'] === 'gone';
            // Tombstone risolto direttamente su qualunque alias: niente catene.
            if (!$gone && ($slug !== $row['slug'] || $path !== $basepath . '/' . $slug . '/')) {
                wp_safe_redirect(OAP_Support::canonical($row['slug']), 301, 'OAP Bridge'); exit;
            }
            status_header($gone ? 410 : 200);
            nocache_headers(); // Escludere anche reverse proxy/CDN dalla cache di queste rotte.
            header('Content-Type: text/html; charset=UTF-8');
            // Theme CSS, fonts, scripts and navigation are loaded through WordPress.
            // Do not apply the former standalone CSP to theme output.
            header('X-Content-Type-Options: nosniff');
            $indexable = $active && (bool)$row['indexable'];
            header('X-Robots-Tag: ' . ($indexable ? 'index, follow' : 'noindex, nofollow'));
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') { exit; }
            self::$bridge_view = true;
            self::$bridge_seo = $bundle['seo'];
            self::$bridge_indexable = $indexable;
            // We are not in a normal post query: don't let an unrelated page title leak.
            add_filter('pre_get_document_title', [__CLASS__, 'bridge_title'], PHP_INT_MAX);
            add_filter('wp_robots', [__CLASS__, 'bridge_robots'], PHP_INT_MAX);
            add_action('wp_head', [__CLASS__, 'bridge_head'], 1);
            add_action('wp_enqueue_scripts', [__CLASS__, 'bridge_assets'], 20);
            // Avoid WordPress core's canonical for its unrelated main query.
            remove_action('wp_head', 'rel_canonical');
            // The theme provides header/footer and wp_head/wp_footer; VPS remains
            // the exclusive source of the product fragment and offer data.
            get_header();
            echo '<main class="oap-bridge-page"><div class="oap-bridge-container">';
            echo $bundle['html'];
            echo '</div></main>';
            get_footer();
            exit;
        } catch (OAP_Failure $e) {
            // Malformed/unrecognized public paths stay in WP's normal 404 handling.
            if ($e->status < 500) { return; }
            status_header(503); nocache_headers(); header('X-Robots-Tag: noindex'); exit;
        } catch (Throwable $e) { status_header(503); nocache_headers(); header('X-Robots-Tag: noindex'); exit; }
    }
}

