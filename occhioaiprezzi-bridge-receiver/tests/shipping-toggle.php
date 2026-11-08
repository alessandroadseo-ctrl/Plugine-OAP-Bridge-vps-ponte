<?php
// Run via WP-CLI only in the disposable test environment.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'oap_test' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'receiver.test') { throw new RuntimeException('Isolated tests only'); }
function shipping_check($ok, $name) {
    if (!$ok) { throw new RuntimeException('FAIL: '.$name); }
    WP_CLI::log('PASS: '.$name);
}
$p = ['protocol'=>'oap-wp-1', 'gtin'=>'00012345678905', 'slug'=>'shipping-contract', 'version'=>1,
    'content_hash'=>str_repeat('a',64), 'html'=>file_get_contents(__DIR__.'/fixtures/shipping-toggle.html'),
    'seo'=>['title'=>'Shipping', 'canonical'=>OAP_Support::canonical('shipping-contract'), 'indexable'=>true, 'modified_at'=>1700000000],
    'state'=>'active', 'redirects'=>[], 'removal_policy'=>'temporary', 'operation'=>'create'];
shipping_check(OAP_Product_Pages::validate($p, $p['gtin']) === $p, 'new VPS2 contract accepted byte for byte');
$old = $p; $old['html'] = file_get_contents(__DIR__.'/fixtures/vps2-product.html');
shipping_check(OAP_Product_Pages::validate($old, $old['gtin']) === $old, 'legacy VPS2 HTML accepted unchanged');
foreach ([
    '<div data-oap-price="8.99"></div>',
    '<p class="oap-bridge-offer-row" data-oap-price="8.99">x</p>',
    '<div class="oap-bridge-offer-row" data-other="x"></div>',
    '<div class="oap-bridge-offer-row" aria-live="polite"></div>',
    '<div class="oap-bridge-offer-row" data-oap-price="NaN"></div>',
    '<div class="oap-bridge-offer-row" data-oap-total="-1.00"></div>',
    '<div class="oap-bridge-offer-row" data-oap-shipping="1,00"></div>',
    '<div class="oap-bridge-offer-row" data-oap-price="1.001"></div>',
    '<div class="oap-bridge-offer-row" data-oap-price></div>',
    '<div class="oap-bridge-offer-row" data-oap-shipping="1.00" onClick="alert(1)"></div>',
    '<button type="submit" class="oap-bridge-shipping-toggle" aria-pressed="false">Includi spedizione</button>',
    '<button type="button" class="other" aria-pressed="false">Includi spedizione</button>',
    '<button type="button" class="oap-bridge-shipping-toggle" aria-pressed="true">Includi spedizione</button>',
    '<button type="button" class="oap-bridge-shipping-toggle" aria-pressed="false" aria-label="other">Includi spedizione</button>',
    '<button type="button" class="oap-bridge-shipping-toggle" aria-pressed="false" data-oap-price="1.00">Includi spedizione</button>',
    '<button type="button" class="oap-bridge-shipping-toggle" aria-pressed="false" formaction="https://example.test/">Includi spedizione</button>',
    '<button type="button" class="oap-bridge-shipping-toggle" aria-pressed="false" oNmOuSeOvEr="alert(1)">Includi spedizione</button>',
    '<button type="button" class="oap-bridge-shipping-toggle" aria-pressed="false"><script>alert(1)</script></button>',
    '<input type="checkbox">', '<form></form>', '<svg></svg>', '<?php echo 1; ?>',
] as $index=>$html) {
    $bad = $p; $bad['html'] = $html;
    try { OAP_Product_Pages::validate($bad, $p['gtin']); throw new RuntimeException('Unsafe markup accepted: '.$index); }
    catch (OAP_Failure $e) { shipping_check($e->getMessage() === 'oap_unsafe_html' && $e->status === 400, 'reject unsafe markup '.$index); }
}
// Assert actual WP enqueue state, including footer placement and cache version.
$flag = new ReflectionProperty(OAP_SEO::class, 'bridge_view');
try {
    foreach ([false, true, false] as $bridge) {
        wp_dequeue_script('oap-bridge-shipping-toggle'); wp_deregister_script('oap-bridge-shipping-toggle');
        $flag->setValue(null, $bridge); OAP_SEO::bridge_assets();
        shipping_check(wp_script_is('oap-bridge-shipping-toggle', 'enqueued') === $bridge, 'script conditional on bridge_view='.($bridge?'true':'false'));
        if ($bridge) {
            $scripts = wp_scripts(); $script = $scripts->registered['oap-bridge-shipping-toggle'];
            shipping_check($script->deps === [] && $scripts->get_data($script->handle, 'group') === 1, 'footer script without jQuery');
            shipping_check($script->ver === (string)filemtime(__DIR__.'/../assets/offer-shipping-toggle.js'), 'script cache version');
        }
    }
} finally { $flag->setValue(null, false); }
WP_CLI::success('Shipping contract, security and enqueue tests complete');
