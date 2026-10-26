> Current native staging evidence: [PHASE-3.md](history/PHASE-3.md). No production or AWS activation is included.

# MuseRelay Core contract and next phase

This contract now has a prepared implementation under `integration/muserelay/src/Core/`, plus `core-hooks.patch` for three existing Core files. It is **not installed or activated**. `BridgeSignature.php` remains the framework-independent signature primitive. The package provider registers routes only when explicitly enabled; it does not auto-register migrations. No production routes, migrations, workers or reseller screens have been changed.

The permanent target is the existing MuseRelay deployment: its reseller/white-label panels, domains, organizations, native inbox/agent/tools and existing database/Redis services. The connector adds five `aws_bridge_*` tables on the Core connection and scoped work/configuration; it does not require a second commercial application, machine, database server, Redis server or hostname. Technical signed routes may use an existing canonical Core HTTPS origin. Temporary phase 4 source/database/Redis/hostname isolation is a test fixture, not the permanent deployment model. See the user-confirmed decision in [PHASE-4.md](history/PHASE-4.md).

Any future production installation, migrations, configuration/cache changes and worker/shared-service reloads require explicit authorization. Before retiring staging, stop its demo or verify that every retained active deployment uses its definitive Core endpoint and resources; removing a still-referenced staging URL would interrupt that deployment. Keep native data and definitive resources outside demo cleanup.

## Minimal connection points

1. The new feature-gated service provider, authenticated event/receipt routes, bindings, durable inbox/outbox and explicit `aws-bridge:drain` worker are prepared in a separate package. Default the feature flag to false. No existing scheduler is modified. Do not execute migrations or install the package in production without approval.
2. A new AWS-only channel must resolve from an internal binding to an existing workspace and organization. The new ingestion service persists the inbound message and emits `InboxBroadcaster::messageReceived()` / conversation events. It reuses `ContactManagerService::findOrCreateFromWhatsApp()` and existing conversation rules, while explicitly verifying all ownership relationships and normalizing phone format.
3. Connect AI and manual inbox replies for **only** those explicit AWS channels to a durable Bridge outbox instead of Graph/Telnyx. Existing direct Meta, Telnyx, Telegram and other channels must retain their current path. An AWS channel must fail closed if the Bridge is disabled; it must never fall back to direct Meta.

The prepared `AutomationService` hook retains the existing `sendToAIAgent()` workflow and writes AI text replies for explicit `managed_provider=aws_social` channels to the durable outbox, keyed by source message. The existing asynchronous sender remains unchanged for other channels. A `WhatsAppSenderService` hook handles manual AWS text replies and blocks non-text AWS operations from falling into Graph/Telnyx. An `AgentExecutor` hook guards native AWS tool calls and checkpoints ticket creation. All three edits are only in the independent Core worktree/patch. The engine, privacy/consent rules, plan limits, pause/draft behavior and token accounting remain pre-existing dependencies requiring staging validation.

Existing APIs are not equivalent: `/api/automation/webhook/{channelId}` consumes N8N output, browser inbox routes rely on a logged-in user/workspace, and telephony routes have node-specific credentials/context. Do not reuse their tokens for the Bridge.

## Authentication

Headers: `x-bridge-binding`, `x-bridge-timestamp` (Unix seconds), `x-bridge-nonce` (16–80 safe characters), `x-bridge-signature` (lowercase SHA-256 hex).

Canonical UTF-8 bytes:

```text
TIMESTAMP\nNONCE\nUPPERCASE_METHOD\nEXACT_PATH\nRAW_REQUEST_BODY
```

The actual separators are LF bytes. The final body has no extra terminator added by the protocol. Preserve the raw body; do not decode and re-encode it before verifying. Reject timestamps over ±300 seconds, use constant-time comparison, then atomically store the nonce for 600 seconds. Shared key lookup must use the authenticated binding, never a caller-provided organization id. Use a unique key per binding and direction.

## Inbound POST /api/aws-engagement-bridge/events

```json
{
  "version": 1,
  "eventId": "SHA256_ID_FROM_BRIDGE",
  "bindingId": "demo",
  "channelRef": "opaque-core-channel-reference",
  "resource": {
    "accountId": "111111111111",
    "region": "eu-west-1",
    "wabaId": "123456",
    "metaPhoneNumberId": "789012",
    "awsPhoneNumberArn": "arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/abc123"
  },
  "kind": "message",
  "providerMessageId": "wamid.synthetic",
  "timestamp": 1792860000,
  "sender": "+12025550123",
  "text": "Synthetic activity question"
}
```

For `kind=status`, require `status` (`sent`, `delivered`, `read`, `failed`) and omit text/sender. Store receipt facts independently; a late `sent` must not downgrade `read`. Correlate AWS and Meta ids using real event fixtures before claiming operational status tracking.

Validate schema, binding, signed AWS resource tuple, channel, organization, workspace, agent and any supplied related identifiers. Reject mismatches. Persist inbox event and processing intent transactionally with unique event id and payload digest. The dedicated drain worker processes that durable table, avoiding a dependency on an ephemeral task dispatch. Persist outbound/action idempotency separately. A missing session must never remove workspace isolation.

Return `202 {"status":"accepted","id":"EVENT_ID"}` only after durable acceptance, or `200 {"status":"duplicate","id":"EVENT_ID"}` for the same payload already accepted. Reusing an id with different content is a conflict. A transient failure must be non-2xx, never an acknowledgement followed by a lost in-memory task.

The Core handler acknowledges promptly; Bedrock and tools run through the dedicated durable inbox drain. The initial implementation serializes a complete binding (stricter than per conversation) with a ten-minute lease and a maximum of three attempts. Accepted events use provider timestamps and acceptance sequence for ordering; standard SNS/SQS still cannot guarantee that a late event never arrives after a later turn has already run. Ticket results are encrypted and committed in the same SQL transaction as the native action. This does not make mail/Redis/external side effects transactional; do not claim exactly-once execution for all tools. The MVP permits `knowledge_search`, `support_get_my_tickets` and `support_create_ticket`; other tools are denied for AWS turns.

## Outbound POST /outbound (AWS HTTP API)

```json
{
  "version": 1,
  "commandId": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "bindingId": "demo",
  "channelRef": "opaque-core-channel-reference",
  "recipient": "+12025550123",
  "text": "We registered request TEST-0001, not a confirmed booking."
}
```

The prepared Core generates a 64-character hexadecimal command id. Use the outbound-direction secret and exact `/outbound` path. Generate a fresh request nonce for retries but **retain the same command id and payload**. An enqueue `202` means queued, not sent/delivered. The Core outbox publisher must be recoverable if SQS or HTTP is unavailable. It rechecks conversation policy and the customer-service window before publication; templates are outside this text-only MVP. Do not allow tools to bypass the AWS transport when sending channel messages directly.

## Receipt POST /api/aws-engagement-bridge/receipts

```json
{
  "version": 1,
  "bindingId": "demo",
  "commandId": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "state": "accepted",
  "providerMessageId": "AWS_RETURNED_ID"
}
```

Other states: `rejected` or `uncertain`, with a sanitized `errorCode`. Authenticate using the core-direction key, resolve the outbox row **within the binding**, persist the receipt idempotently and return `200/202 {"status":"accepted","id":"COMMAND_ID"}`. An uncertain result needs reconciliation; creating a new command id just to resend defeats the protection.

## Pre-existing implementation references

Baseline commit: `1c4b2bb38a560f67a454dfcd350a270d97482eb5`.

- `app/Services/WhatsAppSenderService.php`: `sendRequest()` selects Graph or Telnyx today.
- `app/Jobs/SendAIChannelResponse.php`: agent channel egress.
- `app/Http/Controllers/MessageController.php`: browser/manual channel egress.
- `app/Services/AIAgentService.php`: `processMessage()`, `runViaAgentExecutor()`.
- `app/AI/AgentExecutor.php`: existing model/tool loop and `ai_tool_calls` persistence.
- `app/Services/LLM/BedrockToolCallableModel.php`: `chatWithTools()` → Bedrock Converse.
- `app/AI/Tools/Tickets/CreateTicketTool.php`: `support_create_ticket`, confirmation and inbox/workspace validation.
- `app/Services/ConversationContextService.php`: history per conversation; no automatic cross-channel memory claimed.

## Integration acceptance gates

Tests must run against isolated application storage, database, Redis, mail and fake channel providers before activation. Verify existing reseller/Meta/Telegram behavior, AWS manual and AI replies, paused/draft conversations, tenant rejection, duplicate events, ticket crash/retry, Core outage and provider uncertainty. Then request approval for a reviewed staging deployment. The live production worktree, MuseDock and failover remain outside this module's scope.
