"""Real disposable WordPress HTTP checks for the future VPS2 shipping contract."""
import json
from pathlib import Path
from urllib.request import Request, build_opener, ProxyHandler, HTTPRedirectHandler
from urllib.error import HTTPError


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


opener = build_opener(ProxyHandler({}), NoRedirect())
def request(path, payload=None):
    headers = {'Host': 'receiver.test'}
    if payload is not None:
        headers.update({'Authorization': 'Bearer ' + 'local-test-' * 6,
                        'Content-Type': 'application/json',
                        'Idempotency-Key': payload['content_hash'] + ':1'})
    req = Request('http://127.0.0.1:18086' + path,
                  data=json.dumps(payload).encode() if payload else None,
                  headers=headers, method='PUT' if payload else 'GET')
    try:
        response = opener.open(req, timeout=20)
    except HTTPError as error:
        response = error
    return response.code, response.read().decode()


html = Path(__file__).with_name('fixtures').joinpath('shipping-toggle.html').read_text()
gtin = '00000000000998'
payload = dict(protocol='oap-wp-1', gtin=gtin, operation='create', slug='shipping-toggle-contract',
               version=1, content_hash='9' * 64, html=html, state='active', redirects=[], removal_policy='temporary',
               seo=dict(title='Shipping contract', canonical='https://receiver.test/prodotti/shipping-toggle-contract/',
                        indexable=True, modified_at=1700000000))
code, body = request('/wp-json/oap-bridge/v1/pages/' + gtin, payload)
assert code in (200, 201), (code, body)
code, body = request('/wp-json/oap-bridge/v1/pages/' + gtin, payload)
assert code == 200 and json.loads(body)['operation'] == 'unchanged'
code, body = request('/prodotti/shipping-toggle-contract/')
assert code == 200 and html in body
assert body.count('offer-shipping-toggle.js') == 1
assert body.index('offer-shipping-toggle.js') > body.index('</article>'), 'footer placement'
assert 'offer-shipping-toggle.js?ver=' in body
print('PASS: REST contract accepted unchanged, idempotent retry, exact HTTP HTML, versioned footer script')
for path in ['/', '/sample-page/', '/category/uncategorized/', '/prodotti/', '/prodotti/unknown-shipping/', '/shop/', '/wp-json/']:
    code, body = request(path)
    assert 'offer-shipping-toggle.js' not in body, (path, code)
print('PASS: no toggle script on native, archive, shop, API and unknown Bridge routes')
