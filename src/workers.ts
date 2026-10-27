import { Binding, BridgeError, OutboundCommand, SendResult, outbound, stableId } from './contracts.js';
import { CorePort } from './core-client.js';
import { MediaPort } from './media.js';
import { normalize } from './normalize.js';
import { StateStore } from './state.js';

export async function ingest(envelope: unknown, bindings: Binding[], store: StateStore, core: CorePort, dailyLimit = 40, media?: MediaPort): Promise<void> {
  // 0 = no daily cap.
  if (!Number.isInteger(dailyLimit) || dailyLimit < 0 || dailyLimit > 100_000) throw new BridgeError('invalid_daily_limit');
  for (const event of normalize(envelope, bindings)) {
    const key = `in:${event.bindingId}:${event.eventId}`;
    const claim = await store.claim(key, stableId(JSON.stringify(event)), false);
    if (claim.state === 'done') continue;
    if (claim.state === 'busy') throw new BridgeError('event_busy');
    try {
      if (event.kind === 'message' && dailyLimit > 0 && !await store.spend(`in:${event.bindingId}`, dailyLimit)) throw new BridgeError('daily_limit_reached');
      // The claim fingerprint is the normalized event; the presigned URL is added after it (it changes on every retry).
      // Without a media bucket configured, media still reaches the inbox, only without the file.
      const delivered = event.media && media ? { ...event, media: await media.fetch(event, bindings.find(b => b.id === event.bindingId)!) } : event;
      // Core MUST durably deduplicate eventId too: a worker can die after this POST.
      await core.accept(delivered);
      await store.complete(key, claim.token);
    } catch (error) { await store.release(key, claim.token); throw error; }
  }
}

export interface Sender { send(command: OutboundCommand, binding: Binding): Promise<SendResult>; }
export async function deliver(value: unknown, bindings: Binding[], store: StateStore, core: CorePort,
  sender: Sender, dailyLimit: number): Promise<void> {
  // 0 = no daily cap.
  if (!Number.isInteger(dailyLimit) || dailyLimit < 0 || dailyLimit > 100_000) throw new BridgeError('invalid_daily_limit');
  const { command, binding } = outbound(value, bindings);
  const key = `out:${binding.id}:${command.commandId}`;
  const claim = await store.claim(key, stableId(JSON.stringify(command)), true);
  if (claim.state === 'busy') throw new BridgeError('command_busy');
  if (claim.state === 'done') {
    if (claim.result) await core.receipt(binding.id, command.commandId, claim.result);
    return;
  }
  let result: SendResult;
  try {
    if (dailyLimit > 0 && !await store.spend(binding.id, dailyLimit)) throw new BridgeError('daily_limit_reached');
    result = await sender.send(command, binding);
  } catch (error) {
    // Release ONLY failures known to happen before the network call or explicit throttles.
    if (error instanceof BridgeError && ['daily_limit_reached', 'live_sending_disabled', 'invalid_meta_api_version', 'provider_throttled', 'media_not_configured'].includes(error.code)) {
      await store.release(key, claim.token);
    }
    throw error;
  }
  await store.complete(key, claim.token, result);
  // A failure to notify Core can retry this receipt, never the send operation.
  await core.receipt(binding.id, command.commandId, result);
}
