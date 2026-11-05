<?php
/**
 * Plugin Name: OcchioAiPrezzi Bridge Receiver
 * Description: Ricevitore privato oap-wp-1, indice dedicato e artefatti statici.
 * Version: 1.4.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
foreach (['support', 'auth', 'storage', 'product-pages', 'rest-api', 'seo', 'admin', 'affiliate-redirects', 'regeneration-client', 'regeneration-store', 'regeneration-rest', 'regeneration-admin', 'media'] as $part) {
    require_once __DIR__ . '/includes/class-' . $part . '.php';
}
register_activation_hook(__FILE__, ['OAP_Storage', 'activate']);
add_action('rest_api_init', ['OAP_REST_API', 'register']);
add_action('rest_api_init', ['OAP_Media', 'register']);
add_action('template_redirect', ['OAP_Media', 'serve'], -120);
add_action('template_redirect', ['OAP_Affiliate_Redirects', 'serve'], -110);
add_action('template_redirect', ['OAP_SEO', 'serve'], -100);
add_action('wp_sitemaps_init', static function ($server) {
    require_once __DIR__ . '/includes/class-sitemap.php';
    $server->registry->add_provider('oap', new OAP_Sitemap());
});

add_filter('rest_post_dispatch', static function ($response, $server, $request) {
    if (str_starts_with($request->get_route(), '/oap-bridge/v1/')) { $response->header('Cache-Control', 'no-store, private'); }
    return $response;
}, 10, 3);

add_action('admin_menu', ['OAP_Admin', 'register']);

// Activation hooks do not run on plugin updates. Only additive dbDelta schema changes.
add_action('plugins_loaded', static function () {
    if (get_option('oap_bridge_schema_version') !== '1.3.0') {
        OAP_Storage::activate();
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'oap_affiliate_destinations'))) === $wpdb->prefix . 'oap_affiliate_destinations') {
            update_option('oap_bridge_schema_version', '1.3.0', false);
        }
    }
});
