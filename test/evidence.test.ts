import test from 'node:test';
import assert from 'node:assert/strict';
import { evidence, evidenceHash } from '../src/evidence.js';

test('execution evidence accepts metadata only and is opt-in', () => {
  const before = process.env.BRIDGE_RUN_ID;
  const info = console.info;
  const lines: string[] = [];
  console.info = (line: string) => { lines.push(line); };
  try {
    delete process.env.BRIDGE_RUN_ID;
    evidence('core_ack', { id: 'one' });
    process.env.BRIDGE_RUN_ID = 'invalid\nrun';
    evidence('core_ack', { id: 'one' });
    assert.equal(lines.length, 0);
    process.env.BRIDGE_RUN_ID = 'phase4_synthetic_test';
    evidence('social_api_result', { id: evidenceHash('synthetic-id'), requestId: 'synthetic-request-id',
      status: 'accepted', httpStatus: 200, awsRequestId: '+12025550123',
      body: 'DO_NOT_LOG', headers: 'DO_NOT_LOG', secret: 'DO_NOT_LOG' } as Parameters<typeof evidence>[1]);
    const line = lines[0]!;
    const record = JSON.parse(line);
    assert.deepEqual(Object.keys(record).sort(), ['event', 'httpStatus', 'id', 'requestId', 'runId', 'status', 'time']);
    assert.equal(record.id.length, 64);
    assert.equal(record.requestId, 'synthetic-request-id');
    assert.ok(!line.includes('DO_NOT_LOG'));
    assert.ok(!line.includes('+12025550123'));
  } finally {
    console.info = info;
    if (before === undefined) delete process.env.BRIDGE_RUN_ID; else process.env.BRIDGE_RUN_ID = before;
  }
});
