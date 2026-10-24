# Provenance

New repository started 2026-10-24 on branch `codex/aws-engagement-bridge`. No git remote or publication configured. Use `git log --format='%h %cI %s'` and `git ls-files` to enumerate original bridge commits/assets; do not relabel legacy code as new work.

MuseRelay Core was inspected at commit `1c4b2bb38a560f67a454dfcd350a270d97482eb5` (2026-10-24). Its reseller panel, CRM, queues, agents, knowledge, Bedrock integration, tools and ticket system are pre-existing, external to this repository.

Examples of pre-existing Core introductions verified through Git:

| File | Commit | Date |
| --- | --- | --- |
| `app/Services/BedrockService.php` | `2361ba1c` | 2026-04-15 |
| `app/AI/AgentExecutor.php` | `b30d3b01` | 2026-04-18 |
| `app/AI/Tools/Tickets/CreateTicketTool.php` | `d0940243` | 2026-05-08 |

References consulted: [AWS Chat Orchestrator](https://github.com/aws-samples/sample-chat-orchestrator-for-generative-ai-conversations), its WhatsApp trigger and SDK sender (MIT-0), and [SendWhatsAppMessage API](https://docs.aws.amazon.com/social-messaging/latest/APIReference/API_SendWhatsAppMessage.html). Bridge code is newly written around MuseRelay's contract, not a fork/copy of the historical application or entire AWS example. AWS SDK dependencies are Apache-2.0; retain upstream dependency licensing. No public licensing or disclosure of the historical SaaS is authorized by this repository.

Only local simulated tests are currently evidenced. No claim of real AWS messaging, Bedrock execution through this bridge, ticket creation, APN/ACE completion or WABA approval should be inferred from source availability.
