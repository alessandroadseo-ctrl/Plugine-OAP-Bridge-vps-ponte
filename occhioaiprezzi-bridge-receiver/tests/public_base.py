"""Real WP routing regression: migrate an existing publication without resending it."""
import json
import os
from pathlib import Path
import subprocess
import time
from http.client import HTTPException
from urllib.error import URLError
from urllib.error import HTTPError
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


opener = build_opener(ProxyHandler({}), NoRedirect())
plugin_root = Path(__file__).resolve().parent.parent
test_root = Path(os.environ.get('OAP_TEST_ROOT', plugin_root.parent / 'work/oap-receiver-tests'))
config = test_root / 'wordpress/wp-config.php'
original = config.read_text()
assert "define('DB_NAME','oap_test')" in original
assert 'OAP_BRIDGE_PUBLIC_BASE' not in original, 'Test the actual default without an override'


def set_base(base=None):
    definition = f"define('OAP_BRIDGE_PUBLIC_BASE','https://receiver.test{base}');\n" if base else ''
    config.write_text(original.replace('<?php\n', '<?php\n' + definition, 1))
    # Start a fresh HTTP worker for each wp-config scenario.
    subprocess.check_call(['docker', 'restart', 'oap-receiver-php'], stdout=subprocess.DEVNULL)
    for attempt in range(50):
        try:
            if request('/wp-json/')[0] == 200:
                return
        except (URLError, ConnectionError, HTTPException):
            pass
        time.sleep(0.1)
    raise RuntimeError('Isolated HTTP server did not become ready')


def fixture(mode):
    result = subprocess.check_output([
        'docker', 'exec', 'oap-receiver-php', 'wp', '--allow-root', '--quiet',
        'eval-file', 'wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/public-base.php', mode,
    ], text=True)
    return json.loads(result) if mode != 'cleanup' else None


def request(path, method='GET'):
    req = Request('http://127.0.0.1:18086' + path, headers={'Host': 'receiver.test'}, method=method)
    try:
        response = opener.open(req, timeout=5)
    except HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode()


slug = 'published-before-base-change'
try:
    set_base('/prodotti-ponte')
    before = fixture('create')
    assert request(f'/prodotti-ponte/{slug}/')[0] == 200
    set_base()
    code, headers, body = request(f'/prodotti/{slug}/')
    assert code == 200 and before['bundle']['html'] in body
    assert before['bundle']['seo']['canonical'] in body, 'Preserve supplied VPS SEO'
    assert fixture('inspect') == before, 'No publication, artifact, version, identity or posts changed'
    assert request(f'/prodotti/{slug}/', 'HEAD')[0] == 200
    code, headers, _ = request(f'/prodotti/{slug}')
    assert code == 301 and headers['Location'] == f'https://receiver.test/prodotti/{slug}/'
    print('PASS: existing publication moves to /prodotti/ without VPS resend or data changes')

    for path in ['/prodotti/', '/prodotti/native-route-fixture/']:
        code, _, body = request(path)
        assert code == 200 and 'Existing product fixture' not in body, (path, code)
    for path in ['/prodotti/unclaimed-product/', '/prodotti/bad/slug/',
                 '/prodotti-ponte/unclaimed-product/', f'/prodotti-ponte/{slug}/']:
        code, _, body = request(path)
        assert code == 404 and 'Existing product fixture' not in body, (path, code)
    assert request('/wp-json/')[0] == 200
    assert request('/wp-json/oap-bridge/v1/pages/00000000000991')[0] == 401
    print('PASS: native root/child pages, unknown paths and REST routes retain WordPress handling')

    set_base('/catalogo-riservato')
    code, headers, body = request(f'/catalogo-riservato/{slug}/')
    assert code == 200 and before['bundle']['html'] in body, (code, dict(headers), body[:1000])
    assert request(f'/prodotti/{slug}/')[0] == 404
    code, headers, _ = request(f'/catalogo-riservato/{slug}')
    assert code == 301 and headers['Location'] == f'https://receiver.test/catalogo-riservato/{slug}/'
    assert fixture('inspect') == before
    print('PASS: OAP_BRIDGE_PUBLIC_BASE overrides both routing and generated URLs')
finally:
    set_base()
    fixture('cleanup')
