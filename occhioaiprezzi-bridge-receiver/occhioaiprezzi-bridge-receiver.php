<?php
/**
 * Plugin Name: OcchioAiPrezzi Bridge Receiver
 * Description: Ricevitore privato oap-wp-1, indice dedicato e artefatti statici.
 * Version: 1.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
foreach (['support', 'auth', 'storage', 'product-pages', 'rest-api', 'seo'] as $part) {
    require_once __DIR__ . '/includes/class-' . $part . '.php';
}
register_activation_hook(__FILE__, ['OAP_Storage', 'activate']);
add_action('rest_api_init', ['OAP_REST_API', 'register']);
add_action('template_redirect', ['OAP_SEO', 'serve'], -100);
add_action('wp_sitemaps_init', static function ($server) {
    require_once __DIR__ . '/includes/class-sitemap.php';
    $server->registry->add_provider('oap', new OAP_Sitemap());
});

add_filter('rest_post_dispatch', static function ($response, $server, $request) {
    if (str_starts_with($request->get_route(), '/oap-bridge/v1/')) { $response->header('Cache-Control', 'no-store, private'); }
    return $response;
}, 10, 3);
