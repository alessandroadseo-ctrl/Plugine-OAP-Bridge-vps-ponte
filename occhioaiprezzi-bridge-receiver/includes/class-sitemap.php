<?php
defined('ABSPATH') || exit;
final class OAP_Sitemap extends WP_Sitemaps_Provider {
    public function __construct() { $this->name = 'oap'; $this->object_type = 'oap'; }
    public function get_url_list($page_num, $object_subtype = '') {
        global $wpdb;
        $db = new OAP_Storage(); $offset = (max(1,(int)$page_num)-1)*1000;
        $rows = $db->rows($wpdb->prepare("SELECT * FROM `{$db->pages}` WHERE state='active' AND indexable=1 AND owner='oap-wp-1' ORDER BY gtin LIMIT 1000 OFFSET %d", $offset));
        $urls = [];
        try {
            foreach ($rows as $row) {
                if (!OAP_SEO::public_row($row)) { continue; }
                $bundle = OAP_Storage::read($row['artifact']);
                $urls[] = ['loc'=>$bundle['seo']['canonical'], 'lastmod'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['modified_at'])];
            }
        } catch (Throwable $e) { return []; } // Unreadable artifacts never emit sitemap entries.
        return $urls;
    }
    public function get_max_num_pages($object_subtype = '') {
        $db = new OAP_Storage();
        $row = $db->one("SELECT COUNT(*) AS n FROM `{$db->pages}` WHERE state='active' AND indexable=1 AND owner='oap-wp-1'");
        return (int)ceil((int)$row['n']/1000);
    }
}

