import test from 'node:test';
import assert from 'node:assert/strict';
import type { SQSEvent, APIGatewayProxyEventV2 } from 'aws-lambda';
import { inbound, sender, enqueue } from '../src/handlers.js';

test('master runtime cannot implicitly enable reception, publishing or sending', async () => {
  const names = ['BRIDGE_RUNTIME_ENABLED','BRIDGE_RECEIVE_ENABLED','BRIDGE_SEND_ENABLED','BRIDGE_LIVE_SEND'];
  const original = names.map(name => process.env[name]);
  const event = { Records: [{ messageId: 'synthetic-disabled', body: '{}' }] } as SQSEvent;
  const request = { rawPath: '/outbound', requestContext: { http: { method: 'POST' } },
    headers: { 'x-bridge-binding': 'demo', 'x-bridge-signature': 'synthetic',
      'x-bridge-timestamp': 'synthetic', 'x-bridge-nonce': 'synthetic' } } as unknown as APIGatewayProxyEventV2;
  try {
    process.env.BRIDGE_RUNTIME_ENABLED = 'true';
    delete process.env.BRIDGE_RECEIVE_ENABLED;
    delete process.env.BRIDGE_SEND_ENABLED;
    delete process.env.BRIDGE_LIVE_SEND;
    assert.deepEqual(await inbound(event), { batchItemFailures: [{ itemIdentifier: 'synthetic-disabled' }] });
    // Reception may be explicitly enabled while the entire outbound path stays off.
    process.env.BRIDGE_RECEIVE_ENABLED = 'true';
    for (const flags of [{ send: undefined, live: 'true' }, { send: 'true', live: undefined }, { send: 'TRUE', live: 'true' }]) {
      if (flags.send === undefined) delete process.env.BRIDGE_SEND_ENABLED; else process.env.BRIDGE_SEND_ENABLED = flags.send;
      if (flags.live === undefined) delete process.env.BRIDGE_LIVE_SEND; else process.env.BRIDGE_LIVE_SEND = flags.live;
      assert.deepEqual(await sender(event), { batchItemFailures: [{ itemIdentifier: 'synthetic-disabled' }] });
      assert.equal((await enqueue(request)).statusCode, 503);
    }
  } finally {
    names.forEach((name,index) => { if (original[index] === undefined) delete process.env[name]; else process.env[name] = original[index]; });
  }
});

test('disabled or unset Lambda runtime preserves queued records without requiring AWS configuration', async () => {
  const original = process.env.BRIDGE_RUNTIME_ENABLED;
  const event = { Records: [{ messageId: 'synthetic-1', body: '{}' }, { messageId: 'synthetic-2', body: '{}' }] } as SQSEvent;
  try {
    for (const flag of [undefined, 'false', 'TRUE']) {
      if (flag === undefined) delete process.env.BRIDGE_RUNTIME_ENABLED;
      else process.env.BRIDGE_RUNTIME_ENABLED = flag;
      for (const handler of [inbound, sender]) {
        assert.deepEqual(await handler(event), { batchItemFailures: [
          { itemIdentifier: 'synthetic-1' }, { itemIdentifier: 'synthetic-2' },
        ] });
      }
    }
  } finally {
    if (original === undefined) delete process.env.BRIDGE_RUNTIME_ENABLED;
    else process.env.BRIDGE_RUNTIME_ENABLED = original;
  }
});

test('disabled outbound API rejects signed-looking requests before resolving secrets or queue configuration', async () => {
  const original = process.env.BRIDGE_RUNTIME_ENABLED;
  delete process.env.BRIDGE_RUNTIME_ENABLED;
  try {
    const event = { rawPath: '/outbound', requestContext: { http: { method: 'POST' } },
      headers: { 'x-bridge-binding': 'demo', 'x-bridge-signature': 'synthetic',
        'x-bridge-timestamp': 'synthetic', 'x-bridge-nonce': 'synthetic' } } as unknown as APIGatewayProxyEventV2;
    const result = await enqueue(event);
    assert.equal(result.statusCode, 503);
    assert.equal(JSON.parse(result.body!).error, 'bridge_runtime_disabled');
  } finally {
    if (original === undefined) delete process.env.BRIDGE_RUNTIME_ENABLED;
    else process.env.BRIDGE_RUNTIME_ENABLED = original;
  }
});
