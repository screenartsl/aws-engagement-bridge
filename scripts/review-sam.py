#!/usr/bin/env python3
"""Offline SAM expansion. Requires aws-sam-translator; supplies managed policy ARNs without IAM calls."""
import collections
import json
from pathlib import Path
from samtranslator.translator.transform import transform
from samtranslator.yaml_helper import yaml_parse

class OfflinePolicies:
    def load(self):
        return {name:'arn:aws:iam::aws:policy/service-role/'+name for name in ['AWSLambdaBasicExecutionRole','AWSLambdaSQSQueueExecutionRole']}

fragment=yaml_parse((Path(__file__).resolve().parents[1]/'template.yaml').read_text())
# Review the actual parameterized S3 CodeUri; no upload and no replacement fixture.
params={'BindingsJson':'[]','SocialPhoneArn':'arn:aws:social-messaging:eu-west-1:365470560685:phone-number-id/synthetic-stage',
        'SecretArn':'','CoreBaseUrl':'https://aws-bridge-staging.muserelay.com','MetaApiVersion':'v23.0',
        'KmsAdministratorArn':'arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution',
        'KmsRecoveryAdministratorArn':'arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery',
        'DeployProcessing':'true','ProcessingEnabled':'false','LiveSend':'false',
        'RuntimeArtifactKey':'releases/'+'a'*40+'/bridge.zip'}
expanded=transform(fragment,params,OfflinePolicies())
resources=expanded['Resources']
roles=[r['Properties'] for r in resources.values() if r['Type']=='AWS::IAM::Role']
assert len(roles)==3
initial={name for name,r in fragment['Resources'].items() if 'Condition' not in r}
for name,resource in resources.items():
    if name not in initial:
        assert resource.get('Condition') in ['DeployRuntime','CreateSigningSecret'], 'Runtime resource missing deployment condition: '+name
    if resource['Type']=='AWS::Lambda::Function':
        assert resource['Properties']['Code']=={'S3Bucket':{'Ref':'ArtifactBucket'},'S3Key':{'Ref':'RuntimeArtifactKey'}}
        assert resource['Properties']['Environment']['Variables']['BRIDGE_RUNTIME_ENABLED']=={'Ref':'ProcessingEnabled'}
for role in roles:
    assert not role.get('ManagedPolicyArns'), 'SAM must not grant a broad managed SQS policy'
    for policy in role['Policies']:
        for statement in policy['PolicyDocument']['Statement']:
            if 'Fn::If' in statement:
                statement=statement['Fn::If'][1]
            assert statement['Resource'] != '*', 'Unscoped runtime IAM permission'

print(json.dumps({'result':'PASS','awsCalls':0,'expandedResources':len(resources),
                  'unconditionalResources':len(initial),'conditionalRuntimeResources':len(resources)-len(initial),
                  'resourceTypes':dict(collections.Counter(r['Type'] for r in resources.values())),
                  'roles':{key:value['Properties'] for key,value in resources.items() if value['Type']=='AWS::IAM::Role'}},indent=2))
