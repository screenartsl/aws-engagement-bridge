#!/usr/bin/env python3
"""Offline proposals only: one-statement SAM IAM fix and native CFN alternative.

Never invokes AWS CLI or an AWS API. Native conversion requires the pinned local
aws-sam-translator; run under a network namespace as in the review runbook.
"""
import argparse
import copy
import difflib
import hashlib
import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
TRANSFORM_ARN = 'arn:aws:cloudformation:eu-west-1:aws:transform/Serverless-2016-10-31'
ADDITION = dict(Sid='AllowAwsSamTransformInIreland', Effect='Allow',
                Action=['cloudformation:CreateChangeSet'], Resource=TRANSFORM_ARN)


class Dumper(yaml.SafeDumper):
    def ignore_aliases(self, data):
        return True


def digest(value):
    return hashlib.sha256(value).hexdigest()


def pretty(value):
    return json.dumps(value, indent=2, ensure_ascii=False)+'\n'


def build():
    runtime_path = ROOT/'template.yaml'
    runtime_text = runtime_path.read_text()
    assert digest(runtime_text.encode()) == '88161733676dae8d788eff092cf75c6430c5ca28860e7f7048804e5ce3f694ac'
    iam_path = ROOT/'deploy/runtime-iam/template.yaml'
    assert digest(iam_path.read_bytes()) == 'b2a87ec5d7d205b69250596103427fb55b8896b7f5997507b28b489b18feac3a'
    before = yaml.safe_load(iam_path.read_text())
    after = copy.deepcopy(before)
    old_policies = before['Resources']['StageCloudFormationRole']['Properties']['Policies']
    new_policies = after['Resources']['StageCloudFormationRole']['Properties']['Policies']
    assert [p['PolicyName'] for p in old_policies] == ['bridge-stage-scoped', 'bridge-runtime-receive-provisioning']
    new_policies[1]['PolicyDocument']['Statement'].append(copy.deepcopy(ADDITION))
    reverse = copy.deepcopy(after)
    assert reverse['Resources']['StageCloudFormationRole']['Properties']['Policies'][1]['PolicyDocument']['Statement'].pop() == ADDITION
    assert reverse == before, 'IAM change exceeds one appended statement'
    fixed_iam = yaml.dump(after, Dumper=Dumper, sort_keys=False, width=110)
    iam_name = 'sam-transform-iam-review-'+digest(fixed_iam.encode())[:12]
    iam_request = dict(StackName='muserelay-aws-bridge-iam-foundation', ChangeSetName=iam_name,
                       ChangeSetType='UPDATE', Capabilities=['CAPABILITY_NAMED_IAM'],
                       TemplateBody=fixed_iam,
                       Parameters=[dict(ParameterKey='GrantSocialSend', ParameterValue='false')],
                       Description='PROPOSAL ONLY. Modify only executor inline policy by one exact AWS SAM transform permission. No roles added.')
    request = json.loads((ROOT/'deploy/runtime-iam/change-set.receive-runtime.prepared.json').read_text())
    assert request['TemplateBody'] == runtime_text
    params = {p['ParameterKey']: p['ParameterValue'] for p in request['Parameters']}
    assert params['RuntimeArtifactKey'] == 'releases/82707086af6e0597d741c3e44e7ce843c074a326/bridge.zip'
    assert params['DeployProcessing'] == 'true'
    for name in ['DeployOutbound', 'ProcessingEnabled', 'ReceiveEnabled', 'SendEnabled', 'LiveSend']:
        assert params[name] == 'false'
    os.environ.setdefault('AWS_DEFAULT_REGION', 'eu-west-1')
    os.environ['AWS_EC2_METADATA_DISABLED'] = 'true'
    from samtranslator.translator.transform import transform
    from samtranslator.yaml_helper import yaml_parse
    assert importlib.metadata.version('aws-sam-translator') == '1.115.0'

    class OfflinePolicies:
        def load(self):
            return {}

    source = yaml_parse(runtime_text)
    native = transform(copy.deepcopy(source), params, OfflinePolicies())
    assert not any(k in native for k in ['Transform', 'Globals'])
    assert not any(r['Type'].startswith(('AWS::IAM::', 'AWS::Serverless::')) for r in native['Resources'].values())
    native_text = yaml.dump(native, Dumper=Dumper, sort_keys=False, width=110)
    assert len(native_text.encode()) <= 51200
    assert yaml.safe_load(native_text) == native, 'Native YAML serialization changed the processed model'
    native_name = 'receive-native-review-'+digest(native_text.encode())[:12]+'-82707086'
    native_request = copy.deepcopy(request)
    native_request.pop('Capabilities', None)
    native_request.pop('ResourceTypes', None)
    native_request.update(ChangeSetName=native_name, TemplateBody=native_text,
                          Description='PROPOSAL ONLY. Native CloudFormation, no transform/IAM changes. Existing ZIP; five receive resources with consumer disabled.')
    sam_request = copy.deepcopy(request)
    sam_request.pop('Capabilities', None)  # AUTO_EXPAND has no effect for CreateChangeSet.
    sam_request.update(ChangeSetName='receive-sam-review-88161733676d-82707086-fix1',
                       Description='PROPOSAL ONLY. Requires separately approved exact SAM transform IAM patch first. No execution authorized.')
    spec = importlib.util.spec_from_file_location('runtime_review', ROOT/'scripts/cloudformation/validate.py')
    review = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(review)
    values = dict(params, **{'AWS::AccountId':'365470560685', 'AWS::Region':'eu-west-1', 'AWS::StackName':'muserelay-aws-bridge-stage'})
    assert review.rules(native, values)
    selected = {k:v for k,v in native['Resources'].items() if 'Condition' not in v or review.condition(v['Condition'],values,native['Conditions'])}
    ingress = yaml.safe_load((ROOT/'deploy/sns-bootstrap/template.yaml').read_text())['Resources']
    assert len(selected) == 17
    for name, value in ingress.items():
        assert selected[name] == value, name+' ingress changed'
    assert 'ReservedConcurrentExecutions' not in selected['InboundFunction']['Properties']
    mapping = selected['InboundFunctionInput']['Properties']
    assert mapping['ScalingConfig'] == {'MaximumConcurrency':2}
    assert mapping['Enabled'] == {'Fn::If':['RunInboundConsumer', True, False]}
    assert not review.condition('RunInboundConsumer', values, native['Conditions'])
    assert not review.condition('RunOutboundConsumer', values, native['Conditions'])
    before_json, after_json = pretty(old_policies), pretty(new_policies)
    diff = ''.join(difflib.unified_diff(before_json.splitlines(True),after_json.splitlines(True),
                                       fromfile='executor-policies.before.json',tofile='executor-policies.after.json'))
    report = dict(kind='OFFLINE_SAM_PERMISSION_AND_NATIVE_ALTERNATIVE_REVIEW', awsCalls=0,
                  attemptsAuthorized=False, executionAuthorized=False, iamChangeApplied=False,
                  sourceSamTemplateSha256=digest(runtime_text.encode()),
                  sourceIamTemplateSha256=digest(iam_path.read_bytes()),
                  samFix=dict(iamTemplateSha256=digest(fixed_iam.encode()), iamChangeSetName=iam_name,
                              retryChangeSetName=sam_request['ChangeSetName'],
                              addedStatements=[ADDITION], modifiedResources=['StageCloudFormationRole'],
                              addedResources=[], removedResources=[], replacement=False,
                              oldPoliciesPreserved=True, trustAndRecoveryAndRuntimeRolesUnchanged=True,
                              executionAndMessagingAndBedrockPermissionsAdded=False,
                              finalRuntimeProvisioningStatementCount=10, grantSocialSend=False),
                  nativeAlternative=dict(templateSha256=digest(native_text.encode()),
                              changeSetName=native_name, translatorVersion='1.115.0',
                              sameAsOfflineSamExpansion=True, selectedResources=17,
                              originalIngressResourcesIdentical=12, nativeResourceDefinitions=len(native['Resources']),
                              adds={k:v['Type'] for k,v in selected.items() if k not in ingress},
                              transforms=0, iamResources=0, extraIamPermissions=0,
                              inboundReservedConcurrency=None, sqsMaximumConcurrency=2,
                              consumerEnabled=False, outboundResourcesSelected=0,
                              artifactKey=params['RuntimeArtifactKey'], zipUploadNeeded=False),
                  limitations=['Source IAM baseline and failure supplied by owner; no direct AWS inspection',
                               'Offline compiler equality is not an actual AWS change set or effective permission certification',
                               'No new IAM change, retry, resource creation, consumer or Core activation authorized'])
    return {'deploy/runtime-sam-iam/template.yaml':fixed_iam,
            'deploy/runtime-sam-iam/policy.addition.json':pretty({'Version':'2012-10-17','Statement':[ADDITION]}),
            'deploy/runtime-sam-iam/executor-policies.before.json':before_json,
            'deploy/runtime-sam-iam/executor-policies.after.json':after_json,
            'deploy/runtime-sam-iam/executor-policies.diff':diff,
            'deploy/runtime-sam-iam/change-set.update.prepared.json':pretty(iam_request),
            'deploy/runtime-sam-iam/change-set.runtime-retry.prepared.json':pretty(sam_request),
            'deploy/runtime-native/template.yaml':native_text,
            'deploy/runtime-native/parameters.disabled.json':pretty(request['Parameters']),
            'deploy/runtime-native/change-set.update.prepared.json':pretty(native_request),
            'deploy/runtime-sam-iam/comparison.prepared.json':pretty(report)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    outputs = build()
    for name, content in outputs.items():
        path = ROOT/name
        if args.check:
            assert path.read_text() == content, 'Stale artifact: '+name
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
    report = json.loads(outputs['deploy/runtime-sam-iam/comparison.prepared.json'])
    print(pretty(dict(result='PASS_OFFLINE_ONLY', awsCalls=0, artifacts=len(outputs),
                     samIamTemplateSha256=report['samFix']['iamTemplateSha256'],
                     nativeTemplateSha256=report['nativeAlternative']['templateSha256'],
                     selectedResources=17, executionAuthorized=False)))


if __name__ == '__main__':
    main()
