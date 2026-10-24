# Core staging installation, activation and rollback

**Prepared instructions only. No installation, application migration, live worker, DNS change, AWS deployment or real message has been executed. Obtain explicit authorization for those actions.**

## Files and prerequisites

The integration package is `integration/muserelay/` (`screenartsl/muserelay-aws-engagement-bridge-core`, version 0.2.0). Its provider is discovered by Laravel only after an authorized Composer installation. The package alone does not install the three hooks in the existing application. `core-hooks.patch` applies to Core baseline `b8e9bf8c74789689a7cebddd1856a094e380c9d9` and was checked against that worktree's unmodified index.

Core edits are limited to:

1. `app/Services/WhatsAppSenderService.php`: explicit AWS-only text egress; non-text AWS operations fail closed.
2. `app/Services/AutomationService.php`: explicit AWS AI replies enter the durable outbox, retaining the existing agent workflow.
3. `app/AI/AgentExecutor.php`: AWS-only guarded/checkpointed native tool dispatch.

No reseller UI, direct Meta configuration, Telegram/email implementation, global scheduler, MuseDock or failover file is edited. The independent Core branch is `codex/aws-engagement-bridge-core`; it has not been merged or pushed to the production repository.

Use an isolated copy of MuseRelay with its own database, Redis namespace/instance, storage, mail sink, APP_KEY and synthetic users. Do not copy production `.env` or customer records. The native application and its dependencies are required; this repository is not a replacement SaaS.

## Local isolated connector checks already runnable

From the bridge repository, use an explicit Composer dependency autoloader and a Core worktree without `.env`:

```sh
APP_ENV=testing \
MUSERELAY_TEST_VENDOR=/var/www/vhosts/muserelay.com/httpdocs/vendor/autoload.php \
MUSERELAY_CORE_TEST_SOURCE=/var/www/vhosts/muserelay.com/.codex/worktrees/aws-engagement-bridge-core \
php /var/www/vhosts/muserelay.com/httpdocs/vendor/bin/phpunit -c integration/muserelay/phpunit.xml
```

This reads installed dependencies only. The bootstrap does not load Laravel's production bootstrap, Dotenv or the application's PHPUnit configuration. It explicitly creates SQLite `:memory:` fixtures, not a PostgreSQL/MySQL connection. Tests mock the native conversation/agent/ticket ports and HTTP publication. Actual native model callbacks, Bedrock, mail, Redis and the full panel flow remain staging tests. Legacy Meta/Telnyx configuration resolution uses the actual Core classes from the worktree; no live sends occur.

## Authorized staging sequence

1. Review the hook patch against the exact staging baseline; apply and install the Composer package from a reviewed release/path. Do not modify the live checkout or publish the whole proprietary Core into the bridge repo.
2. Keep `AWS_BRIDGE_ENABLED=false` and `AWS_BRIDGE_ORGANIZATION_IDS` empty during installation. No Bridge routes are registered when disabled. Migrations are not auto-discovered: review and explicitly run only the package migration path against the isolated DB when authorized.
3. Configure the demo organization/workspace, active WhatsApp channel with `managed_provider=aws_social`, `processing_type=ai_agent`, automation/agent enabled, dedicated Bedrock agent, native ticket inbox and activity knowledge. Every configured primary/fallback provider must be Bedrock for this MVP. `features.agent_executor_mode` must be `full`.
4. Create a **disabled** `aws_bridge_bindings` row with `id`, opaque unique `channel_ref`, correct organization/workspace/channel/agent ids, AWS account/region/WABA/Meta phone/AWS phone ARN, explicit approved recipient JSON and conservative daily limits. These values must match the Lambda binding exactly. There is intentionally no public binding-registration endpoint.
5. Provision separate directional signing keys out of band. The Core reader accepts only owner-readable 0600/0400 files under `/run/secrets/` or `/etc/muserelay/aws-bridge/`, outside replicated vhosts. Grant access only to the actual staging process user. Do not place real keys in this repository, `.env.example`, replicated development directories or logs. Corresponding Lambda keys reside in Secrets Manager.
6. After authorization, set only the isolated organization's opt-in id and enable the global flag, then the specific binding. Use the exact approved HTTP API `/outbound` URL in `AWS_BRIDGE_OUTBOUND_URL`. Refresh configuration/routes in staging as needed; no production cache-clearing command is part of this work.
7. Run an explicit dedicated `aws-bridge:drain --limit=10` invocation/process in staging. It processes the durable inbox and publishes outbox commands. No cron/Supervisor configuration is installed automatically. Worker execution must be bounded below the ten-minute turn lease; do not run unbounded LLM/tool tasks through this MVP lease.
8. Initially use fake AWS transport in the isolated setup. Only after authorization deploy AWS resources/associate the dedicated non-production WABA, enable live sends and exercise the real handset → Bedrock → ticket → handset workflow.

## Disable and rollback

Disable the specific binding first and stop only the dedicated Bridge drain process. Set `AWS_BRIDGE_ENABLED=false` and remove organization opt-ins; rebuild staging route/config caches if used. Pending events/outbox rows are retained for review; do not delete uncertain-send evidence or redrive it under new ids. Existing non-AWS channels retain their original transport path.

For source rollback, reverse the three-file hook patch on the reviewed staging baseline and remove the package through Composer. Keep the dedicated AWS channel disabled: it must never fall back to Graph. Do not run the migration's destructive `down()` merely to deactivate; it drops Bridge evidence. No automatic production rollback/failback operation is included.

## Acceptance and remaining risks

- Validate actual Eloquent model observers, encryption/search indexing, inbox events, pause/draft flows, consent, plan limits and native ticket confirmation with a complete isolated Core application.
- Validate SQL locking/uniqueness under concurrent PostgreSQL workers. SQLite in-memory tests prove local behavior, not PostgreSQL race behavior or cross-node failover safety.
- Confirm the real AWS response id ↔ Meta delivery id correlation with sanitized live events. Unmatched statuses remain recorded as input events and do not update arbitrary messages.
- Ticket SQL/checkpoint atomicity requires the same connection (checked for native Message, Conversation, Ticket and TicketAuditLog models). Mail/Redis/other external side effects are not atomic with SQL and may need reconciliation after a crash.
- The worker caches one ticket-tool result per input event and permits only one creation outcome in that event. This may conservatively require a new customer turn after a validation failure instead of retrying altered arguments within the same turn.
- Manual double clicks have independent command ids unless the existing UI supplies an idempotency key; automatic AI replies use the persisted source message id. Do not claim all manual actions are exactly once.
- Existing Core logging, provider limits and infrastructure failover remain application dependencies. The new transport does not repair or certify them.
- Disabled bindings retain queued work; already accepted AWS commands cannot be recalled by toggling the Core flag. Disable/review the AWS binding/sender too when stopping a live demo.
