#!/usr/bin/env python3
"""Offline: derive the SNS-only deployment from the reviewed complete template."""
import copy
from pathlib import Path
import yaml

ROOT = Path(__file__).resolve().parents[2]
INGRESS = ['SnsKey', 'SnsKeyAlias', 'InboundTopic', 'TopicPolicy', 'InboundDlq',
           'InboundQueue', 'InboundQueuePolicy', 'DeliveryDlq', 'DeliveryDlqPolicy', 'Subscription',
           'ArtifactBucket', 'ArtifactBucketPolicy']

def build(source):
    return {
        'AWSTemplateFormatVersion': '2010-09-09',
        'Description': 'Screen Art encrypted WhatsApp demo ingress only; no runtime, sends or Core deployment',
        'Parameters': {'KmsAdministratorArn': copy.deepcopy(source['Parameters']['KmsAdministratorArn'])},
        'Rules': {'ScreenArtDemo': copy.deepcopy(source['Rules']['ScreenArtDemo'])},
        'Resources': {key: copy.deepcopy(source['Resources'][key]) for key in INGRESS},
        'Outputs': {key: copy.deepcopy(source['Outputs'][key]) for key in [
            'InboundTopicArn', 'SnsKeyArn', 'InboundQueueArn', 'InboundQueueUrl',
            'InboundDeadLetterQueue', 'SnsDeliveryDeadLetterQueue', 'SubscriptionArn', 'ArtifactBucketName']},
    }

if __name__ == '__main__':
    result = build(yaml.safe_load((ROOT / 'template.yaml').read_text()))
    (ROOT / 'deploy/sns-bootstrap/template.yaml').write_text(yaml.safe_dump(result, sort_keys=False, width=110))
    print('Prepared 12 bootstrap resources locally. No AWS calls.')
