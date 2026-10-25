#!/usr/bin/env python3
"""Offline structural/security review and resource inventory. No AWS API clients."""
import collections
import hashlib
import importlib.util
import json
from pathlib import Path
import yaml
ROOT=Path(__file__).resolve().parents[2]
ref=lambda n:{'Ref':n}
def resolve(value,values,conditions):
 if not isinstance(value,dict):return value
 if isinstance(value,dict):
  if 'Ref' in value:return values[value['Ref']]
  if 'Condition' in value:return condition(value['Condition'],values,conditions)
  if 'Fn::Equals' in value:return resolve(value['Fn::Equals'][0],values,conditions)==resolve(value['Fn::Equals'][1],values,conditions)
  if 'Fn::Not' in value:return not resolve(value['Fn::Not'][0],values,conditions)
  if 'Fn::And' in value:return all(resolve(v,values,conditions) for v in value['Fn::And'])
 raise ValueError('Unexpected expression')
def condition(name,values,conditions):return resolve(conditions[name],values,conditions)
def rules(t,values):
 for rule in t['Rules'].values():
  if 'RuleCondition' in rule and not resolve(rule['RuleCondition'],values,t.get('Conditions',{})):continue
  if not all(resolve(a['Assert'],values,t.get('Conditions',{})) for a in rule['Assertions']):return False
 return True

def review():
 t=yaml.safe_load((ROOT/'template.yaml').read_text());b=yaml.safe_load((ROOT/'deploy/sns-bootstrap/template.yaml').read_text())
 spec=importlib.util.spec_from_file_location('bootstrap',ROOT/'scripts/cloudformation/generate-bootstrap.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
 assert b==m.build(t),'Bootstrap drift: regenerate before review'
 r=t['Resources'];p=t['Parameters'];values={k:v.get('Default',v.get('AllowedValues',['synthetic-required'])[0]) for k,v in p.items()}
 values.update({'AWS::AccountId':'365470560685','AWS::Region':'eu-west-1','AWS::StackName':'muserelay-aws-bridge-stage'})
 assert rules(t,values) and rules(b,values)
 for k,v in [('AWS::AccountId','374040321139'),('AWS::Region','eu-central-1'),('AWS::StackName','unreviewed-stack')]:
  assert not rules(t,dict(values,**{k:v})),'Wrong deployment identity accepted'
 assert not rules(t,dict(values,ProcessingEnabled='true'))
 assert not rules(t,dict(values,LiveSend='true'))
 assert not rules(t,dict(values,DeployProcessing='true'))
 assert p['DeployProcessing']['Default']==p['ProcessingEnabled']['Default']==p['LiveSend']['Default']=='false'
 assert p['BindingsJson']['Default']=='[]'
 assert p['DailySendLimit']['Default']==p['DailySendLimit']['MaxValue']==6
 assert p['DailyInboundLimit']['Default']==p['DailyInboundLimit']['MaxValue']==6
 assert t['Globals']['Function']['CodeUri']=={'Bucket':ref('ArtifactBucket'),'Key':ref('RuntimeArtifactKey')}
 assert t['Globals']['Function']['Environment']['Variables']['BRIDGE_RUNTIME_ENABLED']==ref('ProcessingEnabled')
 topic=r['InboundTopic']['Properties'];assert topic['FifoTopic'] is False and topic['MaximumMessageSize']==262144
 assert topic['KmsMasterKeyId']=={'Fn::GetAtt':['SnsKey','Arn']}
 key=r['SnsKey'];assert key['DeletionPolicy']==key['UpdateReplacePolicy']=='Retain'
 policy=key['Properties']['KeyPolicy']['Statement'];assert len(policy)==4
 assert key['Properties']['BypassPolicyLockoutSafetyCheck'] is False
 assert policy[0]['Principal']=={'AWS':ref('KmsAdministratorArn')}
 assert 'kms:*' not in policy[0]['Action']
 assert policy[1]['Principal']=={'AWS':ref('KmsRecoveryAdministratorArn')}
 assert set(policy[1]['Action'])==set(policy[2]['Action'])=={'kms:DescribeKey','kms:GetKeyPolicy','kms:PutKeyPolicy'}
 assert policy[2]['Principal']=={'AWS':{'Fn::Sub':'arn:${AWS::Partition}:iam::${AWS::AccountId}:root'}}
 assert policy[3]['Principal']=={'Service':'social-messaging.amazonaws.com'}
 assert set(policy[3]['Action'])=={'kms:GenerateDataKey*','kms:Decrypt'}
 assert policy[3]['Condition']['StringEquals']['aws:SourceAccount']==ref('AWS::AccountId')
 assert p['KmsAdministratorArn']['AllowedValues']==['arn:aws:iam::365470560685:role/muserelay-aws-bridge-stage-cfn-execution']
 assert p['KmsRecoveryAdministratorArn']['AllowedValues']==['arn:aws:iam::365470560685:role/muserelay-aws-bridge-kms-recovery']
 assert r['Subscription']['Properties']['RawMessageDelivery'] is False
 assert r['Subscription']['Properties']['RedrivePolicy']['deadLetterTargetArn']=={'Fn::GetAtt':['DeliveryDlq','Arn']}
 for n in ['InboundQueuePolicy','DeliveryDlqPolicy']:
  s=r[n]['Properties']['PolicyDocument']['Statement'][0]
  assert s['Principal']=={'Service':'sns.amazonaws.com'} and s['Action']=='sqs:SendMessage'
  assert s['Condition']['ArnEquals']['aws:SourceArn']==ref('InboundTopic')
 for n in ['InboundFunction','SenderFunction']:
  props=next(iter(r[n]['Properties']['Events'].values()))['Properties']
  assert props['Enabled']=={'Fn::If':['RunInboundConsumer' if n=='InboundFunction' else 'RunOutboundConsumer',True,False]}
 secret=r['SigningSecret'];assert 'SecretString' not in secret['Properties'] and 'GenerateSecretString' not in secret['Properties']
 assert secret['DeletionPolicy']=='Retain'
 bucket=r['ArtifactBucket'];assert bucket['DeletionPolicy']==bucket['UpdateReplacePolicy']=='Retain'
 assert all(bucket['Properties']['PublicAccessBlockConfiguration'].values())
 assert bucket['Properties']['BucketEncryption']['ServerSideEncryptionConfiguration'][0]['ServerSideEncryptionByDefault']['SSEAlgorithm']=='AES256'
 deny=r['ArtifactBucketPolicy']['Properties']['PolicyDocument']['Statement'][0]
 assert deny['Effect']=='Deny' and deny['Condition']['Bool']['aws:SecureTransport']=='false'
 assert not any(v['Type']=='AWS::IAM::Role' for v in r.values()),'Runtime must not create or edit its IAM roles'
 for name in ['Inbound','Enqueue','Sender']:
  assert r[name+'Function']['Properties']['Role']==ref(name+'ExecutionRoleArn')
 for name in ['ReceiveEnabled','SendEnabled','DeployOutbound']:
  assert p[name]['Default']=='false'
 assert not any(v['Type'].startswith('AWS::Bedrock') for v in r.values())
 inventory={}
 for mode in ['ingress','receive_runtime','runtime']:
  vals=dict(values)
  if mode!='ingress':vals.update(DeployProcessing='true',SocialPhoneArn='arn:aws:social-messaging:eu-west-1:365470560685:phone-number-id/synthetic',CoreBaseUrl='https://aws-bridge-staging.muserelay.com',MetaApiVersion='v23.0',BindingsJson='[{"synthetic":true}]',RuntimeArtifactKey='releases/'+'a'*40+'/bridge.zip')
  if mode=='runtime':vals['DeployOutbound']='true'
  assert rules(t,vals)
  selected={k:v for k,v in r.items() if 'Condition' not in v or condition(v['Condition'],vals,t['Conditions'])}
  inventory[mode]={'declaredResources':len(selected),'resourceTypes':dict(collections.Counter(v['Type'] for v in selected.values())),'logicalIds':list(selected)}
 return {'result':'PASS','kind':'OFFLINE_PREPARATION_ONLY','awsCalls':0,'accountVerified':False,'templateSha256':hashlib.sha256((ROOT/'template.yaml').read_bytes()).hexdigest(),'bootstrapSha256':hashlib.sha256((ROOT/'deploy/sns-bootstrap/template.yaml').read_bytes()).hexdigest(),'defaultRuntimeEnabled':False,'defaultLiveSend':False,'inventory':inventory,'limitations':['No CloudFormation service-side change set or deployment executed','IAM/SCP/quotas/Meta eligibility remain unverified','Signing secret starts without a value; private initialization required before enabling','Bedrock identity and isolated Core staging are external to this AWS transport stack']}
if __name__=='__main__':print(json.dumps(review(),indent=2))
