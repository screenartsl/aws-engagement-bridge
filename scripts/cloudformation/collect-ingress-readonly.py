#!/usr/bin/env python3
"""Collect ingress metadata with AWS CLI. No message bodies, secrets or AWS writes.

Run from the authorized Screen Art CloudShell; stdout is reviewable JSON.
This collector does not certify policies or event delivery automatically.
"""
import argparse
from datetime import datetime, timezone
import json
import os
import re
import shutil
import subprocess

ACCOUNT = '365470560685'
REGION = 'eu-west-1'
STACK = 'muserelay-aws-bridge-stage'
TOPIC = f'arn:aws:sns:{REGION}:{ACCOUNT}:{STACK}-inbound'
BUCKET = f'{STACK}-artifacts-{ACCOUNT}-{REGION}'
QUEUE_SUFFIXES = ['inbound-queue', 'inbound-dlq', 'sns-delivery-dlq']
EXPECTED_RESOURCES = {
    'SnsKey': 'AWS::KMS::Key', 'SnsKeyAlias': 'AWS::KMS::Alias',
    'InboundTopic': 'AWS::SNS::Topic', 'TopicPolicy': 'AWS::SNS::TopicPolicy',
    'Subscription': 'AWS::SNS::Subscription', 'InboundQueue': 'AWS::SQS::Queue',
    'InboundDlq': 'AWS::SQS::Queue', 'DeliveryDlq': 'AWS::SQS::Queue',
    'InboundQueuePolicy': 'AWS::SQS::QueuePolicy', 'DeliveryDlqPolicy': 'AWS::SQS::QueuePolicy',
    'ArtifactBucket': 'AWS::S3::Bucket', 'ArtifactBucketPolicy': 'AWS::S3::BucketPolicy',
}
ALLOWED = {
    ('sts', 'get-caller-identity'),
    ('cloudformation', 'describe-stacks'), ('cloudformation', 'list-stack-resources'),
    ('cloudformation', 'get-template'),
    ('sns', 'get-topic-attributes'), ('sns', 'list-tags-for-resource'),
    ('sns', 'list-subscriptions-by-topic'), ('sns', 'get-subscription-attributes'),
    ('kms', 'describe-key'), ('kms', 'get-key-policy'),
    ('kms', 'get-key-rotation-status'), ('kms', 'list-resource-tags'), ('kms', 'list-aliases'),
    ('sqs', 'get-queue-url'), ('sqs', 'get-queue-attributes'), ('sqs', 'list-queue-tags'),
    ('lambda', 'list-event-source-mappings'),
    ('s3api', 'get-public-access-block'), ('s3api', 'get-bucket-encryption'),
    ('s3api', 'get-bucket-ownership-controls'), ('s3api', 'get-bucket-policy'),
    ('s3api', 'get-bucket-location'), ('s3api', 'get-bucket-lifecycle-configuration'),
    ('s3api', 'get-bucket-tagging'),
}


def collect(invoke):
    report = dict(kind='AWS_INGRESS_READ_ONLY_METADATA',
                  collectedAt=datetime.now(timezone.utc).isoformat(),
                  account=ACCOUNT, region=REGION, stack=STACK,
                  result='REQUIRES_POLICY_REVIEW', awsWrites=0,
                  messageBodiesRead=False, secretValuesRead=False, data={}, errors=[])

    def read(label, service, operation, *args):
        if (service, operation) not in ALLOWED:
            raise ValueError('Operation outside read-only allowlist')
        try:
            value = invoke(service, operation, list(args))
            report['data'][label] = value
            return value
        except (RuntimeError, subprocess.TimeoutExpired, json.JSONDecodeError) as error:
            code = str(error) if isinstance(error, RuntimeError) else type(error).__name__
            if not re.fullmatch(r'[A-Za-z0-9_.-]{1,100}', code):
                code = 'ReadFailed'
            report['errors'].append(dict(label=label, service=service, operation=operation, code=code))
            return {}

    identity = read('identity', 'sts', 'get-caller-identity', '--query', '{Account:Account,Arn:Arn}')
    if identity.get('Account') != ACCOUNT or not re.fullmatch(
        rf'arn:aws:sts::{ACCOUNT}:assumed-role/AWSReservedSSO_AdministratorAccess_[^/]+/.+',
        identity.get('Arn', '')
    ):
        report.update(result='BLOCKED', stoppedBecause='Expected authorized Screen Art AdministratorAccess SSO session')
        return report
    stacks = read('stack', 'cloudformation', 'describe-stacks', '--stack-name', STACK,
                  '--query', 'Stacks[0].{StackName:StackName,Status:StackStatus,RoleARN:RoleARN,Parameters:Parameters,Outputs:Outputs,Tags:Tags}')
    inventory = read('resources', 'cloudformation', 'list-stack-resources', '--stack-name', STACK,
                     '--query', 'StackResourceSummaries[].{LogicalId:LogicalResourceId,Type:ResourceType,PhysicalId:PhysicalResourceId,Status:ResourceStatus}')
    if not isinstance(inventory, list) or len(inventory) != len(EXPECTED_RESOURCES) or {
        item.get('LogicalId'): item.get('Type') for item in inventory
    } != EXPECTED_RESOURCES:
        report.update(result='BLOCKED', stoppedBecause='Expected exactly the twelve ingress resources before reading original template')
        return report
    outputs = {item['OutputKey']: item['OutputValue'] for item in stacks.get('Outputs', [])}
    key_arn = outputs.get('SnsKeyArn', '')
    if outputs.get('InboundTopicArn') != TOPIC or not re.fullmatch(
        rf'arn:aws:kms:{REGION}:{ACCOUNT}:key/[0-9a-f-]{{36}}', key_arn
    ):
        report.update(result='BLOCKED', stoppedBecause='Missing or unexpected actual SNS/KMS stack outputs')
        return report
    read('template', 'cloudformation', 'get-template', '--stack-name', STACK, '--template-stage', 'Original')
    read('topic', 'sns', 'get-topic-attributes', '--topic-arn', TOPIC)
    read('topicTags', 'sns', 'list-tags-for-resource', '--resource-arn', TOPIC)
    subscriptions = read('subscriptions', 'sns', 'list-subscriptions-by-topic', '--topic-arn', TOPIC)
    for index, item in enumerate(subscriptions.get('Subscriptions', [])):
        arn = item.get('SubscriptionArn', '')
        if arn.startswith(TOPIC+':'):
            read(f'subscription{index}', 'sns', 'get-subscription-attributes', '--subscription-arn', arn)
        else:
            report['errors'].append(dict(label=f'subscription{index}', code='UnconfirmedOrUnexpectedSubscriptionArn'))
    read('key', 'kms', 'describe-key', '--key-id', key_arn)
    read('keyPolicy', 'kms', 'get-key-policy', '--key-id', key_arn, '--policy-name', 'default')
    read('keyRotation', 'kms', 'get-key-rotation-status', '--key-id', key_arn)
    read('keyTags', 'kms', 'list-resource-tags', '--key-id', key_arn)
    read('keyAliases', 'kms', 'list-aliases', '--key-id', key_arn)
    for suffix in QUEUE_SUFFIXES:
        name = f'{STACK}-{suffix}'
        arn = f'arn:aws:sqs:{REGION}:{ACCOUNT}:{name}'
        result = read(f'queueUrl:{suffix}', 'sqs', 'get-queue-url', '--queue-name', name,
                      '--queue-owner-aws-account-id', ACCOUNT)
        url = result.get('QueueUrl', '')
        if re.fullmatch(rf'https://sqs\.{REGION}\.amazonaws\.com/{ACCOUNT}/{re.escape(name)}', url):
            read(f'queue:{suffix}', 'sqs', 'get-queue-attributes', '--queue-url', url, '--attribute-names', 'All')
            read(f'queueTags:{suffix}', 'sqs', 'list-queue-tags', '--queue-url', url)
        else:
            report['errors'].append(dict(label=f'queue:{suffix}', code='MissingOrUnexpectedQueueUrl'))
        read(f'lambdaMappings:{suffix}', 'lambda', 'list-event-source-mappings', '--event-source-arn', arn,
             '--query', 'EventSourceMappings[].{UUID:UUID,State:State,EventSourceArn:EventSourceArn,FunctionArn:FunctionArn}')
    for label, operation in [
        ('bucketPublicAccess', 'get-public-access-block'), ('bucketEncryption', 'get-bucket-encryption'),
        ('bucketOwnership', 'get-bucket-ownership-controls'), ('bucketPolicy', 'get-bucket-policy'),
        ('bucketLocation', 'get-bucket-location'), ('bucketLifecycle', 'get-bucket-lifecycle-configuration'),
        ('bucketTags', 'get-bucket-tagging'),
    ]:
        read(label, 's3api', operation, '--bucket', BUCKET, '--expected-bucket-owner', ACCOUNT)
    if report['errors']:
        report['result'] = 'PARTIAL_REQUIRES_POLICY_REVIEW'
    report['limitations'] = [
        'Metadata collected only; a reviewer must compare actual policies and template with the pinned source',
        'Empty Lambda mappings do not exclude non-Lambda SQS pollers outside this stack',
        'No key recovery write, event delivery, message publication or runtime behavior was tested',
        'No Social destination was read or changed; its linked WABA identifiers remain a separate check',
    ]
    return report


def invoke_cli(service, operation, args):
    if (service, operation) not in ALLOWED:
        raise ValueError('Operation outside read-only allowlist')
    env = dict(os.environ, AWS_PAGER='', AWS_CLI_AUTO_PROMPT='off', AWS_EC2_METADATA_DISABLED='true')
    command = ['aws', service, operation, *args, '--region', REGION, '--output', 'json',
               '--no-cli-pager', '--cli-connect-timeout', '5', '--cli-read-timeout', '20']
    result = subprocess.run(command, capture_output=True, text=True, env=env, timeout=45, check=False)
    if result.returncode:
        match = re.search(r'An error occurred \(([A-Za-z0-9_.-]+)\)', result.stderr)
        raise RuntimeError(match.group(1) if match else 'AwsCliReadFailed')
    return json.loads(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dry-run', action='store_true', help='Print allowlisted operations without invoking AWS')
    args = parser.parse_args()
    if args.dry_run:
        print(json.dumps(dict(mode='DRY_RUN', awsCalls=0, allowedReadOperations=sorted(' '.join(x) for x in ALLOWED)), indent=2))
        return
    if not shutil.which('aws'):
        parser.error('AWS CLI not available; use the authorized Screen Art CloudShell. Do not configure server credentials.')
    print(json.dumps(collect(invoke_cli), ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
