"""Real HTTP; unchanged legacy client's behavior plus a LOCAL example of the required VPS flow.
No production client or VPS repository is modified. TLS is simulated only in wp-config.php.
"""
import importlib.util
import os
import subprocess
from types import SimpleNamespace
import httpx

spec=importlib.util.spec_from_file_location('bridge_wp_client',os.environ.get('OAP_REFERENCE_CLIENT','/workspace/vps-ponte/app/wordpress/client.py'))
module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
token='local-test-'*6
local=httpx.Client(base_url='http://127.0.0.1:18086',headers={'Host':'receiver.test'},trust_env=False,follow_redirects=False)
class LocalTransport(httpx.BaseTransport):
    lose_ack=False
    paths=[]
    def handle_request(self,request):
        path=request.url.raw_path.decode(); self.paths.append(path)
        response=local.request(request.method,path,content=request.content,headers=dict(request.headers))
        if self.lose_ack and request.method=='PUT' and response.is_success:
            self.lose_ack=False
            raise httpx.ReadTimeout('injected lost ACK after real installation',request=request)
        return httpx.Response(response.status_code,headers=response.headers,content=response.content,request=request)
transport=LocalTransport()
client=module.WordPressClient(SimpleNamespace(wp_base_url='https://receiver.test/wp-json/oap-bridge/v1/',wp_token=token),transport=transport)
gtin='00000000000111'
def payload(version=1,slug='http-product',state='active',policy='temporary',redirects=None,indexable=True):
    return dict(protocol='oap-wp-1',gtin=gtin,slug=slug,version=version,content_hash=f'{version:064x}',
    html='<article><h1>Testo pronto VPS</h1><p>Offerta EUR 10</p></article>' if state=='active' else '<h1>Messaggio unico preparato dalla VPS</h1>',
    seo=dict(title='SEO VPS '+state,description='Descrizione HTTP VPS',canonical=f'https://receiver.test/canonical-vps/{slug}/',indexable=indexable if state=='active' else False,modified_at=1700000000,jsonld={'@context':'https://schema.org','@type':'Product','name':'</script><script>alert(1)</script>'} if state=='active' else None),
    state=state,redirects=redirects or [],removal_policy=policy)
def prepare(p):
    # This choice lives in the VPS simulation, never inside the WordPress receiver.
    remote=client.installed(p['gtin'])
    assert remote['ownership'] in ('absent','bridge')
    return {**p,'operation':'create' if remote['ownership']=='absent' else 'update'}
def submit(command):
    result=client.request('PUT',f"pages/{command['gtin']}",json=command,headers={'Idempotency-Key':f"{command['content_hash']}:{command['version']}"})
    assert result['gtin']==command['gtin'] and result['version']==command['version'] and result['installed_hash']==command['content_hash']
    return result
assert client.installed(gtin)['version']==0
try: client.publish(payload())
except module.RemoteError as e: assert str(e)=='remote_http_410' and e.permanent
else: raise AssertionError('legacy eligibility client must not receive fabricated permission')
assert client.installed(gtin)['version']==0
print('PASS: legacy publish stops at retired eligibility with no writes; GET reconciliation remains compatible')
transport.paths=[]
command=prepare(payload()); transport.lose_ack=True
try: submit(command)
except module.RemoteError as e: assert str(e)=='remote_transport'
else: raise AssertionError('lost ACK not simulated')
r=submit(command); assert r['operation']=='unchanged' and r['remote_id']==f'oap:{gtin}'
assert not any('eligibility' in path for path in transport.paths)
print('PASS: VPS-selected create, direct authenticated PUT and lost ACK retry without eligibility')
r=local.get('/prodotti-ponte/http-product/'); assert r.status_code==200,(r.status_code,r.text)
assert r.text.count('rel="canonical"')==1 and f'href="{command["seo"]["canonical"]}"' in r.text and r.text.count('application/ld+json')==1
assert command['html'] in r.text and '</script><script>alert(1)' not in r.text and '\\u003C' in r.text
assert r.headers['x-robots-tag']=='index, follow'
print('PASS: HTML, title, canonical and JSON-LD come from VPS without SEO regeneration')
r=local.get('/wp-sitemap-oap-1.xml'); assert r.status_code==200 and command['seo']['canonical'] in r.text and '2023-11-14T22:13:20Z' in r.text
print('PASS: sitemap uses received SEO canonical and modified_at')
# Add matching Woo metadata AFTER publication: receiver must not suppress the VPS decision.
php=f'$p=new WC_Product_Simple(); $p->set_name("HTTP Woo fixture"); $id=$p->save(); update_post_meta($id,"_global_unique_id","{gtin}"); echo $id;'
pid=int(subprocess.check_output(['docker','exec','oap-receiver-php','wp','--allow-root','eval',php],text=True).strip())
assert local.get('/prodotti-ponte/http-product/').status_code==200 and 'Offerta EUR 10' in local.get('/prodotti-ponte/http-product/').text
assert command['seo']['canonical'] in local.get('/wp-sitemap-oap-1.xml').text
print('PASS: later Woo inventory change cannot alter rendering or sitemap')
subprocess.run(['docker','exec','oap-receiver-php','wp','--allow-root','plugin','deactivate','woocommerce'],check=True,capture_output=True)
try:
    assert local.get('/prodotti-ponte/http-product/').status_code==200
    assert command['seo']['canonical'] in local.get('/wp-sitemap-oap-1.xml').text
finally:
    subprocess.run(['docker','exec','oap-receiver-php','wp','--allow-root','plugin','activate','woocommerce'],check=True,capture_output=True)
print('PASS: public rendering and sitemap work with WooCommerce deactivated')
submit(prepare(payload(2,'http-renamed')))
assert local.get('/prodotti-ponte/http-product/').status_code==404
print('PASS: slug change does not invent an unrequested redirect')
submit(prepare(payload(3,'http-renamed',redirects=['http-product'])))
r=local.get('/prodotti-ponte/http-product/'); assert r.status_code==301 and r.headers['location']=='https://receiver.test/prodotti-ponte/http-renamed/'
assert 'canonical-vps' not in r.headers['location']
print('PASS: only received alias redirects, fixed local target even with a different SEO canonical')
p=payload(4,'http-renamed','inactive',redirects=['http-product']); submit(prepare(p))
r=local.get('/prodotti-ponte/http-renamed/'); assert r.status_code==200 and 'noindex' in r.headers['x-robots-tag'] and p['html'] in r.text and p['seo']['title'] in r.text
assert 'Prodotto non disponibile' not in r.text and 'Offerta EUR 10' not in r.text and 'application/ld+json' not in r.text
assert p['seo']['canonical'] not in local.get('/wp-sitemap-oap-1.xml').text
print('PASS: temporary applies 200/noindex and VPS-provided inactive content without local fallback')
submit(prepare(payload(5,'http-renamed','inactive','gone',redirects=['http-product'])))
for slug in ['http-product','http-renamed']:
    r=local.get(f'/prodotti-ponte/{slug}/'); assert r.status_code==410 and 'location' not in r.headers
print('PASS: received gone policy applies 410 on canonical route and explicit aliases')
submit(prepare(payload(6,'http-renamed',indexable=False)))
r=local.get('/prodotti-ponte/http-renamed/'); assert r.status_code==200 and r.headers['x-robots-tag']=='noindex, nofollow'
assert 'canonical-vps/http-renamed/' not in local.get('/wp-sitemap-oap-1.xml').text
print('PASS: VPS controls reactivation and noindex independently of Woo inventory')
p=payload(7,'http-renamed'); p['html']=''; submit(prepare(p))
r=local.get('/prodotti-ponte/http-renamed/'); assert r.status_code==200 and '<main></main>' in r.text and 'Prodotto non disponibile' not in r.text
assert local.get('/wp-json/oap-bridge/v1/pages/'+gtin).status_code==401
assert 'no-store' in local.get('/wp-json/oap-bridge/v1/pages/'+gtin).headers['cache-control']
print('PASS: receiver does not invent missing body content; API remains private and uncached')
subprocess.run(['docker','exec','oap-receiver-php','wp','--allow-root','post','delete',str(pid),'--force'],check=True,capture_output=True)
client.close(); local.close()
