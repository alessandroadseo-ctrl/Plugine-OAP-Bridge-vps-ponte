<?php
defined('ABSPATH') || exit;

/** Dedicated tables only. Raw connection never reconnects inside the transaction. */
final class OAP_Regeneration_Store {
    private $handle;
    public string $workers;
    public string $commands;

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}oap_regeneration_workers (
            worker_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            issued bigint unsigned NOT NULL DEFAULT 0,
            snapshot longtext NOT NULL,
            PRIMARY KEY  (worker_id)
        ) ENGINE=InnoDB $collate;");
        dbDelta("CREATE TABLE {$wpdb->prefix}oap_regeneration_commands (
            worker_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            sequence bigint unsigned NOT NULL,
            command_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            idempotency_key char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload longtext NOT NULL,
            receipt longtext NULL,
            PRIMARY KEY  (worker_id,sequence),
            UNIQUE KEY command_id (command_id),
            UNIQUE KEY idempotency_key (idempotency_key)
        ) ENGINE=InnoDB $collate;");
        foreach (['workers','commands'] as $suffix) {
            $table = $wpdb->prefix . 'oap_regeneration_' . $suffix;
            if ($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table)) !== 'InnoDB') { return; }
        }
        update_option('oap_regeneration_schema_version', '1', false);
    }

    public function __construct() {
        global $wpdb;
        $this->handle = $wpdb->dbh;
        if (!$this->handle instanceof mysqli) { throw new OAP_Failure('oap_regeneration_database', 503); }
        $this->workers = $wpdb->prefix . 'oap_regeneration_workers';
        $this->commands = $wpdb->prefix . 'oap_regeneration_commands';
    }

    public function query(string $sql) {
        global $wpdb;
        try { $result = mysqli_query($this->handle, $wpdb->remove_placeholder_escape($sql)); }
        catch (Throwable $e) { throw new OAP_Failure('oap_regeneration_database', 503); }
        if ($result === false) { throw new OAP_Failure('oap_regeneration_database', 503); }
        return $result;
    }

    public function one(string $sql): ?array {
        $result = $this->query($sql); $row = mysqli_fetch_assoc($result); mysqli_free_result($result); return $row;
    }

    public function locked(string $worker, callable $fn) {
        global $wpdb;
        $name = 'oap_regen_' . substr(hash('sha256', DB_NAME . $wpdb->prefix), 0, 40);
        $lock = $this->one($wpdb->prepare('SELECT GET_LOCK(%s,0) AS acquired', $name));
        if (($lock['acquired'] ?? '') !== '1') { throw new OAP_Failure('oap_regeneration_busy', 409); }
        $transaction = false;
        try {
            foreach ([$this->workers,$this->commands] as $table) {
                $engine = $this->one($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
                if (($engine['ENGINE'] ?? '') !== 'InnoDB') { throw new OAP_Failure('oap_regeneration_database', 503); }
            }
            $auto = $this->one('SELECT @@autocommit AS a');
            if ((int)$auto['a'] !== 1) { throw new OAP_Failure('oap_regeneration_database', 503); }
            $this->query('START TRANSACTION');
            $transaction = true;
            $this->query($wpdb->prepare("INSERT IGNORE INTO `{$this->workers}` (worker_id,issued,snapshot) VALUES (%s,0,'{}')", $worker));
            $row = $this->one($wpdb->prepare("SELECT * FROM `{$this->workers}` WHERE worker_id=%s FOR UPDATE", $worker));
            $result = $fn($this, $row);
            $this->query('COMMIT');
            $transaction = false;
            return $result;
        } finally {
            try { if ($transaction) { $this->query('ROLLBACK'); } $this->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
            catch (Throwable $ignored) { /* A dead connection rolls back and releases its mutex. */ }
        }
    }

    public function pending(string $worker): ?array {
        global $wpdb;
        return $this->one($wpdb->prepare("SELECT * FROM `{$this->commands}` WHERE worker_id=%s AND receipt IS NULL ORDER BY sequence LIMIT 1", $worker));
    }

    public function save_snapshot(string $worker, array $snapshot): void {
        global $wpdb;
        $this->query($wpdb->prepare("UPDATE `{$this->workers}` SET snapshot=%s WHERE worker_id=%s", wp_json_encode($snapshot), $worker));
    }
}
add_action('plugins_loaded', static function () {
    if (get_option('oap_regeneration_schema_version') !== '1') { OAP_Regeneration_Store::activate(); }
});
