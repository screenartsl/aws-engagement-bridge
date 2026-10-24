#!/usr/bin/env python3
"""OFFLINE preparation only. No API calls, deployment, secret creation, sends or publication."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import tempfile

ROOT=Path(__file__).resolve().parents[2]
REQUIRED=['accountId','deployerPrincipalArn','artifactBucket','secretArn','linkedWabaId','metaWabaId','metaPhoneNumberId','socialPhoneArn','recipient','profile']

def validate(config):
    c=dict(config)
    if c.get('accountId') not in ['TO_SUPPLY','365470560685']: raise ValueError('Only the explicitly authorized Screen Art account is allowed')
    if c.get('region')!='eu-west-1': raise ValueError('Ireland only')
    if c.get('approved') is not False: raise ValueError('Preparation does not accept an approval flag; authorization must come from the user')
    for key,pattern in [('runId',r'phase4_[A-Za-z0-9_-]{4,56}'),('stackName',r'[A-Za-z][A-Za-z0-9-]{0,38}'),('bindingId',r'[A-Za-z0-9_-]{1,80}'),('channelRef',r'[A-Za-z0-9_-]{1,80}')]:
        if not re.fullmatch(pattern,str(c.get(key,''))): raise ValueError('Invalid '+key)
    host=c.get('coreHostname','')
    if host!='aws-bridge-staging.muserelay.com':
        raise ValueError('An explicit separate staging subdomain is required')
    for key in ['dailyInboundLimit','dailySendLimit']:
        if type(c.get(key)) is not int or not 1<=c[key]<=6: raise ValueError('First demonstration budget exceeded: '+key)
    if type(c.get('maximumDurationHours')) is not int or not 1<=c['maximumDurationHours']<=96 or c.get('proposedBudgetUsd')!=5: raise ValueError('Review duration/cost before changing this proposal')
    for key,expected in [('organizationId',97101),('workspaceId',10),('channelId',20),('agentId',30),('ticketInboxId',50)]:
        if c.get(key)!=expected: raise ValueError('Fixed isolated fixture id required: '+key)
    for key in ['organizationId','workspaceId','channelId','agentId','ticketInboxId']:
        if type(c.get(key)) is not int or c[key]<1: raise ValueError('Invalid '+key)
    if not re.fullmatch(r'v[0-9]+\.[0-9]+',c.get('metaApiVersion','')): raise ValueError('Invalid Meta API version')
    pending=[key for key in REQUIRED if c.get(key) in [None,'','TO_SUPPLY']]
    if 'accountId' not in pending and not re.fullmatch(r'[0-9]{12}',c['accountId']): raise ValueError('Invalid account id')
    if 'profile' not in pending and not re.fullmatch(r'[A-Za-z0-9_-]{1,80}',c['profile']): raise ValueError('Invalid explicit profile')
    if 'artifactBucket' not in pending and not re.fullmatch(r'[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]',c['artifactBucket']): raise ValueError('Invalid artifact bucket')
    if 'recipient' not in pending and not re.fullmatch(r'\+[1-9][0-9]{6,14}',c['recipient']): raise ValueError('Invalid allowlisted recipient')
    if 'accountId' not in pending:
        account=c['accountId']
        for key,pattern in [('socialPhoneArn',rf'arn:aws:social-messaging:eu-west-1:{account}:phone-number-id/[A-Za-z0-9][A-Za-z0-9-]*'),
            ('secretArn',rf'arn:aws:secretsmanager:eu-west-1:{account}:secret:[A-Za-z0-9/_+=.@-]+'),
            ('deployerPrincipalArn',rf'arn:aws:iam::{account}:(?:role|user)/[A-Za-z0-9/+=,.@_-]+')]:
            if key not in pending and not re.fullmatch(pattern,c[key]): raise ValueError('Mismatched identity: '+key)
    for key in ['metaWabaId','metaPhoneNumberId']:
        if key not in pending and not re.fullmatch(r'[0-9]+',str(c[key])): raise ValueError('Invalid '+key)
    if 'linkedWabaId' not in pending and not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9-]{0,127}',c['linkedWabaId']): raise ValueError('Invalid linked WABA id')
    return c,pending

def render(config):
    c,pending=validate(config); account=c['accountId']; region=c['region']; stack=c['stackName']
    topic=f'arn:aws:sns:{region}:{account}:{stack}-inbound'
    binding={'id':c['bindingId'],'channelRef':c['channelRef'],'enabled':False,'region':region,'topicArn':topic,'wabaId':c['metaWabaId'],
        'metaPhoneNumberId':c['metaPhoneNumberId'],'awsPhoneNumberId':c['socialPhoneArn'],'allowedRecipients':[] if 'recipient' in pending else [c['recipient']]}
    params={'BindingsJson':json.dumps([binding],separators=(',',':')),'SocialPhoneArn':c['socialPhoneArn'],'SecretArn':c['secretArn'],
        'CoreBaseUrl':'https://'+c['coreHostname'],'MetaApiVersion':c['metaApiVersion'],'LiveSend':'false','RunId':c['runId'],
        'DailyInboundLimit':str(c['dailyInboundLimit']),'DailySendLimit':str(c['dailySendLimit'])}
    read_actions=['sts:GetCallerIdentity','social-messaging:GetLinkedWhatsAppBusinessAccount','social-messaging:GetLinkedWhatsAppBusinessAccountPhoneNumber',
        'bedrock:GetInferenceProfile','cloudformation:DescribeStacks','cloudformation:ListStackResources','lambda:GetFunctionConfiguration','lambda:GetPolicy',
        'sns:GetTopicAttributes','sns:ListSubscriptionsByTopic','sqs:GetQueueAttributes','secretsmanager:DescribeSecret','logs:FilterLogEvents',
        'iam:SimulatePrincipalPolicy','iam:GetRole']
    policy={'Version':'2012-10-17','Statement':[
        {'Sid':'ReadIdentity','Effect':'Allow','Action':['sts:GetCallerIdentity'],'Resource':'*'},
        {'Sid':'ReadRegionalLambdaQuota','Effect':'Allow','Action':'lambda:GetAccountSettings','Resource':'*'},
        {'Sid':'InspectDemoSocialOnly','Effect':'Allow','Action':read_actions[1:3],'Resource':[c['socialPhoneArn'],f'arn:aws:social-messaging:{region}:{account}:waba/{c["linkedWabaId"]}']},
        {'Sid':'ReadSelectedProfiles','Effect':'Allow','Action':'bedrock:GetInferenceProfile','Resource':[f'arn:aws:bedrock:{region}:{account}:inference-profile/eu.amazon.nova-lite-v1:0',f'arn:aws:bedrock:{region}:{account}:inference-profile/eu.amazon.nova-micro-v1:0']},
        {'Sid':'DescribeStageSecretWithoutValue','Effect':'Allow','Action':'secretsmanager:DescribeSecret','Resource':c['secretArn']},
        {'Sid':'InspectStageStack','Effect':'Allow','Action':['cloudformation:DescribeStacks','cloudformation:ListStackResources'],'Resource':f'arn:aws:cloudformation:{region}:{account}:stack/{stack}/*'},
        {'Sid':'InspectStageFunctions','Effect':'Allow','Action':['lambda:GetFunctionConfiguration','lambda:GetPolicy'],'Resource':[f'arn:aws:lambda:{region}:{account}:function:{stack}-{s}' for s in ['inbound','enqueue','sender']]},
        {'Sid':'InspectStageTopic','Effect':'Allow','Action':['sns:GetTopicAttributes','sns:ListSubscriptionsByTopic'],'Resource':topic},
        {'Sid':'InspectStageQueues','Effect':'Allow','Action':'sqs:GetQueueAttributes','Resource':f'arn:aws:sqs:{region}:{account}:{stack}-*'},
        {'Sid':'InspectExplicitRuntimeRoles','Effect':'Allow','Action':['iam:GetRole','iam:ListRolePolicies','iam:GetRolePolicy'],'Resource':f'arn:aws:iam::{account}:role/{stack}-*'},
        {'Sid':'InspectStageLogs','Effect':'Allow','Action':'logs:FilterLogEvents','Resource':f'arn:aws:logs:{region}:{account}:log-group:/aws/lambda/{stack}-*:*'},
        {'Sid':'SimulateDeployer','Effect':'Allow','Action':'iam:SimulatePrincipalPolicy','Resource':c['deployerPrincipalArn']}
    ]}
    # Cross-region inference model destinations MUST come from authenticated GetInferenceProfile.
    bedrock={'Version':'2012-10-17','Statement':[{'Sid':'ConverseSelectedEuProfiles','Effect':'Allow','Action':'bedrock:InvokeModel',
        'Resource':[f'arn:aws:bedrock:{region}:{account}:inference-profile/eu.amazon.nova-lite-v1:0',f'arn:aws:bedrock:{region}:{account}:inference-profile/eu.amazon.nova-micro-v1:0','TO_SUPPLY_VERIFIED_EU_DESTINATION_MODEL_ARNS']} ]}
    return {'review':{'kind':'PREPARATION_ONLY','authorizedToExecute':False,'pendingInputs':pending,
        'accountVerified':False,'endpointProvisioned':False,'resourcesInBridgeTemplate':23,'externalResources':['dedicated demo WABA/number','stage signing secret','private artifact S3 bucket/object','dedicated staging OS user/services/TLS host','separate Bedrock identity'],
        'templateSha256':hashlib.sha256((ROOT/'template.yaml').read_bytes()).hexdigest(),'runId':c['runId'],'budgetUsd':5,'maximumHours':c['maximumDurationHours']},
        'parameters':[{'ParameterKey':k,'ParameterValue':v} for k,v in params.items()], 'readOnlyPolicy':policy,'bedrockPolicyDraft':bedrock}

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--inputs',type=Path,default=ROOT/'deploy/phase4/inputs.example.json');args=parser.parse_args()
    rendered=render(json.loads(args.inputs.read_text())); output=Path(tempfile.mkdtemp(prefix='muserelay-bridge-phase4-review-',dir='/tmp'));output.chmod(0o700)
    for name,key in [('review.json','review'),('parameters.disabled.json','parameters'),('read-only-policy.json','readOnlyPolicy'),('bedrock-policy.draft.json','bedrockPolicyDraft')]:
        (output/name).write_text(json.dumps(rendered[key],indent=2)+'\n');(output/name).chmod(0o600)
    print(json.dumps({'reviewDirectory':str(output),'authorizedToExecute':False,'pendingInputs':rendered['review']['pendingInputs']},indent=2))
if __name__=='__main__':main()
