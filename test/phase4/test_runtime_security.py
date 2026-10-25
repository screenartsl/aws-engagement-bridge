"""Offline permission and activation tests. No boto3, AWS calls or production bootstrap."""
import copy
import importlib.util
import itertools
from pathlib import Path
import unittest
import yaml
ROOT=Path(__file__).resolve().parents[2]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
review=load('runtime_review','scripts/cloudformation/validate.py')
generator=load('runtime_iam','scripts/cloudformation/prepare-runtime-iam.py')
class SecurityTests(unittest.TestCase):
    def setUp(self):
        self.runtime=yaml.safe_load((ROOT/'template.yaml').read_text())
        self.foundation=yaml.safe_load((ROOT/'deploy/runtime-iam/template.yaml').read_text())
    def test_foundation_preserves_recovery_trust_and_ingress_permissions(self):
        baseline=yaml.safe_load((ROOT/'deploy/iam-foundation/template.yaml').read_text())
        self.assertEqual(self.foundation,generator.build())
        self.assertEqual(self.foundation['Resources']['KmsRecoveryRole'],baseline['Resources']['KmsRecoveryRole'])
        new=self.foundation['Resources']['StageCloudFormationRole'];old=baseline['Resources']['StageCloudFormationRole']
        for key in ['RoleName','AssumeRolePolicyDocument','MaxSessionDuration']:
            self.assertEqual(new['Properties'][key],old['Properties'][key])
        self.assertEqual(new['Properties']['Policies'][0],old['Properties']['Policies'][0])
        self.assertEqual(new['DeletionPolicy'],old['DeletionPolicy'])
        self.assertEqual(new['UpdateReplacePolicy'],old['UpdateReplacePolicy'])
    def test_executor_cannot_create_or_rewrite_runtime_iam_or_send(self):
        policies=self.foundation['Resources']['StageCloudFormationRole']['Properties']['Policies']
        actions={a for p in policies for s in p['PolicyDocument']['Statement'] if s['Effect']=='Allow' for a in s['Action']}
        self.assertEqual({a for a in actions if a.startswith('iam:')},{'iam:PassRole'})
        self.assertFalse(actions & {'social-messaging:SendWhatsAppMessage','bedrock:InvokeModel','secretsmanager:GetSecretValue','secretsmanager:PutSecretValue'})
        passrole=policies[-1]['PolicyDocument']['Statement'][0]
        self.assertEqual(passrole['Resource'],generator.ARN+generator.PREFIX+'-inbound-runtime')
        self.assertEqual(passrole['Condition']['StringEquals']['iam:PassedToService'],'lambda.amazonaws.com')
    def test_runtime_roles_are_separate_and_social_permission_defaults_absent(self):
        roles=[self.foundation['Resources'][name+'ExecutionRole'] for name in ['Inbound','Enqueue','Sender']]
        self.assertEqual(len({r['Properties']['RoleName'] for r in roles}),3)
        for r in roles:
            self.assertEqual(r['Properties']['AssumeRolePolicyDocument']['Statement'][0]['Principal'],{'Service':'lambda.amazonaws.com'})
            for s in r['Properties']['Policies'][0]['PolicyDocument']['Statement']:
                if 'Fn::If' in s:continue
                self.assertNotEqual(s['Resource'],'*')
                self.assertFalse(any(a.startswith(('bedrock:','iam:')) for a in s['Action']))
        social=roles[-1]['Properties']['Policies'][0]['PolicyDocument']['Statement'][-1]['Fn::If']
        self.assertEqual(self.foundation['Parameters']['GrantSocialSend']['Default'],'false')
        self.assertEqual(social[0],'GrantSocialSend');self.assertEqual(social[2],{'Ref':'AWS::NoValue'})
        self.assertEqual(social[1]['Resource'],generator.PHONE)
        self.assertEqual(social[1]['Action'],['social-messaging:SendWhatsAppMessage'])
    def test_directional_secrets_do_not_expose_outbound_key_to_inbound(self):
        roles=self.foundation['Resources']
        core=roles['InboundExecutionRole']['Properties']['Policies'][0]['PolicyDocument']['Statement'][1]['Resource']
        outgoing=roles['EnqueueExecutionRole']['Properties']['Policies'][0]['PolicyDocument']['Statement'][1]['Resource']
        self.assertNotEqual(core,outgoing)
        self.assertTrue(core.endswith('/signing-??????'))
        self.assertTrue(outgoing.endswith('/signing-outbound-??????'))
    def test_receive_only_never_deploys_outbound_or_enables_sender(self):
        t=self.runtime
        values={k:v.get('Default',v.get('AllowedValues',['synthetic'])[0]) for k,v in t['Parameters'].items()}
        values.update(DeployProcessing='true',ProcessingEnabled='true',ReceiveEnabled='true')
        self.assertTrue(review.condition('RunInboundConsumer',values,t['Conditions']))
        self.assertFalse(review.condition('RunOutboundConsumer',values,t['Conditions']))
        self.assertFalse(review.condition('DeployOutboundRuntime',values,t['Conditions']))
        self.assertFalse(any(r['Type']=='AWS::IAM::Role' for r in t['Resources'].values()))
    def test_receive_uses_unreserved_capacity_and_bounds_only_its_sqs_mapping(self):
        self.assertNotIn('ReservedConcurrentExecutions', self.runtime['Globals']['Function'])
        inbound=self.runtime['Resources']['InboundFunction']['Properties']
        self.assertNotIn('ReservedConcurrentExecutions', inbound)
        sqs=inbound['Events']['Input']['Properties']
        self.assertEqual(sqs['ScalingConfig'], {'MaximumConcurrency':2})
        self.assertEqual(sqs['BatchSize'],1)
        self.assertEqual(sqs['Enabled'], {'Fn::If':['RunInboundConsumer',True,False]})
        for name in ['EnqueueFunction','SenderFunction']:
            self.assertEqual(self.runtime['Resources'][name]['Properties']['ReservedConcurrentExecutions'],1)
    def test_sender_requires_every_gate_including_live_send(self):
        t=self.runtime;names=['DeployProcessing','DeployOutbound','ProcessingEnabled','SendEnabled','LiveSend']
        for flags in itertools.product(['false','true'],repeat=len(names)):
            values=dict(zip(names,flags))
            self.assertEqual(review.condition('RunOutboundConsumer',values,t['Conditions']),all(v=='true' for v in flags))
    def test_ingress_is_unchanged_and_inference_role_is_separate(self):
        boot=load('runtime_boot','scripts/cloudformation/generate-bootstrap.py')
        self.assertEqual(boot.build(self.runtime),yaml.safe_load((ROOT/'deploy/sns-bootstrap/template.yaml').read_text()))
        b=yaml.safe_load((ROOT/'deploy/runtime-iam/bedrock-inference.prepared.yaml').read_text())
        statements=b['Resources']['BedrockInferenceRole']['Properties']['Policies'][0]['PolicyDocument']['Statement']
        self.assertEqual({a for s in statements for a in (s['Action'] if isinstance(s['Action'],list) else [s['Action']])},{'bedrock:InvokeModel','logs:CreateLogStream','logs:PutLogEvents'})
        self.assertTrue(all('Default' not in p for p in b['Parameters'].values()))
if __name__=='__main__':unittest.main()
