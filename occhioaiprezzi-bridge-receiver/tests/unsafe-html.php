<?php
// Included by integration.php in the disposable WordPress test environment.
$sample = bundle('00012345678905');
check(OAP_Product_Pages::validate($sample, $sample['gtin']) === $sample, 'VPS2 template markup and target CTA accepted byte for byte');
foreach ([
    'script' => '<script>alert(1)</script>',
    'PHP' => '<?php echo 1; ?>',
    'onclick on CTA' => '<a href="https://receiver.test/go/?id=abc" target="_blank" rel="noopener noreferrer sponsored nofollow" onclick="alert(1)">Offerta</a>',
    'mixed case event' => '<p oNmOuSeOvEr="alert(1)">x</p>',
    'image event' => '<img src="https://images.test/x" onerror="alert(1)">',
    'iframe' => '<iframe src="https://example.test/"></iframe>',
    'SVG' => '<svg></svg>',
    'form' => '<form></form>',
    'unsupported tag' => '<h4>x</h4>',
    'unsupported attribute' => '<p id="unsupported">x</p>',
    'style attribute' => '<a href="https://example.test/" target="_blank" style="color:red">x</a>',
    'target remains anchor only' => '<p target="_blank">x</p>',
    // KSES pads this entity: rejection proves the byte comparison still runs.
    'KSES byte normalization' => '<p>&#39;</p>',
] as $name => $html) {
    $bad = $sample; $bad['html'] = $html;
    $response = req('PUT', 'pages/'.$bad['gtin'], $bad);
    check($response->get_status() === 400 && $response->get_data()['code'] === 'oap_unsafe_html', 'oap_unsafe_html: '.$name);
}
foreach (['javascript:alert(1)', 'data:text/html,evil'] as $url) {
    $bad = $sample;
    $bad['html'] = '<a href="'.$url.'" target="_blank">x</a>';
    $response = req('PUT', 'pages/'.$bad['gtin'], $bad);
    check($response->get_status() === 400 && $response->get_data()['code'] === 'oap_invalid_url', 'target does not bypass URL validation: '.$url);
}
