#!/usr/bin/env python3
"""Create a private, deterministic local release. No AWS calls or secret access.

Run npm test and npm run bundle first. The destination must not already exist.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[2]

def package(destination):
    destination = Path(destination)
    if not destination.is_absolute() or '/var/www' in str(destination.resolve()):
        raise ValueError('Use an absolute private destination outside web/replicated vhosts')
    for parent in [destination, *destination.parents]:
        if parent.is_symlink():
            raise ValueError('Symlink destination rejected')
    def git(*args):
        return subprocess.check_output(['git', '-c', 'safe.directory='+str(ROOT), *args], cwd=ROOT).decode().strip()
    if git('status', '--porcelain', '--untracked-files=all'):
        raise ValueError('Commit/review the worktree before packaging a pinned release')
    commit = git('rev-parse', 'HEAD')
    payload = ROOT/'build/handlers.mjs'
    if not payload.is_file():
        raise ValueError('Run the reviewed npm run bundle first')
    # Rebuild from the pinned clean worktree; esbuild is local and no download is used.
    subprocess.run(['npm', 'run', 'bundle'], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
    os.mkdir(destination, 0o700)
    artifact = destination/'bridge.zip'
    with zipfile.ZipFile(artifact, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
        entry = zipfile.ZipInfo('handlers.mjs', date_time=(2026, 10, 24, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        archive.writestr(entry, payload.read_bytes())
    artifact.chmod(0o600)
    for source, name in [('deploy/iam-foundation/template.yaml', '00-iam-foundation.yaml'),
                         ('deploy/runtime-iam/template.yaml', '00-iam-runtime-update.yaml'),
                         ('deploy/sns-bootstrap/template.yaml', '01-ingress.yaml'), ('template.yaml', '02-runtime.yaml')]:
        output = destination/name
        output.write_bytes((ROOT/source).read_bytes())
        output.chmod(0o600)
    hashes = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in destination.iterdir()}
    manifest = {'kind': 'LOCAL_ONLY_NOT_UPLOADED', 'sourceCommit': commit,
                'artifactKey': 'releases/'+commit+'/bridge.zip', 'sha256': hashes,
                'awsCalls': 0, 'containsSecrets': False}
    path = destination/'manifest.json'
    path.write_text(json.dumps(manifest, indent=2)+'\n')
    path.chmod(0o600)
    return {'directory': str(destination), **manifest}

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    print(json.dumps(package(parser.parse_args().output), indent=2))
