#!/usr/bin/env python3
"""READ ONLY CloudWatch collection, into a private /tmp directory. No model calls or sends.
Output is allowlisted metadata; it does not by itself certify a successful conversation.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import tempfile
from plan import validate

FIELDS={'core_ack':['id','status','httpStatus','time'], 'social_api_result':['id','requestId','status','httpStatus','time'],
        'lambda_record_ok':['id','awsRequestId','time']}

def sanitize(row,run):
    if not isinstance(row,dict) or row.get('runId')!=run or row.get('event') not in FIELDS:return None
    result={'event':row['event'],'runId':run}
    for key in FIELDS[row['event']]:
        value=row.get(key)
        if key=='httpStatus':
            if type(value) is int and 100<=value<=599:result[key]=value
        elif isinstance(value,str) and re.fullmatch(r'[A-Za-z0-9_.:TZ-]{1,128}',value):result[key]=value
    return result

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--inputs',type=Path,required=True);p.add_argument('--since-epoch-ms',type=int,required=True);args=p.parse_args()
    c,pending=validate(json.loads(args.inputs.read_text()))
    if 'profile' in pending or 'accountId' in pending:raise SystemExit('An explicit non-root staging profile/account is required')
    import boto3
    from botocore.config import Config
    session=boto3.Session(profile_name=c['profile'],region_name='eu-west-1')
    options=Config(connect_timeout=3,read_timeout=10,retries={'max_attempts':1})
    identity=session.client('sts',config=options).get_caller_identity()
    if identity['Account']!='365470560685' or identity['Arn'].endswith(':root'):raise SystemExit('Wrong account/root; no log reads performed')
    logs=session.client('logs',config=options);rows=[];origins=[]
    for suffix in ['inbound','enqueue','sender']:
        group=f'/aws/lambda/{c["stackName"]}-{suffix}';token=None;seen=set()
        for _ in range(20):
            query={'logGroupName':group,'startTime':args.since_epoch_ms,'filterPattern':'{ $.runId = "'+c['runId']+'" }','limit':100}
            if token:query['nextToken']=token
            page=logs.filter_log_events(**query)
            for event in page['events']:
                try:record=sanitize(json.loads(event['message']),c['runId'])
                except (ValueError,TypeError):continue
                if record:
                    rows.append(record);origins.append({'group':group,'eventIdHash':hashlib.sha256(event['eventId'].encode()).hexdigest(),'timestamp':event['timestamp']})
            token=page.get('nextToken')
            if not token or token in seen:break
            seen.add(token)
        else:raise SystemExit('Log pagination cap reached; narrow the time range before collecting')
    directory=Path(tempfile.mkdtemp(prefix='muserelay-bridge-phase4-aws-logs-',dir='/tmp'));directory.chmod(0o700)
    for name,data in [('aws-evidence.ndjson',''.join(json.dumps(row)+'\n' for row in rows)),('aws-origin.json',json.dumps({'accountMatches':True,'region':'eu-west-1','runId':c['runId'],'sources':origins},indent=2)+'\n')]:
        path=directory/name;path.write_text(data);path.chmod(0o600)
    print(json.dumps({'directory':str(directory),'records':len(rows),'conversationProven':False}))
if __name__=='__main__':main()
