# Receive-first IAM and runtime — review package, NOT deployed

This supersedes the runtime IAM lifecycle described in older phase reports. Account 365470560685 / eu-west-1 only. Existing SNS/KMS/SQS/S3 ingress resources remain unchanged. No local script here creates an AWS change set, uploads templates or executes AWS operations.

## Ordered review

1. `template.yaml` in this directory is an **UPDATE of the existing IAM foundation stack**, not a second foundation. Use the current SSO AdministratorAccess console session; do not set the limited ingress executor as this IAM stack's service role. Review CAPABILITY_NAMED_IAM. Expected: Add three retained runtime roles and Modify only the existing execution role's policy list. Existing recovery role, its trust/policy, both existing role names, original ingress inline policy and explicit KMS destruction denials remain identical. This preparation is not a live change-set comparison.
2. Leave `GrantSocialSend=false`. Initial sender has no SendWhatsAppMessage grant. This role's future grant is restricted to the operator-confirmed demo phone ARN. No IAM users or access keys are created. Roles trust Lambda, not the web host or an administrative user.
3. The updated CloudFormation executor can provision the receive-only resources and pass **only the inbound role to Lambda**. It cannot create/rewrite IAM roles, fetch/initialize secret values, invoke Bedrock or send WhatsApp. Outbound provisioning requires a separately reviewed permission update; do not set DeployOutbound=true under this receive-only execution policy.
4. Package the reviewed runtime from a clean pinned module commit. Upload of its ZIP/template, private secret initialization, installation of staging, deployment and consumer activation each remain subject to the user's explicit authorization. Prepared request JSON intentionally has a pending artifact key and is not ready to submit unchanged.
5. Update existing stack `muserelay-aws-bridge-stage` with the root runtime template. Select `muserelay-aws-bridge-stage-cfn-execution` as service role. Set DeployProcessing=true, DeployOutbound=false, ProcessingEnabled=false, ReceiveEnabled=false, SendEnabled=false, LiveSend=false. Binding disabled and tester allowlist empty. Use actual copied phone/topic IDs; no new WABA/number or SNS topic.
6. Before any activation inspect the actual AWS change set. Existing 12 ingress resources must not be replaced or deleted. The receive-only template has 16 declared resources, expanded to 17 including the disabled SQS mapping: the existing 12 plus a state table, one Lambda, its logs, an empty signing secret and its mapping. No HTTP API, outgoing SQS queues, agent, Bedrock call or sender deployment.
7. Only after separate approval and a working authenticated staging inbox: initialize the Core-direction signing key privately on both ends, opt in exactly Screen Art Demo, fill the tester allowlist privately, enable receive gates and only the inbound mapping. Do not use the business sender number as an assumed tester recipient.

## IAM review boundaries

`policies.review.json` lists the exact actions/resources generated into the IAM update. Runtime CRUD/data permissions are confined to its state table, queue, signing secret and logs. The enqueuer's future secret is independently named `signing-outbound`, so it cannot obtain the Core-direction signing key.

Two explicit provisioning exceptions need Resource="*": CreateEventSourceMapping requires it and is constrained by exact lambda:FunctionArn + region; DescribeLogGroups is regional read-only metadata. Existing ingress KMS wildcard exceptions are unchanged. No AdministratorAccess policy or wildcard service action is added.

The executor may read only `releases/*/bridge.zip` in the existing artifact bucket. Upload permissions belong to the operator, not the runtime. Foundation roles are retained. External secret ARN parameters must use the dedicated names matched by the role policies, not an arbitrary secret in this account.

AWS service-side permissions, organization SCPs/boundaries, Lambda quotas and resource existence have not been tested by this local preparation. In particular the account must have capacity for one reserved execution plus the 100 unreserved concurrency minimum. Reconcile an AccessDenied with the exact CloudFormation event; do not broaden policies blindly.

## Bedrock and future output

`bedrock-inference.prepared.yaml` is a **separate future IAM-only template**, not part of the receive-first changes. It defines an independent Lambda inference role and requires verified profile and destination model ARNs. It invokes only the chosen Nova Lite/Micro resources and has no SQS/Social/IAM permission. It does not deploy an inference adapter or make Laravel automatically use a Lambda role. Current production Bedrock authentication remains untouched.

Future outbound requires DeployOutbound=true, separate role pass/provisioning permissions and `GrantSocialSend=true` only after explicit approval; the enqueue/sender gates additionally require ProcessingEnabled, SendEnabled and LiveSend. The dedicated `signing-outbound` secret must be initialized separately. Keep all flags false now.

## Cost and rollback

No AWS cost is incurred by this local preparation. IAM roles have no standing charge. For an authorized four-day, low-volume receive-only demo, allow **USD 1 incremental reserve**, excluding existing services/host, tax and unexpected traffic; request a **USD 5 operational budget** before activation. This is an estimate, not an AWS spending cap. One secret's listed storage price is USD 0.40/month prorated by hours (about USD 0.053 for four days), plus requests. Existing SNS customer-managed KMS key storage remains about USD 1/month (about USD 0.13/four days), plus operations. Add SQS polling, Lambda, DynamoDB, logs, S3, SNS and data-transfer usage; do not assume free tier eligibility. No Bedrock or WhatsApp sends are included in receive-only. Ongoing polling and retained resources continue costing after a short demo if left in place.

Rates checked 2026-10-25: https://aws.amazon.com/secrets-manager/pricing/ ; https://aws.amazon.com/kms/pricing/ ; https://aws.amazon.com/lambda/pricing/ ; https://aws.amazon.com/sqs/pricing/ . Validate actual Ireland/account prices before approval.

Operational rollback: disable only the inbound mapping, revoke receive/organization/binding gates, preserve evidence and queue contents, and stop only the staging application processes. No shared production service reload or database restore. Pending/uncertain commands are never replayed automatically.

Infrastructure rollback: compare a change set restoring the ingress-only template, preserving the original 12 resources. State table and signing secret have Retain and need a separate reviewed data/key disposal plan. Before removing the extra executor policy, remove its runtime resources or keep a limited rollback role; otherwise CloudFormation cannot manage them. Do not remove runtime roles while Lambda still references them. Retain means deleting the IAM stack does not revoke retained roles. Never schedule deletion of the existing SNS key as part of this rollback.

Sources: Lambda authorization https://docs.aws.amazon.com/service-authorization/latest/reference/list_lambda.html ; Social https://docs.aws.amazon.com/service-authorization/latest/reference/list_socialmessaging.html ; Bedrock profiles https://docs.aws.amazon.com/bedrock/latest/userguide/inference-profiles-prereq.html .
