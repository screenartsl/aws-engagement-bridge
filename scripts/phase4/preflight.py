#!/usr/bin/env python3
"""Targeted READ-ONLY AWS checks. Never reads secret values, invokes models or sends messages."""
import argparse
import hashlib
import json
import re
from pathlib import Path
from plan import validate

def fingerprint(value):return hashlib.sha256(str(value).encode()).hexdigest()[:16]

def check(config, client):
    c,pending=validate(config)
    if 'accountId' in pending: return {'result':'PENDING','reason':'account_id_required','mutations':False}
    identity=client('sts').get_caller_identity()
    if identity.get('Arn','').endswith(':root'): raise ValueError('Root identity is forbidden; no other AWS checks performed')
    if identity['Account']!=c['accountId']: raise ValueError('Wrong AWS account; no other AWS checks performed')
    report={'result':'READ_ONLY_CHECKS','accountMatches':True,'callerArnHash':fingerprint(identity['Arn']),'region':'eu-west-1','mutations':False,'checks':{},'pendingInputs':pending}
    def attempt(name,operation):
        try: report['checks'][name]=operation()
        except Exception as error:
            code=getattr(error,'response',{}).get('Error',{}).get('Code','read_failed')
            report['checks'][name]={'status':'BLOCKED','code':code if re.fullmatch(r'[A-Za-z0-9_.-]{1,80}',str(code)) else 'read_failed'}
    def concurrency():
        limits=client('lambda').get_account_settings()['AccountLimit']
        unreserved=limits.get('UnreservedConcurrentExecutions')
        room=type(unreserved) is int and unreserved>=103
        return {'status':'PASS' if room else 'REVIEW_REQUIRED','accountConcurrentLimit':limits.get('ConcurrentExecutions'),
            'unreservedConcurrentExecutions':unreserved,'roomForThreeAdditionalReservations':room,
            'note':'Fresh stack needs three reserved executions while leaving AWS minimum 100 unreserved. Existing stack reservations require separate review.'}
    attempt('lambdaConcurrency',concurrency)
    if not any(k in pending for k in ['linkedWabaId','metaWabaId','socialPhoneArn','metaPhoneNumberId']):
        def social():
            account=client('socialmessaging').get_linked_whats_app_business_account(id=c['linkedWabaId'])['account']
            phone=client('socialmessaging').get_linked_whats_app_business_account_phone_number(id=c['socialPhoneArn'])
            matches=(str(account['wabaId'])==c['metaWabaId'] and phone['linkedWhatsAppBusinessAccountId']==c['linkedWabaId']
                and phone['phoneNumber']['arn']==c['socialPhoneArn'] and str(phone['phoneNumber']['metaPhoneNumberId'])==c['metaPhoneNumberId'])
            return {'status':'PASS' if matches else 'MISMATCH','identityMatches':matches,'linkedWabaHash':fingerprint(c['linkedWabaId']),
                    'eventDestinationCount':len(account.get('eventDestinations',[]))}
        attempt('demoSocialIdentity',social)
    models=[]
    for profile in ['eu.amazon.nova-lite-v1:0','eu.amazon.nova-micro-v1:0']:
        def bedrock(profile=profile):
            p=client('bedrock').get_inference_profile(inferenceProfileIdentifier=profile)
            arns=[m['modelArn'] for m in p['models']]
            if not arns or any(not re.fullmatch(r'arn:aws:bedrock:eu-[a-z]+-[0-9]+::foundation-model/amazon\.nova-(?:lite|micro)-v1:0',arn) for arn in arns):
                raise ValueError('Unexpected non-EU/non-demo model destination')
            models.extend(arns)
            return {'status':'PASS' if p['status']=='ACTIVE' else 'INACTIVE','profileId':profile,'profileArn':p['inferenceProfileArn'],'destinationModelArns':arns,
                    'inferenceExecuted':False}
        attempt('bedrock:'+profile,bedrock)
    if 'secretArn' not in pending:
        def secret():
            result=client('secretsmanager').describe_secret(SecretId=c['secretArn'])
            return {'status':'PASS' if result['ARN']==c['secretArn'] and not result.get('DeletedDate') else 'MISMATCH','valueRead':False,'customerManagedKms':bool(result.get('KmsKeyId'))}
        attempt('stageSecretMetadata',secret)
    if 'deployerPrincipalArn' not in pending:
        def simulate():
            result=client('iam').simulate_principal_policy(PolicySourceArn=c['deployerPrincipalArn'],ActionNames=['cloudformation:CreateChangeSet','cloudformation:ExecuteChangeSet',
                'lambda:CreateFunction','iam:CreateRole','sns:CreateTopic','sqs:CreateQueue','dynamodb:CreateTable','secretsmanager:CreateSecret','s3:PutObject'],ResourceArns=['*'])
            return {'status':'INDICATIVE_ONLY','decisions':[{ 'action':r['EvalActionName'],'decision':r['EvalDecision'],'missingContext':r.get('MissingContextValues',[]) } for r in result['EvaluationResults']],
                    'limits':'Not an authorization proof: resource policies, SCPs, boundaries and actual ARNs/context require review. Wildcard-resource simulation can understate scoped permissions.'}
        attempt('deployerSimulation',simulate)
    report['realConversationReady']=False
    report['notVerified']=['Actual model inference/send permissions of future runtime identities','Secret contents, matching Core key file and DNS/TLS route','Provisioned runtime policies/event routing','Billing restrictions and external Meta approval','User authorization to mutate/deploy/send/publish']
    return report

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--inputs',type=Path,required=True);args=p.parse_args();config=json.loads(args.inputs.read_text());c,pending=validate(config)
    if any(k in pending for k in ['accountId','profile']): print(json.dumps({'result':'PENDING','reason':'account_id_and_explicit_nonroot_profile_required','mutations':False}));return
    import boto3
    from botocore.config import Config
    session=boto3.Session(profile_name=c['profile'],region_name='eu-west-1')
    # Explicit profile only; no application .env or production credential discovery.
    clients={}
    def client(service):
        if service not in clients:clients[service]=session.client(service,region_name='eu-west-1',config=Config(connect_timeout=3,read_timeout=10,retries={'max_attempts':1}))
        return clients[service]
    result=check(c,client);print(json.dumps(result,indent=2))
if __name__=='__main__':main()
