> Historical phase 2 report. Current integration evidence and limitations are in [PHASE-3.md](PHASE-3.md).

# Phase 2 development report — 2026-10-24

## Status

The Core connector source and the three integration hooks are prepared in independent branches. **They are not installed, merged, deployed or activated in production.** Real WhatsApp, native model/tool end-to-end operation and AWS staging acceptance remain pending. A general plugin system, BYO AWS, Marketplace and SES have not been developed.

## Repositories and exact scope

- Bridge: `/var/www/vhosts/muserelay.com/modules-develop/aws-engagement-bridge`, branch `codex/aws-engagement-bridge-phase2`.
- Core source worktree: `/var/www/vhosts/muserelay.com/.codex/worktrees/aws-engagement-bridge-core`, branch `codex/aws-engagement-bridge-core`, based on `b8e9bf8c74789689a7cebddd1856a094e380c9d9`; prepared hook commit `b1be2f59`.
- Live Core remains `/var/www/vhosts/muserelay.com/httpdocs`; no application file there was edited by this work. Other sessions may independently advance its HEAD.
- Private GitHub destination requested/authorized: `screenartsl/aws-engagement-bridge`. Still pending authenticated root/connector access. No token was copied, remote created or judge invited.

Prepared Core edits: `WhatsAppSenderService`, `AutomationService`, `AgentExecutor` only. Their patch is included at `integration/muserelay/core-hooks.patch` so the new repository does not need the full proprietary Core. Existing Meta/Telnyx configuration branches and other channel implementations are retained.

## Implemented connector code

- Laravel package/provider, disabled by default, with explicit organization opt-in and a separately enabled binding per channel.
- HMAC-authenticated event and receipt controllers, timestamp/nonce validation and persistent replay protection.
- Matching of organization, workspace, channel, agent and signed AWS account/region/WABA/Meta phone/AWS phone resource tuple. The demo requires Bedrock primary/fallback providers, allowing authorized global provider records.
- Encrypted durable SQL inbox/outbox and action results; dedicated explicit `aws-bridge:drain` command. No existing cron, Supervisor worker or Horizon configuration is changed.
- Native adapter for existing contacts/conversations/messages, inbox events, the existing agent engine, channel policies, pause/draft handling and existing ticket tools. Revalidation before tools/publication prevents intentional paused/closed or unauthorized turns from continuing through those hooks.
- One transactional ticket-tool result per input event; same SQL connection required for native ticket/audit/message models. Native mail/Redis/external side effects are not exactly-once transactions.
- Stable automatic-reply command id derived from the persisted source message. Publication retries keep that id. Receipt callbacks cannot overwrite an accepted receipt with an uncertain one; publication acknowledgement cannot downgrade a receipt that arrived first.
- Protected Core signing-key reader outside replicated vhosts, separate directional keys and no secret values in Laravel config cache.
- Explicit staging installation, activation/deactivation, rollback and real E2E acceptance instructions.

## Local verification

- **27 PHP tests / 99 assertions**, passed against SQLite `:memory:` fixtures and fake native agent/business ports.
- **30 Node tests**, passed, including actual official SDK serialization against an in-process HTTP handler, not AWS.
- PHP syntax checks and TypeScript compilation/bundling passed.
- Three-file hook patch validated against the baseline index; no patch applied to live Core.
- Phase 1 static infrastructure lint had no findings; the infrastructure template has not changed during phase 2.

The PHP test bootstrap requires `APP_ENV=testing`, an explicit dependency autoloader and a source worktree **without `.env`**. It does not load production app bootstrap, Dotenv, the production PHPUnit bootstrap or PostgreSQL/MySQL connections. Native Meta/Telnyx configuration resolution is checked using the actual Core sender/model classes. Full panel, native ticket observer and Bedrock operation are **not** implied by those tests.

## Remaining acceptance and external inputs

Authorize an isolated full MuseRelay staging installation before testing real Eloquent callbacks, consent/plan/draft flows, native ticket confirmation, Redis, mail sink, concurrency on PostgreSQL, UI and rollback. Then authorize AWS resource creation and approved-recipient messaging. Validate real AWS/Meta delivery-id correlation with sanitized fixtures; unmatched status events are retained and must not update unrelated messages.

Needed: authorized AWS account/region and IAM, dedicated new WABA/number, WABA id, Meta phone id, AWS phone ARN, supported Meta API version, approved tester, isolated Core organization/workspace/channel/agent/ticket inbox/knowledge setup, protected signing keys and Secrets Manager ARN. No credentials should be pasted into chat or committed.

GitHub upload can happen before these runtime dependencies are ready; it must remain private and preserve original commits `2d02e31` and `f2b5d07`. See `REPOSITORY-UPLOAD.md` for root-shell commands. Do not claim that a repository exists remotely until its URL/visibility and pushed refs are checked.
