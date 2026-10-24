"""Offline safety/acceptance tests. No credentials, databases, DNS or AWS calls."""
import copy
import importlib.util
import json
from pathlib import Path
import sys
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/phase4'))
from plan import render,validate
from preflight import check
spec=importlib.util.spec_from_file_location('evidence',ROOT/'scripts/phase4/verify-evidence.py')
evidence=importlib.util.module_from_spec(spec);spec.loader.exec_module(evidence)
log_spec=importlib.util.spec_from_file_location('collector',ROOT/'scripts/phase4/collect-logs.py')
collector=importlib.util.module_from_spec(log_spec);log_spec.loader.exec_module(collector)

def config():
    c=json.loads((ROOT/'deploy/phase4/inputs.example.json').read_text())
    c.update(profile='synthetic-stage',deployerPrincipalArn='arn:aws:iam::365470560685:role/synthetic-stage',artifactBucket='synthetic-stage-bucket',
        secretArn='arn:aws:secretsmanager:eu-west-1:365470560685:secret:synthetic-stage-abcdef',linkedWabaId='synthetic-waba',
        metaWabaId='123456',metaPhoneNumberId='789012',socialPhoneArn='arn:aws:social-messaging:eu-west-1:365470560685:phone-number-id/synthetic-stage',recipient='+12025550123')
    return c

class PreparationTests(unittest.TestCase):
    def test_disabled_plan_and_independent_keys(self):
        c=config();p=render(c);params={v['ParameterKey']:v['ParameterValue'] for v in p['parameters']}
        self.assertEqual(p['review']['pendingInputs'],[])
        self.assertFalse(p['review']['authorizedToExecute']);self.assertFalse(p['review']['accountVerified'])
        self.assertEqual(params['LiveSend'],'false');self.assertFalse(json.loads(params['BindingsJson'])[0]['enabled'])
        self.assertIn('TO_SUPPLY_VERIFIED_EU_DESTINATION_MODEL_ARNS',p['bedrockPolicyDraft']['Statement'][0]['Resource'])
        self.assertEqual(c,config())
    def test_rejects_wrong_account_region_production_and_expansion(self):
        for key,bad in [('accountId','374040321139'),('region','us-east-1'),('coreHostname','www.muserelay.com'),('approved',True),
            ('dailySendLimit',7),('dailyInboundLimit',0),('maximumDurationHours',0),('maximumDurationHours',97),('organizationId',1),
            ('deployerPrincipalArn','arn:aws:iam::365470560685:root'),('secretArn','arn:aws:secretsmanager:eu-west-1:111111111111:secret:synthetic-stage'),
            ('socialPhoneArn','arn:aws:social-messaging:eu-west-1:365470560685:phone-number-id/*'),('profile','synthetic; echo bad')]:
            with self.subTest(key=key,bad=bad):
                c=config();c[key]=bad
                with self.assertRaises(ValueError):validate(c)
    def test_unknown_account_stays_pending_without_client(self):
        c=config();c['accountId']='TO_SUPPLY'
        self.assertEqual(check(c,lambda _:self.fail('AWS must not be contacted'))['result'],'PENDING')
    def test_wrong_identity_stops_before_any_service_reads(self):
        for identity in [{'Account':'111111111111','Arn':'arn:aws:iam::111111111111:role/synthetic'},
            {'Account':'365470560685','Arn':'arn:aws:iam::365470560685:root'}]:
            services=[]
            class Sts:
                def get_caller_identity(self):return identity
            def client(name):services.append(name);self.assertEqual(name,'sts');return Sts()
            with self.assertRaises(ValueError):check(config(),client)
            self.assertEqual(services,['sts'])
    def test_read_only_services_drop_personal_and_secret_values(self):
        c=config();calls=[]
        class ReadOnly:
            def get_caller_identity(self):calls.append('sts');return {'Account':c['accountId'],'Arn':c['deployerPrincipalArn']}
            def get_account_settings(self):calls.append('lambda_quota');return {'AccountLimit':{'ConcurrentExecutions':1000,'UnreservedConcurrentExecutions':1000}}
            def get_linked_whats_app_business_account(self,**kw):
                calls.append('get_waba');return {'account':{'wabaId':c['metaWabaId'],'wabaName':'DO_NOT_EXPORT','eventDestinations':[]}}
            def get_linked_whats_app_business_account_phone_number(self,**kw):
                calls.append('get_phone');return {'linkedWhatsAppBusinessAccountId':c['linkedWabaId'],'phoneNumber':{
                    'arn':c['socialPhoneArn'],'metaPhoneNumberId':c['metaPhoneNumberId'],'phoneNumber':'+12025550123','displayPhoneNumber':'DO_NOT_EXPORT'}}
            def get_inference_profile(self,**kw):
                calls.append('get_profile');p=kw['inferenceProfileIdentifier'];return {'status':'ACTIVE','inferenceProfileArn':f'arn:aws:bedrock:eu-west-1:{c["accountId"]}:inference-profile/{p}',
                    'models':[{'modelArn':f'arn:aws:bedrock:eu-west-1::foundation-model/{p[3:]}'}]}
            def describe_secret(self,**kw):calls.append('describe_secret');return {'ARN':c['secretArn']}
            def simulate_principal_policy(self,**kw):calls.append('simulate');return {'EvaluationResults':[]}
        result=check(c,lambda _:ReadOnly());serialized=json.dumps(result)
        self.assertTrue(result['accountMatches']);self.assertFalse(result['realConversationReady'])
        self.assertNotIn('DO_NOT_EXPORT',serialized);self.assertNotIn('+12025550123',serialized)
        self.assertEqual(calls,['sts','lambda_quota','get_waba','get_phone','get_profile','get_profile','describe_secret','simulate'])
        self.assertTrue(result['checks']['lambdaConcurrency']['roomForThreeAdditionalReservations'])
    def test_small_lambda_quota_is_not_reported_ready(self):
        c=config();c['linkedWabaId']='TO_SUPPLY';c['secretArn']='TO_SUPPLY';c['deployerPrincipalArn']='TO_SUPPLY'
        class Small:
            def get_caller_identity(self):return {'Account':'365470560685','Arn':'arn:aws:iam::365470560685:role/synthetic'}
            def get_account_settings(self):return {'AccountLimit':{'ConcurrentExecutions':10,'UnreservedConcurrentExecutions':10}}
            def get_inference_profile(self,**kw):raise RuntimeError('synthetic unavailable')
        result=check(c,lambda _:Small())
        self.assertEqual(result['checks']['lambdaConcurrency']['status'],'REVIEW_REQUIRED')
        self.assertFalse(result['checks']['lambdaConcurrency']['roomForThreeAdditionalReservations'])
    def test_bad_read_result_fails_closed_and_does_not_print_exception(self):
        class Failed:
            def get_caller_identity(self):return {'Account':'365470560685','Arn':'arn:aws:iam::365470560685:role/synthetic'}
            def __getattr__(self,name):
                def fail(**kw):raise RuntimeError('DO_NOT_EXPORT_SECRET')
                return fail
        result=check(config(),lambda _:Failed())
        self.assertTrue(all(c['status']=='BLOCKED' for c in result['checks'].values()))
        self.assertNotIn('DO_NOT_EXPORT',json.dumps(result))

class EvidenceTests(unittest.TestCase):
    def test_log_collector_keeps_only_metadata_for_this_run(self):
        row={'event':'social_api_result','runId':'phase4_synthetic_test','id':'a'*64,'requestId':'synthetic-request',
            'httpStatus':200,'status':'accepted','body':'DO_NOT_EXPORT','phone':'+12025550123','secret':'DO_NOT_EXPORT'}
        safe=collector.sanitize(row,'phase4_synthetic_test')
        self.assertEqual(set(safe),{'event','runId','id','requestId','httpStatus','status'})
        self.assertNotIn('DO_NOT_EXPORT',json.dumps(safe));self.assertNotIn('+12025550123',json.dumps(safe))
        self.assertIsNone(collector.sanitize(row,'phase4_other_run'))
        self.assertIsNone(collector.sanitize({'event':'raw_prompt','runId':'phase4_synthetic_test'},'phase4_synthetic_test'))
    def test_mock_phase3_cannot_be_accepted_as_real(self):
        result=evidence.verify({'kind':'SIMULATED','runId':'phase4_synthetic_test'},[],[])
        self.assertEqual(result['result'],'NOT_PROVEN');self.assertIn('authenticated_not_simulated',result['missingEvidence'])
    def test_complete_consistent_fixture_and_missing_second_bedrock_call(self):
        run='phase4_synthetic_test';ids=['a'*64,'b'*64];commands=['c'*64,'d'*64]
        manifest={'kind':'LIVE_AWS_RUN','accountId':'365470560685','region':'eu-west-1','runId':run,'accountVerified':True,'simulation':False,
            'releaseCommit':'a'*40,'testerObservedTwoReplies':True,'cloudWatchSourceVerified':True}
        snapshots=[]
        for index,(label,tickets) in enumerate([('before',0),('after_first',0),('after_second',1)]):
            snapshots.append({'kind':'NATIVE_STAGE_SNAPSHOT','runId':run,'label':label,'counts':{'tickets':tickets,'ticket_audit_logs':tickets},
                'events':[{'id':i,'state':'done'} for i in ids[:index]],
                'actions':([] if index==0 else [{'eventId':ids[0],'code':'ticket_confirmation_required','ok':False}])+
                    ([{'eventId':ids[1],'ok':True,'referenceHash':'e'*64}] if index==2 else []),
                'outbox':[{'id':i,'state':'accepted'} for i in commands[:index]]})
        rows=[]
        for id in ids:
            rows.extend([{'runId':run,'event':'core_ack','id':id,'status':'accepted','httpStatus':202},
                {'runId':run,'event':'bedrock_result','eventId':id,'requestId':'synthetic-request','httpStatus':200,'modelId':'eu.amazon.nova-lite-v1:0'}])
        rows.extend([{'runId':run,'event':'social_api_result','id':id,'requestId':'synthetic-request','httpStatus':200,'status':'accepted'} for id in commands])
        rows.append({'runId':run,'event':'lambda_record_ok','awsRequestId':'synthetic-lambda-request'})
        self.assertEqual(evidence.verify(manifest,snapshots,rows)['result'],'PASS')
        rows=[r for r in rows if not(r['event']=='bedrock_result' and r['eventId']==ids[1])]
        self.assertIn('real_bedrock_request_ids_both_turns',evidence.verify(manifest,snapshots,rows)['missingEvidence'])
        # This file is a synthetic validator fixture; it is never written as live-run evidence.

if __name__=='__main__':unittest.main()
