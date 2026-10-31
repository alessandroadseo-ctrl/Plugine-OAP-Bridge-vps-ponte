<?php
// ONLY isolated test configuration. Never deploy this file.
define('DB_NAME','oap_test'); define('DB_USER','root'); define('DB_PASSWORD','oap-local-test-only'); define('DB_HOST','oap-receiver-db');
define('DB_CHARSET','utf8mb4'); define('DB_COLLATE',''); $table_prefix='wp_';
define('WP_HOME','https://receiver.test'); define('WP_SITEURL','https://receiver.test');
define('AUTH_KEY','oap-local-test-key-only'); define('SECURE_AUTH_KEY','oap-local-test-key-only'); define('LOGGED_IN_KEY','oap-local-test-key-only'); define('NONCE_KEY','oap-local-test-key-only');
define('WP_DEBUG',true); define('WP_DEBUG_DISPLAY',false); define('WP_DEBUG_LOG',true); define('DISABLE_WP_CRON',true); define('WP_HTTP_BLOCK_EXTERNAL',true);
define('OAP_BRIDGE_TOKEN',str_repeat('local-test-',6));
define('OAP_BRIDGE_WRITES_ENABLED',true); define('OAP_BRIDGE_PUBLIC_BASE','https://receiver.test/prodotti-ponte');
define('OAP_BRIDGE_ARTIFACT_DIR','/oap-artifacts'); define('OAP_BRIDGE_GTIN_ADAPTER_VERIFIED',true);
define('OAP_BRIDGE_GTIN_META_KEYS',['_global_unique_id','_test_gtin']); define('OAP_BRIDGE_JSONLD_ENABLED',true);
// Simulates trusted TLS termination in the isolated test harness only.
$_SERVER['HTTPS']='on';
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
require_once ABSPATH.'wp-settings.php';
