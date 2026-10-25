# Receive-first preparation — 25 October 2026 (Europe/Paris)

Status: LOCAL PREPARATION ONLY. No installation, AWS deployment, SQS consumption, real inference or WhatsApp send is authorized by this document.

## Independent controls

The master extension switch, organization opt-in and binding remain mandatory. In addition the PHP connector now requires explicit boolean controls `aws_bridge.receive_enabled`, `agent_enabled`, `send_enabled`; all default to false. Environment forms are `AWS_BRIDGE_RECEIVE_ENABLED`, `AWS_BRIDGE_AGENT_ENABLED`, `AWS_BRIDGE_SEND_ENABLED`. Missing or string-valued config flags fail closed. Installation does not opt in.

Receive-only: validate the organization/workspace/channel and AWS resource tuple, authenticate HMAC, persist the durable event, materialize native contact/conversation/message, and finish without an agent turn. No agent or AI provider is required for receive-only or human replies. Agent-enabled bindings still require an owned Bedrock agent and approved fallback providers. Enabling an agent later does not replay completed receive-only messages.

Sending is guarded at outbox creation and publication, and native outgoing authorization. Disabling send preserves pending commands without publishing. Disabling the agent also blocks tools in existing scopes. Previously dispatched network requests cannot be recalled; uncertain sends require reconciliation, never automatic resending. Receipt/status persistence remains possible for already-dispatched commands while their relevant processing path is enabled.

Lambda ingress independently requires BRIDGE_RUNTIME_ENABLED=true and BRIDGE_RECEIVE_ENABLED=true. Sender and outbound API additionally require BRIDGE_SEND_ENABLED=true and BRIDGE_LIVE_SEND=true. Do not enable a polling event-source mapping against a disabled handler: failed attempts would still increase SQS receive counts. The runtime template must use separate inbound/outbound consumer gates.

## User-confirmed identifiers

Source: operator-provided AWS console copy, not a direct AWS API verification by this agent. Original malformed/truncated ARNs were rejected and corrected by the operator before use.

- AWS account: 365470560685; region eu-west-1.
- Demo business number: +34 871 99 91 69.
- Social phone ARN: arn:aws:social-messaging:eu-west-1:365470560685:phone-number-id/b1e7f9e53c454f15be065a2e2b5a1f00
- Meta phone ID: 1383587584839516.
- Meta WABA ID: 29207063275646749.
- AWS linked WABA ID: waba-3fe299c6d9f34872bb25fcf4c0a5c44b.
- SNS ARN: arn:aws:sns:eu-west-1:365470560685:muserelay-aws-bridge-stage-inbound.

The business sender is not the recipient allowlist. The tester's authorized receiving phone remains to be supplied privately; synthetic recipients are used in local tests. No Meta token is required by the AWS Social sender.

## Local verification for delivery 1

- PHP connector: 36 tests / 137 assertions (SQLite).
- Node: 35 passing tests; mocked AWS transports only.
- Disposable native integration: PASS on PostgreSQL 16 and Redis with real local Laravel workers, network namespace isolated and AWS transports mocked. Evidence root `/tmp/muserelay-bridge-phase3-d992vd4s`; all owned services stopped. This run tested the operational changes before the local package version was bumped from 0.4.0 to 0.5.0.
- Installed production release remains 0.4.0; development 0.5.0 is not installed. No full browser or live AWS result is claimed.

## Acceptance and rollback

1. Local synthetic tests first: duplicate input creates one message, receive-only runs no agent/tools/outbox, disallowed organizations fail, and missing flags prevent work.
2. Production remains the installed old release, disabled; changes to this development folder do not install it.
3. Future separate authorizations: install isolated staging, deploy disabled runtime, then enable only demo reception. Record source commits, real stack change set, artifacts, costs and rollback before approval.
4. Disable the inbound event-source mapping and PHP receive gate/binding to stop reception. Disable sender mapping, send gates and LiveSend to stop output independently. No production-wide database restore or shared-service reload is required to disconnect.
5. Source rollback: FIRST disable the master extension, clear organization opt-in, disable bindings and both AWS mappings; then revert the identified delivery commit, re-run isolated checks, and install only after separate approval. Never downgrade an enabled receive-only configuration to 0.4.0: that release does not have the independent gates. New controls default false on older configuration; review explicit settings before reactivation. Preserve event/outbox records for reconciliation.

## Deferred security roadmap

Do not change the current Core Bedrock authentication, providers or encrypted PostgreSQL credentials for this work. Future renewable credentials must resolve account/tenant/purpose explicitly. Runtime messaging and inference identities are separate from each other and CloudFormation administration.

The operator reports a Screen Art Vault integrated with Google services. No access or configuration was audited here. A future encryption design must study bootstrap authentication, availability/cache limits, recovery and break-glass, compatibility with existing Laravel ciphertext, old-key access during rotation, rollback and key backup. Fetching a different APP_KEY is not a migration strategy. Do not implement or store Vault credentials in this module.

Commercial third-party onboarding, managed-AWS resale, Marketplace, calendar enablement and large-scale capacity are deferred. BYO preparation is a connection/identity boundary, not a claim of delivered commercial onboarding.

## Product clarification from the owner

The judges must ultimately use an evaluator user manually created by the owner in the existing MuseRelay installation, limited to Screen Art Demo / its workspace and AWS channel. The native inbox, contacts, conversations and messages are reused. The temporary source copy is an internal isolation test, not a second product, a replacement inbox, or the planned commercial entry point. No evaluator user is created in production by this preparation.

A future reviewed WhatsApp channel form should offer AWS End User Messaging Social only to the authorized demo scope, configuring an owned connection (account/region/role reference/WABA/phone/topic metadata). It must not request permanent AWS access keys, automatically reuse Bedrock credentials or expose signing keys. The user will create the evaluator manually. Neither this form nor a production activation is implemented by the local staging work. Review user/workspace permissions in addition to organization opt-in; a hidden menu alone is not authorization.
