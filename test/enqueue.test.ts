import test from 'node:test';
import assert from 'node:assert/strict';
import type { APIGatewayProxyEventV2 } from 'aws-lambda';
import { sign } from '../src/auth.js';
import { Binding, OutboundCommand } from '../src/contracts.js';
import { EnqueuePort, enqueueRequest } from '../src/enqueue.js';
import { enqueue } from '../src/handlers.js';

const secret = 'synthetic-signing-key-test-not-a-real-key';
const binding: Binding = { id: 'demo', enabled: true, channelRef: 'demo-channel', region: 'eu-west-1', topicArn: 'unused',
  wabaId: '1234', metaPhoneNumberId: '5678', awsPhoneNumberId: 'unused', allowedRecipients: ['+12025550123'] };
const command: OutboundCommand = { version: 1, commandId: 'test-out-0001', bindingId: 'demo', channelRef: 'demo-channel', recipient: '+12025550123', text: 'Synthetic' };
function event(body = JSON.stringify(command), id = 'demo'): APIGatewayProxyEventV2 {
  const h = sign(secret, 'POST', '/outbound', body);
  return { version: '2.0', routeKey: 'POST /outbound', rawQueryString: '', rawPath: '/outbound', body, isBase64Encoded: false,
    requestContext: { accountId: '111111111111', apiId: 'test', domainName: 'test.invalid', domainPrefix: 'test', requestId: 'synthetic',
      routeKey: 'POST /outbound', stage: '$default', time: 'synthetic', timeEpoch: Date.now(),
      http: { method: 'POST', path: '/outbound', protocol: 'HTTP/1.1', sourceIp: '192.0.2.1', userAgent: 'synthetic-test' } },
    headers: { 'x-bridge-binding': id, 'x-bridge-timestamp': h.timestamp, 'x-bridge-nonce': h.nonce, 'x-bridge-signature': h.signature } };
}
function port(): EnqueuePort & { sent: OutboundCommand[] } {
  const seen = new Set<string>(); const sent: OutboundCommand[] = [];
  return { bindings: [binding], secret: id => id === 'demo' ? secret : undefined, sent,
    nonce: async key => { if (seen.has(key)) return false; seen.add(key); return true; }, queue: async c => { sent.push(c); } };
}
test('authenticated enqueue succeeds once; identical signed request is rejected as replay', async () => {
  const p = port(); const e = event();
  assert.equal((await enqueueRequest(e, p)).statusCode, 202);
  assert.equal((await enqueueRequest(e, p)).statusCode, 409); assert.equal(p.sent.length, 1);
});
test('missing or tampered signatures cannot enqueue', async () => {
  const p = port(); const e = event(); e.headers['x-bridge-signature'] = '0'.repeat(64);
  assert.equal((await enqueueRequest(e, p)).statusCode, 401); assert.equal(p.sent.length, 0);
  e.headers = {}; assert.equal((await enqueue(e)).statusCode, 401); // No AWS runtime/config touched.
});
test('signature for one binding cannot publish commands for a second binding', async () => {
  const p = port(); p.bindings.push({ ...binding, id: 'other', channelRef: 'other-channel' });
  const e = event(JSON.stringify({ ...command, bindingId: 'other', channelRef: 'other-channel' }));
  assert.equal((await enqueueRequest(e, p)).statusCode, 403); assert.equal(p.sent.length, 0);
});
test('queue outage does not return false acceptance or leak exception text', async () => {
  const p = port(); p.queue = async () => { throw new Error('private-provider-diagnostic'); };
  const result = await enqueueRequest(event(), p);
  assert.equal(result.statusCode, 503); assert.equal(result.body?.includes('private-provider-diagnostic'), false);
});
test('invalid JSON, oversized body and unknown routes cannot enqueue', async () => {
  const p = port(); assert.equal((await enqueueRequest(event('{'), p)).statusCode, 400);
  assert.equal((await enqueueRequest(event('x'.repeat(20_001)), p)).statusCode, 413);
  const e = event(); e.rawPath = '/unknown'; assert.equal((await enqueueRequest(e, p)).statusCode, 404);
  assert.equal(p.sent.length, 0);
});
function templatesEvent(body = JSON.stringify({ version: 1, bindingId: 'demo' }), signedPath = '/templates'): APIGatewayProxyEventV2 {
  const e = event(body); const h = sign(secret, 'POST', signedPath, body);
  e.rawPath = '/templates'; e.requestContext.http.path = '/templates';
  e.headers = { 'x-bridge-binding': 'demo', 'x-bridge-timestamp': h.timestamp, 'x-bridge-nonce': h.nonce, 'x-bridge-signature': h.signature };
  return e;
}
test('signed /templates lists templates once, never enqueues, and rejects replays', async () => {
  const p = port(); const listed: string[] = [];
  p.templates = async b => { listed.push(b.id); return [{ name: 'hello_world', language: 'en_US', category: 'UTILITY', components: [] }]; };
  const e = templatesEvent(); const result = await enqueueRequest(e, p);
  assert.equal(result.statusCode, 200); assert.equal(JSON.parse(result.body!).templates[0].name, 'hello_world');
  assert.equal((await enqueueRequest(e, p)).statusCode, 409); assert.deepEqual(listed, ['demo']); assert.equal(p.sent.length, 0);
});
function mediaEvent(body: unknown): APIGatewayProxyEventV2 {
  const raw = JSON.stringify(body); const e = event(raw); const h = sign(secret, 'POST', '/media', raw);
  e.rawPath = '/media'; e.requestContext.http.path = '/media';
  e.headers = { 'x-bridge-binding': 'demo', 'x-bridge-timestamp': h.timestamp, 'x-bridge-nonce': h.nonce, 'x-bridge-signature': h.signature };
  return e;
}
test('signed /media returns a one-off upload slot under the binding; type, size and replays are checked', async () => {
  const p = port(); const slots: string[] = [];
  p.uploadUrl = async (key, mime) => { slots.push(`${key}|${mime}`); return `https://bucket.s3.eu-west-1.amazonaws.com/${key}?X-Amz-Signature=t`; };
  const ok = mediaEvent({ version: 1, bindingId: 'demo', type: 'image', mimeType: 'image/png', size: 1024 });
  const res = await enqueueRequest(ok, p); const body = JSON.parse(res.body!);
  assert.equal(res.statusCode, 200); assert.match(body.key, /^outbound\/demo\/[a-f0-9]{32}\/file$/); assert.deepEqual(body.headers, { 'content-type': 'image/png' });
  assert.equal((await enqueueRequest(ok, p)).statusCode, 409);
  assert.equal((await enqueueRequest(mediaEvent({ version: 1, bindingId: 'demo', type: 'image', mimeType: 'image/png', size: 6 * 1024 * 1024 }), p)).statusCode, 422);
  assert.equal((await enqueueRequest(mediaEvent({ version: 1, bindingId: 'demo', type: 'document', mimeType: 'application/zip', size: 10 }), p)).statusCode, 422);
  assert.equal((await enqueueRequest(mediaEvent({ version: 1, bindingId: 'other', type: 'image', mimeType: 'image/png', size: 10 }), p)).statusCode, 403);
  assert.equal(slots.length, 1); assert.equal(p.sent.length, 0);
});
test('/templates rejects a signature made for /outbound and requests for another binding', async () => {
  const p = port(); p.templates = async () => { throw new Error('must not be called'); };
  assert.equal((await enqueueRequest(templatesEvent(undefined, '/outbound'), p)).statusCode, 401);
  assert.equal((await enqueueRequest(templatesEvent(JSON.stringify({ version: 1, bindingId: 'other' })), p)).statusCode, 403);
});
