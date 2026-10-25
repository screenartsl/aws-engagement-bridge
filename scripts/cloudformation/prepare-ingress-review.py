#!/usr/bin/env python3
"""Build review requests locally. Does not import an AWS SDK or invoke AWS CLI."""
import argparse
import hashlib
import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
ACCOUNT = '365470560685'
REGION = 'eu-west-1'
EXECUTION = f'arn:aws:iam::{ACCOUNT}:role/muserelay-aws-bridge-stage-cfn-execution'
RECOVERY = f'arn:aws:iam::{ACCOUNT}:role/muserelay-aws-bridge-kms-recovery'
TAGS = [dict(Key=k, Value=v) for k, v in [
    ('Company', 'ScreenArt'), ('Project', 'MuseRelay'),
    ('Component', 'aws-engagement-bridge'), ('Environment', 'staging'),
    ('Purpose', 'hackathon')]]


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build():
    ingress_path = ROOT/'deploy/sns-bootstrap/template.yaml'
    foundation_path = ROOT/'deploy/iam-foundation/template.yaml'
    ingress = yaml.safe_load(ingress_path.read_text())
    foundation = yaml.safe_load(foundation_path.read_text())
    parameters = [
        dict(ParameterKey='KmsAdministratorArn', ParameterValue=EXECUTION),
        dict(ParameterKey='KmsRecoveryAdministratorArn', ParameterValue=RECOVERY)]
    for parameter in parameters:
        assert ingress['Parameters'][parameter['ParameterKey']]['AllowedValues'] == [parameter['ParameterValue']]
    assert len(ingress['Resources']) == 12
    assert {r['Type'] for r in foundation['Resources'].values()} == {'AWS::IAM::Role'}
    assert ingress['Resources']['Subscription']['Properties']['RawMessageDelivery'] is False
    for name in ['SnsKey', 'ArtifactBucket']:
        assert ingress['Resources'][name]['DeletionPolicy'] == 'Retain'
    for template in [ingress_path, foundation_path]:
        assert len(template.read_bytes()) <= 51200, 'TemplateBody API limit exceeded'
    ingress_request = dict(
        StackName='muserelay-aws-bridge-stage',
        ChangeSetName='ingress-review-'+digest(ingress_path)[:12],
        ChangeSetType='CREATE', RoleARN=EXECUTION,
        Description='Review only: 12 ingress resources. No runtime, WABA association or sends. IAM metadata patch prerequisite.',
        TemplateBody=ingress_path.read_text(), Parameters=parameters, Tags=TAGS,
        ResourceTypes=sorted({r['Type'] for r in ingress['Resources'].values()}))
    foundation_request = dict(
        StackName='muserelay-aws-bridge-iam-foundation',
        ChangeSetName='ingress-metadata-review-'+digest(foundation_path)[:12],
        ChangeSetType='UPDATE', Capabilities=['CAPABILITY_NAMED_IAM'],
        Description='Review only: executor bucket metadata/tagging and SNS metadata permissions. Stable roles unchanged.',
        TemplateBody=foundation_path.read_text())
    manifest = dict(
        kind='LOCAL_REVIEW_PREPARATION_NOT_AWS_CHANGE_SET', account=ACCOUNT, region=REGION,
        awsCalls=0, executed=False, sourceEvidence='Foundation outputs and STS success supplied by user; no direct account access',
        roleArns=dict(CloudFormationExecutionRoleArn=EXECUTION, KmsRecoveryRoleArn=RECOVERY),
        foundationPrerequisite='Review and separately authorize metadata/tagging policy UPDATE; verify actual inline policy before ingress execution',
        ingressTemplateSha256=digest(ingress_path), foundationTemplateSha256=digest(foundation_path),
        expectedIngressChanges=[dict(Action='Add', LogicalResourceId=k, ResourceType=v['Type'],
                                     DeletionPolicy=v.get('DeletionPolicy', 'Delete'))
                                for k, v in ingress['Resources'].items()],
        expectedFoundationChange=dict(Action='Modify', LogicalResourceId='StageCloudFormationRole', Replacement='False'),
        parameters=parameters, ingressRoleARN=EXECUTION,
        expectedTopicArn=f'arn:aws:sns:{REGION}:{ACCOUNT}:muserelay-aws-bridge-stage-inbound',
        expectedTopicArnVerifiedInAws=False,
        missingRuntimeResources=['Lambda', 'API Gateway', 'DynamoDB', 'Secrets Manager', 'Bedrock'],
        deploymentAuthorization=False, sendsAuthorization=False,
        prerequisites=['Non-root authorized Screen Art session', 'Current role policies/trust/boundaries verified',
                       'No existing stage stack or resource-name collisions',
                       'AWS-side change set matches manifest; no replacements or removals',
                       'Separate express authorization before either change set execution'])
    return {
        'deploy/sns-bootstrap/parameters.stage.json': parameters,
        'deploy/sns-bootstrap/change-set.create.prepared.json': ingress_request,
        'deploy/iam-foundation/change-set.metadata-update.prepared.json': foundation_request,
        'deploy/sns-bootstrap/review-manifest.json': manifest}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Check generated files without writing')
    args = parser.parse_args()
    for name, value in build().items():
        path = ROOT/name
        expected = json.dumps(value, ensure_ascii=False, indent=2)+'\n'
        if args.check:
            assert path.read_text() == expected, f'Stale review artifact: {name}'
        else:
            path.write_text(expected)
    print(json.dumps(dict(result='PASS', mode='CHECK' if args.check else 'PREPARE', awsCalls=0,
                          executed=False, artifacts=4)))


if __name__ == '__main__':
    main()
