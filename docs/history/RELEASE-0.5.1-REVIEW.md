# Connector 0.5.1 — local release review, 2026-10-25 (Europe/Paris)

Code commit: `401728d1a66f577481936e07f2630a559144efb9`. Core production remains at `594a042c916120e353820ecbe12631473006abb2`, connector 0.4.0 disabled. No production code/configuration/database was changed, no shared service was reloaded, no AWS change set was created or executed, no queued event was received and no real message/model call was sent by this release preparation. Pre-existing GeoIP changes and the contacts-view backup remain untouched. Publication is authorized only to the existing private `screenartsl/aws-engagement-bridge` repository; no public or judge access is granted.

## Change and scope

- Readiness checks still require the five Bridge tables. They permit a dormant, organization-scoped activation without an AWS binding/secret when all three operation controls are off. This is installation readiness, not proof of connectivity. Enabling the module later still requires the actual demo organization and production verification.
- An inbound callback is rejected at the receive control before binding/signature/nonce processing when reception is off. Tests assert HTTP 503, `bridge_receive_disabled` and zero nonce/event writes. Production still uses the older installed connector until a separately reviewed installation.
- When reception is enabled, readiness still rejects missing bindings or signing material. Sending and agent controls remain separate. Existing Bedrock credentials are untouched.
- Version metadata and the synthetic loader expectation now agree on 0.5.1.

## Validation at this code commit

| Check | Result | Boundary |
| --- | --- | --- |
| Node contract suite | 35 passed | Controlled SDK HTTP transports, no AWS |
| PHP / SQLite | 40 tests, 150 assertions, passed | Explicit private autoloader/source, no production bootstrap |
| PHP / PostgreSQL 16 | 40 tests, 230 assertions, passed | Private Unix socket, fixed synthetic DB, non-superuser role, network namespace |
| Native integration with PostgreSQL/Redis/workers | PASS | Synthetic two turns, ticket/action/concurrency/extension and legacy transport regressions; AWS/Bedrock mocked |
| Native inbox HTTP checks | 24 passed | Actual Core views/controllers in isolated source/data; no replacement inbox |
| Chromium 156 / Playwright 1.64.1 | 27 bounded requests, zero page errors, PASS | Real native PHP kernel responses; browser network intercepted; not live TLS/AWS |
| Python inspection/collector tools | 19 passed | No AWS requests |
| CloudFormation structural/foundation checks | PASS | Offline, not service-side IAM/SCP/quota validation |
| cfn-lint 1.58.0 | PASS | Root runtime, IAM runtime update and deferred inference template |
| Offline SAM expansion | PASS | Zero generated IAM roles; original 12 unconditional ingress resources preserved; conditional full runtime expansion is not the number deployed for receive-only |
| IAM UPDATE comparison | PASS | Recovery role byte-equivalent structurally; executor trust/old policies unchanged; exactly three new logical roles and one added executor inline policy |

The native integration runner used reviewed pre-extension Core baseline `b8e9bf8c74789689a7cebddd1856a094e380c9d9` plus the prepared hooks/support. The separate native UI test used current Core `594a042c916120e353820ecbe12631473006abb2` plus only the prepared Channel recognition patch. Neither is a production installation or full browser certification of all reseller workflows. All owned PostgreSQL and Redis test services were confirmed stopped.

Private evidence (never publish raw HTML/session/password/key artifacts):

- Native integration and final PG assertions: `/tmp/muserelay-bridge-phase3-nxmqm8ng`.
- Native UI/browser: `/tmp/muserelay-bridge-panel-31wchhwc`.
- Synthetic screenshot SHA256: `3d5220a94833326842c5c457bc312606204f0cd8f022d1d882461a87b3322d85`.

## Next authorized review

Follow [the console guide and GPT prompt](CONSOLE-IAM-RUNTIME-REVIEW-2026-10-25.md). The next YAML is `deploy/runtime-iam/template.yaml`, SHA256 `b2a87ec5d7d205b69250596103427fb55b8896b7f5997507b28b489b18feac3a`. This file's content is unchanged from locally reviewed commit `419e05760f15792dc5b6c40a9af4bdaa4d404d6f`.

GO: publish the reviewed source privately and prepare/review an IAM UPDATE change set with GrantSocialSend=false. Execution, runtime deployment, Core installation/activation, HMAC initialization and first real reception remain separate gates. Demo organization identity is pending. Abdu's activity must be checked again immediately before any production activation; an earlier absence indicator is not a continuing guarantee.

No new full database backup/restore was performed for this local release because production was not installed or changed. The earlier restoration evidence remains historical; take a fresh backup and verify restoration before a future production installation, then recheck concurrent Core changes. Rollback of the module must disable its gates before reverting only its reviewed files; never restore a whole production database to disconnect it. AWS rollback/retention limits are described in the console guide and `deploy/runtime-iam/README.md`.
