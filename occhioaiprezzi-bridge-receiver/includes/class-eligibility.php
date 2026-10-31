<?php
defined('ABSPATH') || exit;
final class OAP_Eligibility {
    public static function keys(): array {
        if (!class_exists('WooCommerce') || OAP_Support::cfg('OAP_BRIDGE_GTIN_ADAPTER_VERIFIED', false) !== true) {
            throw new OAP_Failure('oap_inventory_unavailable', 503);
        }
        $keys = OAP_Support::cfg('OAP_BRIDGE_GTIN_META_KEYS', []);
        if (!is_array($keys) || !$keys || count($keys) > 20) { throw new OAP_Failure('oap_inventory_unavailable', 503); }
        foreach ($keys as $key) {
            if (!is_string($key) || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $key)) { throw new OAP_Failure('oap_inventory_unavailable', 503); }
        }
        return array_values(array_unique($keys));
    }
    public static function present(OAP_Storage $db, string $gtin, array $keys): bool {
        global $wpdb;
        $holders = implode(',', array_fill(0, count($keys), '%s'));
        // SQL autorevole; prodotti e varianti di qualunque stato, compreso cestino.
        // Riconosce zeri iniziali e separatori spazio/trattino. Altri formati richiedono adattatore diverso.
        $sql = "SELECT 1 FROM `{$wpdb->postmeta}` JOIN `{$wpdb->posts}` ON `{$wpdb->posts}`.ID=`{$wpdb->postmeta}`.post_id
          WHERE `{$wpdb->posts}`.post_type IN ('product','product_variation') AND meta_key IN ($holders)
          AND TRIM(LEADING '0' FROM REPLACE(REPLACE(TRIM(meta_value),' ',''),'-','')) = %s LIMIT 1";
        return (bool) $db->one($wpdb->prepare($sql, ...array_merge($keys, [ltrim($gtin, '0')])));
    }
}
