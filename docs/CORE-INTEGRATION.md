# MuseRelay Core contract and next phase

This is an integration specification, **not an implemented controller or installed module**. `integration/muserelay/BridgeSignature.php` is the tested, framework-independent signature primitive. It registers nothing. No package discovery, production routes, migrations, workers or reseller screens have been changed.

## Minimal connection points

1. Add a new feature-gated Bridge service provider, authenticated event and receipt routes, a binding model, a durable inbox/outbox and a dedicated job in a separate MuseRelay worktree. Default the feature flag to false. Do not execute migrations in production without approval.
2. A new AWS-only channel must resolve from an internal binding to an existing workspace and organization. The new ingestion service persists the inbound message and emits `InboxBroadcaster::messageReceived()` / conversation events. It reuses `ContactManagerService::findOrCreateFromWhatsApp()` and existing conversation rules, while explicitly verifying all ownership relationships and normalizing phone format.
3. Connect AI and manual inbox replies for **only** those explicit AWS channels to a durable Bridge outbox instead of Graph/Telnyx. Existing direct Meta, Telnyx, Telegram and other channels must retain their current path. An AWS channel must fail closed if the Bridge is disabled; it must never fall back to direct Meta.

Reusing the current `AutomationService::sendToAIAgent()` unchanged will dispatch `SendAIChannelResponse` to the existing WhatsApp sender. Do not wire the new ingress to that function and assume AWS egress happens. Either introduce a narrowly scoped transport seam or have the new Bridge job invoke the existing agent service and store its result through an explicit AWS outbox path. Reuse the same engine, privacy/consent rules, plan limits, pause/draft behavior, token accounting and authorized tool configuration.

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
  "kind": "message",
  "providerMessageId": "wamid.synthetic",
  "timestamp": 1792860000,
  "sender": "+12025550123",
  "text": "Synthetic activity question"
}
```

For `kind=status`, require `status` (`sent`, `delivered`, `read`, `failed`) and omit text/sender. Store receipt facts independently; a late `sent` must not downgrade `read`. Correlate AWS and Meta ids using real event fixtures before claiming operational status tracking.

Validate schema, binding, channel, organization, workspace, agent and any supplied related identifiers. Reject mismatches. Persist inbox event and job-dispatch intent transactionally, with unique `(binding,eventId)` and payload digest. Persist outbound/action idempotency separately. A missing session must never remove workspace isolation.

Return `202 {"status":"accepted","id":"EVENT_ID"}` only after durable acceptance, or `200 {"status":"duplicate","id":"EVENT_ID"}` for the same payload already accepted. Reusing an id with different content is a conflict. A transient failure must be non-2xx, never an acknowledgement followed by a lost in-memory task.

The Core handler must acknowledge promptly; Bedrock and tools run in a dedicated queue. Serialize turns per conversation. Reusing input deduplication is insufficient for a crash after an action: persist action identities/results atomically with local ticket changes and resume workflow checkpoints without repeating business actions.

## Outbound POST /outbound (AWS HTTP API)

```json
{
  "version": 1,
  "commandId": "stable-core-outbox-id-0001",
  "bindingId": "demo",
  "channelRef": "opaque-core-channel-reference",
  "recipient": "+12025550123",
  "text": "We registered request TEST-0001, not a confirmed booking."
}
```

Use the outbound-direction secret and exact `/outbound` path. Generate a fresh request nonce for retries but **retain the same command id and payload**. An enqueue `202` means queued, not sent/delivered. The Core outbox publisher must be recoverable if SQS or HTTP is unavailable. Validate WhatsApp customer-service window and allowed payload before publishing; templates are outside this text-only MVP. Do not allow tools to bypass the AWS transport when sending channel messages directly.

## Receipt POST /api/aws-engagement-bridge/receipts

```json
{
  "version": 1,
  "bindingId": "demo",
  "commandId": "stable-core-outbox-id-0001",
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
