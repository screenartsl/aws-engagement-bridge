#!/usr/bin/env python3
"""Read-only review of the pinned receive change set. No execution or message APIs.

Default: offline --evidence JSON file. --collect explicitly runs metadata-only AWS
CLI calls in the owner's CloudShell. Responses stay in memory; only checks and
nonsecret role/event identifiers are printed. No credentials or application reads.
"""
import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import json
from pathlib import Path
import subprocess

ACCOUNT='365470560685'
REGION='eu-west-1'
STACK='muserelay-aws-bridge-stage'
CHANGE='receive-sam-review-88161733676d-82707086-fix1'
ROLE=f'arn:aws:iam::{ACCOUNT}:role/{STACK}-cfn-execution'
ORIGINAL_RAW='88161733676dae8d788eff092cf75c6430c5ca28860e7f7048804e5ce3f694ac'
ORIGINAL_JSON='9f577ef2768f27a27fc6a830b10b325d8d3b64e22edbed49990707e76e0baed5'
PARAMETERS='920de3f9a5e7496fac425c0fb53a482ade2c079ce5508710ea602d758bb03c85'
POLICIES={
 'bridge-stage-scoped':'7ea557e2f76a54b530a2f88cb95a531ec8b795687b690ca5c6c220e8195fefd7',
 'bridge-runtime-receive-provisioning':'4c42c376f5fb3948407011049d5edcce6cd7dc7493dec7a59bbaca5c103f315b',
}
CANDIDATE_RUNTIME_POLICY_HASH='e8171cc0863ffac6acb1a3da30493691635ee78394d61e8beb13e17b72df6f50'
PROVIDER_HANDLER_HASHES={
 'AWS::DynamoDB::Table':'8dda74c4e4085a8f60ade7286b40b9319bbadeb6d2a6d3cc6632de05f2d97dbb',
 'AWS::Lambda::Function':'e6d14db162456af7923fe8c63ff09a7f6f15c351a5d213686b5300979795759f',
 'AWS::Lambda::EventSourceMapping':'6f289312604fd202e7063a2ca589208be8e2e978bd482c522d27a9363c02c169',
 'AWS::Logs::LogGroup':'227cc88b8fccee68ef09cde36b5833def2d781e50a6c4cc431120efc49e22587',
 'AWS::SecretsManager::Secret':'7a511c938b1f09f86ad3ed05899c08a5790a3e3d651b8ae3490e63b7607ec659',
}
ADDS={'InboundFunctionInput':'AWS::Lambda::EventSourceMapping',
      'InboundFunction':'AWS::Lambda::Function','InboundLogs':'AWS::Logs::LogGroup',
      'SigningSecret':'AWS::SecretsManager::Secret','State':'AWS::DynamoDB::Table'}


def digest(value):
    return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()


def body(response):
    value=response['TemplateBody']
    if isinstance(value,str): value=json.loads(value)
    if not isinstance(value,dict): raise ValueError('TemplateBody is not JSON')
    return value


def original_matches(response):
    value=response.get('TemplateBody')
    if isinstance(value,str) and hashlib.sha256(value.encode()).hexdigest()==ORIGINAL_RAW:
        return True
    try:return digest(body(response))==ORIGINAL_JSON
    except (KeyError,ValueError,TypeError):return False


def provider_prerequisites(e):
    """Known feature dependency, separate from equality with reviewed policy.

    This is not an exhaustive provider IAM simulation. Never turn matching
    prepared policy hashes into evidence that optional-feature APIs are allowed.
    """
    t=body(e['processed'])
    p=t['Resources']['State']['Properties']
    enabled=p.get('PointInTimeRecoverySpecification',{}).get('PointInTimeRecoveryEnabled')
    if enabled is not True:return {'PITR: configuración reconocida para revisar permisos':False}
    needed={'dynamodb:DescribeContinuousBackups','dynamodb:UpdateContinuousBackups'}
    allowed=set()
    for response in e['policies'].values():
        for s in response['PolicyDocument']['Statement']:
            if (s.get('Effect')=='Allow'
                and s.get('Resource')==f'arn:aws:dynamodb:{REGION}:{ACCOUNT}:table/{STACK}-state'
                and s.get('Condition',{}).get('StringEquals',{}).get('aws:RequestedRegion')==REGION):
                a=s.get('Action',[])
                allowed.update(a if isinstance(a,list) else [a])
    return {'PITR: permisos Describe/UpdateContinuousBackups explícitos sobre tabla propia':needed<=allowed}


def resolve(value,template,params):
    if isinstance(value,list):return [resolve(x,template,params) for x in value]
    if not isinstance(value,dict):return value
    if set(value)=={'Ref'}:
        name=value['Ref']
        return params.get(name,'@resource:'+name)
    if set(value)=={'Condition'}:return resolve(template['Conditions'][value['Condition']],template,params)
    if set(value)=={'Fn::If'}:
        name,yes,no=value['Fn::If']
        condition=resolve(template['Conditions'][name],template,params)
        if not isinstance(condition,bool):raise ValueError('Unresolved condition')
        return resolve(yes if condition else no,template,params)
    if set(value)=={'Fn::Equals'}:
        a,b=resolve(value['Fn::Equals'],template,params);return a==b
    if set(value)=={'Fn::And'}:return all(resolve(x,template,params) for x in value['Fn::And'])
    if set(value)=={'Fn::Or'}:return any(resolve(x,template,params) for x in value['Fn::Or'])
    if set(value)=={'Fn::Not'}:return not resolve(value['Fn::Not'][0],template,params)
    return {k:resolve(v,template,params) for k,v in value.items()}


def verify(e):
    checks={}
    c=e['change']; stack=e['stack']['Stacks'][0]
    prefix=f'arn:aws:cloudformation:{REGION}:{ACCOUNT}:'
    checks['Cuenta, región, pila y conjunto exactos']=(
        e['caller']['Account']==ACCOUNT and c.get('StackName')==STACK
        and c.get('ChangeSetName')==CHANGE and c.get('StackId')==stack.get('StackId')
        and c.get('StackId','').startswith(prefix+'stack/'+STACK+'/')
        and c.get('ChangeSetId','').startswith(prefix+'changeSet/'+CHANGE+'/'))
    checks['Conjunto disponible y pila estable']=(c.get('Status')=='CREATE_COMPLETE'
        and c.get('ExecutionStatus')=='AVAILABLE'
        and stack.get('StackStatus') in ['CREATE_COMPLETE','UPDATE_COMPLETE']
        and not c.get('NextToken') and not c.get('IncludeNestedStacks'))
    changes=c.get('Changes',[])
    checks['Exactamente cinco Add previstos, sin otros cambios']=(len(changes)==5
        and {v['ResourceChange']['LogicalResourceId']:v['ResourceChange']['ResourceType'] for v in changes}==ADDS
        and all(v['ResourceChange']['Action']=='Add' and v['ResourceChange'].get('Replacement') in [None,'False',False] for v in changes))
    parameters=c.get('Parameters',[])
    params={p['ParameterKey']:p.get('ParameterValue') for p in parameters}
    checks['Todos los parámetros efectivos coinciden con revisión cerrada']=(len(params)==len(parameters) and digest(params)==PARAMETERS)
    checks['Original íntegro coincide con 881617... revisado']=original_matches(e['original'])
    checks['Rol actual de pila es el ejecutor limitado previsto']=stack.get('RoleARN')==ROLE
    events=e.get('creationEvents',[])
    matched=[]
    for event in events:
        request={k.lower():v for k,v in event.get('requestParameters',{}).items()}
        if (event.get('eventName')=='CreateChangeSet' and event.get('awsRegion')==REGION
            and request.get('changeSetName'.lower())==CHANGE
            and request.get('stackname') in [STACK,c.get('StackId')]
            and not event.get('errorCode')):
            matched.append(request)
    checks['CreateChangeSet conserva el rol previsto o hereda el de la pila']=(
        len(matched)==1 and matched[0].get('rolearn') in [None,'',ROLE]
        and stack.get('RoleARN')==ROLE)
    role=e['role']['Role']
    trust=role.get('AssumeRolePolicyDocument',{}).get('Statement',[])
    checks['Confianza exclusiva CloudFormation; sin boundary nuevo']=(role.get('Arn')==ROLE
        and len(trust)==1 and trust[0].get('Effect')=='Allow'
        and trust[0].get('Principal')=={'Service':'cloudformation.amazonaws.com'}
        and trust[0].get('Action') in ['sts:AssumeRole',['sts:AssumeRole']]
        and 'PermissionsBoundary' not in role)
    checks['Sin managed policies adjuntas ni inline adicionales']=(
        e['attached'].get('AttachedPolicies')==[] and not e['attached'].get('IsTruncated')
        and set(e['inline'].get('PolicyNames',[]))==set(POLICIES) and not e['inline'].get('IsTruncated'))
    checks['Ambas políticas inline íntegras coinciden; KMS Deny preservado']=all(
        digest(e['policies'][name]['PolicyDocument']) in (
            [expected,CANDIDATE_RUNTIME_POLICY_HASH] if name=='bridge-runtime-receive-provisioning' else [expected])
        for name,expected in POLICIES.items())
    t=body(e['processed'])
    f=t['Resources']['InboundFunction']['Properties']
    m=t['Resources']['InboundFunctionInput']['Properties']
    resolved=resolve(m,t,params)
    checks['Mapping efectivamente apagado, lote 1 y MaximumConcurrency=2']=(
        resolved.get('Enabled') is False and resolved.get('BatchSize')==1
        and resolved.get('ScalingConfig',{}).get('MaximumConcurrency')==2)
    checks['Lambda sin concurrencia reservada y rol inbound propio']=(
        'ReservedConcurrentExecutions' not in f
        and resolve(f.get('Role'),t,params)==f'arn:aws:iam::{ACCOUNT}:role/{STACK}-inbound-runtime')
    env=resolve(f['Environment']['Variables'],t,params)
    checks['Runtime/recepción/envío/LiveSend false en Lambda']=all(env.get(k)=='false' for k in [
        'BRIDGE_RUNTIME_ENABLED','BRIDGE_RECEIVE_ENABLED','BRIDGE_SEND_ENABLED','BRIDGE_LIVE_SEND'])
    bindings=json.loads(env['BRIDGE_BINDINGS_JSON'])
    checks['Binding apagado, destinatarios vacíos, outbound no seleccionado']=(
        len(bindings)==1 and bindings[0].get('enabled') is False
        and bindings[0].get('allowedRecipients')==[] and params.get('DeployOutbound')=='false')
    selected={k:v['Type'] for k,v in t['Resources'].items() if not v.get('Condition')
        or resolve(t['Conditions'][v['Condition']],t,params) is True}
    checks['SAM selecciona 17 recursos; solo una Lambda, sin IAM/API nuevos']=(len(selected)==17
        and [k for k,v in selected.items() if v=='AWS::Lambda::Function']==['InboundFunction']
        and not any(v.startswith(('AWS::IAM::','AWS::ApiGateway','Custom::'))
                    or v in ['AWS::Lambda::Url','AWS::CloudFormation::Stack','AWS::CloudFormation::CustomResource'] for v in selected.values()))
    return checks


def collect():
    def aws(service,*args):
        result=subprocess.run(['aws',service,*args,'--region',REGION,'--output','json','--no-cli-pager'],
            capture_output=True,text=True,timeout=45)
        if result.returncode:raise ValueError('Lectura AWS fallida: '+service+' '+args[0]+'; revisar en consola, sin cambiar permisos')
        return json.loads(result.stdout)
    e={'caller':aws('sts','get-caller-identity')}
    if e['caller']['Account']!=ACCOUNT:raise ValueError('Cuenta incorrecta; no se continúa')
    e['change']=aws('cloudformation','describe-change-set','--stack-name',STACK,'--change-set-name',CHANGE)
    e['stack']=aws('cloudformation','describe-stacks','--stack-name',STACK)
    for stage,key in [('Original','original'),('Processed','processed')]:
        e[key]=aws('cloudformation','get-template','--stack-name',STACK,'--change-set-name',CHANGE,'--template-stage',stage)
    role_name=STACK+'-cfn-execution'
    e['role']=aws('iam','get-role','--role-name',role_name)
    e['attached']=aws('iam','list-attached-role-policies','--role-name',role_name)
    e['inline']=aws('iam','list-role-policies','--role-name',role_name)
    e['policies']={n:aws('iam','get-role-policy','--role-name',role_name,'--policy-name',n) for n in POLICIES}
    created=datetime.fromisoformat(e['change']['CreationTime'].replace('Z','+00:00'))
    start=(created-timedelta(minutes=10)).astimezone(timezone.utc).isoformat()
    end=min(created+timedelta(minutes=10),datetime.now(timezone.utc)).astimezone(timezone.utc).isoformat()
    token=None; events=[]
    for _ in range(4):
        args=['lookup-events','--lookup-attributes','AttributeKey=EventName,AttributeValue=CreateChangeSet',
            '--start-time',start,'--end-time',end,'--max-results','50','--no-paginate']
        if token:args+=['--next-token',token]
        page=aws('cloudtrail',*args)
        for row in page.get('Events',[]):
            event=json.loads(row['CloudTrailEvent'])
            request=event.get('requestParameters') or {}
            if any(k.lower()=='changesetname' and v==CHANGE for k,v in request.items()):events.append(event)
        token=page.get('NextToken')
        if not token:break
    # A partial search cannot establish unique creation evidence.
    e['creationEvents']=events if not token else []
    return e


def collect_provider_permissions():
    """Six metadata-only CLI reads; returns provider handler declarations only."""
    def aws(service,*args):
        result=subprocess.run(['aws',service,*args,'--region',REGION,'--output','json','--no-cli-pager'],
            capture_output=True,text=True,timeout=45)
        if result.returncode:raise ValueError('Provider metadata read failed')
        return json.loads(result.stdout)
    caller=aws('sts','get-caller-identity')
    if caller['Account']!=ACCOUNT:raise ValueError('Wrong account')
    result={'account':ACCOUNT,'region':REGION,'scope':'Provider schema metadata only; not an authorization or permission simulation','providers':{}}
    for typ in ['AWS::DynamoDB::Table','AWS::Lambda::Function','AWS::Lambda::EventSourceMapping','AWS::Logs::LogGroup','AWS::SecretsManager::Secret']:
        response=aws('cloudformation','describe-type','--type','RESOURCE','--type-name',typ)
        schema=json.loads(response['Schema'])
        result['providers'][typ]={'defaultVersionId':response.get('DefaultVersionId'),
            'handlers':{k:schema.get('handlers',{}).get(k,{}).get('permissions',[]) for k in ['create','read','delete']},
            'tagging':schema.get('tagging',{})}
    return result


def provider_summary(report):
    rows=[]
    for typ,expected in PROVIDER_HANDLER_HASHES.items():
        p=report['providers'][typ]
        fingerprint=digest({k:sorted(p['handlers'][k]) for k in ['create','read','delete']})
        rows.append({'type':typ,'version':p.get('defaultVersionId'),
            'matchesReviewedHandlers':fingerprint==expected,'fingerprint':fingerprint})
    mapping=report['providers']['AWS::Lambda::EventSourceMapping'].get('tagging',{})
    system_tags=(mapping.get('cloudFormationSystemTags') is True and mapping.get('tagOnCreate') is True)
    return {'scope':'Provider handler declarations only; no effective-permission simulation or deployment approval',
        'allMatch':all(r['matchesReviewedHandlers'] for r in rows) and system_tags,
        'mappingSystemTagsOnCreate':system_tags,'providers':rows}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    group=p.add_mutually_exclusive_group(required=True)
    group.add_argument('--collect',action='store_true')
    group.add_argument('--evidence',type=Path)
    group.add_argument('--provider-permissions',action='store_true')
    group.add_argument('--provider-summary',action='store_true')
    args=p.parse_args()
    try:
        if args.provider_permissions or args.provider_summary:
            report=collect_provider_permissions()
            print(json.dumps(provider_summary(report) if args.provider_summary else report,indent=2))
            return 0
        e=collect() if args.collect else json.loads(args.evidence.read_text())
        checks=verify(e)
        checks.update(provider_prerequisites(e))
    except (ValueError,KeyError,TypeError,IndexError,OSError,subprocess.TimeoutExpired):
        print('REVISAR: lectura incompleta, formato no reconocido o respuesta inesperada; no ejecutar. No se muestran cuerpos ni errores privados.')
        return 1
    for label,ok in checks.items(): print(('PASS' if ok else 'REVISAR')+' — '+label)
    print('Rol esperado: '+ROLE)
    print('RESULTADO: '+('PASS_REVISION_RECEIVE_METADATA' if all(checks.values()) else 'PENDIENTE_NO_EJECUTAR'))
    print('Solo lecturas. Falta autorización expresa y revisión de coste antes de ejecutar runtime; no autoriza consumidores ni mensajes.')
    return 0 if all(checks.values()) else 1


if __name__=='__main__':raise SystemExit(main())
