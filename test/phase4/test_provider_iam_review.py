"""Security contracts for the consolidated proposal. No AWS calls/simulation."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest

import yaml

ROOT=Path(__file__).resolve().parents[2]
OUT=ROOT/'deploy/runtime-provider-iam'
spec=importlib.util.spec_from_file_location('provider_collector',ROOT/'scripts/cloudformation/review-receive-change-set.py')
v=importlib.util.module_from_spec(spec);spec.loader.exec_module(v)


class ProviderIamReview(unittest.TestCase):
    def setUp(self):
        self.before=yaml.safe_load((ROOT/'deploy/runtime-sam-iam/template.yaml').read_text())
        self.after=yaml.safe_load((OUT/'template.yaml').read_text())
        self.add=json.loads((OUT/'policy.additions.json').read_text())
        self.policies=self.after['Resources']['StageCloudFormationRole']['Properties']['Policies']

    def test_removing_exact_seven_additions_restores_whole_previous_template(self):
        actual=copy.deepcopy(self.after)
        statements=actual['Resources']['StageCloudFormationRole']['Properties']['Policies'][1]['PolicyDocument']['Statement']
        self.assertEqual(statements[-7:],self.add)
        del statements[-7:]
        self.assertEqual(actual,self.before)

    def test_fourteen_grants_and_no_business_or_iam_actions_added(self):
        self.assertEqual(sum(len(s['Action']) for s in self.add),14)
        actions={a for s in self.add for a in s['Action']}
        self.assertFalse(any('*' in a for a in actions))
        self.assertFalse(any(a.startswith(('iam:','kms:','sqs:','sns:','social-messaging:','bedrock:','ec2:')) for a in actions))
        self.assertFalse(actions & {'lambda:InvokeFunction','lambda:UpdateEventSourceMapping','lambda:UpdateFunctionCode','dynamodb:GetItem','dynamodb:PutItem','dynamodb:Scan','dynamodb:Query','logs:GetLogEvents','logs:FilterLogEvents','secretsmanager:PutSecretValue'})

    def test_global_exception_is_only_two_regional_policy_metadata_reads(self):
        global_statements=[s for s in self.add if s['Resource']=='*']
        self.assertEqual(len(global_statements),1)
        self.assertEqual(set(global_statements[0]['Action']),{'logs:DescribeIndexPolicies','logs:DescribeResourcePolicies'})
        for s in self.add:
            self.assertEqual(s['Condition']['StringEquals']['aws:RequestedRegion'],'eu-west-1')

    def test_secret_content_read_is_only_exact_dedicated_tagged_staging_secret(self):
        s=next(s for s in self.add if 'secretsmanager:GetSecretValue' in s['Action'])
        self.assertEqual(s['Action'],['secretsmanager:GetSecretValue'])
        self.assertEqual(s['Resource'],'arn:aws:secretsmanager:eu-west-1:365470560685:secret:muserelay-aws-bridge-stage/signing-??????')
        self.assertEqual(s['Condition']['StringEquals']['aws:ResourceTag/Component'],'aws-engagement-bridge')
        self.assertEqual(s['Condition']['StringEquals']['aws:ResourceTag/Environment'],'staging')

    def test_mapping_tag_bootstrap_and_read_use_correct_context_keys(self):
        write=next(s for s in self.add if s['Sid']=='OwnMappingSystemTagBootstrap')
        read=next(s for s in self.add if s['Sid']=='OwnMappingTagRead')
        self.assertEqual(write['Action'],['lambda:TagResource'])
        self.assertEqual(read['Action'],['lambda:ListTags'])
        for s,prefix in [(write,'aws:RequestTag/'),(read,'aws:ResourceTag/')]:
            self.assertEqual(s['Resource'],'arn:aws:lambda:eu-west-1:365470560685:event-source-mapping:*')
            c=s['Condition']['StringEquals']
            self.assertEqual(c[prefix+'aws:cloudformation:stack-name'],'muserelay-aws-bridge-stage')
            self.assertEqual(c[prefix+'aws:cloudformation:logical-id'],'InboundFunctionInput')
            self.assertNotIn('ArnEquals',s['Condition'])

    def test_table_write_and_function_reads_are_exact_resources(self):
        d=next(s for s in self.add if s['Sid']=='OwnStateProviderLifecycle')
        self.assertEqual(d['Resource'],'arn:aws:dynamodb:eu-west-1:365470560685:table/muserelay-aws-bridge-stage-state')
        self.assertEqual({a for a in d['Action'] if not a.split(':')[1].startswith(('Describe','Get'))},{'dynamodb:UpdateContinuousBackups'})
        f=next(s for s in self.add if s['Sid']=='OwnInboundProviderConfigurationRead')
        self.assertEqual(f['Resource'],'arn:aws:lambda:eu-west-1:365470560685:function:muserelay-aws-bridge-stage-inbound')
        self.assertTrue(all(a.startswith('lambda:Get') for a in f['Action']))

    def test_declared_read_contract_covered_without_custom_kms_features(self):
        schemas=json.loads((ROOT/'deploy/runtime-pitr-iam/provider-handlers.local-snapshot.json').read_text())['types']
        allowed={a for p in self.policies for s in p['PolicyDocument']['Statement'] if s['Effect']=='Allow' for a in s['Action']}
        for typ in schemas:
            required=set(schemas[typ]['read']['permissions'])-{'kms:Decrypt'}
            self.assertTrue(required<=allowed,(typ,required-allowed))
        self.assertIn('dynamodb:UpdateContinuousBackups',allowed)

    def test_role_policy_aggregate_size_stays_below_10240(self):
        self.assertLessEqual(len(json.dumps(self.policies,separators=(',',':'))),10240)

    def test_request_is_foundation_update_without_service_role_and_send_false(self):
        req=json.loads((OUT/'provider-iam.change-set.prepared.json').read_text())
        self.assertEqual(req['StackName'],'muserelay-aws-bridge-iam-foundation')
        self.assertEqual(req['ChangeSetType'],'UPDATE')
        self.assertNotIn('RoleARN',req)
        self.assertEqual(req['Capabilities'],['CAPABILITY_NAMED_IAM'])
        self.assertEqual(req['Parameters'],[{'ParameterKey':'GrantSocialSend','ParameterValue':'false'}])
        self.assertEqual(req['TemplateBody'],(OUT/'template.yaml').read_text())
        sha=hashlib.sha256(req['TemplateBody'].encode()).hexdigest()
        self.assertEqual(req['ChangeSetName'],'runtime-provider-iam-review-'+sha[:12])

    def test_collector_accepts_exact_candidate_hash_but_no_arbitrary_additions(self):
        self.assertEqual(v.digest(self.policies[1]['PolicyDocument']),v.CANDIDATE_RUNTIME_POLICY_HASH)
        mutated=copy.deepcopy(self.policies[1]['PolicyDocument'])
        mutated['Statement'][-1]['Resource']='*'
        self.assertNotEqual(v.digest(mutated),v.CANDIDATE_RUNTIME_POLICY_HASH)

    def test_compact_summary_matches_reviewed_handler_sets_and_rejects_changes(self):
        local=json.loads((ROOT/'deploy/runtime-pitr-iam/provider-handlers.local-snapshot.json').read_text())['types']
        report={'providers':{t:{'defaultVersionId':'synthetic','handlers':{k:local[t][k]['permissions'] for k in ['create','read','delete']}} for t in local}}
        report['providers']['AWS::Lambda::EventSourceMapping']['tagging']={'cloudFormationSystemTags':True,'tagOnCreate':True}
        self.assertTrue(v.provider_summary(report)['allMatch'])
        report['providers']['AWS::DynamoDB::Table']['handlers']['read']=[]
        self.assertFalse(v.provider_summary(report)['allMatch'])

    def test_runtime_template_and_prepared_controls_remain_identical(self):
        req=json.loads((ROOT/'deploy/receive-retry/change-set.prepared.json').read_text())
        self.assertEqual(hashlib.sha256(req['TemplateBody'].encode()).hexdigest(),'88161733676dae8d788eff092cf75c6430c5ca28860e7f7048804e5ce3f694ac')
        p={p['ParameterKey']:p['ParameterValue'] for p in req['Parameters']}
        for key in ['ProcessingEnabled','ReceiveEnabled','SendEnabled','LiveSend','DeployOutbound']:
            self.assertEqual(p[key],'false')


if __name__=='__main__':unittest.main()
