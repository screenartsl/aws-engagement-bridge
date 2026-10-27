# Email (Amazon SES) and SMS (AWS End User Messaging SMS) confirmations

Status (2026-10-27):

| Transport | Status |
|---|---|
| Email (SES) | **Verified live.** One confirmation sent from MuseRelay (`aws-bridge:notify`) through `POST /notify` and SESv2 `SendEmail`, from `noreply@muserelay.com` to a verified test recipient. Delivered in 1 s, SPF/DKIM/DMARC pass. Repeating the same operation returned `duplicate` and sent nothing. |
| SMS | Deployed with `DryRun=true`, sender ID `MUSERELAY` (ES), one allowed test recipient. The account is in the SMS sandbox, and the Spanish sender ID is **not registered** yet (`ES_SENDER_ID_REGISTRATION` is required in Spain), so no real SMS should be sent until AWS approves that registration. **Parked on 2026-10-27** for the hackathon: production access requested from AWS (case open, spend limit 1 USD), the test destination could not be verified (the code did not arrive), and no real SMS has been delivered. Do not present SMS as an operational channel. |

Both transports stay restricted to the test recipients (`NotifyAllowedRecipients`) and the daily cap of 5. Core's `AWS_BRIDGE_NOTIFY_ENABLED` stays off; the test was run with it set for that single process only.

## What it does

When a business operation is confirmed (an appointment booked by the Bedrock agent, a ticket opened…), MuseRelay can send a confirmation by email or SMS, in addition to the WhatsApp reply. Each transport is independent: WhatsApp keeps its own functions, roles, queue and checks, and none of them were changed.

```
MuseRelay (Notifier) ──HTTPS, HMAC signed, path-bound──► API Gateway POST /notify ──► Notify Lambda (own IAM role)
                                                                                      ├─ SESv2 SendEmail
                                                                                      └─ SMS v2 SendTextMessage
```

| Piece | File |
|---|---|
| Validation, idempotency, SES/SMS adapter | [src/notify.ts](../src/notify.ts) |
| Lambda handler (`handlers.notify`) | [src/handlers.ts](../src/handlers.ts) |
| Function, route, parameters (all off by default) | [template.yaml](../template.yaml) (`DeployNotify`, `Notify*`) |
| Runtime role and provisioning policy (`GrantNotify`) | [deploy/send-iam/template.yaml](../deploy/send-iam/template.yaml) |
| Core: consent, deduplication, record | [integration/muserelay/src/Core/Notifier.php](../integration/muserelay/src/Core/Notifier.php) |
| Core: signed call | `Publisher::notify` |
| Core: send log table (migration not applied) | `Schema::createNotifications`, `database/migrations/2026_10_27_060000_…` |
| Controlled demo command | `php artisan aws-bridge:notify {channel} {contact} {email\|sms} {type:id} {text} --subject=` |
| Tests | [test/notify.test.ts](../test/notify.test.ts), `CoreTest::testNotify*` |

## Safeguards

- **Consent:** sends only if the customer chose that channel (`contacts.preferred_reminder_channel`, recorded by the agent's `calendar_record_notification_preference` tool). No choice, `none` or another channel → `no_consent`.
- **One send per operation:** `notifyId = sha256(binding | kind | reference | recipient)`. Core keeps it in `aws_bridge_notifications` and the Lambda claims it in DynamoDB; repeating returns the first result. A transport failure stays `pending` and is retried with the same id.
- **Off by default, four switches:** `AWS_BRIDGE_NOTIFY_ENABLED` (Core), `NotifyEnabled`, `NotifyEmailEnabled`, `NotifySmsEnabled` (Lambda).
- **SMS spend:** `MessageType=TRANSACTIONAL`, `MaxPrice` per message (default 0.10 USD), `DryRun=true` unless `NotifySmsDryRun=false`, daily cap (`NotifyDailyLimit`, default 20), optional recipient allowlist. The account's own SMS monthly spend limit still applies.
- **Opt-outs and bounces:** AWS SMS opt-out lists (STOP) and the SES suppression list are enforced by AWS; refusals come back as `rejected` with the AWS error name.
- **Isolation:** own role with only `ses:SendEmail` and `sms-voice:SendTextMessage` in this account and region, explicit deny on `bedrock:*` and `social-messaging:*`. Signing reuses the binding's Core→AWS key; the signature is bound to `/notify`, so it cannot be replayed on `/outbound`.
- **Privacy:** Core stores the recipient only as a hash and never the text. The Lambda logs metadata only (`notify_result`).

## Not yet built

- Delivery/bounce events (SES configuration set → SNS, SMS event destination) back into Core. The `notify_id` tag/context is already sent so the events can be linked later.
- An automatic trigger on appointment confirmation. Today the send is the `Notifier` service and the demo command.
- Panel UI and per-organization sender identities: today one sender identity per deployment.

## What needs the account owner

1. **IAM:** review and execute the change set `notify-ses-sms-admin` on `muserelay-aws-bridge-iam-foundation`. It adds `NotifyExecutionRole` and `NotifyProvisioningPolicy`.
2. **SES:** verify a sender identity in eu-west-1, preferably the domain `muserelay.com` (DKIM DNS records) or one address. While the account is in the SES sandbox, recipients must be verified as well. Limits are 200 messages per 24 h and 1 per second.
3. **SMS:** check the account's SMS sandbox status and monthly spend limit, and add verified destination numbers while in the sandbox. Choose an origination identity for Spain; an alphanumeric sender ID is the usual option. Spain has introduced a registry of alphanumeric sender IDs, so check its current requirements on AWS's Spain country page before relying on a sender ID. Do not buy numbers without approval.
4. **Deploy:** runtime change set with `DeployNotify=true`, `NotifyExecutionRoleArn`, the identities, the switches and `NotifyAllowedRecipients` for the demo. In Core: apply the migration with `--path` and set `AWS_BRIDGE_NOTIFY_ENABLED=true`.

## Approximate cost of a controlled test

| Item | Estimate |
|---|---|
| SES | 0.10 USD per 1,000 emails; a test of a few emails costs well under 0.01 USD |
| SMS to Spain | About 0.07–0.10 USD per message part (check the pricing page); `DryRun` costs nothing |
| Lambda, API Gateway, DynamoDB | Negligible at demo volume |
| IAM role | Free |

A controlled test with a few emails and up to 3 real SMS should stay under 1 USD.
