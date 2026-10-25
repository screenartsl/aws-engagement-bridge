#!/usr/bin/env python3
"""Prepare or perform ONE separately authorized, redacted SQS sample.

Default: offline plan, no AWS calls. --receive-once changes receive count and
temporarily hides up to two messages. It never deletes, acknowledges or retries.
"""
import argparse
from collections import Counter
from datetime import datetime, timezone
import json
import os
import re
import shutil
import subprocess

ACCOUNT = '365470560685'
REGION = 'eu-west-1'
STACK = 'muserelay-aws-bridge-stage'
QUEUE = STACK+'-inbound-queue'
QUEUE_ARN = f'arn:aws:sqs:{REGION}:{ACCOUNT}:{QUEUE}'
TOPIC = f'arn:aws:sns:{REGION}:{ACCOUNT}:{STACK}-inbound'
WABA = '29207063275646749'
DEMO_NUMBER_DIGITS = '34871999169'
MAX_MESSAGES = 2
VISIBILITY_SECONDS = 30
ALLOWED = {('sts', 'get-caller-identity'), ('sqs', 'get-queue-url'),
           ('sqs', 'get-queue-attributes'), ('lambda', 'list-event-source-mappings'),
           ('sqs', 'receive-message')}
MESSAGE_TYPES = {'text', 'image', 'audio', 'video', 'document', 'sticker', 'contacts',
                 'location', 'interactive', 'button', 'reaction', 'order', 'system',
                 'unsupported', 'request_welcome'}
FIELDS = {'messages', 'account_update', 'account_review_update', 'account_alerts',
          'business_capability_update', 'message_template_status_update',
          'message_template_quality_update', 'phone_number_name_update',
          'phone_number_quality_update', 'calls', 'history', 'smb_message_echoes'}
STATUSES = {'sent', 'delivered', 'read', 'failed', 'deleted'}


class InspectionStop(Exception):
    """Fixed error codes only: never include provider response bodies."""


def obj(value):
    if not isinstance(value, dict):
        raise InspectionStop('unexpected_object_shape')
    return value


def decode(value):
    if isinstance(value, str):
        if len(value.encode('utf-8')) > 1_048_576:
            raise InspectionStop('payload_size_limit')
        try:
            return json.loads(value)
        except (ValueError, RecursionError):
            raise InspectionStop('invalid_json') from None
    return value


def items(value):
    if value is None:
        return []
    if not isinstance(value, list) or len(value) > 100:
        raise InspectionStop('unexpected_list_shape_or_limit')
    return value


def enum(value, allowed):
    return value if isinstance(value, str) and value in allowed else 'other'


def present(value):
    return isinstance(value, str) and bool(value)


def sanitize(message):
    """Construct output from fixed keys/enums/counts; never echo unknown data."""
    result = dict(schema='unrecognized', sourceTopicMatches=False)
    try:
        message = obj(message)
        attrs = obj(message.get('Attributes', {}))
        receive_count = attrs.get('ApproximateReceiveCount', '')
        count = int(receive_count) if isinstance(receive_count, str) and re.fullmatch(r'[0-9]{1,3}', receive_count) else None
        result.update(receiveCount=count, receiveBudgetLow=count is not None and count >= 3)
        sns = obj(decode(message.get('Body')))
        result['sourceTopicMatches'] = sns.get('TopicArn') == TOPIC
        if not result['sourceTopicMatches'] or sns.get('Type') != 'Notification':
            raise InspectionStop('unexpected_sns_source_or_type')
        social = obj(decode(sns.get('Message')))
        result['awsAccountMatches'] = social.get('aws_account_id') == ACCOUNT if 'aws_account_id' in social else None
        result['awsContextPresent'] = isinstance(social.get('context'), dict)
        raw = social.get('whatsAppWebhookEntry')
        result['webhookEncoding'] = 'json_string' if isinstance(raw, str) else 'object' if isinstance(raw, dict) else 'other'
        webhook = obj(decode(raw))
        result['demoWabaMatches'] = webhook.get('id') == WABA
        changes = items(webhook.get('changes'))
        summaries = []
        for raw_change in changes:
            change = obj(raw_change)
            value = obj(change.get('value', {}))
            metadata = obj(value.get('metadata', {}))
            messages = [obj(x) for x in items(value.get('messages'))]
            statuses = [obj(x) for x in items(value.get('statuses'))]
            number = metadata.get('display_phone_number')
            digits = re.sub(r'[^0-9]', '', number) if isinstance(number, str) else None
            summaries.append(dict(
                field=enum(change.get('field'), FIELDS),
                messagingProductIsWhatsApp=value.get('messaging_product') == 'whatsapp',
                metaPhoneIdPresent=present(metadata.get('phone_number_id')),
                demoDisplayNumberMatches=digits == DEMO_NUMBER_DIGITS if digits is not None else None,
                inboundMessageCount=len(messages),
                inboundTypes=dict(Counter(enum(x.get('type'), MESSAGE_TYPES) for x in messages)),
                textBodyPresentCount=sum(isinstance(x.get('text'), dict) and present(x['text'].get('body')) for x in messages if x.get('type') == 'text'),
                statusCount=len(statuses),
                statusTypes=dict(Counter(enum(x.get('status'), STATUSES) for x in statuses)),
                contactCount=len(items(value.get('contacts'))),
                errorCount=len(items(value.get('errors'))),
            ))
        result.update(schema='sns_social_webhook_entry', changes=summaries,
                      routingOrAllowlistVerified=False, snsSignatureCryptographicallyVerified=False)
    except (InspectionStop, TypeError, ValueError, RecursionError):
        # Discard partial payload-derived summaries if any shape is unsupported.
        result.pop('changes', None)
        result['schema'] = 'unrecognized_or_unsupported'
    return result


def plan():
    return dict(mode='PREPARED_NOT_EXECUTED', awsCalls=0,
                account=ACCOUNT, region=REGION, queue=QUEUE,
                receiveCalls=1, maximumMessages=MAX_MESSAGES,
                waitTimeSeconds=0, visibilitySeconds=VISIBILITY_SECONDS,
                receiveCountIncrements=True, automaticSdkRetries=False,
                deletes=False, visibilityResetCalls=False, persistentWorkers=False,
                rawPayloadPrintedOrSaved=False, coreOrRuntimeActivation=False,
                authorization='Separate express authorization required before --receive-once')


def inspect(invoke):
    identity = obj(invoke('sts', 'get-caller-identity', []))
    if identity.get('Account') != ACCOUNT or not re.fullmatch(
        rf'arn:aws:sts::{ACCOUNT}:assumed-role/AWSReservedSSO_AdministratorAccess_[^/]+/.+', identity.get('Arn', '')
    ):
        raise InspectionStop('unauthorized_identity_or_account')
    response = obj(invoke('sqs', 'get-queue-url', ['--queue-name', QUEUE, '--queue-owner-aws-account-id', ACCOUNT]))
    url = response.get('QueueUrl', '')
    if url != f'https://sqs.{REGION}.amazonaws.com/{ACCOUNT}/{QUEUE}':
        raise InspectionStop('unexpected_queue_url')
    attributes = obj(obj(invoke('sqs', 'get-queue-attributes', ['--queue-url', url, '--attribute-names',
        'QueueArn', 'SqsManagedSseEnabled', 'RedrivePolicy', 'VisibilityTimeout',
        'ApproximateNumberOfMessagesNotVisible']))['Attributes'])
    redrive = obj(decode(attributes.get('RedrivePolicy')))
    if (attributes.get('QueueArn') != QUEUE_ARN or attributes.get('SqsManagedSseEnabled') != 'true'
        or attributes.get('VisibilityTimeout') != '180'
        or attributes.get('ApproximateNumberOfMessagesNotVisible') != '0'
        or str(redrive.get('maxReceiveCount')) != '5'
        or redrive.get('deadLetterTargetArn') != f'arn:aws:sqs:{REGION}:{ACCOUNT}:{STACK}-inbound-dlq'):
        raise InspectionStop('queue_configuration_or_inflight_requires_review')
    mappings = obj(invoke('lambda', 'list-event-source-mappings', ['--event-source-arn', QUEUE_ARN]))
    if mappings.get('EventSourceMappings') != []:
        raise InspectionStop('lambda_mappings_require_review')
    # Exactly one invocation. No loop, paginator or retry around ReceiveMessage.
    received = obj(invoke('sqs', 'receive-message', [
        '--queue-url', url, '--max-number-of-messages', str(MAX_MESSAGES),
        '--wait-time-seconds', '0', '--visibility-timeout', str(VISIBILITY_SECONDS),
        '--message-system-attribute-names', 'ApproximateReceiveCount']))
    messages = items(received.get('Messages'))
    if len(messages) > MAX_MESSAGES:
        raise InspectionStop('unexpected_sample_size_do_not_repeat')
    return dict(mode='ONE_RECEIVE_COMPLETED', sampledAt=datetime.now(timezone.utc).isoformat(),
                messagesReturned=len(messages), receiveCalls=1, visibilitySeconds=VISIBILITY_SECONDS,
                deletedMessages=0, rawPayloadPrintedOrSaved=False,
                samples=[sanitize(x) for x in messages],
                note='Do not repeat automatically. Empty result does not prove an empty queue. No processing or delivery certification.')


def invoke_cli(service, operation, args):
    if (service, operation) not in ALLOWED:
        raise InspectionStop('operation_not_allowed')
    env = dict(os.environ, AWS_PAGER='', AWS_CLI_AUTO_PROMPT='off',
               AWS_EC2_METADATA_DISABLED='true', AWS_MAX_ATTEMPTS='1', AWS_RETRY_MODE='standard')
    try:
        response = subprocess.run(['aws', service, operation, *args, '--region', REGION,
            '--output', 'json', '--no-cli-pager', '--cli-connect-timeout', '5', '--cli-read-timeout', '15'],
            capture_output=True, text=True, env=env, timeout=25, check=False)
        if response.returncode:
            raise InspectionStop('aws_request_failed_do_not_repeat')
        return decode(response.stdout)
    except subprocess.TimeoutExpired:
        raise InspectionStop('request_outcome_uncertain_do_not_repeat') from None


def ensure_cli_history_disabled():
    # AWS CLI history can persist complete API responses despite stdout redaction.
    # Read this single non-secret setting; do not modify the user's configuration.
    try:
        result = subprocess.run(['aws', 'configure', 'get', 'cli_history'],
            capture_output=True, text=True, timeout=10, check=False,
            env=dict(os.environ, AWS_PAGER='', AWS_CLI_AUTO_PROMPT='off'))
    except subprocess.TimeoutExpired:
        raise InspectionStop('cli_history_state_unknown') from None
    disabled = result.returncode == 0 and result.stdout.strip().lower() == 'disabled'
    unset = result.returncode == 1 and not result.stdout.strip() and not result.stderr.strip()
    if not (disabled or unset):
        raise InspectionStop('cli_history_enabled_or_unverified')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--receive-once', action='store_true', help='Only after separate express authorization; changes SQS receive state')
    args = parser.parse_args()
    if not args.receive_once:
        print(json.dumps(plan(), indent=2))
        return
    if not shutil.which('aws'):
        parser.error('Use authorized CloudShell; do not configure production server credentials.')
    try:
        ensure_cli_history_disabled()
        report = inspect(invoke_cli)
    except (InspectionStop, KeyError, TypeError, ValueError, RecursionError):
        # Do not disclose exception data or retry an uncertain ReceiveMessage.
        report = dict(mode='STOP_DO_NOT_REPEAT', rawPayloadPrintedOrSaved=False,
                      reason='Preflight or request failed; receive outcome may be uncertain. Review metadata without another receive.')
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
