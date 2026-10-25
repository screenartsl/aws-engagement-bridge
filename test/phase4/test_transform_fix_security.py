"""Offline IAM boundary and native deployment-graph checks; no AWS/app bootstrap."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('transform_review', ROOT/'scripts/cloudformation/validate.py')
review = importlib.util.module_from_spec(spec)
spec.loader.exec_module(review)


class TransformFixSecurityTests(unittest.TestCase):
    def setUp(self):
        self.before = yaml.safe_load((ROOT/'deploy/runtime-iam/template.yaml').read_text())
        self.after = yaml.safe_load((ROOT/'deploy/runtime-sam-iam/template.yaml').read_text())
        self.native = yaml.safe_load((ROOT/'deploy/runtime-native/template.yaml').read_text())
        self.request = json.loads((ROOT/'deploy/runtime-native/change-set.update.prepared.json').read_text())
        self.params = {p['ParameterKey']:p['ParameterValue'] for p in self.request['Parameters']}
        self.values = dict(self.params, **{'AWS::AccountId':'365470560685', 'AWS::Region':'eu-west-1', 'AWS::StackName':'muserelay-aws-bridge-stage'})

    def test_iam_fix_only_appends_one_exact_transform_permission(self):
        proposed = copy.deepcopy(self.after)
        addition = proposed['Resources']['StageCloudFormationRole']['Properties']['Policies'][1]['PolicyDocument']['Statement'].pop()
        self.assertEqual(proposed, self.before)
        self.assertEqual(addition, {'Sid':'AllowAwsSamTransformInIreland','Effect':'Allow',
            'Action':['cloudformation:CreateChangeSet'],
            'Resource':'arn:aws:cloudformation:eu-west-1:aws:transform/Serverless-2016-10-31'})
        self.assertNotIn('*', addition['Resource'])
        self.assertNotIn('cloudformation:ExecuteChangeSet', addition['Action'])

    def test_iam_fix_keeps_recovery_runtime_roles_and_key_destruction_denies(self):
        for name in ['KmsRecoveryRole','InboundExecutionRole','EnqueueExecutionRole','SenderExecutionRole']:
            self.assertEqual(self.before['Resources'][name], self.after['Resources'][name])
        policies = self.after['Resources']['StageCloudFormationRole']['Properties']['Policies']
        self.assertEqual(policies[0], self.before['Resources']['StageCloudFormationRole']['Properties']['Policies'][0])
        deny = next(s for s in policies[0]['PolicyDocument']['Statement'] if s['Effect']=='Deny')
        self.assertEqual(set(deny['Action']), {'kms:DisableKey','kms:ScheduleKeyDeletion'})
        self.assertEqual(self.after['Parameters']['GrantSocialSend']['Default'], 'false')

    def test_native_has_no_transform_generated_iam_or_capability(self):
        self.assertNotIn('Transform', self.native)
        self.assertNotIn('Globals', self.native)
        self.assertNotIn('Capabilities', self.request)
        self.assertTrue(all(not r['Type'].startswith(('AWS::Serverless::','AWS::IAM::')) for r in self.native['Resources'].values()))
        self.assertEqual(yaml.safe_load(self.request['TemplateBody']), self.native)
        self.assertEqual(self.request['ChangeSetType'], 'UPDATE')
        self.assertEqual(self.request['RoleARN'], 'arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution')
        def has_nested_transform(value):
            if isinstance(value,dict):
                return 'Fn::Transform' in value or any(has_nested_transform(v) for v in value.values())
            return isinstance(value,list) and any(has_nested_transform(v) for v in value)
        self.assertFalse(has_nested_transform(self.native))

    def test_native_preserves_exact_ingress_and_receive_only_resource_graph(self):
        resources = {k:v for k,v in self.native['Resources'].items() if 'Condition' not in v or review.condition(v['Condition'],self.values,self.native['Conditions'])}
        ingress = yaml.safe_load((ROOT/'deploy/sns-bootstrap/template.yaml').read_text())['Resources']
        self.assertEqual(len(resources),17)
        for name,value in ingress.items():
            self.assertEqual(resources[name],value)
        self.assertEqual(set(resources)-set(ingress), {'State','InboundFunction','InboundFunctionInput','InboundLogs','SigningSecret'})
        self.assertNotIn('ReservedConcurrentExecutions', resources['InboundFunction']['Properties'])
        self.assertEqual(resources['InboundFunctionInput']['Properties']['ScalingConfig'], {'MaximumConcurrency':2})
        self.assertEqual(resources['InboundFunctionInput']['Properties']['Enabled'], {'Fn::If':['RunInboundConsumer',True,False]})
        self.assertFalse(review.condition('RunInboundConsumer',self.values,self.native['Conditions']))
        self.assertFalse(review.condition('RunOutboundConsumer',self.values,self.native['Conditions']))
        self.assertEqual(resources['State']['DeletionPolicy'],'Retain')
        self.assertEqual(resources['SigningSecret']['DeletionPolicy'],'Retain')
        self.assertNotIn('SecretString',resources['SigningSecret']['Properties'])
        self.assertNotIn('GenerateSecretString',resources['SigningSecret']['Properties'])

    def test_native_reuses_uploaded_artifact_without_changing_controls(self):
        original = json.loads((ROOT/'deploy/runtime-review/parameters.disabled.json').read_text())
        self.assertEqual(self.request['Parameters'],original)
        self.assertEqual(self.params['RuntimeArtifactKey'],'releases/82707086af6e0597d741c3e44e7ce843c074a326/bridge.zip')
        self.assertTrue(all(self.params[k]=='false' for k in ['DeployOutbound','ProcessingEnabled','ReceiveEnabled','SendEnabled','LiveSend']))
        binding = json.loads(self.params['BindingsJson'])[0]
        self.assertFalse(binding['enabled'])
        self.assertEqual(binding['allowedRecipients'],[])
        evidence = json.loads((ROOT/'deploy/runtime-review/evidence.s3-upload.owner-reported.json').read_text())
        self.assertTrue(self.params['RuntimeArtifactKey']==evidence['key'])

    def test_native_remains_dormant_by_default(self):
        defaults = {k:v['Default'] for k,v in self.native['Parameters'].items() if 'Default' in v}
        self.assertTrue(all(defaults[k]=='false' for k in ['DeployProcessing','DeployOutbound','ProcessingEnabled','ReceiveEnabled','SendEnabled','LiveSend']))
        values = dict(self.values, **defaults)
        resources = {k:v for k,v in self.native['Resources'].items() if 'Condition' not in v or review.condition(v['Condition'],values,self.native['Conditions'])}
        self.assertEqual(len(resources),12)

    def test_native_rules_reject_empty_artifact_key_when_runtime_is_selected(self):
        self.assertTrue(review.rules(self.native,self.values))
        self.assertFalse(review.rules(self.native,dict(self.values,RuntimeArtifactKey='')))
        self.assertNotEqual(self.params['RuntimeArtifactKey'],'')


if __name__ == '__main__':
    unittest.main()
