#!/usr/bin/env python3
"""Generate local IAM review artifacts only; never calls AWS or executes a change set."""
import copy
import hashlib
import json
from pathlib import Path
import yaml
ROOT=Path(__file__).resolve().parents[2]
ACCOUNT='365470560685'; REGION='eu-west-1'; PREFIX='muserelay-aws-bridge-stage'
ARN=f'arn:aws:iam::{ACCOUNT}:role/'
PHONE=f'arn:aws:social-messaging:{REGION}:{ACCOUNT}:phone-number-id/b1e7f9e53c454f15be065a2e2b5a1f00'
TAGS=[{'Key':k,'Value':v} for k,v in [('Company','ScreenArt'),('Project','MuseRelay'),('Component','aws-engagement-bridge'),('Environment','staging'),('Purpose','hackathon')]]
class Dumper(yaml.SafeDumper):
    def ignore_aliases(self,data):return True

def allow(sid,actions,resources,condition=None):
    s={'Sid':sid,'Effect':'Allow','Action':actions,'Resource':resources}
    if condition:s['Condition']=condition
    return s

def role(name,statements):
    return {'Type':'AWS::IAM::Role','DeletionPolicy':'Retain','UpdateReplacePolicy':'Retain','Properties':{
        'RoleName':PREFIX+'-'+name+'-runtime','MaxSessionDuration':3600,
        'AssumeRolePolicyDocument':{'Version':'2012-10-17','Statement':[{'Effect':'Allow','Principal':{'Service':'lambda.amazonaws.com'},'Action':'sts:AssumeRole'}]},
        'Policies':[{'PolicyName':'bridge-runtime','PolicyDocument':{'Version':'2012-10-17','Statement':statements}}],'Tags':copy.deepcopy(TAGS)}}

def build():
    t=copy.deepcopy(yaml.safe_load((ROOT/'deploy/iam-foundation/template.yaml').read_text()))
    t['Description']='Screen Art IAM foundation UPDATE review: existing roles preserved; independent runtime roles; no messaging or consumers'
    t['Parameters']={'GrantSocialSend':{'Type':'String','Default':'false','AllowedValues':['false','true'],'Description':'Separate approval required; first receipt review must leave false'}}
    t['Conditions']={'GrantSocialSend':{'Fn::Equals':[{'Ref':'GrantSocialSend'},'true']}}
    table=f'arn:aws:dynamodb:{REGION}:{ACCOUNT}:table/{PREFIX}-state'
    secret=f'arn:aws:secretsmanager:{REGION}:{ACCOUNT}:secret:{PREFIX}/signing-??????'
    inbound=f'arn:aws:sqs:{REGION}:{ACCOUNT}:{PREFIX}-inbound-queue'
    outbound=f'arn:aws:sqs:{REGION}:{ACCOUNT}:{PREFIX}-outbound-queue'
    def state(actions):return allow('OwnState',actions,table)
    def key(direction='core'):return allow('OwnSigningSecret',['secretsmanager:GetSecretValue'],secret if direction=='core' else f'arn:aws:secretsmanager:{REGION}:{ACCOUNT}:secret:{PREFIX}/signing-outbound-??????')
    def log(name):return allow('OwnLogs',['logs:CreateLogStream','logs:PutLogEvents'],f'arn:aws:logs:{REGION}:{ACCOUNT}:log-group:/aws/lambda/{PREFIX}-{name}:*')
    def poll(queue):return allow('OwnQueue',['sqs:ReceiveMessage','sqs:DeleteMessage','sqs:GetQueueAttributes'],queue)
    crud=['dynamodb:GetItem','dynamodb:PutItem','dynamodb:UpdateItem','dynamodb:DeleteItem']
    t['Resources']['InboundExecutionRole']=role('inbound',[state(crud),key(),log('inbound'),poll(inbound)])
    t['Resources']['EnqueueExecutionRole']=role('enqueue',[state(['dynamodb:PutItem']),key('outbound'),log('enqueue'),allow('OwnOutboundQueue',['sqs:SendMessage'],outbound)])
    t['Resources']['SenderExecutionRole']=role('sender',[state(crud),key(),log('sender'),poll(outbound),{'Fn::If':['GrantSocialSend',allow('OnlyDemoPhone',['social-messaging:SendWhatsAppMessage'],PHONE),{'Ref':'AWS::NoValue'}]}])
    fn=f'arn:aws:lambda:{REGION}:{ACCOUNT}:function:{PREFIX}-inbound'
    log_group=f'arn:aws:logs:{REGION}:{ACCOUNT}:log-group:/aws/lambda/{PREFIX}-inbound'
    regional={'StringEquals':{'aws:RequestedRegion':REGION}}
    fncondition={'StringEquals':{'aws:RequestedRegion':REGION},'ArnEquals':{'lambda:FunctionArn':fn}}
    statements=[
        allow('PassOnlyInboundRuntime',['iam:PassRole'],ARN+PREFIX+'-inbound-runtime',{'StringEquals':{'iam:PassedToService':'lambda.amazonaws.com'}}),
        allow('InboundFunctionProvisioning',['lambda:CreateFunction','lambda:GetFunction','lambda:GetFunctionConfiguration','lambda:GetFunctionCodeSigningConfig','lambda:GetFunctionConcurrency','lambda:UpdateFunctionCode','lambda:UpdateFunctionConfiguration','lambda:DeleteFunction','lambda:PutFunctionConcurrency','lambda:DeleteFunctionConcurrency','lambda:TagResource','lambda:UntagResource','lambda:ListTags'],fn,regional),
        allow('CreateOnlyInboundMapping',['lambda:CreateEventSourceMapping'],'*',fncondition),
        allow('ManageOnlyInboundMapping',['lambda:GetEventSourceMapping','lambda:UpdateEventSourceMapping','lambda:DeleteEventSourceMapping'],f'arn:aws:lambda:{REGION}:{ACCOUNT}:event-source-mapping:*',fncondition),
        allow('OwnStateTableProvisioning',['dynamodb:CreateTable','dynamodb:DeleteTable','dynamodb:DescribeTable','dynamodb:UpdateTable','dynamodb:UpdateTimeToLive','dynamodb:DescribeTimeToLive','dynamodb:TagResource','dynamodb:UntagResource','dynamodb:ListTagsOfResource'],table,regional),
        allow('EmptySigningSecretMetadata',['secretsmanager:CreateSecret','secretsmanager:DescribeSecret','secretsmanager:TagResource','secretsmanager:UntagResource','secretsmanager:ListSecretVersionIds','secretsmanager:GetResourcePolicy'],secret,regional),
        allow('OwnLogGroupProvisioning',['logs:CreateLogGroup','logs:DeleteLogGroup','logs:PutRetentionPolicy','logs:DeleteRetentionPolicy','logs:TagResource','logs:UntagResource','logs:ListTagsForResource'],[log_group,log_group+':*'],regional),
        allow('RegionalLogGroupMetadata',['logs:DescribeLogGroups'],'*',regional),
        allow('ReadReviewedArtifact',['s3:GetObject'],f'arn:aws:s3:::{PREFIX}-artifacts-{ACCOUNT}-{REGION}/releases/*/bridge.zip')]
    t['Resources']['StageCloudFormationRole']['Properties']['Policies'].append({'PolicyName':'bridge-runtime-receive-provisioning','PolicyDocument':{'Version':'2012-10-17','Statement':statements}})
    for key,name in [('InboundExecutionRoleArn','InboundExecutionRole'),('EnqueueExecutionRoleArn','EnqueueExecutionRole'),('SenderExecutionRoleArn','SenderExecutionRole')]:
        t['Outputs'][key]={'Value':{'Fn::GetAtt':[name,'Arn']}}
    return t

def main():
    out=ROOT/'deploy/runtime-iam';out.mkdir(exist_ok=True)
    t=build();path=out/'template.yaml';path.write_text(yaml.dump(t,Dumper=Dumper,sort_keys=False,width=110))
    request={'StackName':'muserelay-aws-bridge-iam-foundation','ChangeSetName':'receive-runtime-iam-review-'+hashlib.sha256(path.read_bytes()).hexdigest()[:12],
        'ChangeSetType':'UPDATE','Capabilities':['CAPABILITY_NAMED_IAM'],'Parameters':[{'ParameterKey':'GrantSocialSend','ParameterValue':'false'}],
        'Description':'LOCAL REQUEST ONLY. Existing recovery role untouched; add three independent runtime roles; extend executor for ingress only.'}
    (out/'change-set.update.prepared.json').write_text(json.dumps(request,indent=2)+'\n')
    policies={name:res['Properties']['Policies'] for name,res in t['Resources'].items()}
    (out/'policies.review.json').write_text(json.dumps(policies,indent=2)+'\n')
    print(json.dumps({'kind':'LOCAL_PREPARATION_ONLY','awsCalls':0,'resourceCount':len(t['Resources']),'expectedAdds':['InboundExecutionRole','EnqueueExecutionRole','SenderExecutionRole'],'expectedModify':['StageCloudFormationRole'],'recoveryRoleUnchanged':True,'grantSocialSend':False,'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}))
if __name__=='__main__':main()
