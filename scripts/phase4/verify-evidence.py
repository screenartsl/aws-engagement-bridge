#!/usr/bin/env python3
"""OFFLINE acceptance gate. Evidence must be collected from the approved live run, never phase 3 mocks.
This validates consistency, not authenticity; retain CloudWatch provenance and compare AWS request IDs.
"""
import argparse
import json
import re
from pathlib import Path

def verify(manifest, snapshots, records):
    errors=[]
    def require(condition,code):
        if not condition:errors.append(code)
    run=manifest.get('runId','')
    require(manifest.get('kind')=='LIVE_AWS_RUN' and manifest.get('accountId')=='365470560685' and manifest.get('region')=='eu-west-1','live_account_manifest')
    require(manifest.get('accountVerified') is True and manifest.get('simulation') is False,'authenticated_not_simulated')
    require(bool(re.fullmatch(r'phase4_[A-Za-z0-9_-]{4,56}',run)),'run_id')
    require(bool(re.fullmatch(r'[0-9a-f]{40}',manifest.get('releaseCommit',''))),'release_commit')
    require(manifest.get('testerObservedTwoReplies') is True,'two_replies_observed')
    by_label={s.get('label'):s for s in snapshots}
    for label,tickets,inputs in [('before',0,0),('after_first',0,1),('after_second',1,2)]:
        s=by_label.get(label,{})
        require(s.get('kind')=='NATIVE_STAGE_SNAPSHOT' and s.get('runId')==run,'snapshot_'+label)
        require(s.get('counts',{}).get('tickets')==tickets,'ticket_count_'+label)
        require(len(s.get('events',[]))==inputs and all(e.get('state')=='done' for e in s.get('events',[])),'completed_events_'+label)
    first=by_label.get('after_first',{});final=by_label.get('after_second',{})
    first_event=first.get('events',[{}])[0].get('id') if first.get('events') else None
    second_event=final['events'][1].get('id') if len(final.get('events',[]))==2 else None
    require(any(a.get('code')=='ticket_confirmation_required' and a.get('ok') is False and a.get('eventId')==first_event for a in first.get('actions',[])),'native_confirmation_gate')
    success=[a for a in final.get('actions',[]) if a.get('ok') is True and re.fullmatch(r'[0-9a-f]{64}',a.get('referenceHash') or '')]
    require(len(success)==1 and success[0].get('eventId')==second_event and final.get('counts',{}).get('ticket_audit_logs')==1,'single_native_ticket_and_audit')
    # Include only rows for this run. Collectors must preserve origin privately (CloudWatch log group/event ID).
    rows=[r for r in records if r.get('runId')==run]
    events={e.get('id') for e in final.get('events',[])}
    core={r.get('id') for r in rows if r.get('event')=='core_ack' and r.get('status') in ['accepted','duplicate'] and r.get('httpStatus') in [200,202]}
    require(len(events)==2 and events<=core,'signed_ingress_ack_for_both_turns')
    req=lambda r:isinstance(r.get('requestId'),str) and bool(re.fullmatch(r'[A-Za-z0-9_-]{8,128}',r['requestId']))
    bedrock=[r for r in rows if r.get('event')=='bedrock_result' and r.get('httpStatus')==200 and req(r)
        and r.get('modelId') in ['eu.amazon.nova-lite-v1:0','eu.amazon.nova-micro-v1:0']]
    require(events<={r.get('eventId') for r in bedrock},'real_bedrock_request_ids_both_turns')
    commands={o.get('id') for o in final.get('outbox',[]) if o.get('state') in ['accepted','sent','delivered','read']}
    sent={r.get('id') for r in rows if r.get('event')=='social_api_result' and r.get('status')=='accepted' and r.get('httpStatus')==200 and req(r)}
    require(len(commands)==2 and commands<=sent,'aws_social_request_ids_and_receipts')
    require(any(r.get('event')=='lambda_record_ok' and re.fullmatch(r'[A-Za-z0-9_-]{8,128}',r.get('awsRequestId','')) for r in rows),'aws_lambda_execution_id')
    require(manifest.get('cloudWatchSourceVerified') is True,'cloudwatch_provenance_review')
    return {'result':'PASS' if not errors else 'NOT_PROVEN','runId':run,'missingEvidence':errors,'mutations':False,
        'limits':'Consistency check only; request IDs and origin require authenticated AWS review. No authenticity claim for manually fabricated input.'}

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--directory',type=Path,required=True);a=p.parse_args();d=a.directory
    result=verify(json.loads((d/'manifest.json').read_text()),[json.loads((d/(s+'.json')).read_text()) for s in ['before','after_first','after_second']],
        [json.loads(l) for l in (d/'evidence.ndjson').read_text().splitlines() if l.strip()])
    print(json.dumps(result,indent=2));return 0 if result['result']=='PASS' else 1
if __name__=='__main__':raise SystemExit(main())
