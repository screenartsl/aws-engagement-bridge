import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { sign } from '../src/auth.js';

const fixture = { secret: 'synthetic-test-secret-for-interop-never-real', method: 'POST', path: '/api/aws-engagement-bridge/events',
  body: '{"text":"Información cultural: 日本語"}', now: 1792860000 };
test('PHP and Node sign the identical UTF-8 body; PHP verifies the Node signature without bootstrapping MuseRelay', () => {
  const headers = sign(fixture.secret, fixture.method, fixture.path, fixture.body, fixture.now * 1000);
  const result = spawnSync('php', ['-n', 'integration/muserelay/verify-fixture.php'], { input: JSON.stringify({ ...fixture, headers }), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr); assert.deepEqual(JSON.parse(result.stdout), headers);
});
test('PHP rejects modified payload and expired requests', () => {
  const headers = sign(fixture.secret, fixture.method, fixture.path, fixture.body, fixture.now * 1000);
  for (const changed of [{ ...fixture, body: '{"text":"changed"}' }, { ...fixture, now: fixture.now + 301 }]) {
    const result = spawnSync('php', ['-n', 'integration/muserelay/verify-fixture.php'], { input: JSON.stringify({ ...changed, headers }), encoding: 'utf8' });
    assert.equal(result.status, 1); assert.ok(['invalid_signature', 'expired_signature'].includes(JSON.parse(result.stdout).error));
  }
});
