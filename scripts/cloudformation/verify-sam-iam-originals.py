#!/usr/bin/env python3
"""Verify three downloaded CloudFormation JSON responses locally.

Python standard library only. No AWS/API/network calls, credentials, application
bootstrap, writes or execution commands. Matching whole-template hashes connect
the real Original bodies to the previously reviewed one-statement IAM delta.
"""
import argparse
import hashlib
import json
from pathlib import Path

STACK = 'muserelay-aws-bridge-iam-foundation'
CHANGE_SET = 'sam-transform-iam-review-d60476a283b7'
ARN_PREFIX = 'arn:aws:cloudformation:eu-west-1:365470560685:'
ROLE_NAME = 'muserelay-aws-bridge-stage-cfn-execution'
HASHES = {
    'current': ('b2a87ec5d7d205b69250596103427fb55b8896b7f5997507b28b489b18feac3a',
                'e045834df49d6d31026eae3a6d753bc85edbd3e61f1facb43b876a50fe031e60'),
    'proposed': ('d60476a283b76d3dcc2babdd689bee81b1888e834364c90067621cc7ef4531cc',
                 '2c7ae874f68bf674017382466647d6fc8920e7778d801503177a6a3288a37a8c'),
}


def template_matches(response, which):
    if not isinstance(response, dict) or 'TemplateBody' not in response:
        return False
    body = response['TemplateBody']
    raw_hash, canonical_hash = HASHES[which]
    if isinstance(body, str):
        if hashlib.sha256(body.encode()).hexdigest() == raw_hash:
            return True
        try:
            body = json.loads(body)
        except ValueError:
            return False  # Unknown YAML formatting requires review, never acceptance.
    if not isinstance(body, dict):
        return False
    canonical = json.dumps(body, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()
    return hashlib.sha256(canonical).hexdigest() == canonical_hash


def verify(current, proposed, metadata):
    checks = {
        'Original actual coincide con baseline revisado': template_matches(current, 'current'),
        'Original propuesta coincide con corrección revisada': template_matches(proposed, 'proposed'),
    }
    if not isinstance(metadata, dict):
        checks['Metadatos completos del conjunto'] = False
        return checks
    checks['Cuenta, región, pila y conjunto exactos'] = (
        metadata.get('StackName') == STACK and metadata.get('ChangeSetName') == CHANGE_SET
        and isinstance(metadata.get('StackId'), str)
        and metadata['StackId'].startswith(ARN_PREFIX+'stack/'+STACK+'/')
        and isinstance(metadata.get('ChangeSetId'), str)
        and metadata['ChangeSetId'].startswith(ARN_PREFIX+'changeSet/'+CHANGE_SET+'/'))
    checks['CREATE_COMPLETE / AVAILABLE'] = (
        metadata.get('Status') == 'CREATE_COMPLETE' and metadata.get('ExecutionStatus') == 'AVAILABLE')
    changes = metadata.get('Changes')
    only = changes[0].get('ResourceChange') if isinstance(changes,list) and len(changes)==1 and isinstance(changes[0],dict) else None
    checks['Único Modify StageCloudFormationRole; Replacement=False'] = (
        isinstance(only,dict) and only.get('LogicalResourceId')=='StageCloudFormationRole'
        and only.get('Action')=='Modify' and only.get('ResourceType')=='AWS::IAM::Role'
        and (only.get('Replacement')=='False' or only.get('Replacement') is False)
        and only.get('PhysicalResourceId')==ROLE_NAME
        and not metadata.get('NextToken'))
    checks['Scope limitado a Properties'] = (
        isinstance(only,dict) and isinstance(only.get('Scope'),list)
        and only['Scope'] and set(only['Scope'])=={'Properties'}) is True
    parameters = metadata.get('Parameters')
    checks['GrantSocialSend=false en el conjunto real'] = (
        isinstance(parameters,list) and len(parameters)==1
        and isinstance(parameters[0],dict)
        and parameters[0].get('ParameterKey')=='GrantSocialSend'
        and parameters[0].get('ParameterValue')=='false')
    # DescribeChangeSet does not expose RoleARN. Absence only validates the
    # response shape; it cannot prove the stack has no service role.
    checks['DescribeChangeSet sin RoleARN ajeno al esquema documentado'] = metadata.get('RoleARN') in (None, '')
    return checks


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--current',type=Path,default=Path('sam-iam-current.original.json'))
    parser.add_argument('--proposed',type=Path,default=Path('sam-iam-proposed.original.json'))
    parser.add_argument('--metadata',type=Path,default=Path('sam-iam-review.metadata.json'))
    args = parser.parse_args()
    try:
        data = [json.loads(p.read_text()) for p in [args.current,args.proposed,args.metadata]]
    except (OSError,ValueError):
        print('REVISAR: falta un archivo o su JSON está incompleto; no ejecutar cambios.')
        return 1
    checks = verify(*data)
    for label,ok in checks.items():
        print(('PASS' if ok else 'REVISAR')+' — '+label)
    print('NOTA: DescribeChangeSet no acredita el rol de ejecución. Verificar DescribeStacks y el RoleARN de la solicitud en CloudTrail por separado.')
    if not all(checks.values()):
        print('VERIFICACIÓN PENDIENTE. Puede requerir revisar contenido/formato o metadatos; no ejecutar.')
        return 1
    print('RESULTADO: PASS_VERIFICACION_LOCAL_DE_ORIGINALS_Y_CONJUNTO')
    print('ChangeSetId: '+data[2]['ChangeSetId'])
    print('No se ha ejecutado nada; pendiente autorización expresa del propietario.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
