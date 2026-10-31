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
pid=int(run('seed'))
lock=subprocess.Popen(command('hold'),stdout=subprocess.PIPE,text=True)
assert lock.stdout.readline().strip()=='LOCKED'
start=time.monotonic()
sql=f"INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES ({pid},'_test_gtin','00000000000998')"
subprocess.run(['docker','exec',DB,'mariadb','-uroot','-poap-local-test-only','oap_test','-e',sql],check=True,capture_output=True)
elapsed=time.monotonic()-start
assert elapsed>1.5, elapsed
assert lock.wait(timeout=10)==0
print(lock.stdout.read().strip())
print('PASS: unrelated Woo SQL writer blocked until receiver releases table locks')
subprocess.run(['docker','exec',PHP,'wp','--allow-root','post','delete',str(pid),'--force'],check=True,capture_output=True)
subprocess.run(['docker','exec',PHP,'wp','--allow-root','--skip-plugins=woocommerce','eval-file',FILE,'unavailable'],check=True)
