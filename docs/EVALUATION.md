# Evaluation guide

A short path through the submission for judges.

## 1. Read the code that uses AWS

| What | Where |
| --- | --- |
| `SendWhatsAppMessage` through `@aws-sdk/client-socialmessaging` | [src/social.ts](../src/social.ts) |
| Lambda entry points (inbound, enqueue, sender) | [src/handlers.ts](../src/handlers.ts), [src/workers.ts](../src/workers.ts) |
| Status events (delivered/read) correlated to the sent message | [src/normalize.ts](../src/normalize.ts) |
| HMAC contract between AWS and MuseRelay | [src/auth.ts](../src/auth.ts), [integration/muserelay/src/Core/Protocol.php](../integration/muserelay/src/Core/Protocol.php) |
| Live runtime stack (SAM/CloudFormation) | [template.yaml](../template.yaml) |
| Live IAM roles; sender limited to one phone ARN | [deploy/send-iam/template.yaml](../deploy/send-iam/template.yaml) |
| MuseRelay connector: inbox, outbox, delivery status, AI tool guard | [integration/muserelay/src/Core/](../integration/muserelay/src/Core/) |

## 2. Run the tests

No AWS account or credentials needed.

```sh
npm ci --ignore-scripts
npm test
```

The PHP connector tests are described in [STAGING.md](STAGING.md).

## 3. Live evidence (2026-10-26, account 365470560685, eu-west-1)

Recorded in MuseRelay for the demo WhatsApp channel; message contents omitted:

| MuseRelay message | Direction | Result |
| --- | --- | --- |
| 6735 | inbound | received in the native inbox through SNS → SQS → Inbound Lambda |
| 6736 | outbound | rejected by AWS: recipient lacked the E.164 `+` (fixed in commit `0c29dc9`) |
| 6737 | outbound | accepted by `SendWhatsAppMessage` and received on the handset (before status correlation existed, so the inbox shows it as sent) |
| 6741 | outbound | accepted, then **read**: the status came back through AWS and updated the inbox (commit `38983a0`) |

Not yet demonstrated: an AI agent reply (Bedrock) and its tools through this bridge.

## 4. What is new

Everything in this repository was written from 2026-10-24 onwards (see `git log --reverse`). The MuseRelay platform it plugs into (inbox, agents, tools, tickets) is pre-existing and is not included; see [PROVENANCE.md](PROVENANCE.md).
