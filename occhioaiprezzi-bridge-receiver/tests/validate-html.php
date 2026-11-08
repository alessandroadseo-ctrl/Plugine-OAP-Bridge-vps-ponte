<?php
// Database-free runner using real WordPress core, not KSES/HTML parser mocks.
// php tests/validate-html.php /path/to/wordpress
$core = rtrim($argv[1] ?? '', '/');
if (!is_file($core.'/wp-includes/kses.php')) { throw new RuntimeException('WordPress core path required'); }
define('ABSPATH', $core.'/');
define('WPINC', 'wp-includes');
require ABSPATH.WPINC.'/compat.php';
require ABSPATH.WPINC.'/plugin.php';
require ABSPATH.WPINC.'/functions.php';
require ABSPATH.WPINC.'/formatting.php';
require ABSPATH.WPINC.'/http.php';
require ABSPATH.WPINC.'/kses.php';
require ABSPATH.WPINC.'/class-wp-token-map.php';
foreach (['html5-named-character-references', 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-doctype-info', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor'] as $file) {
    require ABSPATH.WPINC.'/html-api/'.$file.'.php';
}
require __DIR__.'/../includes/class-support.php';
require __DIR__.'/../includes/class-product-pages.php';
require __DIR__.'/vps2-shipping.php';
