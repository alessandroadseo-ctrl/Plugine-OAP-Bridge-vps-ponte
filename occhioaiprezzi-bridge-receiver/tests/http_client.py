"""Real HTTP receiver contract tests. No dependency on, or changes to, vps-ponte."""
import json
from urllib.request import Request, build_opener, ProxyHandler, HTTPRedirectHandler
from urllib.error import HTTPError

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None
opener=build_opener(ProxyHandler({}), NoRedirect())
def request(path, method='GET', payload=None, auth=False):
    headers={'Host':'receiver.test'}
    if auth: headers['Authorization']='Bearer '+'local-test-'*6
    if payload is not None:
        headers.update({'Content-Type':'application/json','Idempotency-Key':payload['content_hash']+':'+str(payload['version'])})
    req=Request('http://127.0.0.1:18086'+path, data=json.dumps(payload).encode() if payload is not None else None,headers=headers,method=method)
    try: response=opener.open(req)
    except HTTPError as e: response=e
    return response.code, response.headers, response.read().decode()
gtin='00000000000111'
def payload(version=1,slug='http-product',state='active',policy='temporary',redirects=None):
    return dict(protocol='oap-wp-1',operation='create' if version==1 else 'update',gtin=gtin,slug=slug,version=version,content_hash=f'{version:064x}',
        html='<article><h1>Scelto dalla VPS</h1><p>HTML pronto '+str(version)+'</p></article>',
        seo=dict(title='SEO VPS '+str(version),description='Descrizione VPS',canonical='https://receiver.test/canonical-vps/',indexable=state=='active',modified_at=1700000000,jsonld={'@context':'https://schema.org','@type':'Product','name':'</script><script>alert(1)</script>'}),
        state=state,redirects=redirects or [],removal_policy=policy)
def put(p):
    code,_,body=request('/wp-json/oap-bridge/v1/pages/'+gtin,'PUT',p,True)
    assert code in (200,201),(code,body)
    return json.loads(body)
p=payload(); assert put(p)['operation']=='created'
assert put(p)['operation']=='unchanged'
print('PASS: HTTP create and lost-ACK retry reconciliation')
code,h,body=request('/prodotti-ponte/http-product/')
assert code==200 and p['html'] in body and p['seo']['title'] in body
assert body.count('rel="canonical"')==1 and 'https://receiver.test/canonical-vps/' in body
assert body.count('application/ld+json')==1 and '</script><script>alert(1)' not in body
assert '\\u003C' in body and h['X-Robots-Tag']=='index, follow'
code,_,body=request('/wp-sitemap-oap-1.xml'); assert code==200 and 'canonical-vps/' in body
print('PASS: exact VPS HTML and SEO, canonical is not regenerated')
put(payload(2,'http-renamed',redirects=['http-product']))
code,h,_=request('/prodotti-ponte/http-product/'); assert code==301 and h['Location']=='https://receiver.test/prodotti-ponte/http-renamed/'
p=payload(3,'http-renamed','inactive',redirects=['http-product']); put(p)
code,h,body=request('/prodotti-ponte/http-renamed/')
assert code==200 and p['html'] in body and p['seo']['title'] in body and 'noindex' in h['X-Robots-Tag']
assert 'Prodotto non disponibile' not in body and 'application/ld+json' in body
assert 'canonical-vps/' not in request('/wp-sitemap-oap-1.xml')[2]
print('PASS: inactive renders VPS document, no generated tombstone')
put(payload(4,'http-renamed','inactive','gone',redirects=['http-product']))
for slug in ['http-product','http-renamed']:
    code,h,body=request('/prodotti-ponte/'+slug+'/'); assert code==410 and 'Location' not in h
put(payload(5,'http-renamed'))
assert request('/prodotti-ponte/http-product/')[0]==404
assert request('/prodotti-ponte/http-renamed/')[0]==200
code,h,_=request('/wp-json/oap-bridge/v1/pages/'+gtin); assert code==401 and 'no-store' in h['Cache-Control']
assert request('/wp-json/oap-bridge/v1/gtins/'+gtin+'/eligibility',auth=True)[0]==404
print('PASS: supplied gone state, removed aliases no longer redirect, private API')
