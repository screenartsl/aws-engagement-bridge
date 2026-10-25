"""Collector safety tests with synthetic responses; no AWS, network or app boot."""
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('ingress_collector', Path(__file__).with_name('collect-ingress-readonly.py'))
collector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(collector)


class CollectorSafetyTests(unittest.TestCase):
    def reader(self, identity=None, bad_outputs=False, fail=None, extra_runtime=False):
        calls = []

        def read(service, operation, args):
            calls.append((service, operation, args))
            if (service, operation) == fail:
                raise RuntimeError('AccessDeniedException')
            if operation == 'get-caller-identity':
                return identity or dict(Account=collector.ACCOUNT,
                    Arn=f'arn:aws:sts::{collector.ACCOUNT}:assumed-role/AWSReservedSSO_AdministratorAccess_test/synthetic')
            if operation == 'describe-stacks':
                return dict(Outputs=[] if bad_outputs else [
                    dict(OutputKey='InboundTopicArn', OutputValue=collector.TOPIC),
                    dict(OutputKey='SnsKeyArn', OutputValue=f'arn:aws:kms:{collector.REGION}:{collector.ACCOUNT}:key/00000000-0000-0000-0000-000000000001')])
            if operation == 'list-stack-resources':
                items = [dict(LogicalId=k, Type=v, Status='CREATE_COMPLETE') for k,v in collector.EXPECTED_RESOURCES.items()]
                if extra_runtime:
                    items.append(dict(LogicalId='SigningSecret', Type='AWS::SecretsManager::Secret'))
                return items
            if operation == 'get-queue-url':
                name = args[args.index('--queue-name')+1]
                return dict(QueueUrl=f'https://sqs.{collector.REGION}.amazonaws.com/{collector.ACCOUNT}/{name}')
            if operation == 'list-subscriptions-by-topic':
                return dict(Subscriptions=[dict(SubscriptionArn=collector.TOPIC+':synthetic-subscription')])
            if operation == 'list-event-source-mappings':
                return []
            return {}
        return read, calls

    def test_wrong_account_stops_before_resource_reads(self):
        read, calls = self.reader(identity=dict(Account='111111111111', Arn='arn:aws:iam::111111111111:root'))
        self.assertEqual(collector.collect(read)['result'], 'BLOCKED')
        self.assertEqual(len(calls), 1)

    def test_root_in_correct_account_is_rejected(self):
        read, calls = self.reader(identity=dict(Account=collector.ACCOUNT, Arn=f'arn:aws:iam::{collector.ACCOUNT}:root'))
        self.assertEqual(collector.collect(read)['result'], 'BLOCKED')
        self.assertEqual(len(calls), 1)

    def test_unexpected_stack_outputs_stop_before_key_or_queue_reads(self):
        read, calls = self.reader(bad_outputs=True)
        self.assertEqual(collector.collect(read)['result'], 'BLOCKED')
        self.assertFalse(any(service in ['kms', 'sns', 'sqs', 's3api'] for service, _, _ in calls))

    def test_successful_collection_never_claims_policy_or_delivery_verified(self):
        read, calls = self.reader()
        report = collector.collect(read)
        self.assertEqual(report['result'], 'REQUIRES_POLICY_REVIEW')
        self.assertFalse(report['errors'])
        self.assertEqual(report['awsWrites'], 0)
        self.assertFalse(report['messageBodiesRead'])
        self.assertTrue(all((service, operation) in collector.ALLOWED for service, operation, _ in calls))
        self.assertEqual(sum(operation == 'list-event-source-mappings' for _, operation, _ in calls), 3)

    def test_unexpected_runtime_stops_before_template_or_secret_metadata(self):
        read, calls = self.reader(extra_runtime=True)
        report = collector.collect(read)
        self.assertEqual(report['result'], 'BLOCKED')
        self.assertFalse(any(operation == 'get-template' for _, operation, _ in calls))

    def test_access_denied_is_partial_without_fallback_or_permission_change(self):
        read, calls = self.reader(fail=('kms', 'get-key-policy'))
        report = collector.collect(read)
        self.assertEqual(report['result'], 'PARTIAL_REQUIRES_POLICY_REVIEW')
        self.assertEqual(report['errors'][0]['code'], 'AccessDeniedException')
        self.assertTrue(all((service, operation) in collector.ALLOWED for service, operation, _ in calls))

    def test_write_or_message_operations_cannot_reach_cli(self):
        with patch.object(collector.subprocess, 'run') as invoke:
            for service, operation in [('sns', 'publish'), ('sqs', 'receive-message'),
                                       ('sqs', 'purge-queue'), ('kms', 'put-key-policy'),
                                       ('socialmessaging', 'put-whatsapp-business-account-event-destinations')]:
                with self.assertRaises(ValueError):
                    collector.invoke_cli(service, operation, [])
            invoke.assert_not_called()


if __name__ == '__main__':
    unittest.main()
