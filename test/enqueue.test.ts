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
