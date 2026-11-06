"""End-to-end hard deletion against the disposable receiver.test server only."""
import json
from urllib.request import Request, build_opener, ProxyHandler, HTTPRedirectHandler
from urllib.error import HTTPError

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

opener = build_opener(ProxyHandler({}), NoRedirect())
gtin = '00000000000873'
route = '/wp-json/oap-bridge/v1/pages/' + gtin

def request(path, method='GET', payload=None, auth=False):
    headers = {'Host': 'receiver.test'}
    if auth:
        headers['Authorization'] = 'Bearer ' + 'local-test-' * 6
    if payload is not None:
        headers['Content-Type'] = 'application/json'
        headers['Idempotency-Key'] = ('delete:' + gtin + ':' + str(payload['version']) if method == 'DELETE'
                                      else payload['content_hash'] + ':' + str(payload['version']))
    req = Request('http://127.0.0.1:18086' + path, method=method, headers=headers,
                  data=json.dumps(payload).encode() if payload is not None else None)
    try:
        response = opener.open(req)
    except HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode()

p = dict(protocol='oap-wp-1', operation='create', gtin=gtin, version=1, slug='delete-http',
         content_hash='f'*64, html='<p>SECRET PRODUCT OFFERS</p>', offers=[{'price': 99}],
         seo=dict(title='SECRET SEO', canonical='https://receiver.test/prodotti/delete-http/', indexable=True, modified_at=1),
         state='active', redirects=['delete-http-alias'], removal_policy='temporary')
assert request(route, 'PUT', p, True)[0] == 201
assert request('/prodotti/delete-http/')[0] == 200
assert request('/prodotti/delete-http-alias/')[0] == 301
deletion = dict(protocol='oap-wp-1', operation='delete', gtin=gtin, version=2,
                media_policy=dict(delete_products=[], protected_products=[]))
assert request(route, 'DELETE', deletion)[0] == 401
for _ in range(2):
    code, headers, body = request(route, 'DELETE', deletion, True)
    assert code == 200 and json.loads(body)['operation'] == 'deleted', (code, body)
    assert 'no-store' in headers['Cache-Control']
for slug in ['delete-http', 'delete-http-alias']:
    for suffix in ['/', '', '/?tracking=1']:
        for method in ['GET', 'HEAD']:
            code, headers, body = request('/prodotti/' + slug + suffix, method)
            assert code == 410 and 'Location' not in headers, (code, headers, body)
            assert 'noindex' in headers['X-Robots-Tag'] and 'no-cache' in headers['Cache-Control']
            assert body == ('' if method == 'HEAD' else 'Gone')
assert request('/prodotti/delete-http/', 'POST')[0] == 405
assert 'delete-http/' not in request('/wp-sitemap-oap-1.xml')[2]
assert request(route, 'PUT', p, True)[0] == 409
p['version'] = 3
assert request(route, 'PUT', p, True)[0] == 201
assert request('/prodotti/delete-http/')[0] == 200
assert request('/prodotti/delete-http-alias/')[0] == 301
assert request(route, 'DELETE', deletion, True)[0] == 409
assert request('/prodotti/delete-http/')[0] == 200
print('PASS: DELETE auth, retry, GET/HEAD 410 without HTML/SEO/redirects, 405, sitemap, old events and republication')

# Parallel retries and a deletion racing with a newer publication must serialize.
from concurrent.futures import ThreadPoolExecutor
deletion['version'] = 4
with ThreadPoolExecutor(max_workers=6) as pool:
    results = list(pool.map(lambda _: request(route, 'DELETE', deletion, True)[0], range(6)))
assert results == [200]*6, results
assert request('/prodotti/delete-http/')[0] == 410
p.update(version=5, operation='update')
assert request(route, 'PUT', p, True)[0] == 201
deletion['version'] = 6
p['version'] = 7
with ThreadPoolExecutor(max_workers=2) as pool:
    deleted = pool.submit(request, route, 'DELETE', deletion, True)
    published = pool.submit(request, route, 'PUT', p, True)
    assert deleted.result()[0] in (200, 409)
    assert published.result()[0] in (200, 201)
assert json.loads(request(route, auth=True)[2])['version'] == 7
assert request('/prodotti/delete-http/')[0] == 200
print('PASS: parallel DELETE retries and DELETE/newer PUT race preserve highest version')
