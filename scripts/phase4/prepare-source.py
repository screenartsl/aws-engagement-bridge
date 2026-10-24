#!/usr/bin/env python3
"""Offline review copy in /tmp. Never installs a service/dependency or boots MuseRelay."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile

ROOT=Path(__file__).resolve().parents[2]
BASE='b8e9bf8c74789689a7cebddd1856a094e380c9d9'
HOOKS={'app/Services/WhatsAppSenderService.php','app/Services/AutomationService.php','app/AI/AgentExecutor.php'}

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--core-source',type=Path,required=True);a=p.parse_args()
    source=a.core_source.resolve()
    # Read only committed source. Never copy the checkout filesystem, .env, data or vendor.
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LC_ALL':'C.UTF-8','GIT_TERMINAL_PROMPT':'0'}
    archive=subprocess.check_output(['git','-C',str(source),'archive',BASE,'--','app','bootstrap','config','resources','tests','database','routes','composer.json','composer.lock','artisan'],env=env)
    output=Path(tempfile.mkdtemp(prefix='muserelay-bridge-phase4-source-',dir='/tmp'));output.chmod(0o700)
    core=output/'core';core.mkdir()
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        members=[m for m in tar.getmembers() if not m.issym() and not m.islnk() and not any(x.startswith('.env') or x in ['vendor','node_modules','storage'] for x in Path(m.name).parts)
            and not m.name.startswith('bootstrap/cache/')]
        tar.extractall(core,members=members,filter='data')
    hashes={str(f.relative_to(core)):hashlib.sha256(f.read_bytes()).hexdigest() for f in core.rglob('*') if f.is_file()}
    subprocess.run(['git','apply','--check',str(ROOT/'integration/muserelay/core-hooks.patch')],cwd=core,env=env,check=True)
    subprocess.run(['git','apply',str(ROOT/'integration/muserelay/core-hooks.patch')],cwd=core,env=env,check=True)
    changed={name for name,digest in hashes.items() if hashlib.sha256((core/name).read_bytes()).hexdigest()!=digest}
    if changed!=HOOKS:raise ValueError('Unexpected Core changes')
    manifest=json.loads((core/'composer.json').read_text());package=json.loads((ROOT/'integration/muserelay/composer.json').read_text())
    manifest['repositories']=[{'type':'path','url':'../bridge/integration/muserelay','options':{'symlink':False}}]
    manifest['require'][package['name']]=package['version'];(core/'composer.json').write_text(json.dumps(manifest,indent=2)+'\n')
    review={'kind':'SOURCE_REVIEW_ONLY','baseCommit':BASE,'hookFiles':sorted(changed),'unchangedSourceFiles':len(hashes)-len(changed),
        'connectorComposerOverlay':True,'dependenciesInstalled':False,'servicesStarted':False,'productionModified':False,
        'patchSha256':hashlib.sha256((ROOT/'integration/muserelay/core-hooks.patch').read_bytes()).hexdigest()}
    (output/'source-review.json').write_text(json.dumps(review,indent=2)+'\n')
    print(json.dumps({'reviewDirectory':str(output),**review},indent=2))
if __name__=='__main__':main()
