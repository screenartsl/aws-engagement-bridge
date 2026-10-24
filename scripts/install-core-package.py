#!/usr/bin/env python3
"""Install ONLY an immutable PHP connector release into Core/modules; no enable, SQL or service reload.
Production requires a verified private database-backup manifest. Core support/hooks are separate reviewed changes.
"""
import argparse
from datetime import datetime,timezone
import hashlib
import io
import json
import os
from pathlib import Path,PurePosixPath
import shutil
import subprocess
import tarfile
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PRODUCTION=Path('/var/www/vhosts/muserelay.com/httpdocs')

def digest(path):
    h=hashlib.sha256()
    with path.open('rb') as f:
        for part in iter(lambda:f.read(1024*1024),b''):h.update(part)
    return h.hexdigest()

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--core',type=Path,required=True);p.add_argument('--source-ref',required=True);p.add_argument('--backup-manifest',type=Path);a=p.parse_args()
    core=a.core.resolve()
    if not (core/'artisan').is_file() or not (core/'app').is_dir():raise SystemExit('Explicit MuseRelay Core directory required')
    if core==PRODUCTION or (core/'.env').exists():
        if not a.backup_manifest:raise SystemExit('Verified database backup required before installation')
        b=json.loads(a.backup_manifest.read_text());archive=Path(b['archive'])
        age=(datetime.now(timezone.utc)-datetime.fromisoformat(b['createdAtUtc'])).total_seconds()
        if b.get('result')!='PASS' or not 0<=age<=86400 or digest(archive)!=b.get('sha256') or (archive.stat().st_mode&0o077):raise SystemExit('Backup validation failed')
    sha=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','--verify',a.source_ref+'^{commit}'],text=True).strip()
    data=subprocess.check_output(['git','-C',str(ROOT),'archive',sha,'--','integration/muserelay'])
    modules=core/'modules';modules.mkdir(exist_ok=True)
    if modules.is_symlink():raise SystemExit('A physical modules directory is required')
    target=modules/'aws-engagement-bridge'
    if target.exists() or target.is_symlink():raise SystemExit('Existing release will not be overwritten; review an explicit upgrade')
    temp=Path(tempfile.mkdtemp(prefix='.bridge-install-',dir=modules))
    try:
        files={}
        with tarfile.open(fileobj=io.BytesIO(data)) as tar:
            for member in tar.getmembers():
                if not member.isfile():continue
                relative=PurePosixPath(member.name).relative_to('integration/muserelay')
                allowed=relative.parts[0] in ['src','config','database'] or str(relative) in ['BridgeSignature.php','module.php','composer.json']
                if not allowed:continue
                if '..' in relative.parts or relative.is_absolute():raise ValueError('Unsafe archive path')
                content=tar.extractfile(member).read();path=temp/str(relative);path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(content);path.chmod(0o644)
                files[str(relative)]=hashlib.sha256(content).hexdigest()
        version=json.loads((temp/'composer.json').read_text())['version']
        if not (temp/'module.php').is_file():raise ValueError('Module manifest required')
        release={'module':'aws-engagement-bridge','version':version,'sourceCommit':sha,'files':files,'installedEnabled':False}
        (temp/'module-release.json').write_text(json.dumps(release,indent=2)+'\n');(temp/'module-release.json').chmod(0o644)
        for directory in [temp,*[x for x in temp.rglob('*') if x.is_dir()]]:directory.chmod(0o755)
        os.rename(temp,target)
        print(json.dumps({'installedPath':str(target),'version':version,'sourceCommit':sha,'sourceFiles':len(files),'enabled':False,'databaseModified':False,'servicesReloaded':False},indent=2))
    finally:
        if temp.exists():shutil.rmtree(temp)
if __name__=='__main__':main()
