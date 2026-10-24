import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto';
import { BridgeError } from './contracts.js';

export interface SignatureHeaders { timestamp: string; nonce: string; signature: string; }
export function sign(secret: string, method: string, path: string, body: string, now = Date.now(), nonce: string = randomUUID()): SignatureHeaders {
  if (Buffer.byteLength(secret) < 32) throw new BridgeError('weak_secret');
  const timestamp = String(Math.floor(now / 1000));
  const canonical = `${timestamp}\n${nonce}\n${method.toUpperCase()}\n${path}\n${body}`;
  return { timestamp, nonce, signature: createHmac('sha256', secret).update(canonical).digest('hex') };
}

export function verify(secret: string, method: string, path: string, body: string, headers: SignatureHeaders, now = Date.now()): void {
  if (!/^\d{10}$/.test(headers.timestamp) || Math.abs(Math.floor(now / 1000) - Number(headers.timestamp)) > 300) throw new BridgeError('expired_signature');
  if (!/^[a-zA-Z0-9-]{16,80}$/.test(headers.nonce) || !/^[a-f0-9]{64}$/.test(headers.signature)) throw new BridgeError('invalid_signature');
  const expected = sign(secret, method, path, body, Number(headers.timestamp) * 1000, headers.nonce).signature;
  if (!timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(headers.signature, 'hex'))) throw new BridgeError('invalid_signature');
}
