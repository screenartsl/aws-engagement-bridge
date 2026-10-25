#!/usr/bin/env python3
"""Archive committed Core source for an isolated native inbox; no data, secrets, install or deploy."""
import argparse,hashlib,io,json,os,re,shutil,subprocess,tarfile,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
CORE_REF='594a042c916120e353820ecbe12631473006abb2'
PARTS=['app','bootstrap','config','resources/views','resources/lang','tests/Support/SafetyGuard.php','tests/Unit/AI/Tools/Tickets/Concerns/BuildsTicketToolFixtures.php','routes','composer.json','composer.lock','artisan','public/assets']
def prepare(source):
    source=Path(source).resolve()
    if not (source/'.git').exists():raise ValueError('Explicit Git source required')
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LC_ALL':'C.UTF-8','GIT_TERMINAL_PROMPT':'0'}
    head=subprocess.check_output(['git','-C',str(source),'rev-parse','HEAD'],env=env,text=True).strip()
    if head!=CORE_REF:raise ValueError('Core HEAD changed: review/rebase against the tested commit before preparing')
    data=subprocess.check_output(['git','-C',str(source),'archive',CORE_REF,'--',*PARTS],env=env)
    out=Path(tempfile.mkdtemp(prefix='muserelay-bridge-panel-',dir='/tmp'));out.chmod(0o700)
    (out/'.bridge-panel-local').write_text('synthetic-only; not installed\n');(out/'host-netns').write_text(os.readlink('/proc/self/ns/net'))
    core=out/'core';core.mkdir()
    with tarfile.open(fileobj=io.BytesIO(data)) as archive:
        members=[]
        for m in archive.getmembers():
            path=Path(m.name)
            if not (m.isdir() or m.isfile()) or path.is_absolute() or '..' in path.parts:continue
            if any(part.startswith('.env') or part in ['vendor','node_modules','storage','uploads'] for part in path.parts):continue
            if m.name.startswith('bootstrap/cache/'):continue
            if m.name.startswith('public/') and m.isfile() and path.suffix.lower() not in ['.js','.css','.woff','.woff2','.ttf','.eot','.svg'] and m.name not in ['public/assets/img/logo.png']:continue
            members.append(m)
        archive.extractall(core,members=members,filter='data')
    patch=ROOT/'integration/muserelay/core-provider-recognition.patch'
    subprocess.run(['git','apply','--check',str(patch)],cwd=core,env=env,check=True)
    subprocess.run(['git','apply',str(patch)],cwd=core,env=env,check=True)
    package=out/'bridge';(package/'integration').mkdir(parents=True)
    for name in ['muserelay','panel-staging']:
        shutil.copytree(ROOT/'integration'/name,package/'integration'/name,ignore=shutil.ignore_patterns('__pycache__','*.pyc'))
    (package/'deploy').mkdir()
    for name in ['phase4','panel-staging']:
        shutil.copytree(ROOT/'deploy'/name,package/'deploy'/name,ignore=shutil.ignore_patterns('__pycache__','*.pyc','*.local.json'))
    # Native Inbox views/controllers are unchanged. The staging adapter supplies configuration and routing.
    manifest={'kind':'NATIVE_PANEL_SOURCE_PREPARATION','coreCommit':CORE_REF,'moduleCommit':subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],env=env,text=True).strip(),
        'moduleDirtyAtPreparation':bool(subprocess.check_output(['git','-C',str(ROOT),'status','--porcelain'],env=env,text=True).strip()),
        'coreFiles':{str(f.relative_to(core)):hashlib.sha256(f.read_bytes()).hexdigest() for f in core.rglob('*') if f.is_file()},
        'moduleFiles':{str(f.relative_to(package)):hashlib.sha256(f.read_bytes()).hexdigest() for f in package.rglob('*') if f.is_file()},
        'coreOverlayFiles':['app/Models/Channel.php'],'coreOverlayPatchSha256':hashlib.sha256(patch.read_bytes()).hexdigest(),'nativeInboxViewsAndControllersUnchanged':True,
        'productionDataCopied':False,'productionSecretsCopied':False,'dependenciesInstalled':False,'servicesInstalled':False,'awsCalls':0}
    (out/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    return out
if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--core-source',type=Path,required=True)
    out=prepare(p.parse_args().core_source);print(json.dumps({'directory':str(out),'kind':'PREPARATION_ONLY','servicesInstalled':False,'awsCalls':0}))
