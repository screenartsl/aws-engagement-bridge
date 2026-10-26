"""Negative cases for the offline verifier; synthetic API metadata only."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('sam_original_verifier',ROOT/'scripts/cloudformation/verify-sam-iam-originals.py')
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)


class SamOriginalVerifierTests(unittest.TestCase):
    def setUp(self):
        self.current = {'TemplateBody':(ROOT/'deploy/runtime-iam/template.yaml').read_text()}
        self.proposed = {'TemplateBody':(ROOT/'deploy/runtime-sam-iam/template.yaml').read_text()}
        self.metadata = dict(StackName=v.STACK,ChangeSetName=v.CHANGE_SET,
            StackId=v.ARN_PREFIX+'stack/'+v.STACK+'/synthetic-stack',
            ChangeSetId=v.ARN_PREFIX+'changeSet/'+v.CHANGE_SET+'/synthetic-review',
            Status='CREATE_COMPLETE',ExecutionStatus='AVAILABLE',
            Parameters=[dict(ParameterKey='GrantSocialSend',ParameterValue='false')],
            Changes=[dict(ResourceChange=dict(LogicalResourceId='StageCloudFormationRole',
                PhysicalResourceId=v.ROLE_NAME,ResourceType='AWS::IAM::Role',
                Action='Modify',Replacement='False',Scope=['Properties']))])

    def test_accepts_original_yaml_and_full_correct_metadata(self):
        self.assertTrue(all(v.verify(self.current,self.proposed,self.metadata).values()))

    def test_accepts_json_objects_and_different_json_formatting(self):
        current={'TemplateBody':yaml.safe_load(self.current['TemplateBody'])}
        proposed={'TemplateBody':json.dumps(yaml.safe_load(self.proposed['TemplateBody']),indent=4)}
        self.assertTrue(all(v.verify(current,proposed,self.metadata).values()))

    def test_modified_kms_deny_is_rejected_even_with_good_metadata(self):
        value=yaml.safe_load(self.proposed['TemplateBody'])
        policy=value['Resources']['StageCloudFormationRole']['Properties']['Policies'][0]
        policy['PolicyDocument']['Statement']=[s for s in policy['PolicyDocument']['Statement'] if s['Effect']!='Deny']
        self.assertFalse(all(v.verify(self.current,{'TemplateBody':value},self.metadata).values()))

    def test_modified_recovery_or_runtime_role_is_rejected(self):
        for role in ['KmsRecoveryRole','InboundExecutionRole','EnqueueExecutionRole','SenderExecutionRole']:
            with self.subTest(role=role):
                value=yaml.safe_load(self.proposed['TemplateBody'])
                value['Resources'][role]['Properties']['AssumeRolePolicyDocument']={}
                self.assertFalse(v.template_matches({'TemplateBody':value},'proposed'))

    def test_changed_actual_parameter_is_not_hidden_by_false_template_default(self):
        self.metadata['Parameters'][0]['ParameterValue']='true'
        self.assertFalse(all(v.verify(self.current,self.proposed,self.metadata).values()))

    def test_previous_parameter_without_resolved_value_is_not_assumed_false(self):
        self.metadata['Parameters']=[dict(ParameterKey='GrantSocialSend',UsePreviousValue=True)]
        self.assertFalse(all(v.verify(self.current,self.proposed,self.metadata).values()))

    def test_extra_change_replacement_and_other_scope_are_rejected(self):
        for replacement in ['True','Conditional',None]:
            with self.subTest(replacement=replacement):
                metadata=copy.deepcopy(self.metadata)
                metadata['Changes'][0]['ResourceChange']['Replacement']=replacement
                self.assertFalse(all(v.verify(self.current,self.proposed,metadata).values()))
        metadata=copy.deepcopy(self.metadata);metadata['Changes'].append(metadata['Changes'][0])
        self.assertFalse(all(v.verify(self.current,self.proposed,metadata).values()))
        metadata=copy.deepcopy(self.metadata);metadata['Changes'][0]['ResourceChange']['Scope'].append('Tags')
        self.assertFalse(all(v.verify(self.current,self.proposed,metadata).values()))

    def test_wrong_account_stack_change_set_or_service_role_is_rejected(self):
        variants=[('StackId','arn:aws:cloudformation:eu-west-1:374040321139:stack/'+v.STACK+'/synthetic'),
                  ('ChangeSetName','unreviewed-change'),('ChangeSetId','arn:aws:cloudformation:eu-west-1:374040321139:changeSet/'+v.CHANGE_SET+'/synthetic'),
                  ('RoleARN','arn:aws:iam::365470560685:role/unreviewed-service-role')]
        for field,value in variants:
            with self.subTest(field=field):
                metadata=copy.deepcopy(self.metadata);metadata[field]=value
                self.assertFalse(all(v.verify(self.current,self.proposed,metadata).values()))

    def test_failed_executed_or_paginated_partial_report_is_rejected(self):
        for field,value in [('Status','FAILED'),('ExecutionStatus','EXECUTE_COMPLETE'),('NextToken','synthetic-next-page')]:
            with self.subTest(field=field):
                metadata=copy.deepcopy(self.metadata);metadata[field]=value
                self.assertFalse(all(v.verify(self.current,self.proposed,metadata).values()))

    def test_unrecognized_format_and_incomplete_responses_do_not_pass(self):
        self.assertFalse(v.template_matches({'TemplateBody':'incomplete YAML'},'proposed'))
        self.assertFalse(all(v.verify({},self.proposed,self.metadata).values()))
        self.assertFalse(all(v.verify(self.current,self.proposed,{}).values()))


if __name__ == '__main__':
    unittest.main()
