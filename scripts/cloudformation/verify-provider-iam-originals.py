#!/usr/bin/env python3
"""Offline verification of four downloaded CloudFormation JSON responses.

Standard library only. Default is offline; --collect explicitly invokes fixed
metadata-only AWS CLI reads in CloudShell. No writes or execution APIs, exports
of credentials or application reads. Whole-template digests bind actual Originals
to the reviewed 14-grant delta. No response bodies are printed or saved.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess

STACK='muserelay-aws-bridge-iam-foundation'
CHANGE='runtime-provider-iam-review-cb4d602f2d90'
PREFIX='arn:aws:cloudformation:eu-west-1:365470560685:'
ROLE_NAME='muserelay-aws-bridge-stage-cfn-execution'
HASHES={
 'current':('d60476a283b76d3dcc2babdd689bee81b1888e834364c90067621cc7ef4531cc',
            '2c7ae874f68bf674017382466647d6fc8920e7778d801503177a6a3288a37a8c'),
 'proposed':('cb4d602f2d9067c4271c6789fd2c1ce8716d28aadae2caa6421aa81d55565e98',
             'a95ae086428ba41721b03ba1ba5d7ea0d9136f84a42181f309169784b76d1b9f'),
}


def matches(response,which):
    raw=response.get('TemplateBody') if isinstance(response,dict) else None
    if isinstance(raw,str):
        if hashlib.sha256(raw.encode()).hexdigest()==HASHES[which][0]:return True
        try:raw=json.loads(raw)
        except ValueError:return False
    if not isinstance(raw,dict):return False
    encoded=json.dumps(raw,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
    return hashlib.sha256(encoded).hexdigest()==HASHES[which][1]


def false_send(parameters):
    return (isinstance(parameters,list) and len(parameters)==1 and isinstance(parameters[0],dict)
        and parameters[0].get('ParameterKey')=='GrantSocialSend'
        and parameters[0].get('ParameterValue')=='false')


def verify(current,proposed,change,stack_response):
    checks={'Original actual íntegro coincide con baseline SAM desplegado':matches(current,'current'),
        'Original propuesta íntegra coincide con las 14 concesiones revisadas':matches(proposed,'proposed')}
    if not isinstance(change,dict) or not isinstance(stack_response,dict):
        checks['Metadatos completos']=False;return checks
    stacks=stack_response.get('Stacks',[])
    stack=stacks[0] if isinstance(stacks,list) and len(stacks)==1 and isinstance(stacks[0],dict) else {}
    checks['Cuenta, región, pila y conjunto exactos']=(change.get('StackName')==STACK
        and change.get('ChangeSetName')==CHANGE and stack.get('StackName')==STACK
        and isinstance(change.get('StackId'),str)
        and change['StackId'].startswith(PREFIX+'stack/'+STACK+'/')
        and change['StackId']==stack.get('StackId')
        and isinstance(change.get('ChangeSetId'),str)
        and change['ChangeSetId'].startswith(PREFIX+'changeSet/'+CHANGE+'/'))
    checks['CREATE_COMPLETE / AVAILABLE y pila UPDATE_COMPLETE']=(change.get('Status')=='CREATE_COMPLETE'
        and change.get('ExecutionStatus')=='AVAILABLE' and stack.get('StackStatus')=='UPDATE_COMPLETE')
    changes=change.get('Changes',[])
    r=changes[0].get('ResourceChange',{}) if isinstance(changes,list) and len(changes)==1 and isinstance(changes[0],dict) else {}
    checks['Único Modify StageCloudFormationRole; Replacement=False']=(
        r.get('Action')=='Modify' and r.get('LogicalResourceId')=='StageCloudFormationRole'
        and r.get('ResourceType')=='AWS::IAM::Role' and r.get('PhysicalResourceId')==ROLE_NAME
        and (r.get('Replacement')=='False' or r.get('Replacement') is False)
        and r.get('Scope')==['Properties'] and not change.get('NextToken'))
    checks['GrantSocialSend=false actual y propuesto']=(false_send(stack.get('Parameters'))
        and false_send(change.get('Parameters')))
    checks['DescribeStacks confirma ausencia de rol asociado actual en Foundation']=(
        bool(stack) and stack.get('RoleARN') in [None,''])
    return checks


def collect():
    """Five fixed metadata-only reads in the owner's authenticated CloudShell."""
    def aws(service,*args):
        result=subprocess.run(['aws',service,*args,'--region','eu-west-1','--output','json','--no-cli-pager'],
            capture_output=True,text=True,timeout=45)
        if result.returncode:raise ValueError('Metadata read failed')
        return json.loads(result.stdout)
    identity=aws('sts','get-caller-identity')
    if identity.get('Account')!='365470560685' or identity.get('Arn','').endswith(':root'):
        raise ValueError('Wrong account or root identity')
    stack=aws('cloudformation','describe-stacks','--stack-name',STACK)
    current=aws('cloudformation','get-template','--stack-name',STACK,'--template-stage','Original')
    proposed=aws('cloudformation','get-template','--stack-name',STACK,'--change-set-name',CHANGE,'--template-stage','Original')
    change=aws('cloudformation','describe-change-set','--stack-name',STACK,'--change-set-name',CHANGE)
    return current,proposed,change,stack


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--collect',action='store_true',help='Explicit metadata-only AWS CLI reads; default is offline files')
    for name,default in [('current','provider-iam-current.original.json'),
        ('proposed','provider-iam-proposed.original.json'),('change','provider-iam-change.json'),
        ('stack','provider-iam-stack.json')]:p.add_argument('--'+name,type=Path,default=Path(default))
    args=p.parse_args()
    try:
        values=collect() if args.collect else [json.loads(getattr(args,n).read_text()) for n in ['current','proposed','change','stack']]
        checks=verify(*values)
    except (OSError,ValueError,TypeError,KeyError,subprocess.TimeoutExpired):
        print('REVISAR: archivos ausentes/incompletos o formato inesperado; no ejecutar.');return 1
    for label,ok in checks.items():print(('PASS' if ok else 'REVISAR')+' — '+label)
    print('NOTA: DescribeChangeSet no devuelve RoleARN; la comprobación usa DescribeStacks y solo acredita el rol actual. La solicitud IAM revisada omite RoleARN; no añadir overrides.')
    if not all(checks.values()):
        print('RESULTADO: PENDIENTE_NO_EJECUTAR');return 1
    print('RESULTADO: PASS_PROVIDER_IAM_ORIGINALS')
    print('Delta íntegro: siete declaraciones / catorce concesiones; KMS Deny, roles, confianza y políticas previas preservados.')
    print('Excepciones revisadas: dos lecturas Logs regionales; GetSecretValue solo del secreto signing de staging con tags propios, para CloudFormation.')
    print('Sin cambios ni ejecución. Pendiente autorización expresa para este conjunto IAM exclusivamente.')
    return 0


if __name__=='__main__':raise SystemExit(main())
