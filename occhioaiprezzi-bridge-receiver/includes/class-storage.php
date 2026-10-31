<?php
defined('ABSPATH') || exit;
final class OAP_Storage {
    private $handle;
    public string $pages;
    public string $slugs;
    public function __construct() {
        global $wpdb;
        $this->handle = $wpdb->dbh;
        if (!$this->handle instanceof mysqli) { throw new OAP_Failure('oap_database_unsupported', 503); }
        $this->pages = $wpdb->prefix . 'oap_pages'; $this->slugs = $wpdb->prefix . 'oap_slugs';
    }
    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}oap_pages (
            gtin char(14) NOT NULL,
            owner varchar(32) NOT NULL,
            version bigint unsigned NOT NULL,
            installed_hash char(64) NOT NULL,
            slug varchar(180) NOT NULL,
            artifact char(64) NOT NULL,
            state varchar(8) NOT NULL,
            indexable tinyint NOT NULL DEFAULT 0,
            modified_at bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (gtin),
            KEY sitemap (state,indexable,gtin)
        ) ENGINE=InnoDB $collate;");
        dbDelta("CREATE TABLE {$wpdb->prefix}oap_slugs (
            slug varchar(180) NOT NULL,
            gtin char(14) NOT NULL,
            PRIMARY KEY  (slug),
            KEY gtin (gtin)
        ) ENGINE=InnoDB $collate;");
    }
    public function query(string $sql) {
        // Usa il socket esistente senza la riconnessione automatica di wpdb:
        // una perdita di connessione non deve trasformare scritture transazionali in autocommit.
        try { $result = mysqli_query($this->handle, $sql); }
        catch (Throwable $e) { throw new OAP_Failure('oap_database_unavailable', 503); }
        if ($result === false) { throw new OAP_Failure('oap_database_unavailable', 503); }
        return $result;
    }
    public function one(string $sql): ?array {
        $r = $this->query($sql); $row = mysqli_fetch_assoc($r); mysqli_free_result($r); return $row ?: null;
    }
    public function rows(string $sql): array {
        $r = $this->query($sql); $rows = mysqli_fetch_all($r, MYSQLI_ASSOC); mysqli_free_result($r); return $rows;
    }
    public function page(string $gtin): ?array {
        global $wpdb;
        return $this->one($wpdb->prepare("SELECT * FROM `{$this->pages}` WHERE gtin=%s", $gtin));
    }
    public function locked(callable $fn) {
        global $wpdb;
        // Global serialization is deliberately stronger than per-GTIN. READ locks
        // block *all* concurrent Woo writes, even writers unaware of this plugin.
        // Both custom tables must be transactional; no WP hooks run in this scope.
        foreach ([$this->pages, $this->slugs] as $table) {
            $engine = $this->one($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
            if (($engine['ENGINE'] ?? '') !== 'InnoDB') { throw new OAP_Failure('oap_database_unsupported', 503); }
        }
        $auto = $this->one('SELECT @@autocommit AS a, @@session.lock_wait_timeout AS t');
        if ((int)$auto['a'] !== 1) { throw new OAP_Failure('oap_transaction_in_progress', 503); }
        $locked = false;
        $this->query('SET SESSION lock_wait_timeout=5');
        $this->query('SET autocommit=0');
        try {
            $this->query("LOCK TABLES `{$wpdb->posts}` READ, `{$wpdb->postmeta}` READ, `{$this->pages}` WRITE, `{$this->slugs}` WRITE");
            $locked = true;
            $result = $fn($this);
            $this->query('COMMIT');
            return $result;
        } finally {
            // No SQL reconnect. Connection death implicitly drops locks and rolls back.
            try { $this->query('ROLLBACK'); if ($locked) { $this->query('UNLOCK TABLES'); } $this->query('SET autocommit=1'); $this->query('SET SESSION lock_wait_timeout=' . (int)$auto['t']); }
            catch (Throwable $ignored) { /* connection is unusable; caller returns a stable error */ }
        }
    }
    public static function directory(): string {
        $path = realpath((string)OAP_Support::cfg('OAP_BRIDGE_ARTIFACT_DIR', ''));
        $web = realpath(ABSPATH);
        $document = realpath($_SERVER['DOCUMENT_ROOT'] ?? ABSPATH);
        if (!$path || !is_dir($path) || !is_writable($path)) { throw new OAP_Failure('oap_storage_unavailable', 503); }
        foreach (array_filter([$web, $document]) as $root) {
            if ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR)) { throw new OAP_Failure('oap_storage_not_private', 503); }
        }
        return $path;
    }
    public static function artifact(array $data): string {
        $bytes = OAP_Support::json($data); $hash = hash('sha256', $bytes); $dir = self::directory(); $file = "$dir/$hash.json";
        if (is_file($file)) {
            if (!hash_equals($hash, @hash_file('sha256', $file))) { throw new OAP_Failure('oap_artifact_integrity', 503); }
            return $hash;
        }
        $tmp = @tempnam($dir, '.oap-');
        if (!$tmp) { throw new OAP_Failure('oap_storage_unavailable', 503); }
        try {
            $f = @fopen($tmp, 'wb');
            if (!$f) { throw new OAP_Failure('oap_storage_unavailable', 503); }
            try {
                if (@fwrite($f, $bytes) !== strlen($bytes) || !@fflush($f) || !@fsync($f)) { throw new OAP_Failure('oap_storage_unavailable', 503); }
            } finally { fclose($f); }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) { throw new OAP_Failure('oap_storage_unavailable', 503); }
            $parent = @fopen($dir, 'r');
            if (!$parent) { throw new OAP_Failure('oap_storage_unavailable', 503); }
            try { if (!@fsync($parent)) { throw new OAP_Failure('oap_storage_unavailable', 503); } }
            finally { fclose($parent); }
        } finally { if (is_file($tmp)) { @unlink($tmp); } }
        return $hash;
    }
    public static function read(string $hash): array {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $hash)) { throw new OAP_Failure('oap_artifact_integrity', 503); }
        $file = self::directory() . '/' . $hash . '.json';
        $bytes = @file_get_contents($file);
        if ($bytes === false || !hash_equals($hash, hash('sha256', $bytes))) { throw new OAP_Failure('oap_artifact_integrity', 503); }
        return json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    }
}
