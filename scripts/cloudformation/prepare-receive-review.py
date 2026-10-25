#!/usr/bin/env python3
"""Prepare a disabled receive-only UPDATE locally. No AWS SDK, CLI or upload.

Requires an existing private release produced by package-runtime.py. Does not
rebuild it, fetch credentials, initialize secrets or inspect application data.
"""
import argparse
import base64
import hashlib
import json
from pathlib import Path
import re
import zipfile

import yaml

ROOT = Path(__file__).resolve().parents[2]
ACCOUNT = '365470560685'
REGION = 'eu-west-1'
STACK = 'muserelay-aws-bridge-stage'
ROLE = f'arn:aws:iam::{ACCOUNT}:role/'
PHONE = f'arn:aws:social-messaging:{REGION}:{ACCOUNT}:phone-number-id/b1e7f9e53c454f15be065a2e2b5a1f00'
BUCKET = f'{STACK}-artifacts-{ACCOUNT}-{REGION}'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def build(release_dir):
    release_dir = Path(release_dir).resolve()
    if not release_dir.is_absolute() or '/var/www' in str(release_dir):
        raise ValueError('Use a private release outside web/replicated vhosts')
    release = json.loads((release_dir/'manifest.json').read_text())
    commit = release['sourceCommit']
    if release['kind'] != 'LOCAL_ONLY_NOT_UPLOADED' or not re.fullmatch('[a-f0-9]{40}', commit):
        raise ValueError('Expected a pinned local release manifest')
    artifact_key = f'releases/{commit}/bridge.zip'
    if release['artifactKey'] != artifact_key:
        raise ValueError('Artifact key does not match source commit')
    template_bytes = (ROOT/'template.yaml').read_bytes()
    if (release_dir/'02-runtime.yaml').read_bytes() != template_bytes:
        raise ValueError('Pinned release and current runtime template differ')
    for name in ['bridge.zip', '02-runtime.yaml']:
        if sha((release_dir/name).read_bytes()) != release['sha256'][name]:
            raise ValueError('Release checksum mismatch: '+name)
    with zipfile.ZipFile(release_dir/'bridge.zip') as archive:
        if archive.namelist() != ['handlers.mjs'] or archive.testzip() is not None:
            raise ValueError('Unexpected or corrupt runtime ZIP')
    if len(template_bytes) > 51200:
        raise ValueError('TemplateBody exceeds the direct API limit')
    template = yaml.safe_load(template_bytes)
    bindings = [dict(id='screenart-demo', channelRef='screenart-demo-aws', region=REGION,
                     enabled=False, topicArn=f'arn:aws:sns:{REGION}:{ACCOUNT}:{STACK}-inbound',
                     wabaId='29207063275646749', metaPhoneNumberId='1383587584839516',
                     awsPhoneNumberId=PHONE, allowedRecipients=[])]
    parameters = {name: spec['Default'] for name, spec in template['Parameters'].items() if 'Default' in spec}
    parameters.update(KmsAdministratorArn=ROLE+STACK+'-cfn-execution',
                      KmsRecoveryAdministratorArn=ROLE+'muserelay-aws-bridge-kms-recovery',
                      DeployProcessing='true', DeployOutbound='false', ProcessingEnabled='false',
                      ReceiveEnabled='false', SendEnabled='false', LiveSend='false',
                      SocialPhoneArn=PHONE, CoreBaseUrl='https://aws-bridge-staging.muserelay.com',
                      RuntimeArtifactKey=artifact_key, RunId='phase4_receive_review',
                      MetaApiVersion='', SecretArn='', OutboundSecretArn='',
                      BindingsJson=json.dumps(bindings, separators=(',', ':')))
    if set(parameters) != set(template['Parameters']):
        raise ValueError('Runtime parameter inventory drift')
    parameter_list = [dict(ParameterKey=k, ParameterValue=str(v)) for k, v in parameters.items()]
    name = f'receive-runtime-review-{sha(template_bytes)[:12]}-{commit[:8]}'
    request = dict(StackName=STACK, ChangeSetName=name, ChangeSetType='UPDATE',
                   RoleARN=ROLE+STACK+'-cfn-execution', Capabilities=['CAPABILITY_AUTO_EXPAND'],
                   Description='Review only: add receive-only resources; mapping, handlers, binding and sends disabled. No Core changes.',
                   TemplateBody=template_bytes.decode(), Parameters=parameter_list)
    # These are expectations, not a result obtained from an AWS change set.
    adds = dict(State='AWS::DynamoDB::Table', InboundFunction='AWS::Lambda::Function',
                InboundFunctionInput='AWS::Lambda::EventSourceMapping',
                InboundLogs='AWS::Logs::LogGroup', SigningSecret='AWS::SecretsManager::Secret')
    manifest = dict(kind='LOCAL_RECEIVE_REVIEW_NOT_AN_AWS_CHANGE_SET', account=ACCOUNT, region=REGION,
                    stack=STACK, changeSetName=name, templatePath='template.yaml',
                    templateSha256=sha(template_bytes), artifactSourceCommit=commit,
                    artifactBucket=BUCKET, artifactKey=artifact_key,
                    artifactSha256=release['sha256']['bridge.zip'],
                    artifactChecksumSHA256Base64=base64.b64encode(bytes.fromhex(release['sha256']['bridge.zip'])).decode(),
                    artifactUploadedByThisPreparation=False, artifactExistenceInAwsVerified=False,
                    iamFoundationState='UPDATE_COMPLETE reported by owner; no direct AWS verification',
                    managedPoliciesAndBoundariesVerified=False, lambdaQuotaVerified=False,
                    awsCalls=0, changeSetCreatedInAws=False, executionAuthorized=False,
                    expectedAdds=[dict(LogicalResourceId=k, ResourceType=v, Action='Add') for k, v in adds.items()],
                    expectedModifications=[], expectedRemovals=[], expectedReplacements=[],
                    preserveOriginalIngressResources=12, expectedSelectedResourcesAfterSam=17,
                    receiveConsumerEnabled=False, processingEnabled=False, sendEnabled=False,
                    bindingEnabled=False, allowedRecipients=[],
                    intendedOrganization='Screen Art Demo Workspace', intendedOrganizationId=227,
                    demoWorkspaceId=312, demoOrganizationIdentityReadOnlyVerified=True,
                    demoAuthenticatedAccessVerified=False, authorizedOrganizationIds=[],
                    coreOrganizationWorkspaceChannelIdsVerified=False, coreEndpointVerified=False,
                    coreEndpoint='https://aws-bridge-staging.muserelay.com',
                    coreEndpointStatus='temporary technical test destination only; not activated; final native Core endpoint needs review',
                    productionModified=False, agentDeployed=False,
                    prerequisitesBeforeExecution=['Read actual change set and preserve the original 12 resources',
                        'Verify attached managed policies/boundaries and execution role policy in AWS',
                        'Verify Lambda reserved concurrency capacity without changing quotas or other functions',
                        'Authorize private ZIP upload separately and verify S3 checksum',
                        'Explicit authorization to execute this exact runtime change set'],
                    prerequisitesBeforeReception=['Owner-created Screen Art Demo identity and scope verification',
                        'Reviewed Core connector installation, native inbox, rollback and authenticated HTTPS endpoint',
                        'Private HMAC initialization and expiry/allowlist checks',
                        'Explicit authorization covering existing SQS backlog and receive/drain activation'])
    return {'deploy/runtime-iam/change-set.receive-runtime.prepared.json': request,
            'deploy/runtime-review/parameters.disabled.json': parameter_list,
            'deploy/runtime-review/manifest.prepared.json': manifest}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release-dir', type=Path, required=True)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    for name, value in build(args.release_dir).items():
        path = ROOT/name
        expected = json.dumps(value, indent=2, ensure_ascii=False)+'\n'
        if args.check:
            if path.read_text() != expected:
                raise ValueError('Stale preparation: '+name)
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(expected)
    print(json.dumps(dict(result='PASS_LOCAL_PREPARATION', awsCalls=0, changeSetCreated=False,
                          executionAuthorized=False, mode='CHECK' if args.check else 'PREPARE')))


if __name__ == '__main__':
    main()
