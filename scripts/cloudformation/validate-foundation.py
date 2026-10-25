#!/usr/bin/env python3
"""Offline consistency and trust-pattern checks; not AWS permission simulation."""
import fnmatch
import hashlib
import json
from pathlib import Path
import yaml

ROOT = Path(__file__).resolve().parents[2]
folder = ROOT/'deploy/iam-foundation'
t = yaml.safe_load((folder/'template.yaml').read_text())
resources = t['Resources']
assert len(resources) == 2
execution = resources['StageCloudFormationRole']
recovery = resources['KmsRecoveryRole']
for role in resources.values():
    assert role['Type'] == 'AWS::IAM::Role'
    assert role['DeletionPolicy'] == role['UpdateReplacePolicy'] == 'Retain'
    assert not role['Properties'].get('ManagedPolicyArns')
for name, role, key in [
    ('execution-role-trust.json',execution,'AssumeRolePolicyDocument'),
    ('recovery-role-trust.json',recovery,'AssumeRolePolicyDocument'),
]:
    assert json.loads((folder/name).read_text()) == role['Properties'][key]
for name, role in [('execution-ingress-policy.json',execution),('recovery-policy.json',recovery)]:
    assert json.loads((folder/name).read_text()) == role['Properties']['Policies'][0]['PolicyDocument']
assert execution['Properties']['AssumeRolePolicyDocument']['Statement'][0]['Principal'] == {'Service':'cloudformation.amazonaws.com'}
trust = recovery['Properties']['AssumeRolePolicyDocument']['Statement'][0]
assert trust['Principal'] == {'AWS':'arn:aws:iam::365470560685:root'}
pattern = trust['Condition']['ArnLike']['aws:PrincipalArn']
prefix='arn:aws:iam::365470560685:role/aws-reserved/sso.amazonaws.com/eu-west-1/'
for suffix in ['6a3ad0308126a256','different-recreated-suffix']:
    assert fnmatch.fnmatchcase(prefix+'AWSReservedSSO_AdministratorAccess_'+suffix,pattern)
for wrong in [
    prefix+'AWSReservedSSO_ReadOnlyAccess_abc',
    (prefix+'AWSReservedSSO_AdministratorAccess_abc').replace('365470560685','374040321139'),
    (prefix+'AWSReservedSSO_AdministratorAccess_abc').replace('eu-west-1','eu-central-1'),
    'arn:aws:iam::365470560685:root',
    'arn:aws:sts::365470560685:assumed-role/AWSReservedSSO_AdministratorAccess_abc/session',
]:
    assert not fnmatch.fnmatchcase(wrong,pattern)
for role in resources.values():
    for statement in role['Properties']['Policies'][0]['PolicyDocument']['Statement']:
        actions=statement['Action'] if isinstance(statement['Action'],list) else [statement['Action']]
        assert 'kms:*' not in actions and '*' not in actions
        assert not any(a.startswith(('iam:','bedrock:','social-messaging:')) for a in actions)
        assert not set(actions) & {'sns:Publish','sqs:SendMessage','sqs:ReceiveMessage','sqs:DeleteMessage','kms:Decrypt','kms:GenerateDataKey','s3:GetObject','s3:PutObject'}
        if statement['Resource']=='*':
            assert actions in [['kms:CreateKey'],['kms:ListAliases']]
            assert statement['Condition']['StringEquals']['aws:RequestedRegion']=='eu-west-1'
key=yaml.safe_load((ROOT/'template.yaml').read_text())['Resources']['SnsKey']['Properties']
statements={s['Sid']:s for s in key['KeyPolicy']['Statement']}
assert key['BypassPolicyLockoutSafetyCheck'] is False
execution_statements=execution['Properties']['Policies'][0]['PolicyDocument']['Statement']
allowed={a for s in execution_statements if s['Effect']=='Allow' for a in s['Action']}
assert not allowed & {'kms:PutKeyPolicy','kms:DisableKey','kms:ScheduleKeyDeletion','kms:CancelKeyDeletion'}
denied={a for s in execution_statements if s['Effect']=='Deny' for a in s['Action']}
assert denied=={'kms:DisableKey','kms:ScheduleKeyDeletion'}
assert not set(statements['DedicatedKeyAdministration']['Action']) & {'kms:DisableKey','kms:ScheduleKeyDeletion','kms:CancelKeyDeletion'}
assert set(statements['AccountPolicyRecovery']['Action'])=={'kms:DescribeKey','kms:GetKeyPolicy','kms:PutKeyPolicy'}
assert statements['DedicatedRecoveryAdministrator']['Principal']=={'AWS':{'Ref':'KmsRecoveryAdministratorArn'}}
assert 'AWSReservedSSO_' not in json.dumps(key['KeyPolicy'])
print(json.dumps({'result':'PASS','kind':'OFFLINE_ONLY','awsCalls':0,'iamRolesCreated':0,
    'foundationResources':2,'foundationSha256':hashlib.sha256((folder/'template.yaml').read_bytes()).hexdigest(),
    'checks':['Template/JSON equality','Stable retained roles without AdministratorAccess','Recreated SSO suffix accepted by trust pattern','Wrong account/region/permission set rejected by trust pattern','No runtime messaging/AI permissions','KMS lockout check remains enabled','Limited account recovery and stable recovery administrator','Executor explicitly denied DisableKey and ScheduleKeyDeletion','Executor IAM has no tag-based PutKeyPolicy grant; direct administration comes from the one-key policy'],
    'limitations':['Not IAM policy simulation or IAM Access Analyzer','No authenticated AWS/SCP/boundary checks','Propagation, create/update/delete and recovery must be verified after authorization','Execution policy covers ingress only, not runtime stage 2']},indent=2))
