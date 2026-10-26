# Isolated staging: reproduce, activate, deactivate and roll back

Phase 3 authorizes disposable staging only. Production, reseller panels, existing channels, DNS, service managers, AWS resources and real messaging remain outside this authorization. Publishing to GitHub requires a new specific authorization.

## Reproduce locally

Run as the ordinary development user, never root:

```sh
cd /var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge
python3 integration/staging/run.py \
  --core-source /var/www/vhosts/muserelay.com/.codex/worktrees/aws-engagement-bridge-core
```

Prerequisites: Linux with unprivileged user/network namespaces, Python 3.10+ with the tar data filter security backport, PHP 8.1+ with pdo_pgsql/pdo_sqlite/redis, Composer, Node 20+ (22 recommended), npm, Redis server/CLI and PostgreSQL 16 binaries. `--pg-bin` overrides their directory. Composer/npm may download dependencies before application tests start. No host package installation is automated.

The runner archives reviewed **committed source** at Core baseline `b8e9bf8c74789689a7cebddd1856a094e380c9d9`, excludes environments, runtime storage, caches, dependencies and symlinks, checks/applies the three hooks only to that archive, installs Core dependencies without scripts/plugins, and installs package version **0.3.0** through a Composer path repository. No production autoloader is used. The Core must be accessible privately; it is not distributed in this module.

Each run creates `/tmp/muserelay-bridge-phase3-<random>` with mode 0700, outside `/var/www/vhosts` replication. It has:

- A new PostgreSQL cluster with TCP disabled, a private Unix socket, 32 MB shared buffers, 20 connections, ordinary `bridge_stage` SQL role and databases `bridge_phase3_test`/`bridge_unit_test`. No connection to host ports 5432/5433.
- A separate Redis instance with TCP disabled, private 0700 Unix socket, 64 MB memory ceiling and persistence off. No connection to production Redis.
- Its own storage, configuration, encryption/index keys, mail array sink, logs and Composer installation. Keys are deterministic synthetic fixtures, unsuitable for deployment.
- A Laravel Foundation application using selected framework providers, actual Core AppServiceProvider/AIToolsServiceProvider and the installed Bridge provider. Production Dotenv, bootstrap/cache and public/panel routes are not loaded. Only a synthetic ticket URL and Bridge routes are registered.
- Real Laravel Redis queue workers running test drain jobs that invoke the installed `aws-bridge:drain` command. Existing background chat/notification jobs may be enqueued into the private Redis; this suite does not consume their queues.
- An empty Linux network namespace for **every application and test process**, inherited by child workers. Bootstrap rejects the host namespace. This denies AWS, Meta, email and host TCP connections even if a simulator is misconfigured. PostgreSQL/Redis remain reachable through private Unix sockets.

The schema is an explicit **synthetic integration fixture**, partly derived from committed Core ticket tests and extended for native models, observers, encryption and tools. It is not a dump or certification of all historical Core migrations. The alias named `mysql` is also configured to the private PostgreSQL database because some legacy central models explicitly use that connection name.

The runner executes Node contracts, SQLite contracts, PostgreSQL contracts, two customer turns, signed callbacks/read statuses, simultaneous duplicate acceptance/claims, native SQL rollback and legacy dispatch regressions. Failure returns nonzero. Its `finally` stops only its Redis/PostgreSQL instances; workers stop when the staging queue empties. Synthetic logs and `result.json` remain in the private temporary directory. Do not publish complete runtime directories.

AWS boundaries are controlled simulations: SNS envelopes, SQS delivery and DynamoDB state; official Social Messaging and Bedrock SDK HTTP handlers; a signed Core HTTP bridge dispatched in-process. Actual NativeCore, AutomationService, AIAgentService, AgentExecutor, native ticket tool, Eloquent callbacks and encryption run locally. Model selection is a staging-only factory selecting the actual Bedrock adapter with a simulated SDK transport. Billing uses an explicit synthetic usage tracker, so charging and live model quality are not acceptance claims.

## Installation and organization activation for a later authorized staging release

1. Use a separate application checkout, DB/Redis/storage, mail sink, application/message/index keys and worker configuration. Review `core-hooks.patch` against the exact target revision; abort on drift. Install only the module package and those hooks. Keep `AWS_BRIDGE_ENABLED=false` and `AWS_BRIDGE_ORGANIZATION_IDS` empty initially.
2. Review and explicitly apply `integration/muserelay/database/migrations/2026_10_24_180000_create_aws_bridge_tables.php` against the staging default connection. The provider does not auto-register migrations. Native Message, Conversation, Ticket and TicketAuditLog must share that connection for atomic checkpoints.
3. Create a synthetic organization/workspace, an active channel with `type=whatsapp`, `managed_provider=aws_social`, `processing_type=ai_agent`, `ai_agent_enabled=true`, and enabled automation. Set `features.agent_executor_mode=full`. Use a Bedrock-only agent, including any configured fallback providers. Assign native ticket inboxes through **`ai_agents.ticket_inbox_ids`**, not a singular inbox field. Ensure the existing `public.ticket.show` named route is present in a full app.
4. Insert a disabled `aws_bridge_bindings` row with exact organization/workspace/channel/agent ids, opaque unique `channel_ref`, account/region/WABA/Meta phone/AWS phone ARN, recipient allowlist and daily budgets. No public binding-registration endpoint exists. Tenant restrictions are application validation plus SQL uniqueness/locks; this module does not install PostgreSQL RLS.
5. Provision two independent random directional HMAC keys per binding, minimum 32 bytes, through a protected path: `/run/secrets/muserelay-aws-bridge.json` or `/etc/muserelay/aws-bridge/…`, owner readable only (0600), never under the replicated vhost. Each binding maps to `{ "core": "<random>", "outbound": "<different random>" }`; the AWS secret must match. Do not use staging fixture keys. Configure `AWS_BRIDGE_SIGNING_KEYS_FILE` and `AWS_BRIDGE_OUTBOUND_URL` (exact HTTPS API Gateway `/outbound`, eu-west-1).
6. Opt in **only** the demo organization using `AWS_BRIDGE_ORGANIZATION_IDS=<demo-id>`, enable its binding and set `AWS_BRIDGE_ENABLED=true`. Rebuild configuration/route caches in that staging installation. Start only the dedicated staging drain worker. Real AWS configuration and sends still require authorization; see [AWS checklist](history/AWS-LIVE-CHECKLIST.md).

## Deactivation

Disable the binding, then set `AWS_BRIDGE_ENABLED=false` and clear the organization allowlist. Stop the dedicated drain worker and rebuild staging config/routes caches. Disable/review the AWS sender and AWS binding too if a later live test has already queued commands: disabling Core cannot recall work accepted by AWS. Preserve inbox/outbox/receipts, especially uncertain sends; do not redrive them under new ids.

## Rollback

Deactivation does not require table deletion. For source rollback, keep the AWS channel disabled, reverse the reviewed three-file patch and remove the package from that isolated application through Composer. Rebuild that application's caches and restart only its own workers. The production branch is not merged or modified by this workflow.

The suite also executes migration `down()` on the disposable database and checks that native messages, tickets and channels survive. Do not use this destructive schema rollback as an ordinary live off switch; it deletes bridge evidence. Redis confirmation marks, mail and other external effects are not atomic with SQL. The rollback test proves that ticket/audit/checkpoint rows roll back together; recovery after loss of a Redis confirmation may require a new customer confirmation.

## Coverage limits

Read [Phase 3 evidence](history/PHASE-3.md). Full reseller/panel browser testing, every legacy transport's provider acceptance, a complete Core migration chain, crash/failover across hosts, real AWS status-id correlation, model permissions/quotas and live WhatsApp remain separate gates. Existing Telnyx text sending throws a pre-existing “Media WhatsApp aún no implementado…” exception in the reviewed baseline; the regression records this unchanged behavior and does not repair the Core.
