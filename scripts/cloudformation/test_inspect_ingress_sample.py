"""Privacy and one-receive safety checks using synthetic events only."""
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('sample_inspector', Path(__file__).with_name('inspect-ingress-sample.py'))
inspector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inspector)

SENTINELS = ['PRIVATE_TEXT_SENTINEL', 'Alice Private Sentinel', '12025550123',
             'https://private.example/media', 'PRIVATE_RECEIPT_SENTINEL', 'wamid.private-sentinel']


def message(field='messages', message_type='text', status='read'):
    webhook = dict(id=inspector.WABA, changes=[dict(field=field, value=dict(
        messaging_product='whatsapp', metadata=dict(display_phone_number=inspector.DEMO_NUMBER_DIGITS, phone_number_id='private-meta-id'),
        contacts=[dict(profile=dict(name=SENTINELS[1]), wa_id=SENTINELS[2])],
        messages=[dict(type=message_type, id=SENTINELS[5], **{'from': SENTINELS[2]},
                       text=dict(body=SENTINELS[0]), image=dict(id='private-media-id', url=SENTINELS[3]))],
        statuses=[dict(status=status, id=SENTINELS[5], recipient_id=SENTINELS[2])],
        errors=[dict(message=SENTINELS[0])]))])
    social = dict(aws_account_id=inspector.ACCOUNT, context={}, whatsAppWebhookEntry=json.dumps(webhook))
    envelope = dict(Type='Notification', TopicArn=inspector.TOPIC, Message=json.dumps(social), SigningCertURL=SENTINELS[3])
    return dict(Body=json.dumps(envelope), MessageId='private-sqs-id', ReceiptHandle=SENTINELS[4], Attributes=dict(ApproximateReceiveCount='1'))


class InspectorTests(unittest.TestCase):
    def assert_private(self, output):
        encoded = json.dumps(output)
        for value in SENTINELS+['private-meta-id', 'private-sqs-id', 'private-media-id', inspector.WABA, inspector.DEMO_NUMBER_DIGITS]:
            self.assertNotIn(value, encoded)

    def reader(self, *, root=False, mappings=False, messages=None, fail_receive=False):
        calls = []

        def read(service, operation, args):
            calls.append((service, operation, args))
            if operation == 'get-caller-identity':
                return dict(Account=inspector.ACCOUNT, Arn=f'arn:aws:iam::{inspector.ACCOUNT}:root' if root else
                    f'arn:aws:sts::{inspector.ACCOUNT}:assumed-role/AWSReservedSSO_AdministratorAccess_synthetic/test')
            if operation == 'get-queue-url':
                return dict(QueueUrl=f'https://sqs.{inspector.REGION}.amazonaws.com/{inspector.ACCOUNT}/{inspector.QUEUE}')
            if operation == 'get-queue-attributes':
                return dict(Attributes=dict(QueueArn=inspector.QUEUE_ARN, SqsManagedSseEnabled='true',
                    VisibilityTimeout='180', ApproximateNumberOfMessagesNotVisible='0',
                    RedrivePolicy=json.dumps(dict(maxReceiveCount=5, deadLetterTargetArn=
                        f'arn:aws:sqs:{inspector.REGION}:{inspector.ACCOUNT}:{inspector.STACK}-inbound-dlq'))))
            if operation == 'list-event-source-mappings':
                return dict(EventSourceMappings=[dict(State='Enabled')] if mappings else [])
            if operation == 'receive-message':
                if fail_receive:
                    raise inspector.InspectionStop('request_outcome_uncertain_do_not_repeat')
                return dict(Messages=[message(), message()] if messages is None else messages)
            raise AssertionError('Unexpected operation')
        return read, calls

    def test_text_redaction_keeps_only_shape_and_booleans(self):
        result = inspector.sanitize(message())
        self.assert_private(result)
        self.assertTrue(result['sourceTopicMatches'])
        self.assertTrue(result['demoWabaMatches'])
        self.assertEqual(result['changes'][0]['inboundTypes'], {'text': 1})
        self.assertEqual(result['changes'][0]['statusTypes'], {'read': 1})

    def test_unknown_enum_values_are_never_echoed(self):
        result = inspector.sanitize(message(field=SENTINELS[0], message_type=SENTINELS[1], status=SENTINELS[2]))
        self.assert_private(result)
        self.assertEqual(result['changes'][0]['field'], 'other')
        self.assertEqual(result['changes'][0]['inboundTypes'], {'other': 1})

    def test_media_urls_ids_and_customer_metadata_remain_private(self):
        result = inspector.sanitize(message(message_type='image'))
        self.assert_private(result)
        self.assertEqual(result['changes'][0]['inboundTypes'], {'image': 1})

    def test_invalid_json_and_untrusted_topics_never_echo_payloads(self):
        bad = message(); bad['Body'] = SENTINELS[0]
        self.assert_private(inspector.sanitize(bad))
        bad = message(); body = json.loads(bad['Body']); body['TopicArn'] = SENTINELS[0]; bad['Body'] = json.dumps(body)
        result = inspector.sanitize(bad)
        self.assert_private(result)
        self.assertNotIn('changes', result)

    def test_root_stops_before_receive(self):
        read, calls = self.reader(root=True)
        with self.assertRaises(inspector.InspectionStop):
            inspector.inspect(read)
        self.assertEqual(len(calls), 1)

    def test_lambda_mapping_stops_before_receive(self):
        read, calls = self.reader(mappings=True)
        with self.assertRaises(inspector.InspectionStop):
            inspector.inspect(read)
        self.assertFalse(any(operation == 'receive-message' for _, operation, _ in calls))

    def test_one_receive_has_explicit_limits_and_no_acknowledgment(self):
        read, calls = self.reader()
        result = inspector.inspect(read)
        self.assert_private(result)
        receives = [args for _, operation, args in calls if operation == 'receive-message']
        self.assertEqual(len(receives), 1)
        self.assertIn('--max-number-of-messages', receives[0])
        self.assertEqual(receives[0][receives[0].index('--max-number-of-messages')+1], '2')
        self.assertEqual(receives[0][receives[0].index('--visibility-timeout')+1], '30')
        self.assertEqual(result['deletedMessages'], 0)
        self.assertTrue(all((service, operation) in inspector.ALLOWED for service, operation, _ in calls))

    def test_empty_or_failed_receive_is_not_retried(self):
        read, calls = self.reader(messages=[])
        self.assertEqual(inspector.inspect(read)['messagesReturned'], 0)
        self.assertEqual(sum(operation == 'receive-message' for _, operation, _ in calls), 1)
        read, calls = self.reader(fail_receive=True)
        with self.assertRaises(inspector.InspectionStop):
            inspector.inspect(read)
        self.assertEqual(sum(operation == 'receive-message' for _, operation, _ in calls), 1)

    def test_sdk_retries_disabled_and_write_operations_blocked(self):
        response = type('Response', (), dict(returncode=0, stdout='{}'))()
        with patch.object(inspector.subprocess, 'run', return_value=response) as run:
            inspector.invoke_cli('sqs', 'receive-message', [])
            self.assertEqual(run.call_args.kwargs['env']['AWS_MAX_ATTEMPTS'], '1')
            self.assertTrue(run.call_args.kwargs['capture_output'])
            run.reset_mock()
            for operation in ['delete-message', 'purge-queue', 'change-message-visibility', 'send-message']:
                with self.assertRaises(inspector.InspectionStop):
                    inspector.invoke_cli('sqs', operation, [])
            run.assert_not_called()

    def test_plan_does_not_call_aws(self):
        with patch.object(inspector.subprocess, 'run') as run:
            self.assertEqual(inspector.plan()['awsCalls'], 0)
            run.assert_not_called()

    def test_cli_history_enabled_stops_without_changing_configuration(self):
        response = type('Response', (), dict(returncode=0, stdout='enabled\n', stderr=''))()
        with patch.object(inspector.subprocess, 'run', return_value=response) as run:
            with self.assertRaises(inspector.InspectionStop):
                inspector.ensure_cli_history_disabled()
            self.assertEqual(run.call_args.args[0], ['aws', 'configure', 'get', 'cli_history'])

    def test_cli_history_disabled_or_unset_is_accepted(self):
        for code, output in [(0, 'disabled\n'), (1, '')]:
            response = type('Response', (), dict(returncode=code, stdout=output, stderr=''))()
            with patch.object(inspector.subprocess, 'run', return_value=response):
                inspector.ensure_cli_history_disabled()


if __name__ == '__main__':
    unittest.main()
