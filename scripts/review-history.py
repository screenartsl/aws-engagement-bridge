#!/usr/bin/env python3
"""Read-only, redacted secret/PII review of ALL stored Git objects and publishable working files.
Heuristic screening is evidence, not a guarantee that no sensitive data exists.
"""
import argparse
import hashlib
import json
import math
from pathlib import Path
import re
import subprocess
from urllib.parse import urlsplit

ROOT=Path(__file__).resolve().parents[1]
RULES={
 'aws-access-key': rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b',
 'github-token': rb'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})\b',
 'private-key': rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----',
 'slack-token': rb'\bxox[baprs]-[A-Za-z0-9-]{20,}',
 'stripe-secret': rb'\b(?:sk_live|sk_test|rk_live)_[A-Za-z0-9]{16,}',
 'meta-token': rb'\bEAA[A-Za-z0-9]{60,}\b',
 'jwt': rb'\beyJ[A-Za-z0-9_-]{20,}\.eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}',
}
ASSIGN=re.compile(rb'''(?i)(?:secret|password|token|api[_-]?key|access[_-]?key)\s*["']?\s*[:=]\s*["']([^"'\r\n]{20,})["']''')
EMAIL=re.compile(rb'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}')
PHONE=re.compile(rb'(?<![A-Za-z0-9])\+[1-9][0-9]{8,14}(?![0-9])')

def entropy(value):
    return -sum((value.count(c)/len(value))*math.log2(value.count(c)/len(value)) for c in set(value))

def inspect(data):
    hits=[]
    for rule, pattern in RULES.items():
        if re.search(pattern,data): hits.append(rule)
    for m in ASSIGN.finditer(data):
        value=m.group(1)
        # Exact human-readable synthetic markers/placeholders. No high-entropy opaque value is waived by file path.
        if any(marker in value.lower() for marker in [b'synthetic',b'test-only',b'test_only',b'never-use',b'<',b'${',b'{{']): continue
        if entropy(value)>=3.8 and re.fullmatch(rb'[A-Za-z0-9_+/=.-]{24,}',value): hits.append('opaque-secret-assignment')
    for m in EMAIL.finditer(data):
        domain=m.group().split(b'@')[1].lower()
        if domain.endswith((b'.invalid',b'.example',b'.test')) or domain in [b'example.com',b'example.org',b'example.net']: continue
        hits.append('non-synthetic-email')
    for m in PHONE.finditer(data):
        if not m.group().startswith(b'+1202555'): hits.append('non-synthetic-phone')
    return sorted(set(hits))

def git(*args): return subprocess.check_output(['git',*args],cwd=ROOT)

def selftest():
    assert inspect(b'key='+b'AK' + b'IA' + b'A'*16)==['aws-access-key']
    assert 'github-token' in inspect(b'gh' + b'p_' + b'A'*36)
    assert 'private-key' in inspect(b'-----BEGIN '+b'PRIVATE KEY-----')
    assert 'non-synthetic-email' in inspect(b'customer@'+b'real-domain.tld')
    assert not inspect(b'visitor@example.invalid +12025550123 synthetic-stage-key')
    print('Scanner rule self-tests passed')

parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--self-test',action='store_true'); args=parser.parse_args()
if args.self_test: selftest(); raise SystemExit()
blobs=[row.split() for row in git('cat-file','--batch-all-objects','--batch-check=%(objectname) %(objecttype) %(objectsize)').decode().splitlines()]
findings=[]; count=0; byte_count=0; object_count=0; metadata_count=0
for sha,kind,size in blobs:
    data=git('cat-file',kind,sha); object_count+=1; byte_count+=len(data)
    if kind=='blob': count+=1
    else: metadata_count+=1
    for rule in inspect(data): findings.append({'object':sha,'rule':rule})
files=git('ls-files','--cached','--others','--exclude-standard','-z').split(b'\0')
working_count=0
for name in sorted(set(files)):
    if not name: continue
    path=ROOT/name.decode();
    if not path.is_file(): continue
    working_count+=1
    for rule in inspect(path.read_bytes()): findings.append({'file':name.decode(),'rule':rule})
remotes=subprocess.run(['git','config','--get-regexp',r'^remote\..*\.url$'],cwd=ROOT,stdout=subprocess.PIPE,check=False).stdout.decode()
for row in remotes.splitlines():
    name,url=row.split(maxsplit=1); parsed=urlsplit(url)
    if parsed.password or (parsed.scheme in ['http','https'] and parsed.username): findings.append({'config':name,'rule':'credential-in-remote-url'})
report={'scope':'all stored Git objects including commit/tag/tree metadata and unreachable objects; tracked and non-ignored working files; remote URL credentials',
        'commits':git('rev-list','--all').decode().splitlines(),'gitObjects':object_count,'gitBlobs':count,'metadataObjects':metadata_count,'objectBytes':byte_count,'workingFiles':working_count,
        'findings':findings,'result':'PASS' if not findings else 'REVIEW_REQUIRED',
        'limitations':'Heuristic rules; external LFS objects, ignored local files and remote-only Git history are not covered. No secret values are printed.'}
print(json.dumps(report,indent=2)); raise SystemExit(1 if findings else 0)
