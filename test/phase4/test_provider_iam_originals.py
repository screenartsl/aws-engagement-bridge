"""Full-Original verification and negative metadata cases, synthetic only."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest
import yaml
from types import SimpleNamespace
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('provider_originals',ROOT/'scripts/cloudformation/verify-provider-iam-originals.py')
v=importlib.util.module_from_spec(spec);spec.loader.exec_module(v)


class ProviderOriginalsTests(unittest.TestCase):
    def setUp(self):
        self.current={'TemplateBody':(ROOT/'deploy/runtime-sam-iam/template.yaml').read_text()}
        self.proposed={'TemplateBody':(ROOT/'deploy/runtime-provider-iam/template.yaml').read_text()}
        parameters=[{'ParameterKey':'GrantSocialSend','ParameterValue':'false'}]
        self.stack={'Stacks':[{'StackName':v.STACK,'StackId':v.PREFIX+'stack/'+v.STACK+'/synthetic',
            'StackStatus':'UPDATE_COMPLETE','Parameters':copy.deepcopy(parameters)}]}
        self.change={'StackName':v.STACK,'StackId':self.stack['Stacks'][0]['StackId'],
            'ChangeSetName':v.CHANGE,'ChangeSetId':v.PREFIX+'changeSet/'+v.CHANGE+'/synthetic',
            'Status':'CREATE_COMPLETE','ExecutionStatus':'AVAILABLE','Parameters':parameters,
            'Changes':[{'ResourceChange':{'Action':'Modify','LogicalResourceId':'StageCloudFormationRole',
                'PhysicalResourceId':v.ROLE_NAME,'ResourceType':'AWS::IAM::Role','Replacement':'False','Scope':['Properties']}}]}

    def ok(self):return all(v.verify(self.current,self.proposed,self.change,self.stack).values())

    def test_accepts_exact_whole_originals_and_metadata(self):self.assertTrue(self.ok())

    def test_accepts_json_body_and_different_json_whitespace(self):
        self.current['TemplateBody']=yaml.safe_load(self.current['TemplateBody'])
        self.proposed['TemplateBody']=json.dumps(yaml.safe_load(self.proposed['TemplateBody']),indent=4)
        self.assertTrue(self.ok())

    def test_rejects_widened_logs_secret_mapping_or_removed_kms_deny(self):
        source=yaml.safe_load(self.proposed['TemplateBody'])
        for sid in ['OwnSecretProviderRead','OwnMappingSystemTagBootstrap','RegionalProviderPolicyMetadata']:
            mutated=copy.deepcopy(source)
            statement=next(s for s in mutated['Resources']['StageCloudFormationRole']['Properties']['Policies'][1]['PolicyDocument']['Statement'] if s['Sid']==sid)
            statement.pop('Condition')
            self.proposed['TemplateBody']=mutated
            self.assertFalse(self.ok())
        mutated=copy.deepcopy(source)
        p=mutated['Resources']['StageCloudFormationRole']['Properties']['Policies'][0]['PolicyDocument']
        p['Statement']=[s for s in p['Statement'] if s['Effect']!='Deny']
        self.proposed['TemplateBody']=mutated;self.assertFalse(self.ok())

    def test_rejects_recovery_or_runtime_role_changes(self):
        source=yaml.safe_load(self.proposed['TemplateBody'])
        for name in ['KmsRecoveryRole','InboundExecutionRole','EnqueueExecutionRole','SenderExecutionRole']:
            changed=copy.deepcopy(source);changed['Resources'][name]['Properties']['AssumeRolePolicyDocument']={}
            self.proposed['TemplateBody']=changed;self.assertFalse(self.ok())

    def test_rejects_extra_changes_replacement_and_tag_scope(self):
        for field,value in [('Replacement','True'),('Scope',['Properties','Tags'])]:
            original=copy.deepcopy(self.change)
            self.change['Changes'][0]['ResourceChange'][field]=value;self.assertFalse(self.ok())
            self.change=original
        self.change['Changes'].append(copy.deepcopy(self.change['Changes'][0]));self.assertFalse(self.ok())

    def test_rejects_send_true_or_unresolved_in_either_actual_or_proposed(self):
        self.change['Parameters'][0]['ParameterValue']='true';self.assertFalse(self.ok())
        self.setUp();self.stack['Stacks'][0]['Parameters'][0]['ParameterValue']='true';self.assertFalse(self.ok())
        self.setUp();self.change['Parameters']=[{'ParameterKey':'GrantSocialSend','UsePreviousValue':True}];self.assertFalse(self.ok())

    def test_rejects_other_account_set_stale_state_and_new_current_service_role(self):
        self.change['ChangeSetId']='arn:aws:cloudformation:eu-west-1:374040321139:changeSet/'+v.CHANGE+'/synthetic'
        self.assertFalse(self.ok())
        self.setUp();self.change['ExecutionStatus']='OBSOLETE';self.assertFalse(self.ok())
        self.setUp();self.stack['Stacks'][0]['RoleARN']='arn:aws:iam::365470560685:role/unreviewed';self.assertFalse(self.ok())

    def test_missing_fields_do_not_pass_or_assume_default_false(self):
        self.change.pop('Parameters');self.assertFalse(self.ok())
        self.setUp();self.stack={'Stacks':[]};self.assertFalse(self.ok())
        self.setUp();self.current={};self.assertFalse(self.ok())

    def test_collect_is_five_fixed_metadata_reads(self):
        calls=[]
        def fake(cmd,**kwargs):
            calls.append((cmd[1],cmd[2]))
            if cmd[1]=='sts':data={'Account':'365470560685','Arn':'arn:aws:sts::365470560685:assumed-role/synthetic/session'}
            elif cmd[2]=='describe-stacks':data=self.stack
            elif cmd[2]=='describe-change-set':data=self.change
            elif cmd[2]=='get-template':data=self.proposed if '--change-set-name' in cmd else self.current
            else:raise AssertionError('Unexpected AWS operation')
            return SimpleNamespace(returncode=0,stdout=json.dumps(data))
        with patch.object(v.subprocess,'run',side_effect=fake):result=v.collect()
        self.assertEqual(len(calls),5)
        self.assertTrue(all(v.verify(*result).values()))

    def test_collect_stops_for_wrong_account_or_root(self):
        for account,arn in [('374040321139','arn:aws:sts::374040321139:assumed-role/synthetic/session'),
                            ('365470560685','arn:aws:iam::365470560685:root')]:
            with patch.object(v.subprocess,'run',return_value=SimpleNamespace(returncode=0,stdout=json.dumps({'Account':account,'Arn':arn}))) as run:
                with self.assertRaises(ValueError):v.collect()
                self.assertEqual(run.call_count,1)


if __name__=='__main__':unittest.main()
