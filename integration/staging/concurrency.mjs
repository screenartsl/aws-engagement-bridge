import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { sign } from '../../dist/src/auth.js';
import { stableId } from '../../dist/src/contracts.js';
const cli = new URL('./console.php', import.meta.url).pathname;
const event = { version: 1, eventId: stableId('demo', 'message', 'wamid.stage-concurrent'), bindingId: 'demo', channelRef: 'demo-channel',
  resource: { accountId: '111111111111', region: 'eu-west-1', wabaId: '123456', metaPhoneNumberId: '789012', awsPhoneNumberArn: 'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/phone-number-id-stageabc123' },
  kind: 'message', providerMessageId: 'wamid.stage-concurrent', timestamp: Math.floor(Date.now()/1000), sender: '+12025550123', text: 'Sí, registra una solicitud diferente.' };
const body = JSON.stringify(event);
const run = (mode, input) => new Promise((resolve, reject) => {
  const child = spawn('php', [cli, mode], { env: process.env }); let out = '', err = '';
  child.stdout.on('data', d => out += d); child.stderr.on('data', d => err += d);
  child.on('error', reject); child.on('close', code => code === 0 ? resolve(JSON.parse(out)) : reject(Error(err || out)));
  child.stdin.end(input ? JSON.stringify(input) : undefined);
});
const accepted = await Promise.all(Array.from({length: 8}, () => {
  const h = sign('synthetic-stage-core-key-no-live-credentials', 'POST', '/api/aws-engagement-bridge/events', body);
  return run('accept', { endpoint: 'events', body, headers: { 'x-bridge-binding': 'demo',
    'x-bridge-timestamp': h.timestamp, 'x-bridge-nonce': h.nonce, 'x-bridge-signature': h.signature } });
}));
assert.equal(accepted.filter(r => r.statusCode === 202).length, 1);
assert.equal(accepted.filter(r => r.statusCode === 200 && r.body.status === 'duplicate').length, 7);
const claims = await Promise.all(Array.from({length: 6}, () => run('claim')));
assert.equal(claims.filter(r => r.claimed).length, 1);
console.log(JSON.stringify({ concurrentRequests: 8, accepted: 1, duplicates: 7, concurrentClaimants: 6, leases: 1 }));
