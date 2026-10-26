# Native inbox staging — local preparation, 25 October 2026

## Product decision

There is **one MuseRelay inbox**. The adapter reuses the committed `ConversationController`, `MessageController`, native Blade inbox/layout, models, relationships, encryption and services. No replacement inbox or new conversational SaaS was written. One additional Core-model recognition hook is prepared as a separate patch; it is not installed in production.

The isolated copy is only an internal temporary test. The intended final judges' access is a user manually created by the owner (for example a demo corporate alias), with its **new organization and workspace inside existing MuseRelay**. Its AWS Social channel binds the already-linked Screen Art demo WABA/phone. This preparation creates no real account, organization or channel in production. Use actual IDs after manual creation; synthetic IDs 97101/10/20 are not production IDs to copy blindly.

A future WhatsApp channel provider choice must be labeled AWS End User Messaging Social and use owned connection metadata/role references. Gate it server-side by organization/workspace/user permissions, including any demo-user restriction, not only by visibility of a menu. No permanent AWS key form, automatic reuse of Core Bedrock credentials, third-party onboarding or managed resale is implemented here. Existing Meta options/transports stay intact.

## What was prepared

- `scripts/phase4/prepare-panel-source.py`: exports ONLY a reviewed committed Core tree, no `.env`, caches, vendor, storage, uploads, production data or production secrets. Static source assets are limited to CSS/JS/fonts/SVG and the explicitly allowed committed MuseRelay logo. A private manifest hashes Core and adapter files. Native inbox views/controllers are not patched. The separately reviewable `core-provider-recognition.patch` changes only `Channel::isConfigured` in the private copy so an authorized AWS binding is recognized without Meta token fields. Production is untouched; installing that Core hook requires explicit review.
- Core reference `594a042c916120e353820ecbe12631473006abb2`: compared to audited `7252eac087973d12d243299cf8d0f03569cfddc2`, the concurrent change adds seven documentation lines only. If HEAD changes again the preparer stops for review.
- `integration/panel-staging`: receive-only staging configuration, private evaluation login using Laravel sessions/password hashing/CSRF, and a route boundary exposing native inbox reads, own-message read state and activity only. Other native route names can be used by shared views but their actions are not exposed. Tenant routes supply names required by the shared sidebar; tenant controllers are not exposed.
- Synthetic PostgreSQL fixture built from committed test fixtures plus the native inbox's empty auxiliary tables. This is **not an exact production-schema replica** and does not certify every screen or tool. No SQL schema or rows are copied from production. No AI provider/agent is needed or seeded for reception.
- Dedicated own Redis cache, session storage, files and fresh independent encryption material. SMTP and broadcasts use non-network drivers; no inference factory or outbound HTTP is available. Native polling endpoints provide the initial read surface; WebSockets are disabled in this test profile.
- `deploy/panel-staging`: FPM, bounded SQL-drain service and Caddy proposals only. No units, vhosts, users, DNS or certificates were installed. FPM/drain allow Unix sockets only and cannot access production vhosts. Caddy has a documentation-only evaluator IP and must not be imported unchanged.

The test login is a technical staging fixture, not a new customer login product. The business/demo number is not a security boundary. Scope and permissions provide isolation.

## Reproduce local checks

Run as the unprivileged development user, with existing disposable dependencies, never production vendor:

```sh
python3 scripts/phase4/test-panel-local.py \
  --core-source /var/www/vhosts/muserelay.com/httpdocs \
  --dependencies-from /tmp/muserelay-bridge-phase3-d992vd4s/core/vendor
```

The runner prepares a private `/tmp/muserelay-bridge-panel-*`, starts its OWN PostgreSQL 16 Unix socket at logical port 55435 and its own Redis Unix socket, and runs PHP inside a Linux namespace with no network interface enabled. There is no HTTP listener, DNS, AWS client, SQS polling, live agent or transport. Each HTTP-kernel probe runs in a fresh PHP process with cookie/password material only in private pipes. All owned services are stopped on success and failure. Current Core HEAD must still match the reviewed source ref.

Test expectations: all gates/binding disabled on seed; explicitly opted-in **synthetic** input creates one native contact/conversation/message; duplicate is deduplicated; no agent/provider/action/outbox; guest denied; evaluation login and CSRF; native inbox page/list/detail; other scope denied; administration/send routes unavailable. Optional Chromium checks exercise the native inbox JavaScript and visible message against actual PHP/PG responses routed over private process pipes. This does not validate a real HTTP/TLS listener, DNS, AWS input or production-user access; those remain separate future acceptance checks.

Private artifacts may include synthetic login secrets, application keys, session cookies, native short-lived token material and rendered HTML. They stay outside Git/replicated vhosts. Do not upload them to the module repository or display their contents. Hashes/counts and redacted test summaries are publishable.

## Future installation and first reception — separate approvals

1. Review the exact source/release hashes, dependencies, resource limits and actual proxy/user/group on the chosen node. Source code must be read-only under `/srv/muserelay-aws-stage`; data belongs under `/var/lib/muserelay-aws-stage`, sockets under `/run/muserelay-aws-stage`. Never symlink production storage/vendor/config. Installation is NOT authorized by source preparation.
2. Install fresh isolated PostgreSQL/Redis and dedicated Linux users only after approval. Existing `deploy/phase4` database/Redis proposals can be reviewed/reused; no shared service reload. The SQL application role must be non-superuser and confined to `bridge_phase4_test`. Verify the actual DB identity before any seed.
3. Generate new 32-byte encryption keys and a fresh >=24-character evaluator password privately. Config `/etc/muserelay/aws-bridge/panel-stage.json`, password `/etc/muserelay/aws-bridge/evaluator-stage.json`, owner application user, mode 0600. Use the example's fixed synthetic IDs and a fresh expiry <=96h. All gates false. Never use Core APP_KEY or existing credentials. The example contains intentionally invalid placeholders and is not an activation file.
4. Run the bounded `console.php seed` against the EMPTY isolated database only; inspect disabled state. No production migrations or registrations. The prepared seeder uses a minimal synthetic fixture and is not a command for the commercial Core.
5. Review technical HTTPS/IP restriction/Caddy and FPM configuration before any installation/reload. The temporary hostname is for internal staging, not the final judges' commercial panel. Dynamic requests go only through the adapter entrypoint; never serve the Core's PHP entrypoint or data directories. Confirm static asset read permissions without granting the proxy access to secrets/data.
6. After a separate AWS runtime authorization, review disabled receive-only CloudFormation changes and private HMAC initialization. The inbound Lambda holds no production Bedrock/admin keys. HMAC material is distinct from Laravel encryption keys.
7. Before authorizing a real consumer, resolve handling of the **two existing SQS items**: enabling a mapping can receive/acknowledge them, even if the intended test is a new message. They must remain untouched until explicit authorization covers them or an isolated new queue/subscription is approved. Supply the actual tester phone privately; do not assume the WABA sender is the recipient.
8. Only then authorize one demo binding, receive gates, its Lambda mapping and a bounded staging SQL drain. No agent, output, native tools or calendar in this phase. Event acceptance by the endpoint is durable storage; the bounded SQL drain materializes the native inbox message. Capture timestamped event/message correlation and demonstrate the actual native inbox.
9. Production installation/activation is a later review: owner creates the demo user/new organization manually; verify its actual IDs, membership and access restrictions; preserve current Core HEAD and all other channels; backup/recovery and explicit approval before applying module files/configuration. Organization opt-in alone is not proof of a specific-user restriction. The AWS choice in the existing channel settings is still pending implementation/review.

## Independent rollback

Stop only the staging receive drain and inbound Lambda mapping. Disable PHP receive/master/organization/binding gates; send/agent remain false. Retain events and native messages for evidence. Roll back the module source only after those gates are off: older 0.4.0 code lacks independent operation controls. Preserve any uncertain outbox items; never automatically resend.

Remove the temporary vhost/DNS/certificate and dedicated services/users/data only after confirming no dependencies and receiving approval for shared proxy changes. No production database restoration, blanket queue stop or shared PostgreSQL/Redis reload is required to disconnect staging. Restore of the pre-install production backup was verified in the earlier phase, not repeated by this synthetic UI suite. This work does not certify production UI or live AWS delivery.

## Concepts confirmed from the owner clarification

Organization/workspace/channel own the logical messaging data. The evaluator user is an authorized operator, not the owner of a personal AWS credential or a required online session. Reception runs without that user's session; another future operator can be authorized using native membership/roles. Legal WABA ownership remains Screen Art's Meta/AWS business account.

A future connection test must be clearly labeled metadata/permission diagnostics: STS identity, own linked WABA/phone, SNS subscription, SQS attributes and role grants. Do not implement ReceiveMessage, SendWhatsAppMessage, Bedrock inference, secret disclosure or configuration changes behind a supposedly read-only test button. Metadata consistency alone does not prove end-to-end delivery.

## Verification evidence (local only)

Final disposable UI root: `/tmp/muserelay-bridge-panel-xensqhru`. 24 positive/negative HTTP/native checks passed, including native AWS readiness without Meta token fields, unchanged Meta configuration recognition, receive-only without a login/agent, duplicate suppression, native inbox list/detail, actual foreign-organization denial, CSRF and closed send/admin surfaces. All configuration gates were false after cleanup; PostgreSQL/Redis PID files were absent. No ReceiveMessage or any other AWS call ran.

Chromium 156.0.8078.4 / Playwright 1.64.1: PASS, 28 bounded browser requests, zero JavaScript page errors. Screenshot: `native-inbox-synthetic.png`, SHA256 `76b68d55be0465a32517ae1c1f50d082cc1faee9d31eabfd314cc8a67a814c8b`. The source files for the native inbox view and both native controllers match the archived Core manifest. Only the documented Channel model overlay differs from the reviewed Core source. The screenshot shows synthetic test content and a synthetic sender, not a message received from AWS.

Browser tools are optional, pinned at Playwright 1.64.1 and downloaded to `/tmp/muserelay-bridge-browser-tools-20261025`, without global npm/OS installs. Reproduce with `--browser-tools /tmp/muserelay-bridge-browser-tools-20261025` added to the runner command. Browser network requests are intercepted; dynamic responses come from real fresh PHP HTTP-kernel processes and the private PG database. Authentication/cookies/CSRF have separate native HTTP-kernel assertions. No external browser traffic or public server is required. Chrome uses --no-sandbox only inside the disposable Linux user/network namespace for this synthetic test; this is not a production browser setup.

Parallel legacy/native regression suite: PASS, PostgreSQL 16 and Redis workers, original three transport hooks, simulated AWS/Bedrock, source baseline b8e9bf8c74789689a7cebddd1856a094e380c9d9; root `/tmp/muserelay-bridge-phase3-hefescug`, owned services stopped. Connector conformance: 36 PHP tests / 137 assertions. Node: 35 passing tests. Infrastructure/preparation: 17 passing Python tests. CloudFormation lint: no errors/warnings for runtime, receive IAM update and future inference IAM template. These are local checks, not effective-permission or live-provider certification.

The new Core provider-recognition patch still requires production review and installation approval. The channel configuration form/provider selector and per-evaluator commercial access provisioning remain pending; no form asking for AWS access keys was implemented.

Secret/history screening before the delivery commit: PASS, 540 stored Git objects and 191 tracked/non-ignored working files, no heuristic findings. Scope excludes private ignored artifacts, remote-only history and external LFS; this is screening evidence, not a guarantee. Fresh staging encryption/password/session material stays in the protected disposable directory, outside Git.
