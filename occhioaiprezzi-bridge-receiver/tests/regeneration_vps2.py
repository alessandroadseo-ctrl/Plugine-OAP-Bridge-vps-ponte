"""Real VPS2 poller -> real WordPress REST + admin AJAX, disposable databases only.

Run using vps-ponte's frozen Python environment, pass its checkout path.
The only transport adaptation maps HTTPS receiver.test to the local HTTP harness
(which simulates trusted TLS termination). No protocol peer is mocked.
"""
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path
from uuid import uuid4

ROOT = Path(__file__).resolve().parents[2]
VPS = Path(sys.argv[1]).resolve()
PIN = "67f1885574bfc4055ff09fc650a14d058c9f816d"
assert subprocess.check_output(["git", "-C", str(VPS), "rev-parse", "HEAD"], text=True).strip() == PIN
sys.path.insert(0, str(VPS))
import httpx  # noqa: E402
from alembic import command as migrations  # noqa: E402
from alembic.config import Config  # noqa: E402
from sqlalchemy import func, select  # noqa: E402
from app.config import Settings  # noqa: E402
from app.db.session import database  # noqa: E402
from app.models.tables import Product, RegenerationCampaign, RegenerationCommand  # noqa: E402
from app.services.regeneration import status, tick  # noqa: E402
from app.services.regeneration_control import pending_receipt, execute, Command  # noqa: E402
from app.wordpress.regeneration import RegenerationPoller  # noqa: E402


def wp(code):
    return subprocess.check_output([
        "docker", "exec", "oap-receiver-php", "wp", "--allow-root", "eval", code,
    ], text=True).strip()


# Refuse to touch any other WordPress/database. This fixture never deploys.
assert wp('echo DB_NAME . "|" . home_url();') == 'oap_test|https://receiver.test'
CONFIG = ROOT / "work/oap-receiver-tests/wordpress/wp-config.php"
original = CONFIG.read_text()
TOKEN = "p" * 48
settings = "define('OAP_REGENERATION_POLL_TOKEN',str_repeat('p',48));\ndefine('OAP_REGENERATION_WORKER_ID','vps2-test');\n"
CONFIG.write_text(original.replace("require_once ABSPATH.'wp-settings.php';", settings + "require_once ABSPATH.'wp-settings.php';"))


class LocalTransport(httpx.BaseTransport):
    def __init__(self):
        self.http = httpx.Client(trust_env=False, timeout=10)
        self.lose_ack = False
        self.commands = []
        self.receipts = []

    def handle_request(self, request):
        assert request.url.host == 'receiver.test' and request.url.scheme == 'https'
        response = self.http.request(request.method, 'http://127.0.0.1:18086' + request.url.path,
                                     headers={**request.headers, 'host': 'receiver.test'}, content=request.content)
        body = response.json()
        assert response.status_code == 200, (response.status_code, body)
        assert 'no-store' in response.headers['cache-control']
        if request.url.path.endswith('/poll') and body.get('command'):
            self.commands.append(body['command'])
        if request.url.path.endswith('/ack'):
            self.receipts.append(json.loads(request.content))
            if self.lose_ack:
                self.lose_ack = False
                raise httpx.ReadTimeout('test: response lost after WordPress commit')
        return httpx.Response(response.status_code, json=body)

    def close(self):
        self.http.close()


try:
    wp('global $wpdb; $db=new OAP_Regeneration_Store(); $wpdb->query("DELETE FROM {$db->commands}"); $wpdb->query("DELETE FROM {$db->workers}");')
    browser = httpx.Client(base_url='http://127.0.0.1:18086', headers={'host': 'receiver.test'}, trust_env=False)
    browser.get('/wp-login.php')
    browser.headers['cookie'] = '; '.join(f'{c.name}={c.value}' for c in browser.cookies.jar)
    login = browser.post('/wp-login.php', data={'log':'oap_test_admin','pwd':'oap-local-test-password','wp-submit':'Log In','testcookie':'1'})
    assert login.status_code == 302, login.status_code
    browser.headers['cookie'] = '; '.join(f'{c.name}={c.value}' for c in browser.cookies.jar)
    page = browser.get('/wp-admin/admin.php?page=oap-regeneration')
    assert page.status_code == 200, (page.status_code, page.headers.get('location'))
    nonce = json.loads(re.search(r'var oapRegeneration = (\{.*?\});', page.text).group(1))['nonce']
    assert TOKEN not in page.text

    def admin(action='status', key=None, target=None, sequence=0, nonce_value=nonce):
        data={'action':'oap_regeneration','command':action,'nonce':nonce_value,
              'idempotency_key':key or str(uuid4()),'campaign_id':target or '', 'sequence':str(sequence)}
        response = browser.request('GET' if action == 'status' else 'POST', '/wp-admin/admin-ajax.php',
                                   params=data if action == 'status' else None, data=data if action != 'status' else None)
        return response.status_code, response.json()

    assert admin('start', nonce_value='bad')[0] == 403
    assert admin()[1]['data']['stale']
    with tempfile.TemporaryDirectory(prefix='oap-regeneration-') as temp:
        dburl = f'sqlite:///{temp}/vps2.db'
        os.environ['BRIDGE_DATABASE_URL'] = dburl
        os.environ['BRIDGE_API_TOKEN'] = 't' * 32
        os.chdir(VPS)
        migrations.upgrade(Config('alembic.ini'), 'head')
        engine, sessions = database(dburl)
        cfg = Settings(database_url=dburl, api_token='t'*32, artifact_dir=Path(temp)/'artifacts',
                       regeneration_poll_url='https://receiver.test/wp-json/oap-bridge/v1/regeneration/',
                       regeneration_poll_token=TOKEN, regeneration_worker_id='vps2-test',
                       regeneration_poll_interval_seconds=1)
        # A real product in the disposable VPS2 snapshot keeps the campaign active.
        with sessions.begin() as session:
            session.add(Product(gtin='00012345678905', data={}, source_version=0))
        transport = LocalTransport()
        poller = RegenerationPoller(cfg, sessions, transport=transport)
        poller.run_once(now=1)
        assert not admin()[1]['data']['stale']
        assert status(sessions)['state'] == 'idle'
        key = str(uuid4())
        assert admin('start',key)[1]['data']['pending']
        assert admin('start',key)[0] == 200  # Simulated lost local response, same key.
        assert admin('start')[0] == 409  # Double click with another key.
        transport.lose_ack = True
        poller.run_once(now=3)
        assert pending_receipt(sessions,'vps2-test') is not None
        first = transport.receipts[0]
        cid = first['campaign']['campaign_id']
        poller.close(); engine.dispose()
        # Restart Python client and DB engine; WP requests are separate PHP processes.
        engine, sessions = database(dburl)
        transport = LocalTransport()
        poller = RegenerationPoller(cfg, sessions, transport=transport)
        poller.run_once(now=1)
        assert transport.receipts == [first] and not transport.commands
        assert pending_receipt(sessions,'vps2-test') is None
        assert not admin()[1]['data']['synchronized']
        poller.run_once(now=3)
        assert admin()[1]['data']['campaign'] == status(sessions)
        assert admin('pause',target=cid,sequence=1)[0] == 200
        poller.run_once(now=5); poller.run_once(now=7)
        assert status(sessions)['state']=='paused'
        assert admin()[1]['data']['campaign']['state']=='paused'
        assert not tick(sessions,cfg)  # Paused: no new jobs.
        assert admin('resume',target=cid,sequence=2)[0]==200
        poller.run_once(now=9); poller.run_once(now=11)
        assert status(sessions)['state']=='running'
        # Old pause receipt is replayed without mutating current campaign/snapshot.
        pause_command = Command.model_validate(transport.commands[0])
        receipt = execute(sessions,'vps2-test',pause_command)
        before = admin()[1]['data']['campaign']
        echo = poller.request('ack',receipt)
        assert echo['receipt_hash']==receipt['receipt_hash']
        assert status(sessions)['state']=='running' and admin()[1]['data']['campaign']==before
        with sessions() as session:
            assert session.scalar(select(func.count()).select_from(RegenerationCampaign)) == 1
            assert session.scalar(select(func.count()).select_from(RegenerationCommand)) == 3
        assert admin('start')[0]==409
        assert status(sessions)==admin()[1]['data']['campaign']
        poller.close(); engine.dispose()
    browser.close()
    print('PASS: real VPS2 poller + WordPress REST/admin AJAX: start/pause/resume, double click, lost ACK, restart, historical replay, identical counters, one campaign/three commands')
finally:
    CONFIG.write_text(original)
    wp('global $wpdb; $db=new OAP_Regeneration_Store(); $wpdb->query("DELETE FROM {$db->commands}"); $wpdb->query("DELETE FROM {$db->workers}");')
