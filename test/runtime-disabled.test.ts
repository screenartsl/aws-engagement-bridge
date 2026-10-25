import test from 'node:test';
import assert from 'node:assert/strict';
import type { SQSEvent, APIGatewayProxyEventV2 } from 'aws-lambda';
import { inbound, sender, enqueue } from '../src/handlers.js';

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
