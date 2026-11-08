<?php
// Included by integration.php, or by validate-html.php without a database.
$shipping = [
    'protocol'=>'oap-wp-1', 'gtin'=>'00012345678905', 'slug'=>'vps2-shipping',
    'version'=>1, 'content_hash'=>str_repeat('a', 64),
    'html'=>file_get_contents(__DIR__.'/fixtures/vps2-shipping.html'),
    'seo'=>['title'=>'VPS2 shipping', 'canonical'=>'https://receiver.test/prodotti/vps2-shipping/', 'indexable'=>true, 'modified_at'=>1700000000],
    'state'=>'active', 'redirects'=>[], 'removal_policy'=>'temporary', 'operation'=>'create',
];
if (OAP_Product_Pages::validate($shipping, $shipping['gtin']) !== $shipping) {
    throw new RuntimeException('VPS2 shipping markup must remain byte-identical');
}
foreach ([
    file_get_contents(__DIR__.'/fixtures/vps2-product.html'),
    '<button class="oap-bridge-shipping-toggle" type="button" aria-pressed="false">Includi spedizione</button>',
] as $html) {
    $valid = $shipping; $valid['html'] = $html;
    if (OAP_Product_Pages::validate($valid, $valid['gtin']) !== $valid) {
        throw new RuntimeException('Legacy markup and enabled toggle must remain byte-identical');
    }
}
foreach ([
    '<button class="oap-bridge-shipping-toggle" type="button" aria-pressed="false" disabled="disabled" onclick="alert(1)">Spedizione</button>',
    '<button type="button" oNfOcUs="alert(1)">Spedizione</button>',
    '<button type="button" data-arbitrary="x">Spedizione</button>',
    '<button type="button" formaction="https://example.test/">Spedizione</button>',
    '<div class="oap-bridge-offer-row" data-oap-price="19.90" data-oap-other="x"></div>',
    '<p data-oap-price="19.90">x</p>',
    '<div aria-pressed="false"></div>',
    '<button type="button">&#39;</button>',
    '<script>alert(1)</script>',
    '<?php echo 1; ?>',
    '<iframe src="https://example.test/"></iframe>',
    '<svg></svg>',
    '<form></form>',
    '<button style="color:red">x</button>',
    '<img src="https://images.test/x" onerror="alert(1)">',
] as $html) {
    $bad = $shipping; $bad['html'] = $html;
    try {
        OAP_Product_Pages::validate($bad, $bad['gtin']);
    } catch (OAP_Failure $e) {
        if ($e->getMessage() === 'oap_unsafe_html' && $e->status === 400) { continue; }
        throw $e;
    }
    throw new RuntimeException('Unsafe shipping markup accepted: '.$html);
}
foreach (['javascript:alert(1)', 'data:text/html,evil'] as $url) {
    $bad = $shipping; $bad['html'] = '<a href="'.$url.'">x</a>';
    try {
        OAP_Product_Pages::validate($bad, $bad['gtin']);
    } catch (OAP_Failure $e) {
        if ($e->getMessage() === 'oap_invalid_url') { continue; }
        throw $e;
    }
    throw new RuntimeException('Unsafe URL accepted');
}
echo "PASS: VPS2 shipping byte identity and strict attribute/event rejection\n";
