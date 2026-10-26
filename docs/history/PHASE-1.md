# Phase 1 report — 2026-10-24

## Isolation

Independent local Git repository, branch `codex/aws-engagement-bridge`, outside `httpdocs`. Originally created under `.codex/projects/`, which is excluded by lsyncd. Later on 2026-10-24 the user requested relocation to `/var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge`; the repository was moved there intact. The new location is within the existing lsyncd scope, without any change to that service. Replication of these development files does not load or activate the module. No remote is configured. No code, configuration, credentials or dependencies were installed into the live MuseRelay application. No production route, migration, process, cron, infrastructure or channel was changed.

The live application's pre-existing status remained the same at verification: modified `storage/geoip/GeoLite2-Country.mmdb`, untracked `resources/views/contacts/index.blade.php.back`. Those files were not edited by this work.

## Implemented

- `src/social.ts`: official SDK WhatsApp text adapter; explicit AWS phone identity; live flag off by default; no automatic SDK send retries; accepted/rejected/uncertain outcomes.
- `src/normalize.ts`, `src/contracts.ts`: topic/WABA/phone/channel binding, multi-message handling, separate status events, recipient allowlist and payload validation.
- `src/state.ts`, `src/workers.ts`: DynamoDB leases/deduplication, durable receipts, replay prevention, daily demo budgets and callback retries without re-sending accepted messages.
- `src/handlers.ts`, `src/enqueue.ts`: SQS inbound/sender handlers and signed outbound HTTP handler. No public endpoint exists until a separately authorized deployment.
- `src/core-client.ts`: signed Core client with bounded timeout, no redirects and strict durable-ack contract.
- `integration/muserelay/BridgeSignature.php`: pure PHP signer/verifier, not loaded or installed in Core.
- `template.yaml`: SAM/CloudFormation definition, disabled live sends, scoped IAM, SNS, SQS/DLQs, DynamoDB and three Lambdas.
- English README, architecture diagram, Core contract, provenance and three-minute video plan.

## Local verification

2026-10-24: `npm test` passed **30/30 tests**; TypeScript compiled; `npm run bundle` produced the Lambda artifact; PHP syntax checks passed; `cfn-lint` validated `template.yaml` for `eu-west-1` without findings; `npm audit --omit=dev` reported zero known production dependency vulnerabilities.

These checks are local. AWS SDK serialization was executed against an in-process fake HTTP handler; DynamoDB transport and Core were mocked. PHP interoperability ran without Laravel bootstrap. No real AWS API calls, paid sends, ticket creation or Bedrock inference were executed. A passing static template check does not establish account permissions or successful deployment.

## Remaining Core development

Implement the feature-gated API, binding/inbox/outbox persistence, conversation/agent job, native-tool crash recovery and transport wiring for the dedicated AWS channel. Validate AI and manual replies in an isolated application and ensure direct Meta/Telnyx/Telegram regressions do not occur. See `CORE-INTEGRATION.md`. This phase is not a completed E2E MVP.

## External data required for authorized staging

- AWS account and confirmed region/service access.
- Dedicated new WABA/number, WABA id, Meta phone id and AWS phone ARN.
- Supported Meta API version.
- Approved test recipient(s).
- Existing Secrets Manager ARN with separate per-binding signing keys; do not paste keys in chat.
- Isolated MuseRelay evaluation host, organization/workspace, channel reference, Bedrock agent/model, ticket inbox and activity knowledge configuration.
- Separate submission eligibility/ACE and judge repository-access details.

No BYO AWS, SES, Marketplace, new reseller UI, production migration or failover change was implemented.
