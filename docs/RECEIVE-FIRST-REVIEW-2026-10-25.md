# Receive-first review — 25 October 2026, Europe/Paris

Status: the three authorized LOCAL preparations are complete. No AWS change set was created/executed, no resource uploaded/deployed, no SQS message received, no real inference/send, no production installation, credentials change or service reload. All live deployment/activation remains subject to separate approval.

## Code deliveries

1. `afacdc42f84fa8f971384ce6387adfd6f2c635ff`: independent PHP receive/agent/send controls, receive-only native persistence without an agent dependency, outbound/tool guards, fail-closed Lambda handler gates and tests. All defaults false. Connector development version 0.5.0; installed production 0.4.0 untouched.
2. `419e05760f15792dc5b6c40a9af4bdaa4d404d6f`: separately provisioned runtime IAM identities; receive-only deployment without outbound resources; directional HMAC secrets; future independent inference-role proposal; local review requests, policy inventory and validation.
3. `12c788dceba061c820d055abb9288a22a19f1ea5`: isolated native inbox preparation, real PG/Redis/HTTP-kernel tests, Chromium DOM/JS proof, scope/authentication tests and separate Channel-model recognition patch. No replacement inbox, new customer platform or new production evaluator account.

The current Core ref is `594a042c916120e353820ecbe12631473006abb2`. Its only change relative to the prior audited `7252eac087973d12d243299cf8d0f03569cfddc2` is seven documentation lines from another session. Existing dirty GeoIP data and untracked contacts backup were left untouched. The exported test copy applies only `integration/muserelay/core-provider-recognition.patch` to Channel::isConfigured; native inbox views/controllers remain unchanged. That patch requires explicit production review/approval.

## Acceptance evidence

- PHP conformance: 36 tests, 137 assertions, passed on SQLite and private PG.
- Node: 35 tests passed using controlled SDK transports.
- Python infrastructure/preparation: 17 tests passed; no AWS calls.
- CloudFormation lint: runtime, receive IAM update and future inference-role proposal passed without warnings/errors. Effective AWS permissions/quotas remain unverified.
- Native suite with PostgreSQL 16 / Redis / real local Laravel workers passed against original reviewed Core baseline, mocked AWS/Bedrock. Root `/tmp/muserelay-bridge-phase3-hefescug`; services stopped.
- Native inbox HTTP suite: 24 checks passed on isolated PG/Redis, including no-session reception, actual native contact/conversation/message, deduplication, zero outbox/tickets, unchanged Meta configuration recognition, native list/detail, existing foreign-org denial, login/CSRF and closed send/admin endpoints.
- Chromium 156.0.8078.4 / Playwright 1.64.1: 28 bounded requests, zero page JS errors, the synthetic message visible in the unchanged native UI. Responses are real PHP/PG results over private pipes; browser network intercepted. Real AWS/TLS/DNS/production access not tested.
- UI root `/tmp/muserelay-bridge-panel-xensqhru`; screenshot SHA256 `76b68d55be0465a32517ae1c1f50d082cc1faee9d31eabfd314cc8a67a814c8b`. All config gates false after cleanup; PG/Redis PID files absent. Never upload the private HTML/session/password/key artifacts.
- Committed history screening after delivery 3: PASS, 576 stored Git objects and 191 working files, zero heuristic findings. It does not cover ignored/private artifacts, remote-only history or external LFS, and is not a guarantee.

## Exact initial AWS review scope

- IAM foundation UPDATE proposal: retain existing two roles/trust/recovery and original ingress inline policy; add inbound/enqueue/sender roles; extend only the executor for receive-only provisioning. GrantSocialSend=false. The executor cannot create/rewrite IAM roles, obtain signing values, invoke Bedrock or send WhatsApp.
- Runtime UPDATE proposal: preserve existing 12 ingress resources. Add state table, inbound Lambda, own logs, empty Core-signing secret and a disabled SQS mapping (five resources after SAM expansion). No API, outbound queues, enqueuer/sender function or inference in this first deployment.
- Required flags: DeployProcessing=true only for a separately authorized deployment; DeployOutbound=false, ProcessingEnabled=false, ReceiveEnabled=false, SendEnabled=false, LiveSend=false. Binding disabled and recipient list empty. The artifact key is intentionally pending until a pinned local ZIP is generated/reviewed and its upload authorized.
- Detailed exact actions/resources and future inference-role proposal: `deploy/runtime-iam/`. Console request JSON files are local preparations, not real AWS change sets. No IAM users or long-lived access keys are required.

## Cost, remaining risks and first live gates

Local preparation costs no AWS usage. For four days of a handful of authorized receive-only messages, propose USD 1 incremental reserve and USD 5 operational budget for approval (not a spending cap; excludes existing host/services/tax and unexpected traffic). IAM has no standing charge. One Secrets Manager secret is about USD 0.053/four days at USD 0.40/month; existing KMS key about USD 0.13/four days at USD 1/month, plus usage. Add incoming Social, SNS/SQS polling, Lambda, DDB/logs/S3 and transfer charges. Retained resources keep costing until separately removed. Official rate links and assumptions are in `deploy/runtime-iam/README.md`.

Before a real test:

1. Review release hashes, the extra Channel hook, the staging service/network/proxy proposal and isolated fixture limitations. Install neither into production nor system services yet.
2. Authorize staging installation and temporary DNS/TLS/proxy changes separately. Verify private DB/Redis identity, non-superuser SQL role and recovery. This minimal synthetic schema is not a full production clone.
3. Review and authorize IAM then the disabled runtime. Check real AWS changes/SCPs/quota; one reserved Lambda execution needs room beyond the 100-unreserved minimum. Do not automatically alter other functions' reservations.
4. Initialize fresh HMAC material privately, populate the real tester recipient and verify endpoint authentication. No Core APP_KEY or Bedrock/admin key reuse.
5. Explicitly resolve the TWO existing SQS items. An enabled Standard queue mapping may receive/acknowledge them before a new message. Either authorize that scope or approve a separate queue/subscription/IAM design; no purge/move/ReceiveMessage now.
6. Authorize only demo receive flags/mapping and a bounded own SQL drain. Prove one real phone-to-native-inbox message with metadata correlation, no agent/output.
7. Later phases: human reply through AWS, then independent Bedrock + confirmed native ticket + handoff. Calendar, BYO onboarding and managed commercial accounts remain deferred.

## Final user/organization and production transition

The owner will manually create the evaluator/new organization in the current MuseRelay. The organization/workspace owns the logical AWS channel/data; the user is an operator, and reception does not need its session. Legal WABA ownership remains Screen Art's Meta/AWS account. Existing panels/brands and native inbox remain the final access point. The temporary hostname/copy is internal testing only.

The production channel selector/form, per-evaluator management restriction and deployment metadata are NOT implemented or activated here. It must expose account/region/WABA/phone/role reference/status, never permanent AWS access-key fields. A diagnostic connection test must not silently receive/send messages, invoke a model or disclose secrets.

The runtime currently allows only the technical staging CoreBaseUrl. Transition to the current MuseRelay needs a reviewed endpoint/allowlist and actual organization/workspace/channel IDs, approved module/core-hook installation and bounded access checks. Do not repoint the test database/Redis to production or copy test encryption keys to reuse the staging instance as the product.

Current Core Bedrock authentication and encrypted PostgreSQL credentials remain unchanged. Renewable identity/Vault/Google-backed encryption migration is a later design with bootstrap/availability/recovery/rotation and legacy-ciphertext compatibility review.

## Independent rollback

- Normal disconnect: stop only demo inbound mapping/drain, disable receive/master/organization/binding gates; output/agent stay off. Preserve messages/evidence. Existing Meta channels continue under their native transport.
- Before code downgrade, turn OFF all master/binding/org gates and mappings: 0.4.0 lacks the new operation controls. Revert only reviewed module/core-hook files against the then-current Core, never overwrite concurrent work or restore the whole production DB to disconnect a module.
- Infrastructure rollback proposal: reviewed UPDATE back to ingress-only, preserving original 12 resources. DDB/secret are Retain; reintroducing resources with the same names may require CloudFormation import/reuse and another review. Do not promise that toggling DeployProcessing back on will automatically adopt retained resources.
- Keep runtime roles declared while referenced, and do not orphan retained named roles by blindly restoring an old IAM template; revoke unused grants after resources are detached, under a reviewed update. Recovery role/KMS administration remains unchanged. No AWS rollback is claimed tested because no runtime was deployed by this work.
