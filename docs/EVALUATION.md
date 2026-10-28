# Evaluation guide

A short path through the submission for judges. Every capability below is in one of three states:

- **Verified live** — observed in production with the real WhatsApp number on AWS; evidence listed in section 4.
- **Implemented, covered by automated tests** — deployed, but not yet observed end to end with real traffic.
- **Not claimed** — prepared or partial; not part of the submission.

## 1. Read the code that uses AWS

| What | Where |
| --- | --- |
| `SendWhatsAppMessage`, `PostWhatsAppMessageMedia`, template listing through `@aws-sdk/client-socialmessaging` | [src/social.ts](../src/social.ts) |
| `GetWhatsAppMessageMedia` for inbound media | [src/media.ts](../src/media.ts) |
| Lambda entry points (inbound, enqueue, sender, notify) | [src/handlers.ts](../src/handlers.ts), [src/workers.ts](../src/workers.ts) |
| Status events (delivered/read) correlated to the sent message | [src/normalize.ts](../src/normalize.ts) |
| HMAC contract between AWS and MuseRelay | [src/auth.ts](../src/auth.ts), [integration/muserelay/src/Core/Protocol.php](../integration/muserelay/src/Core/Protocol.php) |
| Live runtime stack (SAM/CloudFormation) | [template.yaml](../template.yaml) |
| Live IAM roles; sender limited to one phone ARN | [deploy/send-iam/template.yaml](../deploy/send-iam/template.yaml) |
| MuseRelay connector: inbox, outbox, delivery status, AI tool guard | [integration/muserelay/src/Core/](../integration/muserelay/src/Core/) |

Architecture diagram: [architecture-diagram.png](architecture-diagram.png).

## 2. Run the tests

No AWS account or credentials needed.

```sh
npm ci --ignore-scripts
npm test
```

The PHP connector tests are described in [STAGING.md](STAGING.md).

## 3. Try the live demo

1. Open https://demo.screenart.es (fictitious company "Screen Art Connect") and use the chat in the corner. It is answered by the same MuseRelay agent (Claude Haiku 4.5 on Amazon Bedrock) that answers the WhatsApp number on AWS.
2. *"My internet connection is down. I need help."* — the agent asks you to confirm before opening a request, then for a name and an email address you can read, and gives a reference such as TK-2026-00002.
3. In a private window (a new conversation): *"I already have a support request, but I don't remember the ticket number."* — give the email you used, type the 6-digit code you receive, and continue the same request.
4. The WhatsApp side of the journey is shown in the demo video.

Abuse protection applies to testers too: from one IP, asking for codes for more than 3 different references, emails or phones within an hour blocks that origin for 60 minutes.

## 4. Status and live evidence

Account 365470560685, region eu-west-1, demo WhatsApp channel in MuseRelay. Message contents are omitted; numbers are MuseRelay message, ticket and audit-log ids.

### Verified live

| Capability | Evidence |
| --- | --- |
| WhatsApp text received through SNS → SQS → Inbound Lambda into the native inbox | message 6735 (2026-10-26) and 20 inbound texts up to 2026-10-27 |
| Reply sent with `SendWhatsAppMessage` and received on the handset | message 6737 (2026-10-26). Message 6736 was rejected by AWS because the recipient lacked the E.164 `+` (fixed in commit `0c29dc9`) |
| Delivered/read status returned through AWS and shown in the inbox | message 6741 marked **read** (2026-10-26, commit `38983a0`); 22 outbound texts read up to 2026-10-28 |
| Signed contract | probe against the live endpoint with a status event for a non-existent message: valid signature → `202`, same request repeated → `409` (replay), forged signature → `401` |
| AI agent replies through the Bridge (Claude Haiku 4.5 on Amazon Bedrock) | 19 agent replies sent through AWS on 2026-10-27 (messages 6812–6861), all read on the handset |
| Ticket actions by the agent over WhatsApp through AWS | ticket TK-2026-00001 created after the customer confirmed (audit 138, 2026-10-27 15:18), information added (audit 139–143), status changed (audit 144); tool calls recorded with model `claude-haiku-4-5` |
| Inbound photo and voice note fetched with `GetWhatsAppMessageMedia` and stored | images 6786, 6810; voice note 6808 (2026-10-27) |
| File sent from the inbox (`PostWhatsAppMessageMedia` + `SendWhatsAppMessage`) | image 6806, read (2026-10-27) |
| One-time code for continuing a WhatsApp ticket from the web chatbot, delivered through AWS to the ticket's WhatsApp | message 6928, read (2026-10-28 15:45); code accepted in web conversation 1788 (audit 145 `access_code_requested`, 146 `access_verified`) |
| Confirmation email through Amazon SES (`POST /notify`, SESv2 `SendEmail`) | one email to a verified test recipient (2026-10-27); see [NOTIFY-SES-SMS.md](NOTIFY-SES-SMS.md) |

In that first web-chat run (2026-10-28), the agent did not reply after the correct code (an empty message). It was fixed the same day in MuseRelay 3.169.65: the server now checks the code before calling the model. The full journey has to be re-run (see below).

### Implemented, covered by automated tests — live check pending

These live in the MuseRelay Core (proprietary, not in this repository) and are covered by its test suite:

- Full WhatsApp → web chatbot journey after the fix: status shown and information added from the web chat to the same ticket.
- Web chatbot → WhatsApp direction: a request opened on the web is continued from WhatsApp; the code goes to the ticket's email (platform mail service, not SES).
- Recovering requests without the reference, with the email or phone used to open them (MuseRelay 3.169.66).
- Central sending limits for verification codes, enumeration blocking and neutral answers (MuseRelay 3.169.65; multi-session, multi-IP and multi-organisation abuse tests).
- WhatsApp template send through AWS; inbound PDF.
- A calendar action by the agent through the Bridge.

### Not claimed

- SMS through AWS End User Messaging SMS: deployed in dry-run only; no real SMS delivered ([NOTIFY-SES-SMS.md](NOTIFY-SES-SMS.md)).
- Amazon SES for MuseRelay's ticket emails: verification codes and ticket emails use the platform mail service.
- Video, stickers and documents other than PDF on inbound WhatsApp (ignored).

## 5. What is new

Everything in this repository was written from 2026-10-24 onwards (see `git log --reverse`). The MuseRelay platform it plugs into (inbox, agents, tools, tickets) is pre-existing and is not included; see [PROVENANCE.md](PROVENANCE.md). The Core changes used in the demo journey (cross-channel continuity with one-time codes, central code-sending limits) were made in MuseRelay during the same period and are described in the technical document.
