import { sign } from './auth.js';
import { BridgeError, InboundEvent, SendResult } from './contracts.js';

export interface CorePort {
  accept(event: InboundEvent): Promise<void>;
  receipt(bindingId: string, commandId: string, result: SendResult): Promise<void>;
}
export class CoreClient implements CorePort {
  constructor(private readonly baseUrl: string, private readonly secretFor: (bindingId: string) => string,
    private readonly request: typeof fetch = fetch) {
    const url = new URL(baseUrl);
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
      throw new BridgeError('invalid_core_url');
    }
  }
  async accept(event: InboundEvent): Promise<void> {
    await this.post('/api/aws-engagement-bridge/events', event.bindingId, event, event.eventId);
  }
  async receipt(bindingId: string, commandId: string, result: SendResult): Promise<void> {
    await this.post('/api/aws-engagement-bridge/receipts', bindingId, { version: 1, bindingId, commandId, ...result }, commandId);
  }
  private async post(path: string, bindingId: string, payload: unknown, id: string): Promise<void> {
    const body = JSON.stringify(payload);
    const signed = sign(this.secretFor(bindingId), 'POST', path, body);
    const response = await this.request(new URL(path, this.baseUrl), { method: 'POST', body, redirect: 'error',
      signal: AbortSignal.timeout(10_000), headers: { 'content-type': 'application/json', 'x-bridge-binding': bindingId,
        'x-bridge-timestamp': signed.timestamp, 'x-bridge-nonce': signed.nonce, 'x-bridge-signature': signed.signature } });
    if (![200, 202].includes(response.status)) throw new BridgeError('core_not_acknowledged');
    const ack = await response.json() as { id?: string; status?: string };
    if (ack.id !== id || !['accepted', 'duplicate'].includes(ack.status ?? '')) throw new BridgeError('invalid_core_ack');
  }
}
