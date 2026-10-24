# Exact prerequisites for a real AWS staging conversation

Status: **not provisioned or live-tested by this phase**. No AWS account session or Meta permission review was executed here. Unknown identifiers below must be supplied/verified through an authorized operator; the synthetic account `111111111111` and all demo identifiers are fixtures, not Screen Art S.L. credentials. Preparing this list does not authorize provisioning, association, permission changes, paid inference or messages.

## Resources

The reviewed `template.yaml`, restricted to `eu-west-1`, expands offline to the following 23 CloudFormation resources. Suggested future stack name: `muserelay-aws-bridge-stage` (keep stack name short enough for Lambda's 64-character function-name limit).

| Type | Count | Purpose / logical identifiers |
| --- | ---: | --- |
| SNS standard topic | 1 | `InboundTopic`; `${StackName}-inbound` |
| SNS topic policy | 1 | `TopicPolicy`; Social service publish, exact account/source service ARN |
| SNS subscription | 1 | `Subscription`; topic → inbound SQS, raw delivery **false** |
| SQS queues | 4 | `InboundQueue`, `OutboundQueue`, `InboundDlq`, `OutboundDlq` |
| SQS resource policy | 1 | `InboundQueuePolicy`; only the exact SNS topic/account may publish |
| DynamoDB table | 1 | `State`; `pk` string, TTL `expiresAt`, on-demand, SSE, PITR, retained on deletion |
| Lambda functions | 3 | `InboundFunction`, `EnqueueFunction`, `SenderFunction`; Node 22/arm64, 256 MB, 30 s timeout, reserved concurrency 1 each |
| IAM execution roles | 3 | `InboundExecutionRole`, `EnqueueExecutionRole`, `SenderExecutionRole`; explicit scoped policies |
| Lambda SQS event mappings | 2 | Inbound/sender; batch 1, partial batch failure reporting |
| Lambda invoke permission | 1 | API Gateway → enqueue function |
| API Gateway v2 HTTP API | 1 | `HttpApi`, inline OpenAPI route/integration `POST /outbound`; HMAC authenticated by handler |
| API stage | 1 | Generated default stage; 1 request/s, burst 2 |
| CloudWatch log groups | 3 | Per-function groups, 7-day retention |

**Outside this template:** one demo WABA/number linked to Social, its enabled event destination, one Secrets Manager secret with per-binding directional HMAC keys, an S3 artifact bucket/object for SAM packaging, a deployment identity/role and an isolated MuseRelay staging endpoint/application. A dedicated Bedrock inference identity is also required on the Core side. The template does not create a VPC, EC2/RDS/ElastiCache instance, Bedrock Agent, Knowledge Base, SES or RCS resource. Existing or externally managed dedicated staging hosting can run the connector.

The queues have managed encryption, 180 s visibility and 5 receives before DLQ; main queues retain 4 days, DLQs 14 days. SNS SSE is not enabled in this template. If a customer managed key is selected for SNS or Secrets Manager, extend the exact key policies/runtime grants before deployment; do not assume `alias/aws/sns` permits Social to publish. AWS documents the required Social key grant. [Event destination and KMS policies](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations-add.html).

## IAM runtime: exact grants in this template

All three roles trust only `lambda.amazonaws.com` for `sts:AssumeRole`. No runtime role attaches a broad managed SQS policy; offline expansion checks this explicitly.

| Role/principal | Actions | Resource |
| --- | --- | --- |
| Inbound role | `dynamodb:GetItem`, `PutItem`, `UpdateItem`, `DeleteItem` | Only `State.Arn` |
| Inbound role | `secretsmanager:GetSecretValue` | Only supplied `SecretArn` |
| Inbound role | `sqs:ReceiveMessage`, `DeleteMessage`, `GetQueueAttributes` | Only `InboundQueue.Arn` |
| Enqueue role | `dynamodb:PutItem` | Only `State.Arn` |
| Enqueue role | `secretsmanager:GetSecretValue` | Only `SecretArn` |
| Enqueue role | `sqs:SendMessage` | Only `OutboundQueue.Arn` |
| Sender role | `dynamodb:GetItem`, `PutItem`, `UpdateItem`, `DeleteItem` | Only `State.Arn` |
| Sender role | `secretsmanager:GetSecretValue` | Only `SecretArn` |
| Sender role | `sqs:ReceiveMessage`, `DeleteMessage`, `GetQueueAttributes` | Only `OutboundQueue.Arn` |
| Sender role | `social-messaging:SendWhatsAppMessage` | Only `SocialPhoneArn` |
| Each Lambda role | `logs:CreateLogStream`, `logs:PutLogEvents` | Only its own `/aws/lambda/${StackName}-{inbound,enqueue,sender}:*` log ARN |
| `social-messaging.amazonaws.com` | `sns:Publish` | Only `InboundTopic`, account/source conditions |
| `sns.amazonaws.com` | `sqs:SendMessage` | Only inbound queue, exact topic/account conditions |
| `apigateway.amazonaws.com` | `lambda:InvokeFunction` | Generated route-scoped invoke permission |

CloudFormation creates log groups; runtime roles do not need to create arbitrary groups. A separate deployer needs CloudFormation/SAM change-set operations, S3 artifact access and creation/update/tagging/deletion/read actions for the above resource types, including `iam:PassRole` **only for these execution roles**, with `iam:PassedToService=lambda.amazonaws.com`. The final exact deployment policy depends on the chosen artifact bucket, stack ARN, role paths, boundaries/SCPs and KMS choices; these inputs are presently unknown. Do not claim this account is deployable until an authorized operator reviews the expanded change set and identity policy. Runtime permissions above are fully enumerated; deployer permissions are not granted or verified by this repository.

Social setup is an **operator** task, separate from sender runtime: `social-messaging:AssociateWhatsAppBusinessAccount`, `ListLinkedWhatsAppBusinessAccounts`, `GetLinkedWhatsAppBusinessAccount`, `GetLinkedWhatsAppBusinessAccountPhoneNumber`, `PutWhatsAppBusinessAccountEventDestinations`, and tagging actions if used. Scope resource-capable operations to the demo WABA/phone; list/association operations require the service's supported scope. The current authorization reference also lists conditional tagging/`iam:PassRole` dependencies for association; grant PassRole only for an actual selected integration role, not unrestricted roles. [Official IAM operations/resource types](https://docs.aws.amazon.com/service-authorization/latest/reference/list_socialmessaging.html).

For Bedrock, the existing Core adapter uses **Converse**. Give the separate staging inference identity `bedrock:InvokeModel` on the chosen model/inference profile and its permitted destination model ARNs; add `bedrock:InvokeModelWithResponseStream` only if its selected path uses streaming. `bedrock:Converse` is not the IAM grant to invent. Core maps `nova-lite` to `eu.amazon.nova-lite-v1:0` and classifier `nova-micro` to `eu.amazon.nova-micro-v1:0`; verify actual account access, quotas and the profile's EU destinations before inference. Third-party fallback models may introduce subscription/FTU requirements; the demo can keep the chain Amazon-only. [Bedrock inference permissions](https://docs.aws.amazon.com/bedrock/latest/userguide/inference.html).

The existing `BedrockService` constructs explicit credentials from the AIProvider record; this release does not add an automatic instance-role credential chain. Supply **new staging-only** encrypted access key/secret/session token fields through the existing protected provider mechanism (for example, short-lived credentials issued separately to the scoped staging role). Do not reuse production provider credentials, and account for token expiry. No IAM identity or key was created during this phase.

## Identifiers/configuration to collect

| Input | Required value/check | Current evidence |
| --- | --- | --- |
| AWS account id / caller ARN | Actual 12-digit Screen Art demo account, allowed deployment role, boundaries/SCPs | Not authenticated in phase 3 |
| Region | `eu-west-1`; Social availability and Bedrock profile access | Template/connector enforced; account access unverified |
| Meta portfolio / WABA | Dedicated demo assets under an authorized Meta administrator | Not created or migrated |
| AWS linked WABA id/ARN | Actual Social-linked resource; distinct from Meta WABA id | Not available |
| Meta WABA id | Numeric source `entry.id`, exact Core/Lambda binding | Fixture only |
| Meta phone id | Numeric webhook `metadata.phone_number_id` | Fixture only |
| AWS phone ARN | Exact returned `arn:aws:social-messaging:eu-west-1:<account>:phone-number-id/<id>` | Fixture only; validator supports documented hyphenated origination ids |
| Approved customer E.164 | A test participant, allowlisted in both bindings, 24 h reply window opened by their inbound | No real messages sent |
| SNS ARN | Actual stack output `InboundTopicArn`, same account/region as phone | Not deployed |
| Secret ARN / key material | Secret JSON `{ "<binding-id>": { "core": "<random>", "outbound": "<different random>" } }` | No real secret provisioned |
| HTTPS Core origin | Dedicated publicly reachable staging TLS origin; `/api/aws-engagement-bridge/events` and `/receipts`; no redirects/challenges | In-process fixture only; public staging ingress not provisioned |
| Outbound API URL | Stack `OutboundUrl`: exact `https://<api>.execute-api.eu-west-1.amazonaws.com/outbound` | Not deployed |
| Binding ids | Same opaque `id`/`channelRef` in Lambda JSON and Core binding; explicit recipient list; enabled only after review | Synthetic `demo` only |
| Core ownership ids | Dedicated organization, workspace, channel, agent and inbox ids with matching membership | Synthetic org 97101/workspace 10/channel 20/agent 30/inbox 50 |
| Agent/ticket settings | Full executor, Bedrock primary/fallbacks, `ticket_inbox_ids`, native named ticket route and appropriate plan | Exercised in fixture; live stage not installed |
| Bedrock model/profile/identity | Concrete returned profile ARN, destination model ARNs, new scoped credentials, quota and expiry | SDK request/response simulation only |
| Meta API version | Version currently supported by Social at deployment time | `v23.0` is a serialization fixture, not a live compatibility assertion |
| Deployment inputs | `BindingsJson`, `SocialPhoneArn`, `SecretArn`, `CoreBaseUrl`, `MetaApiVersion`, `LiveSend=false`, daily inbound 40/outbound 20 | Source template ready |
| Application configuration | Own DB/Redis/storage/mail/key settings; `AWS_BRIDGE_ENABLED`, organization allowlist, key file, outbound URL; route/config cache refresh | Local harness exercised |

The app Webhook URL registered directly with Meta for existing MuseRelay channels must remain unchanged. Social delivers demo events through SNS/SQS, and AWS workers call the dedicated Core staging endpoints; Core sends its signed commands to API Gateway. Core needs no Meta access token for this AWS transport. The selected AWS registration flow, not MuseRelay's current Meta app permissions, grants AWS access to the demo WABA. No claim is made here about the current app's approved/pending Meta permissions.

To preserve the user's requirement that existing channels keep working, use a dedicated demo WABA/number rather than importing a WABA whose production behavior might change. AWS signup can select/create or migrate an account and requires control/OTP/PIN and enabled event publishing; it is not an audit-only action. A WABA has one event destination covering its associated numbers. [AWS signup prerequisites](https://docs.aws.amazon.com/social-messaging/latest/userguide/getting-started-whatsapp.html), [WABA destination scope](https://docs.aws.amazon.com/social-messaging/latest/userguide/managing-event-destinations.html).

## Live acceptance gate, after specific authorization

1. Confirm account/Meta administrator access, isolated assets and the exact inputs above. Review the expanded stack; leave live sending false until inbound mapping/signatures pass.
2. Associate only demo assets and enable the dedicated SNS destination. Deploy only the reviewed staging stack; install the connector only in dedicated staging.
3. Participant initiates WhatsApp conversation. Correlate one real ingress event, one Core message, Bedrock request, guarded native ticket result, one outbox command and the Social send receipt. Then correlate actual Social/Meta delivery identifiers; the simulation intentionally uses matching ids and cannot prove their production relation.
4. Check pause/disable, duplicate callback and retry behavior with strict test budgets. Preserve sanitized evidence, stop sending, disable bindings/workers and clean up only authorized resources. DynamoDB is retained deliberately and must be explicitly reviewed for later cleanup.

Engineering estimate after all assets/permissions exist: **4–8 working hours** for first real conversation and evidence, plus **0.5–1 day** to handle provider/schema/permission differences and rehearse. OTP, Meta review, account billing restrictions, model access/quotas, IAM/SCP denials and public staging ingress can extend this by days or make the 28 October deadline impossible. Local simulated reproduction is already the fallback; it must not be presented as a real WhatsApp/Bedrock run.
