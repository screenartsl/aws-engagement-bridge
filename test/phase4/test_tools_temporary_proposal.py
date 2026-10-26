"""Local security contracts for proposed policies, not an AWS IAM simulation."""
import json
from pathlib import Path
import unittest
import copy

import yaml

ROOT = Path(__file__).resolve().parents[2]
DIR = ROOT/'deploy/tools-temporary'


def load(name):
    return json.loads((DIR/name).read_text())


def allows(policy):
    return [s for s in policy['Statement'] if s['Effect']=='Allow']


def actions(policy):
    return {a for s in allows(policy) for a in s['Action']}


class TemporaryToolsProposal(unittest.TestCase):
    def setUp(self):
        self.prepare=load('tools.prepare.policy.json')
        self.deploy=load('tools.deploy-closed.policy.json')
        self.executor=load('executor.closed.policy.json')

    def test_deploy_adds_only_stack_scoped_execution(self):
        before=allows(self.prepare); after=allows(self.deploy)
        self.assertEqual(after[:-1],before)
        self.assertEqual(after[-1]['Action'],['cloudformation:ExecuteChangeSet'])
        self.assertEqual(after[-1]['Resource'],'arn:aws:cloudformation:eu-west-1:365470560685:stack/muserelay-aws-bridge-stage/*')
        self.assertNotIn('cloudformation:ExecuteChangeSet',actions(self.prepare))

    def test_no_direct_data_or_iam_mutation_or_lambda_code_access(self):
        forbidden={'sts:AssumeRole','iam:PutRolePolicy','iam:AttachRolePolicy',
            'iam:UpdateAssumeRolePolicy','iam:DeleteRolePermissionsBoundary',
            'lambda:InvokeFunction','lambda:UpdateFunctionCode','lambda:UpdateFunctionConfiguration',
            'sqs:ReceiveMessage','sqs:DeleteMessage','sqs:SendMessage',
            'secretsmanager:GetSecretValue','secretsmanager:PutSecretValue',
            'dynamodb:GetItem','dynamodb:PutItem','cloudformation:UpdateStack',
            'cloudformation:DeleteStack','cloudformation:SetStackPolicy','s3:DeleteObject'}
        for p in [self.prepare,self.deploy]:
            self.assertFalse(actions(p)&forbidden)
            self.assertFalse(any(a.startswith(('social-messaging:','bedrock:')) for a in actions(p)))
            self.assertEqual({a for a in actions(p) if a.startswith('iam:')},
                {'iam:GetRole','iam:ListRolePolicies','iam:GetRolePolicy','iam:ListAttachedRolePolicies','iam:PassRole'})

    def test_prepare_requires_new_bounded_executor_and_review_names(self):
        s=next(s for s in allows(self.prepare) if s['Sid']=='PrepareOnlyBridgeRuntime')
        self.assertEqual(s['Condition']['StringEquals']['cloudformation:RoleArn'],
            'arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-tools-cfn-execution')
        self.assertEqual(s['Condition']['StringLike']['cloudformation:ChangeSetName'],'bridge-tools-closed-*')

    def test_passrole_chain_uses_exact_roles_and_services(self):
        for policy,role,service in [(self.prepare,'tools-cfn-execution','cloudformation.amazonaws.com'),
                                   (self.executor,'inbound-runtime','lambda.amazonaws.com')]:
            passes=[s for s in allows(policy) if 'iam:PassRole' in s['Action']]
            self.assertEqual(len(passes),1)
            self.assertEqual(passes[0]['Resource'],'arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-'+role)
            self.assertEqual(passes[0]['Condition']['StringEquals']['iam:PassedToService'],service)

    def test_expiry_and_deny_complement_present(self):
        for p in [self.prepare,self.deploy,self.executor]:
            deny=next(s for s in p['Statement'] if s['Sid']=='DenyUnlistedActions')
            self.assertEqual(deny['Effect'],'Deny')
            self.assertEqual(set(deny['NotAction']),actions(p))
            end=next(s for s in p['Statement'] if s['Sid']=='ExpireAllAccess')
            self.assertEqual(end['Effect'],'Deny'); self.assertEqual(end['Action'],'*')
            self.assertEqual(end['Condition']['DateGreaterThanEquals']['aws:CurrentTime'],'2026-10-27T00:00:00Z')

    def test_no_ingress_policy_administration_in_executor(self):
        a=actions(self.executor)
        self.assertFalse(a & {'kms:CreateKey','kms:PutKeyPolicy','kms:CreateGrant',
            'sns:SetTopicAttributes','sns:Subscribe','sns:Publish','sqs:SetQueueAttributes',
            'sqs:PurgeQueue','s3:PutBucketPolicy','s3:PutLifecycleConfiguration'})
        self.assertEqual({x for x in a if x.startswith('iam:')},{'iam:PassRole'})
        self.assertIn('lambda:UpdateFunctionCode',a)
        self.assertNotIn('lambda:InvokeFunction',a)

    def test_boundaries_close_all_real_data_actions(self):
        for label in ['inbound','enqueue','sender']:
            p=load('runtime.'+label+'.closed.boundary.json')
            deny=next(s for s in p['Statement'] if s['Sid']=='BlockRealDataBeforeSeparateApproval')
            self.assertEqual(deny['Effect'],'Deny'); self.assertEqual(deny['Resource'],'*')
            self.assertTrue({'sqs:ReceiveMessage','sqs:DeleteMessage','sqs:SendMessage',
                'secretsmanager:GetSecretValue','social-messaging:*','bedrock:*','sts:AssumeRole'}<=set(deny['Action']))
            self.assertNotIn('social-messaging:SendWhatsAppMessage',actions(p))
            self.assertLessEqual(len(json.dumps(p,separators=(',',':'))),6144)

    def test_artifact_uploads_cannot_overwrite_existing_releases(self):
        s=next(s for s in allows(self.prepare) if s['Sid']=='NewReviewArtifactsOnly')
        self.assertEqual(s['Resource'],'arn:aws:s3:::muserelay-aws-bridge-stage-artifacts-365470560685-eu-west-1/tooling-review/20261026/*')
        self.assertEqual(s['Action'],['s3:PutObject'])
        self.assertEqual(s['Condition']['StringEquals']['s3:if-none-match'],'*')
        self.assertEqual(s['Condition']['StringEquals']['s3:x-amz-server-side-encryption'],'AES256')

    def test_ingress_stack_policy_protects_all_twelve_existing_resources(self):
        p=load('ingress.stack-policy.proposed.json')
        deny=p['Statement'][1]
        self.assertEqual(deny['Effect'],'Deny');self.assertEqual(deny['Action'],'Update:*')
        self.assertEqual(len(deny['Resource']),12)
        for r in ['SnsKey','InboundTopic','ArtifactBucket']:
            self.assertIn('LogicalResourceId/'+r,deny['Resource'])

    def test_inline_policy_sizes_and_no_wildcard_allow_actions(self):
        for p in [self.prepare,self.deploy,self.executor]:
            self.assertLessEqual(len(json.dumps(p,separators=(',',':'))),10240)
            self.assertFalse(any('*' in a for a in actions(p)))

    def test_executor_trust_and_not_assumable_by_tools(self):
        trust=load('executor.trust.json')['Statement']
        self.assertEqual(len(trust),1)
        self.assertEqual(trust[0]['Principal'],{'Service':'cloudformation.amazonaws.com'})
        self.assertNotIn('sts:AssumeRole',actions(self.deploy))

    def test_manifest_does_not_claim_activation_or_complete_implementation(self):
        m=load('review-manifest.json')
        self.assertEqual(m['status'],'PROPOSAL_ONLY_NOT_AUTHORIZATION')
        self.assertFalse(m['trustedRunnerImplemented'])
        self.assertTrue(m['requiresTrustedRunnerBeforeDeployCredentialExposure'])
        self.assertTrue(m['requiresExecutorAndAllRuntimeBoundariesVerified'])
        self.assertFalse(any(m['controls'].values()))
        self.assertFalse(m['sendPolicyPrepared'])

    def test_foundation_delta_is_only_new_executor_boundaries_and_associations(self):
        baseline=yaml.safe_load((ROOT/'deploy/runtime-sam-iam/template.yaml').read_text())
        proposed=yaml.safe_load((DIR/'foundation.update.proposed.yaml').read_text())
        self.assertEqual(len(proposed['Resources'])-len(baseline['Resources']),4)
        for logical,value in baseline['Resources'].items():
            actual=copy.deepcopy(proposed['Resources'][logical])
            if logical in ['InboundExecutionRole','EnqueueExecutionRole','SenderExecutionRole']:
                key=actual['Properties'].pop('PermissionsBoundary')['Ref']
                self.assertEqual(proposed['Resources'][key]['Type'],'AWS::IAM::ManagedPolicy')
                self.assertEqual(proposed['Resources'][key]['DeletionPolicy'],'Retain')
            self.assertEqual(actual,value)
        self.assertEqual(proposed['Parameters'],baseline['Parameters'])
        self.assertEqual(proposed['Rules'],baseline['Rules'])
        self.assertEqual(proposed['Resources']['ToolsClosedCloudFormationRole']['Properties']['Policies'][0]['PolicyDocument'],self.executor)
        self.assertEqual(proposed['Resources']['ToolsClosedCloudFormationRole']['Properties']['AssumeRolePolicyDocument'],load('executor.trust.json'))

    def test_sso_template_keeps_assignments_disabled_and_embeds_exact_policies(self):
        s=yaml.safe_load((DIR/'sso.permission-sets.proposed.yaml').read_text())
        self.assertEqual(s['Parameters']['AssignPrepare']['Default'],'false')
        self.assertEqual(s['Parameters']['AssignDeployClosed']['Default'],'false')
        for key,policy,condition in [('Prepare',self.prepare,'PrepareApproved'),
                                     ('DeployClosed',self.deploy,'DeployApproved')]:
            permission=s['Resources'][key+'PermissionSet']['Properties']
            self.assertEqual(permission['InlinePolicy'],policy)
            self.assertEqual(permission['SessionDuration'],'PT1H')
            assignment=s['Resources'][key+'Assignment']
            self.assertEqual(assignment['Condition'],condition)
            self.assertEqual(assignment['Properties']['TargetId'],'365470560685')
            self.assertEqual(assignment['Properties']['PrincipalType'],'USER')
            self.assertNotIn('ManagedPolicies',permission)
        self.assertIn('OnlyOneWriteProfile',s['Rules'])


if __name__=='__main__':
    unittest.main()
