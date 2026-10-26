# Three-minute demonstration plan — execute only after real E2E acceptance

1. **0:00–0:25:** Explain the new AWS transport and show the architecture. Clearly identify MuseRelay as the pre-existing SaaS and AWS Engagement Bridge as the new submission.
2. **0:25–1:00:** An approved tester asks on WhatsApp about a cultural activity. Show the event arriving via AWS Social/SNS and the synthetic conversation in the correct demo organization's inbox.
3. **1:00–1:50:** The tester requests registration and supplies the confirmation/details required by the native tool. Show the real Bedrock model/provider, `support_create_ticket` result and persisted ticket/audit in the panel.
4. **1:50–2:25:** Show the reply delivered via `SendWhatsAppMessage` and its correlated provider id/status. Say “request registered”, not “seat confirmed”.
5. **2:25–3:00:** Demonstrate duplicate-event handling and describe the disabled-by-default configuration, organization isolation and deployment instructions.

Do not film secrets, real customer data, unrelated tenants, or raw sensitive payloads. English narration/subtitles and instructions are needed for judging. Keep a usable evaluation environment during the required judging period. Current source/tests do not provide the live footage above yet.

External items to resolve separately: AWS account/region, new number and WABA association, Social phone ARN and Meta phone id, supported Meta API version, approved test recipient, demo Core instance and organization, agent/ticket/knowledge assignment, Bedrock model access, APN/ACE submission and repository judge access.
