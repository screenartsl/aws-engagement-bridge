"""Synthetic metadata tests only. Never calls collect or AWS."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch
from types import SimpleNamespace

import yaml

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('receive_review',ROOT/'scripts/cloudformation/review-receive-change-set.py')
v=importlib.util.module_from_spec(spec);spec.loader.exec_module(v)


class ReceiveReviewTests(unittest.TestCase):
    def setUp(self):
        p=json.loads((ROOT/'deploy/receive-retry/change-set.prepared.json').read_text())
        stack_id=f'arn:aws:cloudformation:{v.REGION}:{v.ACCOUNT}:stack/{v.STACK}/synthetic'
        change_id=f'arn:aws:cloudformation:{v.REGION}:{v.ACCOUNT}:changeSet/{v.CHANGE}/synthetic'
        policies=json.loads((ROOT/'deploy/runtime-sam-iam/executor-policies.after.json').read_text())
        self.e={'caller':{'Account':v.ACCOUNT},'stack':{'Stacks':[{'StackId':stack_id,'RoleARN':v.ROLE,'StackStatus':'CREATE_COMPLETE'}]},
            'change':{'StackId':stack_id,'StackName':v.STACK,'ChangeSetName':v.CHANGE,'ChangeSetId':change_id,'Status':'CREATE_COMPLETE','ExecutionStatus':'AVAILABLE','Parameters':p['Parameters'],
                'Changes':[{'ResourceChange':{'LogicalResourceId':k,'ResourceType':t,'Action':'Add'}} for k,t in v.ADDS.items()]},
            'original':{'TemplateBody':p['TemplateBody']},
            'processed':{'TemplateBody':yaml.safe_load((ROOT/'deploy/runtime-native/template.yaml').read_text())},
            'creationEvents':[{'eventName':'CreateChangeSet','awsRegion':v.REGION,'requestParameters':{'stackName':v.STACK,'changeSetName':v.CHANGE,'roleARN':v.ROLE}}],
            'role':{'Role':{'Arn':v.ROLE,'AssumeRolePolicyDocument':{'Statement':[{'Effect':'Allow','Principal':{'Service':'cloudformation.amazonaws.com'},'Action':['sts:AssumeRole']}]}}},
            'attached':{'AttachedPolicies':[]},'inline':{'PolicyNames':list(v.POLICIES)},
            'policies':{p['PolicyName']:{'PolicyDocument':p['PolicyDocument']} for p in policies}}

    def ok(self):return all(v.verify(self.e).values())

    def test_valid_complete_metadata_without_describechangeset_rolearn(self):
        self.assertNotIn('RoleARN',self.e['change']);self.assertTrue(self.ok())

    def test_rolearn_query_null_is_not_proof_of_execution_identity(self):
        self.e['change']['RoleARN']=None
        self.assertTrue(self.ok())
        self.e['stack']['Stacks'][0]['RoleARN']=None
        self.assertFalse(self.ok())

    def test_missing_override_uses_verified_stack_role(self):
        del self.e['creationEvents'][0]['requestParameters']['roleARN']
        self.assertTrue(self.ok())

    def test_explicit_different_creation_role_rejected(self):
        self.e['creationEvents'][0]['requestParameters']['roleARN']='arn:aws:iam::365470560685:role/unreviewed'
        self.assertFalse(self.ok())

    def test_missing_cloudtrail_evidence_never_assumed_safe(self):
        self.e['creationEvents']=[];self.assertFalse(self.ok())

    def test_metadata_pass_does_not_hide_missing_pitr_permissions(self):
        self.assertTrue(self.ok())
        self.assertFalse(all(v.provider_prerequisites(self.e).values()))

    def test_pitr_guard_requires_exact_table_and_region(self):
        p=self.e['policies']['bridge-runtime-receive-provisioning']['PolicyDocument']
        add={'Effect':'Allow','Action':['dynamodb:DescribeContinuousBackups','dynamodb:UpdateContinuousBackups'],
            'Resource':f'arn:aws:dynamodb:{v.REGION}:{v.ACCOUNT}:table/{v.STACK}-state',
            'Condition':{'StringEquals':{'aws:RequestedRegion':v.REGION}}}
        p['Statement'].append(add)
        self.assertTrue(all(v.provider_prerequisites(self.e).values()))
        # Policy equality still rejects unreviewed expansion until its new full
        # version is reviewed. The feature guard is not an automatic IAM upgrade.
        self.assertFalse(self.ok())
        add['Resource']='*'
        self.assertFalse(all(v.provider_prerequisites(self.e).values()))

    def test_wrong_stack_role_and_account_rejected(self):
        self.e['stack']['Stacks'][0]['RoleARN']='arn:aws:iam::365470560685:role/AdministratorAccess'
        self.assertFalse(self.ok())
        self.setUp();self.e['caller']['Account']='374040321139';self.assertFalse(self.ok())

    def test_extra_change_and_unavailable_set_rejected(self):
        self.e['change']['Changes'].append(copy.deepcopy(self.e['change']['Changes'][0]))
        self.assertFalse(self.ok())
        self.setUp();self.e['change']['ExecutionStatus']='UNAVAILABLE';self.assertFalse(self.ok())

    def test_changed_effective_parameter_or_original_rejected(self):
        self.e['change']['Parameters'][0]['ParameterValue']='changed-synthetic-binding'
        # This may make processed evaluation malformed; either rejection or an
        # explicit parser failure must never be accepted.
        with self.assertRaises((ValueError,KeyError)):
            v.verify(self.e)
        self.setUp();self.e['original']['TemplateBody']+='\n# changed';self.assertFalse(self.ok())

    def test_enabled_mapping_max_concurrency_or_reservation_rejected(self):
        for update in [{'Enabled':True},{'ScalingConfig':{'MaximumConcurrency':10}}]:
            self.setUp();self.e['processed']['TemplateBody']['Resources']['InboundFunctionInput']['Properties'].update(update)
            self.assertFalse(self.ok())
        self.setUp();self.e['processed']['TemplateBody']['Resources']['InboundFunction']['Properties']['ReservedConcurrentExecutions']=1
        self.assertFalse(self.ok())

    def test_send_environment_override_rejected(self):
        self.e['processed']['TemplateBody']['Resources']['InboundFunction']['Properties']['Environment']['Variables']['BRIDGE_SEND_ENABLED']='true'
        self.assertFalse(self.ok())

    def test_new_iam_or_api_resource_rejected(self):
        self.e['processed']['TemplateBody']['Resources']['Injected']={'Type':'AWS::IAM::Role','Properties':{}}
        self.assertFalse(self.ok())

    def test_extra_managed_or_inline_policy_and_removed_kms_deny_rejected(self):
        self.e['attached']['AttachedPolicies']=[{'PolicyArn':'arn:aws:iam::aws:policy/AdministratorAccess'}]
        self.assertFalse(self.ok())
        self.setUp();self.e['inline']['PolicyNames'].append('unreviewed');self.assertFalse(self.ok())
        self.setUp();p=self.e['policies']['bridge-stage-scoped']['PolicyDocument']
        p['Statement']=[s for s in p['Statement'] if s['Effect']!='Deny']
        self.assertFalse(self.ok())

    def test_collect_uses_only_eleven_metadata_calls_and_no_writes(self):
        self.e['change']['CreationTime']='2026-10-26T00:00:00Z'
        calls=[]
        def fake(cmd,**kwargs):
            self.assertEqual(cmd[0],'aws');calls.append((cmd[1],cmd[2]))
            route={( 'sts','get-caller-identity'):'caller',
                ('cloudformation','describe-change-set'):'change',
                ('cloudformation','describe-stacks'):'stack',
                ('iam','get-role'):'role',('iam','list-attached-role-policies'):'attached',
                ('iam','list-role-policies'):'inline'}
            key=(cmd[1],cmd[2])
            if key==('cloudformation','get-template'):
                response=self.e['original' if cmd[cmd.index('--template-stage')+1]=='Original' else 'processed']
            elif key==('iam','get-role-policy'):
                response=self.e['policies'][cmd[cmd.index('--policy-name')+1]]
            elif key==('cloudtrail','lookup-events'):
                response={'Events':[{'CloudTrailEvent':json.dumps(self.e['creationEvents'][0])}]}
            else:response=self.e[route[key]]
            return SimpleNamespace(returncode=0,stdout=json.dumps(response),stderr='')
        with patch.object(v.subprocess,'run',side_effect=fake):
            result=v.collect()
        self.assertEqual(len(calls),11)
        self.assertTrue(all(v.verify(result).values()))

    def test_collect_stops_after_identity_when_account_is_wrong(self):
        calls=[]
        def fake(cmd,**kwargs):
            calls.append(cmd)
            return SimpleNamespace(returncode=0,stdout=json.dumps({'Account':'374040321139'}))
        with patch.object(v.subprocess,'run',side_effect=fake):
            with self.assertRaises(ValueError):v.collect()
        self.assertEqual(len(calls),1)

    def test_provider_audit_is_exactly_six_read_only_metadata_calls(self):
        calls=[]
        def fake(cmd,**kwargs):
            calls.append((cmd[1],cmd[2]))
            if calls[-1]==('sts','get-caller-identity'):response={'Account':v.ACCOUNT}
            else:
                self.assertEqual(calls[-1],('cloudformation','describe-type'))
                response={'DefaultVersionId':'synthetic','Schema':json.dumps({'handlers':{'read':{'permissions':['synthetic:Describe']}}})}
            return SimpleNamespace(returncode=0,stdout=json.dumps(response),stderr='')
        with patch.object(v.subprocess,'run',side_effect=fake):response=v.collect_provider_permissions()
        self.assertEqual(len(calls),6)
        self.assertEqual(len(response['providers']),5)


if __name__=='__main__':unittest.main()
