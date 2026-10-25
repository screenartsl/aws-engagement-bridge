#!/usr/bin/env python3
"""Disposable PG/Redis + native HTTP kernel checks in an offline namespace. No live consumers or deployment."""
import argparse,base64,importlib.util,json,os,pwd,secrets,shutil,signal,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--core-source',type=Path,required=True);parser.add_argument('--dependencies-from',type=Path,required=True);parser.add_argument('--browser-tools',type=Path);args=parser.parse_args()
    if os.geteuid()==0:raise ValueError('Run as unprivileged developer')
    dep=args.dependencies_from.resolve()
    if not str(dep).startswith('/tmp/muserelay-bridge-phase3-') or dep.name!='vendor' or not (dep/'autoload.php').is_file():raise ValueError('Use disposable dependencies only, never production vendor')
    subprocess.run(['unshare','--user','--map-root-user','--net','true'],check=True)
    spec=importlib.util.spec_from_file_location('panel_source',ROOT/'scripts/phase4/prepare-panel-source.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);out=m.prepare(args.core_source)
    print('Synthetic panel root: '+str(out),flush=True)
    for folder in ['pgsock','data/storage/app','data/storage/framework/views','data/storage/framework/sessions','data/storage/framework/cache/data','data/storage/logs','data/cache','no-dotenv']:(out/folder).mkdir(parents=True,exist_ok=True)
    shutil.copytree(dep,out/'core/vendor')
    # Prepare only local cache files; no artisan, Composer scripts or production bootstrap.
    for name in ['packages','services']:(out/'core/bootstrap/cache').mkdir(exist_ok=True,parents=True)
    (out/'core/bootstrap/cache/packages.php').write_text('<?php return [];\n')
    c={'hostname':'aws-bridge-staging.muserelay.com','organizationId':97101,'workspaceId':10,'channelId':20,'userId':97101,'enabled':False,'receiveEnabled':False,'agentEnabled':False,'sendEnabled':False,'expiresAt':int(time.time())+3600}
    for key in ['appKey','messagesKey','indexKey']:c[key]='base64:'+base64.b64encode(secrets.token_bytes(32)).decode()
    for name,data in [('panel-local.json',c),('evaluation-local.json',{'password':secrets.token_urlsafe(32)})]:
        p=out/name;p.write_text(json.dumps(data));p.chmod(0o600)
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LC_ALL':'C.UTF-8','APP_ENV':'testing','BRIDGE_PANEL_ROOT':str(out),'AWS_EC2_METADATA_DISABLED':'true'}
    for key,name in [('APP_CONFIG_CACHE','config'),('APP_SERVICES_CACHE','services'),('APP_PACKAGES_CACHE','packages'),('APP_ROUTES_CACHE','routes')]:env[key]=str(out/'data/cache'/f'{name}.php')
    if args.browser_tools:env['BRIDGE_PANEL_BROWSER_CHECK']='1'
    pg=Path('/usr/lib/postgresql/16/bin');started_pg=started_redis=False
    def run(cmd,label,network=False):
        print(label,flush=True)
        with (out/(label+'.log')).open('w')as log:
            p=subprocess.run((['unshare','--user','--map-root-user','--net']if network else[])+list(map(str,cmd)),env=env,cwd=ROOT,stdout=log,stderr=subprocess.STDOUT,timeout=180)
        if p.returncode:raise RuntimeError(label+' failed: '+(out/(label+'.log')).read_text()[-8000:])
    try:
        run([pg/'initdb','-D',out/'postgres','--auth-local=trust','--auth-host=reject','--encoding=UTF8','--no-locale'],'initdb')
        run([pg/'pg_ctl','-D',out/'postgres','-l',out/'postgres.log','-o',f"-k {out}/pgsock -p 55435 -c listen_addresses='' -c shared_buffers=32MB -c max_connections=12",'start'],'postgres-start');started_pg=True
        run([pg/'psql','-h',out/'pgsock','-p','55435','-d','postgres','-v','ON_ERROR_STOP=1','-c','CREATE ROLE bridge_panel LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;'],'role')
        run([pg/'createdb','-h',out/'pgsock','-p','55435','-O','bridge_panel','bridge_panel_test'],'database')
        run(['redis-server','--port','0','--unixsocket',out/'redis.sock','--unixsocketperm','700','--daemonize','yes','--pidfile',out/'redis.pid','--logfile',out/'redis.log','--save','','--appendonly','no','--maxmemory','32mb','--maxmemory-policy','noeviction'],'redis-start');started_redis=True
        run(['php',out/'bridge/integration/panel-staging/check.php'],'native-panel',network=True)
        print((out/'native-panel.log').read_text()[-5000:],flush=True)
        if args.browser_tools:
            tools=args.browser_tools.resolve()
            if not str(tools).startswith('/tmp/muserelay-bridge-browser-tools-'):raise ValueError('Private browser tool path required')
            env['PLAYWRIGHT_BROWSERS_PATH']=str(tools/'browsers')
            run(['node',out/'bridge/integration/panel-staging/browser.mjs',tools],'native-browser',network=True)
            print((out/'native-browser.log').read_text()[-2500:],flush=True)
    finally:
        # Disarm the disposable fixture before stopping its private services, on both success and failure.
        (out/'panel-local.json').write_text(json.dumps(c));(out/'panel-local.json').chmod(0o600)
        if started_pg:subprocess.run([str(pg/'psql'),'-h',str(out/'pgsock'),'-p','55435','-U','bridge_panel','-d','bridge_panel_test','-c','UPDATE aws_bridge_bindings SET enabled=false;'],env=dict(env,PGCONNECT_TIMEOUT='3',PGOPTIONS='-c statement_timeout=3000'),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        if started_redis:subprocess.run(['redis-cli','-s',str(out/'redis.sock'),'shutdown','nosave'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        if started_pg:subprocess.run([str(pg/'pg_ctl'),'-D',str(out/'postgres'),'-m','fast','stop'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        if (out/'postgres/postmaster.pid').exists() or (out/'redis.pid').exists():raise RuntimeError('Disposable services did not stop cleanly')
        print('Owned disposable panel services stopped: '+str(out),flush=True)
if __name__=='__main__':main()
