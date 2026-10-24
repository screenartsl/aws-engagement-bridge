import test from 'node:test';
import assert from 'node:assert/strict';
import { DynamoDBDocumentClient, GetCommand, PutCommand, UpdateCommand } from '@aws-sdk/lib-dynamodb';
import { DynamoState } from '../src/state.js';

const now = 1792860000000;
const conditional = () => Object.assign(new Error(), { name: 'ConditionalCheckFailedException' });
function client(run: (command: { input: Record<string, unknown> }) => Promise<unknown>): DynamoDBDocumentClient {
  return { send: run } as unknown as DynamoDBDocumentClient;
}
test('expired outbound lease becomes an uncertain receipt, never a new send reservation', async () => {
  let calls = 0;
  const db = client(async c => {
    calls++;
    if (c instanceof PutCommand) throw conditional();
    if (c instanceof GetCommand) return { Item: { digest: 'same', state: 'working', token: 'old', leaseUntil: now / 1000 - 1 } };
    assert.ok(c instanceof UpdateCommand);
    assert.equal(c.input.ConditionExpression, '#s = :working AND #t = :old');
    return {};
  });
  const state = new DynamoState('synthetic-table', 'eu-west-1', () => now, db);
  assert.deepEqual(await state.claim('out:test:1', 'same', true), { state: 'done', result: { state: 'uncertain', errorCode: 'worker_interrupted' } });
  assert.equal(calls, 3);
});
test('expired inbound lease can be reclaimed, but live leases cannot', async () => {
  let leaseUntil = now / 1000 + 1;
  const db = client(async c => {
    if (c instanceof PutCommand) throw conditional();
    if (c instanceof GetCommand) return { Item: { digest: 'same', state: 'working', token: 'old', leaseUntil } };
    assert.ok(c instanceof UpdateCommand); return {};
  });
  const state = new DynamoState('synthetic-table', 'eu-west-1', () => now, db);
  assert.deepEqual(await state.claim('in:test:1', 'same', false), { state: 'busy' });
  leaseUntil = now / 1000 - 1;
  assert.equal((await state.claim('in:test:1', 'same', false)).state, 'acquired');
});
test('Dynamo idempotency conflict is rejected before any lease update', async () => {
  const db = client(async c => {
    if (c instanceof PutCommand) throw conditional();
    assert.ok(c instanceof GetCommand); return { Item: { digest: 'other-payload', state: 'done' } };
  });
  await assert.rejects(new DynamoState('table', 'eu-west-1', () => now, db).claim('key', 'new', true), /idempotency_conflict/);
});
test('nonce and budget use conditional writes and fail closed on contention', async () => {
  const db = client(async c => {
    if (c instanceof PutCommand) assert.equal(c.input.ConditionExpression, 'attribute_not_exists(pk)');
    else assert.equal(c.input.ConditionExpression, 'attribute_not_exists(used) OR used < :limit');
    throw conditional();
  });
  const state = new DynamoState('table', 'eu-west-1', () => now, db);
  assert.equal(await state.nonce('test'), false); assert.equal(await state.spend('demo', 20), false);
});
test('Dynamo service errors are not mistaken for duplicate records', async () => {
  const state = new DynamoState('table', 'eu-west-1', () => now, client(async () => { throw new Error('simulated_unavailable'); }));
  await assert.rejects(state.claim('key', 'digest', true), /simulated_unavailable/);
  await assert.rejects(state.nonce('key'), /simulated_unavailable/);
});
