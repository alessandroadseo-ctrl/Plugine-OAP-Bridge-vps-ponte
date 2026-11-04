"""Compare an existing WordPress /compara handler with and without Bridge hooks."""
import os
from pathlib import Path
from urllib.request import Request, build_opener, ProxyHandler, HTTPRedirectHandler
from urllib.error import HTTPError

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

opener = build_opener(ProxyHandler({}), NoRedirect())
root = Path(__file__).resolve().parent.parent
fixture_root = Path(os.environ.get('OAP_TEST_ROOT', root.parent / 'work/oap-receiver-tests'))
config = (fixture_root / 'wordpress/wp-config.php').read_text()
assert "define('DB_NAME','oap_test')" in config and 'receiver.test' in config
mu = fixture_root / 'wordpress/wp-content/mu-plugins'
mu.mkdir(exist_ok=True)
fixture = mu / 'oap-test-existing-compara.php'
flag = mu / 'oap-test-disable-bridge'
assert not fixture.exists() and not flag.exists(), 'Refuse to overwrite existing fixtures'
fixture.write_text('''<?php
if (DB_NAME !== 'oap_test' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'receiver.test') { return; }
add_action('plugins_loaded', static function () {
    if (is_file(__DIR__ . '/oap-test-disable-bridge')) {
        remove_action('template_redirect', ['OAP_Affiliate_Redirects', 'serve'], -110);
        remove_action('template_redirect', ['OAP_SEO', 'serve'], -100);
    }
}, 20);
// Simulate an existing integration, later than both Bridge HTTP handlers.
add_action('template_redirect', static function () {
    $path = wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/compara' && !str_starts_with($path, '/compara/')) { return; }
    status_header(301);
    header('Location: https://merchant.example.org/existing?x=%2f&x=%2F');
    header('X-OAP-Existing-Handler: unchanged');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') { echo 'Existing compara handler'; }
    exit;
}, -90);
''')

def request(path, method):
    try:
        response = opener.open(Request('http://127.0.0.1:18086' + path,
            headers={'Host': 'receiver.test'}, method=method), timeout=10)
    except HTTPError as error:
        response = error
    # Date changes between requests; compare all other response headers and body.
    headers = {k.lower(): v for k, v in response.headers.items() if k.lower() != 'date'}
    return response.code, headers, response.read()

paths = ['/compara', '/compara/', '/compara?id='+'D'*43,
         '/compara/QUALSIASI-CODICE', '/compara/?id='+'D'*43]
try:
    flag.touch()
    baseline = {(path, method): request(path, method)
                for path in paths for method in ['GET', 'HEAD', 'POST', 'OPTIONS']}
    flag.unlink()
    for (path, method), expected in baseline.items():
        actual = request(path, method)
        assert actual == expected, (path, method, expected, actual)
        status, headers, body = actual
        assert status == 301 and headers['location'] == 'https://merchant.example.org/existing?x=%2f&x=%2F'
        assert headers['x-oap-existing-handler'] == 'unchanged'
        assert 'referrer-policy' not in headers and 'x-robots-tag' not in headers
    print('PASS: 20 existing /compara responses identical with and without both Bridge hooks')
finally:
    fixture.unlink(missing_ok=True)
    flag.unlink(missing_ok=True)
