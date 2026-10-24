import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { SocialMessagingClient } from '@aws-sdk/client-socialmessaging';
import { normalize } from '../../dist/src/normalize.js';
import { CoreClient } from '../../dist/src/core-client.js';
import { enqueueRequest } from '../../dist/src/enqueue.js';
import { ingest, deliver } from '../../dist/src/workers.js';
import { SocialAdapter } from '../../dist/src/social.js';

const php = (mode, packet) => execFileSync('php', [new URL('./console.php', import.meta.url).pathname, mode], {
  env: process.env, input: packet ? JSON.stringify(packet) : undefined, encoding: 'utf8', timeout: 60000,
});
const binding = { id: 'demo', channelRef: 'demo-channel', enabled: true, region: 'eu-west-1', wabaId: '123456',
  metaPhoneNumberId: '789012', awsPhoneNumberId: 'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/phone-number-id-stageabc123',
  topicArn: 'arn:aws:sns:eu-west-1:111111111111:stage-events', allowedRecipients: ['+12025550123'] };
// Controlled DynamoDB/SQS substitutes: PostgreSQL and Redis below are REAL services.
class SimulatedState {
  entries = new Map(); nonces = new Set();
  async claim(key, digest) {
    const old = this.entries.get(key);
    if (old && old.digest !== digest) throw Error('idempotency_conflict');
    if (old?.done) return { state: 'done', result: old.result };
    if (old) return { state: 'busy' };
    this.entries.set(key, { digest }); return { state: 'acquired', token: key };
  }
  async complete(key, token, result) { Object.assign(this.entries.get(key), { done: true, result }); }
  async release(key) { this.entries.delete(key); }
  async nonce(key) { if (this.nonces.has(key)) return false; this.nonces.add(key); return true; }
  async spend() { return true; }
}
const request = async (url, options) => {
  const packet = { endpoint: new URL(url).pathname.split('/').at(-1), body: options.body, headers: options.headers };
  const response = JSON.parse(php('accept', packet));
  assert.ok([200,202].includes(response.statusCode), JSON.stringify(response));
  return new Response(JSON.stringify(response.body), { status: response.statusCode });
};
const client = new CoreClient('https://bridge-stage.invalid', () => 'synthetic-stage-core-key-no-live-credentials', request);
const state = new SimulatedState();
const turn = Number(process.argv[2] ?? 1);
const value = { metadata: { phone_number_id: binding.metaPhoneNumberId }, messages: [{ id: `wamid.stage-turn-${turn}`, from: '12025550123',
  timestamp: String(Math.floor(Date.now()/1000)), type: 'text', text: { body: turn === 1 ? 'Necesito registrar una solicitud de accesibilidad para el museo.' : 'Sí, confirma y registra mi solicitud. Soy Demo Visitor.' } }] };
const envelope = v => ({ Type: 'Notification', TopicArn: binding.topicArn,
  Message: JSON.stringify({ whatsAppWebhookEntry: JSON.stringify({ id: binding.wabaId, changes: [{ field: 'messages', value: v }] }) }) });
await ingest(envelope(value), [binding], state, client);
await ingest(envelope(value), [binding], state, client);
await client.accept(normalize(envelope(value), [binding])[0]); // Lost Lambda acknowledgement, fresh signed nonce.
process.stdout.write(php('dispatch'));
process.stdout.write(php('worker'));
let inspection = JSON.parse(php('inspect'));
assert.equal(inspection.counts.tickets, turn === 1 ? 0 : 1, 'Native confirmation must gate real ticket creation');
assert.equal(inspection.counts.ticket_audit_logs, turn === 1 ? 0 : 1);
if (turn === 1) assert.equal(inspection.actions[0].error, 'ticket_confirmation_required');
else assert.ok(inspection.actions.some(a => a.ok && a.number), JSON.stringify(inspection.actions));
assert.equal(inspection.counts.aws_bridge_actions, turn);
assert.equal(inspection.counts.conversations, 1);
assert.ok(inspection.events.every(e => e.state === 'done' && e.attempts === 1), JSON.stringify(inspection));
const queuedIds = new Set(inspection.outbox.filter(o => o.state === 'queued').map(o => o.id));
const packets = readFileSync(process.env.BRIDGE_STAGE_ROOT + '/outbound.ndjson', 'utf8').trim().split('\n').map(JSON.parse).filter(p => queuedIds.has(p.command.commandId));
assert.equal(packets.length, 1);
let simulatedSends = 0;
const sdk = new SocialMessagingClient({ region: 'eu-west-1', maxAttempts: 1, credentials: { accessKeyId: 'TESTONLY', secretAccessKey: 'synthetic-stage-only' },
  requestHandler: { handle: async req => {
    assert.equal(req.method, 'POST'); assert.equal(req.path, '/v1/whatsapp/send'); simulatedSends++;
    const payload = JSON.parse(typeof req.body === 'string' ? req.body : new TextDecoder().decode(req.body)); assert.equal(payload.originationPhoneNumberId, binding.awsPhoneNumberId);
    return { response: { statusCode: 200, headers: {}, body: new TextEncoder().encode(JSON.stringify({ messageId: `wamid.stage-reply-${turn}` })) } };
  } } });
const sender = new SocialAdapter(sdk, 'v23.0', true); // ONLY this in-memory SDK handler is enabled; no network exists.
for (const packet of packets) {
  const queue = [];
  const apiEvent = { rawPath: '/outbound', body: packet.body, headers: packet.headers, isBase64Encoded: false, requestContext: { http: { method: 'POST' } } };
  const port = { bindings: [binding], secret: () => 'synthetic-stage-outbound-key-independent-only', nonce: key => state.nonce(key), queue: async c => { queue.push(c); } };
  assert.equal((await enqueueRequest(apiEvent, port)).statusCode, 202);
  assert.equal((await enqueueRequest(apiEvent, port)).statusCode, 409);
  assert.equal(queue.length, 1);
  await deliver(queue[0], [binding], state, client, sender, 20);
  await deliver(queue[0], [binding], state, client, sender, 20);
}
assert.equal(simulatedSends, 1, 'Repeated delivery must not resend');
const bedrockCalls = readFileSync(process.env.BRIDGE_STAGE_ROOT + '/bedrock.ndjson', 'utf8').trim().split('\n').length;
await ingest(envelope({ metadata: value.metadata, statuses: [{ id: `wamid.stage-reply-${turn}`, timestamp: String(Math.floor(Date.now()/1000)), status: 'read' }] }), [binding], state, client);
process.stdout.write(php('dispatch')); process.stdout.write(php('worker'));
assert.equal(readFileSync(process.env.BRIDGE_STAGE_ROOT + '/bedrock.ndjson', 'utf8').trim().split('\n').length, bedrockCalls, 'Status must not invoke agent');
inspection = JSON.parse(php('inspect'));
assert.ok(inspection.outbox.every(o => o.state === 'accepted'));
console.log(JSON.stringify({ turn, simulatedSends, ...inspection }, null, 2));
