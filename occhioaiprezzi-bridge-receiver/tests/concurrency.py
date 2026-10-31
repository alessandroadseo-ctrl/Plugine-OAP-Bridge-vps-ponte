"""Real parallel WordPress processes against the disposable MariaDB test database."""
import concurrent.futures
import json
import subprocess

PHP = 'oap-receiver-php'
DB = 'oap-receiver-db'
FILE = 'wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/concurrent-worker.php'
def command(mode):
    return ['docker','exec',PHP,'wp','--allow-root','eval-file',FILE,mode]
def run(mode):
    return subprocess.check_output(command(mode),text=True).strip()
run('reset')
for mode,operation in [('put','created'),('update','updated')]:
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        rows=[json.loads(r) for r in pool.map(run,[mode]*6)]
    assert sum(r['operation']==operation for r in rows)==1, rows
    assert len({r['remote_id'] for r in rows})==1
    assert sum(r['operation']=='unchanged' for r in rows)==5
    print(f'PASS: six concurrent {mode} commands, one {operation} and five confirmations')
pid=int(run('seed'))
lock=subprocess.Popen(command('hold'),stdout=subprocess.PIPE,text=True)
assert lock.stdout.readline().strip()=='LOCKED'
sql=f"INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES ({pid},'_global_unique_id','00000000000999')"
# The receiver must no longer lock/read Woo metadata or wait for inventory arbitration.
subprocess.run(['docker','exec',DB,'mariadb','-uroot','-poap-local-test-only','oap_test','-e',sql],check=True,capture_output=True,timeout=2)
assert lock.poll() is None, 'metadata write must complete before receiver window ends'
assert lock.wait(timeout=10)==0
print('PASS: Woo metadata writer proceeds during receiver lock window')
subprocess.run(['docker','exec',PHP,'wp','--allow-root','post','delete',str(pid),'--force'],check=True,capture_output=True)
subprocess.run(['docker','exec',PHP,'wp','--allow-root','--skip-plugins=woocommerce','eval-file',FILE,'standalone'],check=True)
