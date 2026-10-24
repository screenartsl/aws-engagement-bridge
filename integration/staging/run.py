#!/usr/bin/env python3
"""Disposable native PostgreSQL/Redis staging. No AWS calls, production boot or deploy."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import signal
import subprocess
import tarfile
import tempfile
import time

MODULE = Path(__file__).resolve().parents[2]
BASE = 'b8e9bf8c74789689a7cebddd1856a094e380c9d9'
HOOKS = {'app/Services/WhatsAppSenderService.php', 'app/Services/AutomationService.php', 'app/AI/AgentExecutor.php'}
EXTENSION_EDITS={'config/app.php','routes/central.php','resources/views/superadmin/layouts/layout.blade.php','resources/views/superadmin/modules/index.blade.php'}
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--core-source', type=Path, required=True, help='Read-only Git source; no .env or customer files are copied')
parser.add_argument('--core-ref', default=BASE, help='Reviewed baseline compatible with core-hooks.patch')
parser.add_argument('--pg-bin', type=Path, default=Path('/usr/lib/postgresql/16/bin'))
parser.add_argument('--dependencies-from',type=Path,help='Optional disposable phase 3 vendor directory; never production vendor')
args = parser.parse_args()
if os.geteuid() == 0:
    raise SystemExit('Run as an unprivileged development user, not root')
for executable in ['php', 'composer', 'node', 'npm', 'redis-server', 'redis-cli', 'git', 'unshare']:
    if not shutil.which(executable): raise SystemExit('Missing prerequisite: '+executable)
for executable in ['initdb', 'pg_ctl', 'psql', 'createdb']:
    if not (args.pg_bin/executable).is_file(): raise SystemExit('Missing PostgreSQL binary '+executable)
subprocess.run(['unshare','--user','--map-root-user','--net','true'], check=True)
root = Path(tempfile.mkdtemp(prefix='muserelay-bridge-phase3-', dir='/tmp'))
root.chmod(0o700)
(root/'.bridge-staging').write_text('synthetic-only\n')
(root/'host-netns').write_text(os.readlink('/proc/self/ns/net'))
print('Staging runtime: '+str(root), flush=True)
core = root/'core'; core.mkdir()
base_env = {'PATH':os.environ.get('PATH','/usr/local/bin:/usr/bin:/bin'), 'LC_ALL':'C.UTF-8',
            'COMPOSER_HOME':str(root/'composer-home'), 'COMPOSER_CACHE_DIR':str(root/'composer-cache'),
            'AWS_EC2_METADATA_DISABLED':'true', 'COMPOSER_NO_INTERACTION':'1'}
stage_env = dict(base_env, APP_ENV='testing', BRIDGE_STAGE_ROOT=str(root))
network = ['unshare','--user','--map-root-user','--net']

def run(command, label, env=None, cwd=MODULE, timeout=120, input_bytes=None):
    print(label, flush=True)
    with (root/(label+'.log')).open('wb') as log:
        process = subprocess.Popen([str(c) for c in command], cwd=cwd, env=env or base_env, stdout=log, stderr=subprocess.STDOUT,
                                   stdin=subprocess.PIPE if input_bytes is not None else subprocess.DEVNULL, start_new_session=True)
        try:
            process.communicate(input_bytes, timeout=timeout)
        except BaseException:
            os.killpg(process.pid, signal.SIGTERM)
            process.wait(timeout=10)
            raise
    if process.returncode:
        print((root/(label+'.log')).read_text(errors='replace')[-6500:], flush=True)
        raise RuntimeError(label+' failed; private log: '+str(root/(label+'.log')))

pg_started = redis_started = False
report = None
started = time.monotonic()
try:
    # Archive ONLY the selected committed tree. Exclude envs, runtime files and cached bootstrap.
    archive = subprocess.check_output(['git','-C',str(args.core_source),'archive',args.core_ref,'--','app','bootstrap','config','resources','tests','database','routes','composer.json','composer.lock','artisan'], env=base_env)
    archive_path = root/'core.tar'; archive_path.write_bytes(archive)
    with tarfile.open(archive_path) as tar:
        members = [m for m in tar.getmembers() if not m.issym() and not m.islnk() and not any(part.startswith('.env') or part in ['vendor','node_modules'] for part in Path(m.name).parts)
                   and not m.name.startswith(('storage/', 'bootstrap/cache/'))]
        tar.extractall(core, members=members, filter='data')
    archive_path.unlink()
    hashes = {str(p.relative_to(core)): hashlib.sha256(p.read_bytes()).hexdigest() for p in core.rglob('*') if p.is_file()}
    run(['git','apply','--check',str(MODULE/'integration/muserelay/core-hooks.patch')], 'hooks-check', cwd=core)
    run(['git','apply',str(MODULE/'integration/muserelay/core-hooks.patch')], 'hooks-apply', cwd=core)
    run(['git','apply','--check',str(MODULE/'integration/muserelay/core-extension-support.patch')], 'extensions-check', cwd=core)
    run(['git','apply',str(MODULE/'integration/muserelay/core-extension-support.patch')], 'extensions-apply', cwd=core)
    changed = {name for name, digest in hashes.items() if hashlib.sha256((core/name).read_bytes()).hexdigest() != digest}
    if changed != HOOKS|EXTENSION_EDITS: raise RuntimeError('Unexpected Core overlay: '+repr(changed))
    (root/'core-overlay.json').write_text(json.dumps({'base':args.core_ref,'changed':sorted(changed),'unchangedFiles':len(hashes)-len(changed)},indent=2))
    for directory in ['bootstrap/cache','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs']:
        (core/directory).mkdir(parents=True,exist_ok=True)
    for directory in ['pgsock','storage/framework/views','storage/framework/cache/data','storage/framework/sessions','storage/logs','storage/app','no-dotenv']:
        (root/directory).mkdir(parents=True,exist_ok=True)
    target=core/'modules/aws-engagement-bridge';target.mkdir(parents=True)
    for name in ['src','config','database'] : shutil.copytree(MODULE/'integration/muserelay'/name,target/name)
    for name in ['BridgeSignature.php','module.php','composer.json']:shutil.copy2(MODULE/'integration/muserelay'/name,target/name)
    if args.dependencies_from:
        dependency_path=args.dependencies_from.resolve()
        if not str(dependency_path).startswith('/tmp/muserelay-bridge-phase3-') or dependency_path.name!='vendor':raise RuntimeError('Only disposable phase 3 dependencies may be reused')
        shutil.copytree(dependency_path,core/'vendor')
    run(['composer','install','--no-scripts','--no-plugins','--prefer-dist','--no-interaction'], 'core-dependencies', cwd=core, timeout=600)
    run(network+['php','integration/staging/folder-autoload.php'],'folder-autoload',env=stage_env)
    manifest_path = core/'composer.json'; manifest=json.loads(manifest_path.read_text())
    package=json.loads((MODULE/'integration/muserelay/composer.json').read_text())
    manifest['repositories']=[{'type':'path','url':str(MODULE/'integration/muserelay'),'options':{'symlink':False}}]
    manifest['require'][package['name']]=package['version']; manifest_path.write_text(json.dumps(manifest,indent=2))
    run(['composer','update',package['name'],'--no-scripts','--no-plugins','--no-interaction'], 'connector-install', cwd=core, timeout=600)
    (core/'bootstrap/cache/packages.php').write_text('<?php return [];\n')
    run([args.pg_bin/'initdb','-D',root/'postgres','--auth-local=trust','--auth-host=reject','--encoding=UTF8','--no-locale'], 'postgres-init')
    run([args.pg_bin/'pg_ctl','-D',root/'postgres','-l',root/'postgres.log','-o',f"-k {root}/pgsock -p 55432 -c listen_addresses='' -c shared_buffers=32MB -c max_connections=20",'start'], 'postgres-start')
    pg_started=True
    admin=pwd.getpwuid(os.geteuid()).pw_name
    run([args.pg_bin/'psql','-h',root/'pgsock','-p','55432','-U',admin,'-d','postgres','-v','ON_ERROR_STOP=1','-c',
         'CREATE ROLE bridge_stage LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;'], 'postgres-role')
    for database in ['bridge_phase3_test','bridge_unit_test']:
        run([args.pg_bin/'createdb','-h',root/'pgsock','-p','55432','-U',admin,'-O','bridge_stage',database],database+'-create')
    run(['redis-server','--port','0','--unixsocket',root/'redis.sock','--unixsocketperm','700','--daemonize','yes','--pidfile',root/'redis.pid',
         '--logfile',root/'redis.log','--save','','--appendonly','no','--maxmemory','64mb','--maxmemory-policy','noeviction'], 'redis-start')
    redis_started=True
    # Dependencies may be fetched before this point; ALL application/test execution has no network.
    if not (MODULE/'node_modules').is_dir():
        run(['npm','ci','--ignore-scripts','--no-audit','--no-fund'],'node-dependencies',timeout=600)
    run(network+['npm','test'], 'node-contract-tests', env=stage_env)
    unit_env=dict(stage_env, MUSERELAY_TEST_VENDOR=str(core/'vendor/autoload.php'),MUSERELAY_CORE_TEST_SOURCE=str(core))
    run(network+['php',core/'vendor/bin/phpunit','-c','integration/muserelay/phpunit.xml'],'sqlite-contract-tests',env=unit_env)
    run(network+['php',core/'vendor/bin/phpunit','-c','integration/muserelay/phpunit.xml'],'postgres-contract-tests',env=dict(unit_env,BRIDGE_TEST_PG_SOCKET=str(root/'pgsock')))
    run(network+['php','integration/staging/console.php','setup'], 'native-schema',env=stage_env)
    for turn in ['1','2']:
        run(network+['node','integration/staging/flow.mjs',turn], 'native-turn-'+turn,env=stage_env)
    run(network+['node','integration/staging/concurrency.mjs'],'postgres-concurrency',env=stage_env)
    run(network+['php','integration/staging/console.php','extensions'],'extension-controls',env=stage_env)
    run(network+['php','integration/staging/console.php','assertions'],'native-assertions',env=stage_env)
    run(network+['php','integration/staging/console.php','regression'],'legacy-regression',env=stage_env)
    run(network+['php','integration/staging/console.php','disabled'],'disabled-connector',env=dict(stage_env,BRIDGE_STAGE_DISABLED='1'))
    report={'result':'PASS','elapsedSeconds':round(time.monotonic()-started,1),'coreBase':args.core_ref,
            'hookFiles':sorted(HOOKS),'network':'isolated Linux network namespace; no interface enabled',
            'postgres':'16, private Unix socket, non-superuser role','redis':'private Unix socket, real Laravel queue workers',
            'aws':'simulated SDK transports, SNS/SQS/DynamoDB; no deployment or real sends',
            'billing':'synthetic usage tracker; not a charging acceptance test','artifacts':str(root)}
finally:
    cleanup_errors=[]
    if redis_started:
        try: run(['redis-cli','-s',root/'redis.sock','SHUTDOWN','NOSAVE'],'redis-stop')
        except Exception: cleanup_errors.append('redis-stop')
    if pg_started:
        try: run([args.pg_bin/'pg_ctl','-D',root/'postgres','-m','fast','-w','stop'],'postgres-stop')
        except Exception: cleanup_errors.append('postgres-stop')
    if report is not None:
        report['servicesStopped'] = not cleanup_errors
        if cleanup_errors: report['result']='FAIL'
        (root/'result.json').write_text(json.dumps(report,indent=2))
        print(json.dumps(report,indent=2),flush=True)
    if cleanup_errors: raise RuntimeError('Private staging cleanup failed: '+', '.join(cleanup_errors))
    print('Owned staging services stopped. Synthetic evidence retained at '+str(root),flush=True)
