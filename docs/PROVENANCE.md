# Provenance

New repository started 2026-10-24 on branch `codex/aws-engagement-bridge`; phase 2 development is on `codex/aws-engagement-bridge-phase2`. A local `origin` now points to `https://github.com/screenartsl/aws-engagement-bridge.git`; remote existence/privacy and remote-only history have not been authenticated or certified. Phase 3 is on `codex/aws-engagement-bridge-phase3`. The latest user instruction places publication on hold pending specific authorization. Use `git log --all --format='%h %cI %s'` and `git ls-files` to enumerate original bridge commits/assets; do not relabel legacy code as new work.

MuseRelay Core was inspected at commit `1c4b2bb38a560f67a454dfcd350a270d97482eb5` (2026-10-24). Its reseller panel, CRM, queues, agents, knowledge, Bedrock integration, tools and ticket system are pre-existing, external to this repository.

Examples of pre-existing Core introductions verified through Git:

| File | Commit | Date |
| --- | --- | --- |
| `app/Services/BedrockService.php` | `2361ba1c` | 2026-04-15 |
| `app/AI/AgentExecutor.php` | `b30d3b01` | 2026-04-18 |
| `app/AI/Tools/Tickets/CreateTicketTool.php` | `d0940243` | 2026-05-08 |

References consulted: [AWS Chat Orchestrator](https://github.com/aws-samples/sample-chat-orchestrator-for-generative-ai-conversations), its WhatsApp trigger and SDK sender (MIT-0), and [SendWhatsAppMessage API](https://docs.aws.amazon.com/social-messaging/latest/APIReference/API_SendWhatsAppMessage.html). Bridge code is newly written around MuseRelay's contract, not a fork/copy of the historical application or entire AWS example. AWS SDK dependencies are Apache-2.0; retain upstream dependency licensing. No public licensing or disclosure of the historical SaaS is authorized by this repository.

Phase 1/2 initially evidenced simulated/in-memory tests. Phase 3 adds private PostgreSQL/Redis staging with native Core agent and ticket execution using controlled AWS SDK transports; see `PHASE-3.md`. These tests do not establish live AWS connectivity. Phase 2 adds newly written connector code and a three-file Core hook patch based on Core commit `b8e9bf8c74789689a7cebddd1856a094e380c9d9`; the historical Core files remain pre-existing, and their full source is not copied into this repository. No claim of real AWS messaging, Bedrock execution through this bridge, native ticket creation outside the synthetic staging fixture, APN/ACE completion or WABA approval should be inferred from source availability.

Phase 4 is prepared on `codex/aws-engagement-bridge-phase4`, descending from `bc571e75d143885c6ea782fa8125417681d85fb9`. Newly written on 2026-10-24: offline plan/source preparation, account/root read-only guards, a separate real-SDK staging bootstrap and metadata observer, redacted execution tracing/collector, acceptance verifier and deployment/removal proposals. These are new Bridge artifacts after the 2026-09-14 cutoff; the agent, Bedrock adapter and native tickets remain historical Core functionality. Git ancestry/diffs establish local code provenance, not hackathon eligibility or a live deployment. Original commits/dates are preserved; GitHub publication still requires specific authorization.
