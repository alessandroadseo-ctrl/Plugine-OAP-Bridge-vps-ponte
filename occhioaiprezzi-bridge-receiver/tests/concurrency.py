"""Host runner, requires the disposable Docker setup described in tests/README.md."""
import concurrent.futures
import json
import subprocess
import time

PHP = 'oap-receiver-php'
DB = 'oap-receiver-db'
FILE = 'wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/concurrent-worker.php'
def command(mode):
    return ['docker','exec',PHP,'wp','--allow-root','eval-file',FILE,mode]
def run(mode):
    return subprocess.check_output(command(mode),text=True).strip()
run('reset')
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    rows=[json.loads(r) for r in pool.map(run,['put']*6)]
assert sum(r['operation']=='created' for r in rows)==1, rows
assert len({r['remote_id'] for r in rows})==1
assert sum(r['operation']=='unchanged' for r in rows)==5
print('PASS: six concurrent identical writes, one creation and five confirmations')
run('reset')
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    rows=[json.loads(r) for r in pool.map(run,['put','conflict']*3)]
assert sum(r.get('operation')=='created' for r in rows)==1, rows
assert sum(r.get('status')==409 for r in rows)==3, rows
print('PASS: competing create requests cannot overwrite one GTIN')
run('reset')
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    rows=[json.loads(r) for r in pool.map(run,['put','slug-race']*3)]
assert sum(r.get('operation')=='created' for r in rows)==1, rows
assert sum(r.get('error')=='oap_slug_conflict' for r in rows)==3, rows
print('PASS: concurrent GTINs cannot claim the same slug')
run('reset')
r=subprocess.check_output(['docker','exec',PHP,'wp','--allow-root','--skip-plugins=woocommerce','eval-file',FILE,'put'],text=True)
assert json.loads(r)['operation']=='created'
print('PASS: receiver creates without WooCommerce loaded')
