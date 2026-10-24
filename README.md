# MuseRelay AWS Engagement Bridge

Private development module for Screen Art S.L.'s AWS CDS Agentic AI Partner Hackathon project.
Created on 2026-10-24. This repository contains new bridge code, not a copy of the proprietary MuseRelay application.

## Current status — transport phase, not a completed MVP

Implemented locally: official AWS Social Messaging SDK adapter, text/status event normalization, explicitly scoped channel bindings, HMAC transport, DynamoDB idempotency/leases/nonces/demo budgets, SQS handlers, isolated infrastructure template and PHP/Node signature interoperability.

**Not deployed, no real WhatsApp sends, no AWS resource creation, no real Bedrock calls or ticket creation performed by this module yet.** The MuseRelay ingestion/receipt controllers, durable Core inbox/outbox and agent/bandeja connector are the next development phase. Until those are implemented and tested, this is not an end-to-end working demo. No existing reseller, Meta, Telegram or other channel is changed.

## Architecture

```mermaid
flowchart LR
  WhatsApp --> Social[AWS End User Messaging Social]
  Social --> SNS[SNS standard topic]
  SNS --> IQ[SQS inbound + DLQ]
  IQ --> Inbound[Inbound Lambda]
  Inbound -->|signed event| Core[MuseRelay Bridge API - NEXT PHASE]
  Core --> Agent[Existing MuseRelay agent / Bedrock / tools]
  Agent --> Ticket[Existing ticket system]
  Core -->|signed command| API[API Gateway + Enqueue Lambda]
  API --> OQ[SQS outbound + DLQ]
  OQ --> Sender[Sender Lambda]
  Sender --> SDK[SendWhatsAppMessage]
  SDK --> Social
  Sender -->|signed receipt| Core
```

`src/social.ts` calls `SocialMessagingClient.send(new SendWhatsAppMessageCommand(...))`. It uses Lambda's IAM role, never a Meta access token. SDK automatic retries for sends are disabled. API acceptance is not delivery: status events must update the Core message independently.

## Local checks — no production application bootstrap

Requirements: Node 22 recommended (local development also tested on Node 20), npm, PHP 8.1+ for the interoperability tests. No database, Redis, `.env`, AWS account or credentials needed for the tests.

```sh
npm ci --ignore-scripts
npm test
npm run bundle
```

Tests use synthetic identities and an in-process SDK HTTP transport. They do not contact AWS or Meta and do not run MuseRelay's artisan/phpunit bootstrap. DynamoDB tests mock the document transport; persistence behavior in real DynamoDB still requires an authorized staging test.

`package-lock.json` pins the resolved dependencies. Runtime artifacts are produced in `build/`; they are not committed. The SAM template deploys Node 22 arm64 functions with one reserved execution each.

## Configuration and secrets

See `examples/bindings.example.json` and `.env.example`. The module does not automatically load any `.env` file. Each binding maps an exact SNS topic, WABA, Meta phone id and AWS phone ARN to one opaque MuseRelay channel reference. Organization/workspace/agent ownership is resolved and enforced by Core, never chosen by the incoming body. Use one dedicated demo identity and synthetic organization.

Two independent HMAC keys of at least 32 bytes per binding are required in an **existing Secrets Manager secret**. Its JSON structure is `{ "binding-id": { "core": "...", "outbound": "..." } }`. Generate keys outside source control. Never reuse the production `APP_KEY`, Meta tokens or reseller SMTP credentials. The keys are cached for at most 60 seconds by each warm function. IAM limits access to the provided secret ARN. Customer-managed KMS encryption for this secret additionally needs a scoped `kms:Decrypt` grant; the current template assumes the default Secrets Manager key.

The first deployment must use `enabled:false` and `LiveSend=false`. Allowed recipients default to an empty array. The live-send flag alone cannot bypass a disabled binding or the recipient allowlist.

## Provisioning — requires separate authorization

Do not deploy to the production hosting server. Use a reviewed AWS account and region `eu-west-1`, after confirming current Social/Meta version and number eligibility.

1. Implement the Core contract in `docs/CORE-INTEGRATION.md` in a separate MuseRelay worktree, with its feature flag off. Test in an isolated application/database first.
2. Resolve WABA linkage separately using a new non-production number. Obtain the WABA id, Meta phone id and **AWS phone ARN**; they are different identifiers.
3. Prepare the signing secret. Build with `npm ci --ignore-scripts && npm run bundle`.
4. Review `template.yaml`. Plan the topic ARN as `arn:aws:sns:eu-west-1:ACCOUNT:STACK-inbound`, matching `BindingsJson`. Use a dedicated stack name.
5. After approval, deploy the template using SAM/CloudFormation and the documented parameters. No deploy command is run by npm scripts. IAM resource creation requires acknowledgment.
6. After approval, associate the resulting SNS topic as the WABA event destination. This is a separate configuration action, not automated here. Avoid replacing the destination on a production WABA.
7. Set the Core API integration, verify signatures and receipts, and only then enable the demo binding and live sends for approved test recipients.

Resources: SNS topic and policy; two queues and two DLQs; DynamoDB state table; three Lambda functions with scoped roles; HTTP API; three seven-day log groups. The SNS topic currently uses service-default storage encryption behavior without a customer-managed key; SQS and DynamoDB specify encryption. If SNS KMS encryption is required, add a customer-managed key and the Social publisher grants first, not `alias/aws/sns` without verification.

IAM: only the sender role receives `social-messaging:SendWhatsAppMessage`, scoped to the provided phone ARN. The ingress role cannot send WhatsApp. SNS publication is restricted to Social in the stack account. Queue publication is restricted to the topic. No WABA-registration or IAM-management permissions are granted to runtime functions. API `/outbound` is publicly reachable only after an authorized deployment; every request must pass application HMAC verification before enqueueing. No CORS or unauthenticated send path is provided.

## Delivery semantics and limits

- Duplicates use the provider message id, not the SNS notification id. Core must independently persist the same idempotency key before acknowledging an event.
- Inbound leases can be reclaimed after 180 seconds. Outbound leases cannot: a dead sender might already have sent the message. Expired outbound leases become `uncertain` for manual reconciliation.
- Outbound payload changes under the same command id are rejected. After AWS accepts a command, a receipt callback retry cannot resend it.
- Known throttle rejections can retry via SQS; unknown provider failures are uncertain. Do not manually redrive an uncertain command under a new id without checking it.
- Default budgets are 40 inbound demo message attempts and 20 outbound send attempts per binding per UTC day, configurable up to 100. Retries may consume extra budget. These are demo controls, not a complete SaaS billing system or hard limit on every AWS infrastructure cost.
- Status messages do not invoke the agent. Text only; media and other notifications do not generate automatic replies. Events for unallowlisted users are ignored in this restricted demo.
- State hashes/identifiers expire after 30 days. Replays after the retention period require the independent Core deduplication policy. Queue payloads contain customer text and require restricted administration; application logs intentionally omit it.
- Ordering across standard SNS/SQS messages is not guaranteed. Core must serialize turns per conversation and deal with late status transitions. Do not claim this is already implemented here.
- The existing agent/tool engine and its historical failover/idempotency limitations remain pre-existing dependencies. Transport deduplication alone does not make ticket/tool execution exactly once.

## Real end-to-end acceptance — pending authorization/resources

Use an approved demo organization, ticket inbox, agent with Bedrock configured and cultural activity knowledge. Ask about an activity and request registration. The agent must explicitly create a **request/ticket**, not claim a confirmed seat or booking. Follow the existing tool's confirmation and required-contact rules.

Evidence must correlate: inbound WhatsApp provider id → SNS event → Core conversation/message → actual Bedrock provider/model → `support_create_ticket` call → persisted ticket/audit → outbound command → SDK message id → handset delivery/read event. Use synthetic content in captures.

Also test invalid HMAC, replay, cross-binding requests, duplicate SNS events, repeated commands, Core outage, explicit throttling, sender crash and uncertain sends. Demonstrate deployment in a clean test stack. Mocks are local test evidence only.

## Originality, judging and repository access

See `docs/PROVENANCE.md`. Keep repository private. No remote, invitation, publishing or judge access has been configured by this development phase. Provide authorized judges the bridge source and a working isolated MuseRelay evaluation instance; do not publish the entire legacy SaaS to make this repository look standalone. A simulator cannot replace the final real workflow.

README, diagram, setup, limits, source versions, test instructions, video script and required APN/ACE submission details should accompany the final submission. See `docs/DEMO.md`. BYO AWS/STS, SES, UI onboarding and Marketplace are not implemented or claimed by this phase.

## Removal after authorized testing

Disable live sends and bindings first; coordinate removing the WABA event destination, then remove the reviewed stack. DynamoDB is retained intentionally to preserve uncertain-send evidence. Inspect and remove retained data and the external signing secret only after the retention/review decision. Never use this procedure on the production WABA.
