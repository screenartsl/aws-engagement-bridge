import type { APIGatewayProxyEventV2, APIGatewayProxyStructuredResultV2 } from 'aws-lambda';
import { verify } from './auth.js';
import { Binding, BridgeError, OutboundCommand, outbound, stableId } from './contracts.js';

export interface EnqueuePort {
  bindings: Binding[];
  secret(bindingId: string): string | undefined;
  nonce(key: string): Promise<boolean>;
  queue(command: OutboundCommand): Promise<void>;
}
export function jsonResponse(statusCode: number, body: unknown): APIGatewayProxyStructuredResultV2 {
  return { statusCode, headers: { 'content-type': 'application/json', 'cache-control': 'no-store' }, body: JSON.stringify(body) };
}
export async function enqueueRequest(event: APIGatewayProxyEventV2, port: EnqueuePort): Promise<APIGatewayProxyStructuredResultV2> {
  try {
    if (event.requestContext.http.method !== 'POST' || event.rawPath !== '/outbound') return jsonResponse(404, { error: 'not_found' });
    const body = event.isBase64Encoded ? Buffer.from(event.body ?? '', 'base64').toString('utf8') : event.body ?? '';
    if (Buffer.byteLength(body) > 20_000) return jsonResponse(413, { error: 'payload_too_large' });
    const id = event.headers['x-bridge-binding'] ?? '';
    const secret = port.secret(id);
    if (!secret) return jsonResponse(401, { error: 'unauthorized' });
    const nonce = event.headers['x-bridge-nonce'] ?? '';
    verify(secret, 'POST', '/outbound', body, { nonce, timestamp: event.headers['x-bridge-timestamp'] ?? '', signature: event.headers['x-bridge-signature'] ?? '' });
    let value: unknown;
    try { value = JSON.parse(body); } catch { return jsonResponse(400, { error: 'invalid_json' }); }
    const { command } = outbound(value, port.bindings);
    if (command.bindingId !== id) return jsonResponse(403, { error: 'binding_mismatch' });
    if (!await port.nonce(stableId(id, nonce))) return jsonResponse(409, { error: 'replayed_request' });
    await port.queue(command);
    return jsonResponse(202, { status: 'accepted', id: command.commandId });
  } catch (error) {
    const code = error instanceof BridgeError ? error.code : 'temporary_failure';
    const auth = ['expired_signature', 'invalid_signature', 'weak_secret'];
    const validation = ['binding_disabled_or_unknown', 'recipient_not_allowed', 'channel_mismatch', 'invalid_command_id', 'empty_text', 'unsupported_version', 'invalid_string', 'invalid_object'];
    return jsonResponse(auth.includes(code) ? 401 : validation.includes(code) ? 422 : 503, { error: code });
  }
}
