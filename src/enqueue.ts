import type { APIGatewayProxyEventV2, APIGatewayProxyStructuredResultV2 } from 'aws-lambda';
import { verify } from './auth.js';
import { randomBytes } from 'node:crypto';
import { Binding, BridgeError, OUTBOUND_MEDIA, OutboundCommand, OutboundMediaType, TemplateSummary, outbound, record, stableId, string } from './contracts.js';

export interface EnqueuePort {
  bindings: Binding[];
  secret(bindingId: string): string | undefined;
  nonce(key: string): Promise<boolean>;
  queue(command: OutboundCommand): Promise<void>;
  templates?(binding: Binding): Promise<TemplateSummary[]>;
  /** Presigned PUT for one outbound file (signed Content-Type); the object is then sent with a later /outbound command. */
  uploadUrl?(key: string, mimeType: string): Promise<string>;
}
export function jsonResponse(statusCode: number, body: unknown): APIGatewayProxyStructuredResultV2 {
  return { statusCode, headers: { 'content-type': 'application/json', 'cache-control': 'no-store' }, body: JSON.stringify(body) };
}
export async function enqueueRequest(event: APIGatewayProxyEventV2, port: EnqueuePort): Promise<APIGatewayProxyStructuredResultV2> {
  try {
    const path = event.rawPath;
    if (event.requestContext.http.method !== 'POST' || (path !== '/outbound' && path !== '/templates' && path !== '/media')) return jsonResponse(404, { error: 'not_found' });
    const body = event.isBase64Encoded ? Buffer.from(event.body ?? '', 'base64').toString('utf8') : event.body ?? '';
    if (Buffer.byteLength(body) > 20_000) return jsonResponse(413, { error: 'payload_too_large' });
    const id = event.headers['x-bridge-binding'] ?? '';
    const secret = port.secret(id);
    if (!secret) return jsonResponse(401, { error: 'unauthorized' });
    const nonce = event.headers['x-bridge-nonce'] ?? '';
    verify(secret, 'POST', path, body, { nonce, timestamp: event.headers['x-bridge-timestamp'] ?? '', signature: event.headers['x-bridge-signature'] ?? '' });
    let value: unknown;
    try { value = JSON.parse(body); } catch { return jsonResponse(400, { error: 'invalid_json' }); }
    if (path === '/templates') {
      // Read-only listing for the inbox; signed and replay-protected like a send.
      const request = record(value);
      if (request.version !== 1 || string(request.bindingId, 80) !== id) return jsonResponse(403, { error: 'binding_mismatch' });
      const binding = port.bindings.find(b => b.id === id && b.enabled);
      if (!binding || !port.templates) return jsonResponse(422, { error: 'binding_disabled_or_unknown' });
      if (!await port.nonce(stableId(id, nonce))) return jsonResponse(409, { error: 'replayed_request' });
      return jsonResponse(200, { templates: await port.templates(binding) });
    }
    if (path === '/media') {
      // Upload slot for a file the inbox is about to send: a random key under this binding, valid for a few minutes.
      const request = record(value);
      if (request.version !== 1 || string(request.bindingId, 80) !== id) return jsonResponse(403, { error: 'binding_mismatch' });
      const binding = port.bindings.find(b => b.id === id && b.enabled);
      if (!binding || !port.uploadUrl) return jsonResponse(422, { error: 'binding_disabled_or_unknown' });
      const type = request.type as OutboundMediaType; const mimeType = string(request.mimeType, 120); const size = request.size;
      if (!OUTBOUND_MEDIA[type] || !OUTBOUND_MEDIA[type].mime.test(mimeType)) return jsonResponse(422, { error: 'unsupported_media' });
      if (typeof size !== 'number' || !Number.isInteger(size) || size < 1 || size > OUTBOUND_MEDIA[type].max) return jsonResponse(422, { error: 'media_too_large' });
      if (!await port.nonce(stableId(id, nonce))) return jsonResponse(409, { error: 'replayed_request' });
      const key = `outbound/${id}/${randomBytes(16).toString('hex')}/file`;
      return jsonResponse(200, { key, url: await port.uploadUrl(key, mimeType), headers: { 'content-type': mimeType } });
    }
    const { command } = outbound(value, port.bindings);
    if (command.bindingId !== id) return jsonResponse(403, { error: 'binding_mismatch' });
    if (!await port.nonce(stableId(id, nonce))) return jsonResponse(409, { error: 'replayed_request' });
    await port.queue(command);
    return jsonResponse(202, { status: 'accepted', id: command.commandId });
  } catch (error) {
    const code = error instanceof BridgeError ? error.code : 'temporary_failure';
    const auth = ['expired_signature', 'invalid_signature', 'weak_secret'];
    const validation = ['binding_disabled_or_unknown', 'recipient_not_allowed', 'channel_mismatch', 'invalid_command_id', 'empty_text', 'unsupported_version', 'invalid_string', 'invalid_object', 'invalid_template', 'templates_not_configured', 'invalid_media'];
    return jsonResponse(auth.includes(code) ? 401 : validation.includes(code) ? 422 : 503, { error: code });
  }
}
