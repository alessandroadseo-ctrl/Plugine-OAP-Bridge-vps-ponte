"""Real HTTP E2E using the unchanged Python client of vps-ponte.
HTTPS termination is simulated ONLY by the isolated wp-config.php.
"""
import importlib.util
import os
import re
from pathlib import Path
from types import SimpleNamespace
import httpx

spec=importlib.util.spec_from_file_location('bridge_wp_client',os.environ.get('OAP_REFERENCE_CLIENT','/workspace/vps-ponte/app/wordpress/client.py'))
module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
token='local-test-'*6
local=httpx.Client(base_url='http://127.0.0.1:18086',headers={'Host':'receiver.test'},trust_env=False,follow_redirects=False)
class LocalTransport(httpx.BaseTransport):
    lose_ack=False
    def handle_request(self,request):
        path=request.url.raw_path.decode()
        response=local.request(request.method,path,content=request.content,headers=dict(request.headers))
        if self.lose_ack and request.method=='PUT' and response.is_success:
            self.lose_ack=False
            raise httpx.ReadTimeout('injected lost ACK after real installation',request=request)
        return httpx.Response(response.status_code,headers=response.headers,content=response.content,request=request)
transport=LocalTransport()
client=module.WordPressClient(SimpleNamespace(wp_base_url='https://receiver.test/wp-json/oap-bridge/v1/',wp_token=token),transport=transport)
gtin='00000000000111'
def payload(version=1,slug='http-product',state='active',policy='temporary'):
    return dict(protocol='oap-wp-1',gtin=gtin,slug=slug,version=version,content_hash=f'{version:064x}',html='<article><h1>HTTP product</h1><p>Offerta EUR 10</p></article>',
    seo=dict(title='SEO HTTP',description='Descrizione HTTP',canonical=f'https://receiver.test/prodotti-ponte/{slug}/',indexable=True,modified_at=1700000000,jsonld={'@context':'https://schema.org','@type':'Product','name':'</script><script>alert(1)</script>'}),
    state=state,redirects=['http-product'] if version>1 else [],removal_policy=policy)
assert client.installed(gtin)['version']==0
transport.lose_ack=True
try: client.publish(payload())
except module.RemoteError as e: assert str(e)=='remote_transport'
else: raise AssertionError('lost ACK not simulated')
r=client.publish(payload()); assert r['operation']=='unchanged' and r['remote_id']==f'oap:{gtin}'
print('PASS: unchanged Ponte Python client, real HTTP and lost ACK recovery')
r=local.get('/prodotti-ponte/http-product/'); assert r.status_code==200,(r.status_code,r.text)
assert r.text.count('rel="canonical"')==1 and r.text.count('application/ld+json')==1
assert '</script><script>alert(1)' not in r.text and '\\u003C' in r.text
assert r.headers['x-robots-tag']=='index, follow'
print('PASS: active HTTP, single canonical, single escaped JSON-LD')
r=local.get('/wp-sitemap-oap-1.xml'); assert r.status_code==200 and 'http-product/' in r.text and '2023-11-14T22:13:20Z' in r.text,(r.status_code,r.text[:500])
print('PASS: live WordPress sitemap provider')
client.publish(payload(2,'http-renamed'))
r=local.get('/prodotti-ponte/http-product/'); assert r.status_code==301 and r.headers['location']=='https://receiver.test/prodotti-ponte/http-renamed/'
print('PASS: historical slug direct 301')
client.publish(payload(3,'http-renamed','inactive'))
r=local.get('/prodotti-ponte/http-renamed/'); assert r.status_code==200 and 'noindex' in r.headers['x-robots-tag'] and 'Offerta EUR 10' not in r.text and 'application/ld+json' not in r.text
assert 'http-renamed/' not in local.get('/wp-sitemap-oap-1.xml').text
print('PASS: temporary inactive 200/noindex, no offers, excluded sitemap')
client.publish(payload(4,'http-renamed','inactive','gone'))
for slug in ['http-product','http-renamed']:
    r=local.get(f'/prodotti-ponte/{slug}/'); assert r.status_code==410 and 'location' not in r.headers
print('PASS: gone 410 on canonical and historical aliases')
client.publish(payload(5,'http-renamed'))
assert local.get('/prodotti-ponte/http-renamed/').status_code==200
assert local.get('/wp-json/oap-bridge/v1/pages/'+gtin).status_code==401
assert 'no-store' in local.get('/wp-json/oap-bridge/v1/pages/'+gtin).headers['cache-control']
print('PASS: reactivation and private uncached API errors')
client.close(); local.close()
