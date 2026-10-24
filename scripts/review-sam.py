#!/usr/bin/env python3
"""Offline SAM expansion. Requires aws-sam-translator; supplies managed policy ARNs without IAM calls."""
import collections
import copy
import json
from pathlib import Path
from samtranslator.translator.transform import transform
from samtranslator.yaml_helper import yaml_parse

class OfflinePolicies:
    def load(self):
        return {name:'arn:aws:iam::aws:policy/service-role/'+name for name in ['AWSLambdaBasicExecutionRole','AWSLambdaSQSQueueExecutionRole']}

fragment=yaml_parse((Path(__file__).resolve().parents[1]/'template.yaml').read_text())
# SAM's translator needs an already-packaged CodeUri; this value is a fixture, not an upload.
fragment=copy.deepcopy(fragment)
fragment['Globals']['Function']['CodeUri']='s3://synthetic-stage-package/bridge.zip'
params={'BindingsJson':'[]','SocialPhoneArn':'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/phone-number-id-stageabc123',
        'SecretArn':'arn:aws:secretsmanager:eu-west-1:111111111111:secret:synthetic-stage','CoreBaseUrl':'https://bridge-stage.invalid','MetaApiVersion':'v23.0'}
expanded=transform(fragment,params,OfflinePolicies())
resources=expanded['Resources']
roles=[r['Properties'] for r in resources.values() if r['Type']=='AWS::IAM::Role']
assert len(roles)==3
for role in roles:
    assert not role.get('ManagedPolicyArns'), 'SAM must not grant a broad managed SQS policy'
    for policy in role['Policies']:
        for statement in policy['PolicyDocument']['Statement']:
            assert statement['Resource'] != '*', 'Unscoped runtime IAM permission'

print(json.dumps({'resourceTypes':dict(collections.Counter(r['Type'] for r in resources.values())),
                  'roles':{key:value['Properties'] for key,value in resources.items() if value['Type']=='AWS::IAM::Role'}},indent=2))
