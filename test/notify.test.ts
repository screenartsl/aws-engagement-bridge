import test from 'node:test';
import assert from 'node:assert/strict';
import type { APIGatewayProxyEventV2 } from 'aws-lambda';
import { SendEmailCommand } from '@aws-sdk/client-sesv2';
import { SendTextMessageCommand } from '@aws-sdk/client-pinpoint-sms-voice-v2';
import { sign } from '../src/auth.js';
import { Binding, BridgeError, SendResult } from '../src/contracts.js';
import { NotifyAdapter, NotifyConfig, NotifyPort, notifyCommand, notifyConfig, notifyRequest } from '../src/notify.js';
import { notify } from '../src/handlers.js';
import { Claim, StateStore } from '../src/state.js';

const secret = 'synthetic-signing-key-test-not-a-real-key';
const binding: Binding = { id: 'demo', enabled: true, channelRef: 'demo-channel', region: 'eu-west-1', topicArn: 'unused',
  wabaId: '1234', metaPhoneNumberId: '5678', awsPhoneNumberId: 'unused', allowedRecipients: [] };
const sms = { version: 1, notifyId: 'notify-test-0001', bindingId: 'demo', kind: 'sms', to: '+34600000001', text: 'Your appointment is confirmed for Tuesday 10:00.', reference: { type: 'appointment', id: '42' } };
const email = { ...sms, notifyId: 'notify-test-0002', kind: 'email', to: 'customer@example.com', subject: 'Appointment confirmed' };
const config: NotifyConfig = { emailEnabled: true, smsEnabled: true, emailFrom: 'noreply@example.com', smsOrigination: 'ScreenArt',
  smsMaxPrice: '0.10', smsDryRun: true, dailyLimit: 0, allowedRecipients: [] };

class MemoryState implements StateStore {
  entries = new Map<string, { digest: string; result?: SendResult; done: boolean }>(); nonces = new Set<string>(); allowSpend = true;
  async claim(key: string, digest: string): Promise<Claim> {
    const old = this.entries.get(key);
    if (old && old.digest !== digest) throw new BridgeError('idempotency_conflict');
    if (old?.done) return { state: 'done', result: old.result };
    if (old) return { state: 'busy' };
    this.entries.set(key, { digest, done: false }); return { state: 'acquired', token: 't' };
  }
  async complete(key: string, _t: string, result?: SendResult) { const e = this.entries.get(key)!; e.done = true; e.result = result; }
  async release(key: string) { this.entries.delete(key); }
  async nonce(key: string) { if (this.nonces.has(key)) return false; this.nonces.add(key); return true; }
  async spend() { return this.allowSpend; }
}
function event(payload: unknown, signedPath = '/notify'): APIGatewayProxyEventV2 {
  const body = JSON.stringify(payload); const h = sign(secret, 'POST', signedPath, body);
  return { version: '2.0', routeKey: 'POST /notify', rawPath: '/notify', rawQueryString: '', body, isBase64Encoded: false,
    requestContext: { accountId: '111111111111', apiId: 't', domainName: 't.invalid', domainPrefix: 't', requestId: 's', routeKey: 'POST /notify',
      stage: '$default', time: 's', timeEpoch: Date.now(), http: { method: 'POST', path: '/notify', protocol: 'HTTP/1.1', sourceIp: '192.0.2.1', userAgent: 't' } },
    headers: { 'x-bridge-binding': 'demo', 'x-bridge-timestamp': h.timestamp, 'x-bridge-nonce': h.nonce, 'x-bridge-signature': h.signature } };
}
function port(sent: unknown[] = [], cfg: NotifyConfig = config): NotifyPort & { store: MemoryState } {
  return { bindings: [binding], secret: id => id === 'demo' ? secret : undefined, store: new MemoryState(), config: cfg,
    nonce(key) { return this.store.nonce(key); },
    adapter: { send: async c => { sent.push(c); return { state: 'accepted', providerMessageId: `aws-${c.notifyId}` }; } } };
}

test('notify commands are strictly validated: recipient per kind, header-safe subject, bounded SMS, typed reference', () => {
  assert.equal(notifyCommand(sms, [binding]).command.kind, 'sms');
  assert.equal(notifyCommand(email, [binding]).command.subject, 'Appointment confirmed');
  for (const bad of [{ ...sms, to: '600000001' }, { ...email, to: 'x@y' }, { ...email, subject: 'Hi\r\nBcc: a@b.c' }, { ...email, subject: undefined },
    { ...sms, text: 'x'.repeat(601) }, { ...sms, reference: { type: 'invoice', id: '1' } }, { ...sms, notifyId: '../x' }, { ...sms, kind: 'whatsapp' },
    { ...sms, bindingId: 'other' }]) assert.throws(() => notifyCommand(bad, [binding]));
});
test('SES and SMS use the official SDK commands; SMS is transactional, price-capped and dry-run by default', async () => {
  const calls: unknown[] = [];
  const adapter = new NotifyAdapter({ send: async c => { calls.push(c); return { MessageId: 'ses-1' }; } }, { send: async c => { calls.push(c); return { MessageId: 'sms-1' }; } });
  assert.deepEqual(await adapter.send(notifyCommand(email, [binding]).command, config), { state: 'accepted', providerMessageId: 'ses-1' });
  const ses = calls[0] as SendEmailCommand; assert.ok(ses instanceof SendEmailCommand);
  assert.deepEqual(ses.input.Destination, { ToAddresses: ['customer@example.com'] }); assert.equal(ses.input.FromEmailAddress, 'noreply@example.com');
  assert.deepEqual(ses.input.EmailTags, [{ Name: 'notify_id', Value: 'notify-test-0002' }, { Name: 'reference', Value: 'appointment-42' }]);
  assert.deepEqual(await adapter.send(notifyCommand(sms, [binding]).command, config), { state: 'accepted', providerMessageId: 'sms-1' });
  const text = calls[1] as SendTextMessageCommand; assert.ok(text instanceof SendTextMessageCommand);
  assert.deepEqual([text.input.MessageType, text.input.MaxPrice, text.input.DryRun, text.input.OriginationIdentity], ['TRANSACTIONAL', '0.10', true, 'ScreenArt']);
});
test('disabled or unconfigured transports and definitive AWS rejections never look like a send', async () => {
  const none = new NotifyAdapter({ send: async () => { throw new Error('must not be called'); } }, { send: async () => { throw new Error('must not be called'); } });
  assert.equal((await none.send(notifyCommand(sms, [binding]).command, { ...config, smsEnabled: false })).errorCode, 'sms_disabled');
  assert.equal((await none.send(notifyCommand(email, [binding]).command, { ...config, emailFrom: undefined })).errorCode, 'email_not_configured');
  const reject = (name: string) => new NotifyAdapter({ send: async () => { const e = new Error(); e.name = name; throw e; } }, { send: async () => { const e = new Error(); e.name = name; throw e; } });
  assert.deepEqual(await reject('MessageRejected').send(notifyCommand(email, [binding]).command, config), { state: 'rejected', errorCode: 'MessageRejected' });
  assert.deepEqual(await reject('TimeoutError').send(notifyCommand(sms, [binding]).command, config), { state: 'uncertain', errorCode: 'provider_result_unknown' });
});
test('signed /notify sends once; a repeated notifyId returns the first result without sending again; replays are rejected', async () => {
  const sent: unknown[] = []; const p = port(sent);
  const first = await notifyRequest(event(sms), p);
  assert.equal(first.statusCode, 200); assert.equal(JSON.parse(first.body!).providerMessageId, 'aws-notify-test-0001');
  const e = event(sms); await notifyRequest(e, p);
  assert.equal(JSON.parse((await notifyRequest(event(sms), p)).body!).duplicate, true);
  assert.equal((await notifyRequest(e, p)).statusCode, 409); assert.equal(sent.length, 1);
  assert.equal((await notifyRequest(event({ ...sms, text: 'Changed' }), p)).statusCode, 409); assert.equal(sent.length, 1);
});
test('/notify rejects other paths\' signatures, other bindings, recipients outside the allowlist, disabled kinds and daily cap', async () => {
  const sent: unknown[] = [];
  assert.equal((await notifyRequest(event(sms, '/outbound'), port(sent))).statusCode, 401);
  assert.equal((await notifyRequest(event({ ...sms, bindingId: 'other' }), port(sent))).statusCode, 422);
  assert.equal((await notifyRequest(event(sms), port(sent, { ...config, allowedRecipients: ['+34600000002'] }))).statusCode, 422);
  assert.equal((await notifyRequest(event(email), port(sent, { ...config, emailEnabled: false }))).statusCode, 422);
  const capped = port(sent, { ...config, dailyLimit: 1 }); capped.store.allowSpend = false;
  assert.equal((await notifyRequest(event(sms), capped)).statusCode, 429); assert.equal(capped.store.entries.size, 0);
  assert.equal(sent.length, 0);
});
test('handler is off without NOTIFY_ENABLED and configuration defaults are safe', async () => {
  assert.equal((await notify(event(sms))).statusCode, 503);
  const d = notifyConfig({});
  assert.deepEqual([d.emailEnabled, d.smsEnabled, d.smsDryRun, d.smsMaxPrice, d.dailyLimit], [false, false, true, '0.10', 20]);
  assert.throws(() => notifyConfig({ NOTIFY_SMS_MAX_PRICE: '5' }), /invalid_max_price/);
  assert.throws(() => notifyConfig({ NOTIFY_ALLOWED_RECIPIENTS: 'not-a-recipient' }), /invalid_allowlist/);
});
