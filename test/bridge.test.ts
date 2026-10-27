import test from 'node:test';
import assert from 'node:assert/strict';
import { ListWhatsAppMessageTemplatesCommand, SendWhatsAppMessageCommand, SocialMessagingClient } from '@aws-sdk/client-socialmessaging';
import { sign, verify } from '../src/auth.js';
import { Binding, BridgeError, InboundEvent, OutboundCommand, SendResult, outbound, parseBindings } from '../src/contracts.js';
import { normalize } from '../src/normalize.js';
import { SocialAdapter } from '../src/social.js';
import { CoreClient, CorePort } from '../src/core-client.js';
import { Claim, StateStore } from '../src/state.js';
import { deliver, ingest } from '../src/workers.js';

const secret = 'test-only-secret-never-use-in-a-deployment';
const at = 1792860000000;
const binding: Binding = { id: 'demo', region: 'eu-west-1', channelRef: 'bridge-demo', enabled: true,
  topicArn: 'arn:aws:sns:eu-west-1:111111111111:bridge-demo', wabaId: '123456', metaPhoneNumberId: '789012',
  awsPhoneNumberId: 'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/abc123', allowedRecipients: ['+12025550123'] };
const command: OutboundCommand = { version: 1, commandId: 'out-test-0001', bindingId: binding.id,
  channelRef: binding.channelRef, recipient: '+12025550123', text: 'Synthetic demo response' };
function envelope(messages: unknown[] = [{ id: 'wamid.synthetic1', from: '12025550123', timestamp: '1792860000', type: 'text', text: { body: 'Synthetic request' } }], statuses?: unknown[]) {
  return { Type: 'Notification', TopicArn: binding.topicArn,
    Message: JSON.stringify({ whatsAppWebhookEntry: JSON.stringify({ id: binding.wabaId,
      changes: [{ field: 'messages', value: { metadata: { phone_number_id: binding.metaPhoneNumberId }, messages, statuses } }] }) }) };
}

class MemoryState implements StateStore {
  entries = new Map<string, { digest: string; result?: SendResult; done: boolean }>();
  nonces = new Set<string>(); budget = 0; allowSpend = true;
  async claim(key: string, digest: string, _outbound: boolean): Promise<Claim> {
    const old = this.entries.get(key);
    if (old && old.digest !== digest) throw new BridgeError('idempotency_conflict');
    if (old?.done) return { state: 'done', result: old.result };
    if (old) return { state: 'busy' };
    this.entries.set(key, { digest, done: false }); return { state: 'acquired', token: 'test-token' };
  }
  async complete(key: string, _token: string, result?: SendResult) { const e = this.entries.get(key)!; e.done = true; e.result = result; }
  async release(key: string) { this.entries.delete(key); }
  async nonce(key: string) { if (this.nonces.has(key)) return false; this.nonces.add(key); return true; }
  async spend() { this.budget++; return this.allowSpend; }
}
class Core implements CorePort {
  events: InboundEvent[] = []; receipts: SendResult[] = []; fail = false; failReceipt = false;
  async accept(event: InboundEvent) { if (this.fail) throw new Error('simulated_core_down'); this.events.push(event); }
  async receipt(_b: string, _c: string, result: SendResult) { if (this.failReceipt) throw new Error('simulated_callback_down'); this.receipts.push(result); }
}

test('signatures bind exact bytes, path and method; reject stale and malformed requests', () => {
  const h = sign(secret, 'POST', '/outbound', '{"x":1}', at);
  verify(secret, 'POST', '/outbound', '{"x":1}', h, at);
  for (const [method, path, body] of [['GET', '/outbound', '{"x":1}'], ['POST', '/other', '{"x":1}'], ['POST', '/outbound', '{"x":2}']]) {
    assert.throws(() => verify(secret, method!, path!, body!, h, at), /invalid_signature/);
  }
  assert.throws(() => verify(secret, 'POST', '/outbound', '{"x":1}', h, at + 301_000), /expired_signature/);
  assert.throws(() => verify(secret, 'POST', '/outbound', '{}', { ...h, signature: 'x' }, at), /invalid_signature/);
});
test('binding defaults disabled; rejects ambiguous identities and cross-account resource ARNs', () => {
  assert.equal(parseBindings(JSON.stringify([{ ...binding, enabled: undefined }]))[0]?.enabled, false);
  assert.throws(() => parseBindings(JSON.stringify([binding, binding])), /duplicate_binding/);
  assert.throws(() => parseBindings(JSON.stringify([{ ...binding, awsPhoneNumberId: binding.awsPhoneNumberId.replace('111111111111', '222222222222') }])), /invalid_aws_phone_arn/);
});
test('normalizer reads every message; provider idempotency does not depend on SNS notification id', () => {
  const env = envelope([{ id: 'one', from: '12025550123', timestamp: '1792860000', type: 'text', text: { body: 'one' } },
    { id: 'two', from: '12025550123', timestamp: '1792860001', type: 'text', text: { body: 'two' } }]);
  const events = normalize(env, [binding]); assert.equal(events.length, 2);
  assert.notEqual(events[0]?.eventId, events[1]?.eventId);
  assert.equal(normalize({ ...env, MessageId: 'new-sns-id' }, [binding])[0]?.eventId, events[0]?.eventId);
});
test('untrusted topics, unknown WABA/phone and disabled bindings fail closed', () => {
  assert.throws(() => normalize({ ...envelope(), TopicArn: binding.topicArn.replace('111111111111', '222222222222') }, [binding]), /untrusted_topic/);
  assert.throws(() => normalize(envelope(), [{ ...binding, metaPhoneNumberId: '9999' }]), /unmapped_identity/);
  assert.throws(() => normalize(envelope(), [{ ...binding, wabaId: '9999' }]), /unmapped_identity/);
  assert.throws(() => normalize(envelope(), [{ ...binding, enabled: false }]), /untrusted_topic/);
});
test('delivery/read events stay statuses, never become customer prompts', () => {
  const events = normalize(envelope([], [{ id: 'wamid.out', timestamp: '1792860000', status: 'read' }]), [binding]);
  assert.equal(events[0]?.kind, 'status'); assert.equal(events[0]?.text, undefined);
});
test('status echoes our commandId as commandRef; malformed callback data is ignored', () => {
  const ok = normalize(envelope([], [{ id: 'wamid.out', timestamp: '1792860000', status: 'delivered', biz_opaque_callback_data: 'cmd_synthetic_0001' }]), [binding]);
  assert.equal(ok[0]?.commandRef, 'cmd_synthetic_0001'); assert.equal(ok[0]?.providerMessageId, 'wamid.out');
  const bad = normalize(envelope([], [{ id: 'wamid.out', timestamp: '1792860000', status: 'delivered', biz_opaque_callback_data: '../x' }]), [binding]);
  assert.equal(bad[0]?.commandRef, undefined);
});
test('MVP ignores media; empty allowlist = open number, non-empty list restricts senders', () => {
  assert.equal(normalize(envelope([{ type: 'image' }]), [binding]).length, 0);
  assert.equal(normalize(envelope(), [{ ...binding, allowedRecipients: [] }]).length, 1);
  assert.equal(normalize(envelope(), [{ ...binding, allowedRecipients: ['+34600000000'] }]).length, 0);
});
test('open binding still validates the outbound recipient format', () => {
  assert.doesNotThrow(() => outbound({ ...command, recipient: '+12025550124' }, [{ ...binding, allowedRecipients: [] }]));
  assert.throws(() => outbound({ ...command, recipient: 'not-a-phone' }, [{ ...binding, allowedRecipients: [] }]), /invalid_recipient|invalid_string/);
});
test('outbound cannot select another channel or recipient', () => {
  assert.throws(() => outbound({ ...command, channelRef: 'other-tenant' }, [binding]), /channel_mismatch/);
  assert.throws(() => outbound({ ...command, recipient: '+12025550124' }, [binding]), /recipient_not_allowed/);
});
test('adapter is off by default and cannot call the provided transport', async () => {
  let calls = 0;
  const adapter = new SocialAdapter({ send: async () => { calls++; return {}; } }, 'v23.0');
  await assert.rejects(adapter.send(command, binding), /live_sending_disabled/); assert.equal(calls, 0);
});
test('adapter invokes actual SDK command with AWS identity and UTF-8 payload', async () => {
  let calls = 0;
  const adapter = new SocialAdapter({ send: async c => {
    calls++; assert.ok(c instanceof SendWhatsAppMessageCommand);
    assert.equal(c.input.originationPhoneNumberId, binding.awsPhoneNumberId);
    assert.equal(c.input.metaApiVersion, 'v23.0');
    const data = JSON.parse(new TextDecoder().decode(c.input.message));
    assert.equal(data.to, '+12025550123'); assert.equal(data.text.body, command.text); // AWS Social requires E.164 with '+'
    assert.equal(data.messaging_product, 'whatsapp'); assert.equal(data.biz_opaque_callback_data, command.commandId);
    return { messageId: 'aws.synthetic' };
  } }, 'v23.0', true);
  assert.deepEqual(await adapter.send(command, binding), { state: 'accepted', providerMessageId: 'aws.synthetic' }); assert.equal(calls, 1);
});
test('template command sends a WhatsApp template with body parameters; invalid templates are rejected', async () => {
  const tpl: OutboundCommand = { ...command, template: { name: 'appointment_reminder', language: 'es', parameters: ['Ana', 'lunes'] } };
  let data: any;
  const adapter = new SocialAdapter({ send: async c => { data = JSON.parse(new TextDecoder().decode(c.input.message)); return { messageId: 'aws.tpl' }; } }, 'v23.0', true);
  assert.equal((await adapter.send(tpl, binding)).state, 'accepted');
  assert.equal(data.type, 'template'); assert.equal(data.text, undefined); assert.equal(data.template.name, 'appointment_reminder');
  assert.equal(data.template.language.code, 'es'); assert.deepEqual(data.template.components[0].parameters, [{ type: 'text', text: 'Ana' }, { type: 'text', text: 'lunes' }]);
  assert.equal(data.biz_opaque_callback_data, tpl.commandId);
  for (const template of [{ name: 'Bad Name', language: 'es', parameters: [] }, { name: 'ok', language: 'es; drop', parameters: [] }, { name: 'ok', language: 'es', parameters: [''] }]) {
    assert.throws(() => outbound({ ...command, template }, [binding]), /invalid_template/);
  }
});
test('templates lists only approved ones of the binding WABA, with their components', async () => {
  const calls: string[] = [];
  const adapter = new SocialAdapter({ send: async (c: any) => {
    calls.push(c.constructor.name);
    if (c instanceof ListWhatsAppMessageTemplatesCommand) {
      assert.equal(c.input.id, 'waba-' + 'a'.repeat(32));
      return { templates: [{ templateName: 'hello_world', templateLanguage: 'en_US', templateStatus: 'APPROVED', templateCategory: 'UTILITY', metaTemplateId: '1' },
        { templateName: 'draft', templateLanguage: 'es', templateStatus: 'PENDING', metaTemplateId: '2' }] };
    }
    assert.equal(c.input.metaTemplateId, '1');
    return { template: JSON.stringify({ components: [{ type: 'BODY', text: 'Hello {{1}}' }] }) };
  } }, 'v23.0');
  await assert.rejects(adapter.templates(binding), /templates_not_configured/);
  const list = await adapter.templates({ ...binding, awsWabaId: 'waba-' + 'a'.repeat(32) });
  assert.deepEqual(list, [{ name: 'hello_world', language: 'en_US', category: 'UTILITY', components: [{ type: 'BODY', text: 'Hello {{1}}' }] }]);
  assert.deepEqual(calls, ['ListWhatsAppMessageTemplatesCommand', 'GetWhatsAppMessageTemplateCommand']);
  assert.throws(() => parseBindings(JSON.stringify([{ ...binding, awsWabaId: 'waba-../x' }])), /invalid_aws_waba_id/);
});
test('official SDK serialization executes against an in-process HTTP handler, no network', async () => {
  let requests = 0;
  const beforeRun = process.env.BRIDGE_RUN_ID;
  const beforeInfo = console.info;
  const audit: string[] = [];
  const sdk = new SocialMessagingClient({ region: 'eu-west-1', maxAttempts: 1,
    credentials: { accessKeyId: 'TESTONLY', secretAccessKey: 'test-only-not-real' },
    requestHandler: { handle: async (request: { method: string; path: string; body?: unknown }) => {
      requests++; assert.equal(request.method, 'POST'); assert.equal(request.path, '/v1/whatsapp/send');
      const raw = typeof request.body === 'string' ? request.body : new TextDecoder().decode(request.body as Uint8Array);
      const body = JSON.parse(raw); assert.equal(body.originationPhoneNumberId, binding.awsPhoneNumberId);
      const message = JSON.parse(Buffer.from(body.message, 'base64').toString('utf8')); assert.equal(message.text.body, command.text);
      return { response: { statusCode: 200, headers: { 'content-type': 'application/json', 'x-amzn-requestid': 'synthetic-sdk-request' }, body: Buffer.from('{"messageId":"sdk.synthetic"}') } };
    } },
  });
  try {
    process.env.BRIDGE_RUN_ID = 'phase4_synthetic_sdk_test';
    console.info = (line: string) => { audit.push(line); };
    const result = await new SocialAdapter(sdk, 'v23.0', true).send(command, binding);
    assert.equal(result.providerMessageId, 'sdk.synthetic'); assert.equal(requests, 1);
    const entry = JSON.parse(audit[0]!);
    assert.equal(entry.requestId, 'synthetic-sdk-request'); assert.equal(entry.httpStatus, 200);
    assert.ok(!audit[0]!.includes(command.text)); assert.ok(!audit[0]!.includes(command.recipient));
  } finally {
    console.info = beforeInfo;
    if (beforeRun === undefined) delete process.env.BRIDGE_RUN_ID; else process.env.BRIDGE_RUN_ID = beforeRun;
    sdk.destroy();
  }
});
test('timeouts and unknown failures are uncertain; explicit throttle is retryable', async () => {
  const timeout = new SocialAdapter({ send: async () => { throw new Error('contains private data'); } }, 'v23.0', true);
  assert.deepEqual(await timeout.send(command, binding), { state: 'uncertain', errorCode: 'provider_result_unknown' });
  const throttle = new SocialAdapter({ send: async () => { const e = new Error(); e.name = 'ThrottledRequestException'; throw e; } }, 'v23.0', true);
  await assert.rejects(throttle.send(command, binding), /provider_throttled/);
});
test('ingress deduplicates and retries a failed Core delivery', async () => {
  const store = new MemoryState(); const core = new Core(); core.fail = true;
  await assert.rejects(ingest(envelope(), [binding], store, core)); assert.equal(store.entries.size, 0);
  core.fail = false; await ingest(envelope(), [binding], store, core); await ingest(envelope(), [binding], store, core);
  assert.equal(core.events.length, 1);
});
test('daily limit 0 means no cap: inbound is forwarded without spending budget', async () => {
  const store = new MemoryState(); const core = new Core(); store.allowSpend = false;
  await ingest(envelope(), [binding], store, core, 0);
  assert.equal(core.events.length, 1);
  await assert.rejects(ingest(envelope(), [binding], store, core, -1), /invalid_daily_limit/);
});
test('statuses do not consume agent turn budget and inbound budget blocks forwarding', async () => {
  const store = new MemoryState(); const core = new Core(); store.allowSpend = false;
  await assert.rejects(ingest(envelope(), [binding], store, core), /daily_limit_reached/); assert.equal(core.events.length, 0);
  await ingest(envelope([], [{ id: 'out', timestamp: '1792860000', status: 'sent' }]), [binding], store, core);
  assert.equal(core.events[0]?.kind, 'status'); assert.equal(store.budget, 1);
});
test('duplicate outbound command never sends twice; changed payload is rejected', async () => {
  const store = new MemoryState(); const core = new Core(); let calls = 0;
  const sender = { send: async (): Promise<SendResult> => { calls++; return { state: 'accepted', providerMessageId: 'out' }; } };
  await deliver(command, [binding], store, core, sender, 20); await deliver(command, [binding], store, core, sender, 20);
  assert.equal(calls, 1);
  await assert.rejects(deliver({ ...command, text: 'changed' }, [binding], store, core, sender, 20), /idempotency_conflict/);
});
test('failed receipt notification retries only notification, not the accepted send', async () => {
  const store = new MemoryState(); const core = new Core(); core.failReceipt = true; let calls = 0;
  const sender = { send: async (): Promise<SendResult> => { calls++; return { state: 'accepted', providerMessageId: 'out' }; } };
  await assert.rejects(deliver(command, [binding], store, core, sender, 20));
  core.failReceipt = false; await deliver(command, [binding], store, core, sender, 20);
  assert.equal(calls, 1); assert.equal(core.receipts.length, 1);
});
test('uncertain send is persisted and cannot be automatically resent', async () => {
  const store = new MemoryState(); const core = new Core(); let calls = 0;
  const sender = { send: async (): Promise<SendResult> => { calls++; return { state: 'uncertain', errorCode: 'timeout' }; } };
  await deliver(command, [binding], store, core, sender, 20); await deliver(command, [binding], store, core, sender, 20);
  assert.equal(calls, 1); assert.equal(core.receipts[0]?.state, 'uncertain');
});
test('outbound budget stops the send and keeps retry possible', async () => {
  const store = new MemoryState(); const core = new Core(); store.allowSpend = false;
  let calls = 0; const sender = { send: async (): Promise<SendResult> => { calls++; return { state: 'accepted' }; } };
  await assert.rejects(deliver(command, [binding], store, core, sender, 20), /daily_limit_reached/);
  assert.equal(calls, 0); assert.equal(store.entries.size, 0);
});
test('Core transport signs exact body and rejects redirects, wrong acknowledgements and untrusted URL forms', async () => {
  let calls = 0;
  const client = new CoreClient('https://demo.invalid', () => secret, async (_url, init) => {
    calls++; const h = init!.headers as Record<string, string>;
    verify(secret, 'POST', '/api/aws-engagement-bridge/events', String(init!.body), {
      timestamp: h['x-bridge-timestamp']!, nonce: h['x-bridge-nonce']!, signature: h['x-bridge-signature']! });
    assert.equal(init!.redirect, 'error'); return new Response(JSON.stringify({ id: 'wrong', status: 'accepted' }), { status: 202 });
  });
  await assert.rejects(client.accept(normalize(envelope(), [binding])[0]!), /invalid_core_ack/); assert.equal(calls, 1);
  assert.throws(() => new CoreClient('http://127.0.0.1', () => secret), /invalid_core_url/);
  assert.throws(() => new CoreClient('https://demo.invalid/path', () => secret), /invalid_core_url/);
});

// IAM's phone-number ARN format includes the origination id; AWS examples include hyphens.
test('phone ARNs accept AWS prefixed identifiers and reject path/wildcard injection', () => {
  const arn = binding.awsPhoneNumberId.replace('/abc123', '/phone-number-id-abc123');
  assert.equal(parseBindings(JSON.stringify([{ ...binding, awsPhoneNumberId: arn }]))[0]?.awsPhoneNumberId, arn);
  for (const suffix of ['/other', ':other', '*']) {
    assert.throws(() => parseBindings(JSON.stringify([{ ...binding, awsPhoneNumberId: arn + suffix }])), /invalid_aws_phone_arn/);
  }
});
