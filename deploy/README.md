# Deployment files

**Current:**

| File | Stack |
| --- | --- |
| [../template.yaml](../template.yaml) | Runtime stack `muserelay-aws-bridge-stage` (SNS, SQS, Lambdas, API, DynamoDB, KMS, secrets) |
| [send-iam/template.yaml](send-iam/template.yaml) | IAM foundation: the three runtime roles, with send permission limited to the demo phone ARN |
| [sns-bootstrap/template.yaml](sns-bootstrap/template.yaml) | First stage that created the encrypted ingress of the same stack |

**History:** the other folders hold the change sets, parameters and IAM before/after diffs prepared and reviewed for each step. They stay in place because the review scripts in `scripts/` and the tests in `test/phase4/` read them.
