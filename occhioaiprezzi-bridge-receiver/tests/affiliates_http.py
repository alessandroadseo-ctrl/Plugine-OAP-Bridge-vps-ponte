"""Run after affiliates.php on the disposable receiver.test fixture only."""
from urllib.request import Request, build_opener, ProxyHandler, HTTPRedirectHandler
from urllib.error import HTTPError
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None
opener = build_opener(ProxyHandler({}), NoRedirect())
def request(path, method='GET'):
    try:
        response = opener.open(Request('http://127.0.0.1:18086' + path, headers={'Host':'receiver.test'}, method=method))
    except HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode()
url='https://93.184.215.14/offer?tag=original&x=%2f&x=%2F&empty=&plus=a+b#tracking'
for method in ['GET','HEAD']:
    status, headers, body = request('/compara?id='+'D'*43, method)
    assert status == 302 and headers['Location'] == url, (status, headers)
    assert 'no-store' in headers['Cache-Control'] and headers['Referrer-Policy'] == 'no-referrer'
    assert body == ''
print('PASS: real HTTP GET/HEAD 302, exact Location, no-store and no-referrer')
for code in ['A'*43, 'B'*43, 'Z'*43, 'malformed']:
    status, headers, body = request('/compara?id='+code)
    assert status == 404 and 'Location' not in headers
print('PASS: real HTTP revoked, unknown and malformed codes return 404')
for gtin in ['03253561929809','03253561756726']:
    status, headers, body = request('/prodotti/affiliate-test-'+gtin+'/')
    assert status == 200 and '/compara?id=' in body and 'https://93.184.215.14/' not in body
    assert request('/wp-json/oap-bridge/v1/pages/'+gtin)[0] == 401
assert request('/wp-json/wp/v2/oap_affiliate_destinations')[0] == 404
print('PASS: both GTINs render local CTAs without affiliate URLs, no public destinations API')
