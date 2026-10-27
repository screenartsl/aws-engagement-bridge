import type { APIGatewayProxyEventV2, APIGatewayProxyStructuredResultV2 } from 'aws-lambda';
import { SendEmailCommand, SESv2Client } from '@aws-sdk/client-sesv2';
import { PinpointSMSVoiceV2Client, SendTextMessageCommand } from '@aws-sdk/client-pinpoint-sms-voice-v2';
import { verify } from './auth.js';
import { Binding, BridgeError, SendResult, record, stableId, string } from './contracts.js';
import { jsonResponse } from './enqueue.js';
import { evidence } from './evidence.js';
import { StateStore } from './state.js';

/**
 * Transactional email (Amazon SES) and SMS (AWS End User Messaging SMS) for a confirmed business operation
 * (appointment, ticket…). Independent of WhatsApp: own function, own role, own route (POST /notify).
 * Off unless enabled per transport; SMS defaults to DryRun (AWS validates without delivering or charging).
 */
export type NotifyKind = 'email' | 'sms';
export interface NotifyCommand {
  version: 1;
  /** Idempotency key chosen by Core (one per operation, kind and recipient). */
  notifyId: string;
  bindingId: string;
  kind: NotifyKind;
  to: string;
  subject?: string;
  text: string;
  reference: { type: 'appointment' | 'ticket' | 'operation'; id: string };
}

export interface NotifyConfig {
  emailEnabled: boolean;
  smsEnabled: boolean;
  /** Verified SES identity (address or domain) used as From. */
  emailFrom?: string;
  emailConfigurationSet?: string;
  /** Phone number, sender id or pool ARN/id authorised in AWS End User Messaging SMS. */
  smsOrigination?: string;
  /** Hard price cap per SMS in USD (MaxPrice); AWS rejects the send if a part costs more. */
  smsMaxPrice: string;
  smsDryRun: boolean;
  dailyLimit: number;
  /** Non-empty = only these recipients (sandbox/demo); empty = any recipient Core sends. */
  allowedRecipients: string[];
}

const EMAIL = /^[^\s@<>"',;]{1,64}@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;
const PHONE = /^\+[1-9]\d{6,14}$/;

export function notifyCommand(value: unknown, bindings: Binding[]): { command: NotifyCommand; binding: Binding } {
  const c = record(value);
  if (c.version !== 1) throw new BridgeError('unsupported_version');
  const bindingId = string(c.bindingId, 80);
  const binding = bindings.find(b => b.id === bindingId && b.enabled);
  if (!binding) throw new BridgeError('binding_disabled_or_unknown');
  const notifyId = string(c.notifyId, 100);
  if (!/^[a-zA-Z0-9_-]{8,100}$/.test(notifyId)) throw new BridgeError('invalid_notify_id');
  if (c.kind !== 'email' && c.kind !== 'sms') throw new BridgeError('invalid_kind');
  const to = string(c.to, 254);
  if (!(c.kind === 'email' ? EMAIL : PHONE).test(to)) throw new BridgeError('invalid_recipient');
  // SMS: up to 4 concatenated parts; longer texts are rejected rather than silently cut.
  const text = string(c.text, c.kind === 'sms' ? 600 : 10_000);
  if (!text.trim()) throw new BridgeError('empty_text');
  const command: NotifyCommand = { version: 1, notifyId, bindingId, kind: c.kind, to, text, reference: reference(c.reference) };
  if (c.kind === 'email') {
    const subject = string(c.subject, 200);
    if (/[\r\n]/.test(subject) || !subject.trim()) throw new BridgeError('invalid_subject');
    command.subject = subject;
  }
  return { command, binding };
}

function reference(value: unknown): NotifyCommand['reference'] {
  const r = record(value);
  if (r.type !== 'appointment' && r.type !== 'ticket' && r.type !== 'operation') throw new BridgeError('invalid_reference');
  const id = string(r.id, 64);
  if (!/^[a-zA-Z0-9_-]+$/.test(id)) throw new BridgeError('invalid_reference');
  return { type: r.type, id };
}

export interface Transport { send(command: any): Promise<any>; }
export class NotifyAdapter {
  constructor(private readonly ses: Transport, private readonly sms: Transport) {}

  static aws(region: string): NotifyAdapter {
    // No automatic retries: a timeout may mean the message was already accepted.
    return new NotifyAdapter(new SESv2Client({ region, maxAttempts: 1 }), new PinpointSMSVoiceV2Client({ region, maxAttempts: 1 }));
  }

  async send(command: NotifyCommand, config: NotifyConfig): Promise<SendResult> {
    try {
      if (command.kind === 'email') {
        if (!config.emailEnabled) throw new BridgeError('email_disabled');
        if (!config.emailFrom) throw new BridgeError('email_not_configured');
        const result = await this.ses.send(new SendEmailCommand({
          FromEmailAddress: config.emailFrom,
          Destination: { ToAddresses: [command.to] },
          Content: { Simple: { Subject: { Data: command.subject!, Charset: 'UTF-8' }, Body: { Text: { Data: command.text, Charset: 'UTF-8' } } } },
          ...(config.emailConfigurationSet ? { ConfigurationSetName: config.emailConfigurationSet } : {}),
          // Returned in SES delivery/bounce events: links them back to the operation without personal data.
          EmailTags: [{ Name: 'notify_id', Value: command.notifyId }, { Name: 'reference', Value: `${command.reference.type}-${command.reference.id}` }],
        }));
        evidence('notify_result', { id: command.notifyId, requestId: result.$metadata?.requestId, status: result.MessageId ? 'email_accepted' : 'email_uncertain' });
        return result.MessageId ? { state: 'accepted', providerMessageId: result.MessageId } : { state: 'uncertain', errorCode: 'missing_provider_id' };
      }
      if (!config.smsEnabled) throw new BridgeError('sms_disabled');
      if (!config.smsOrigination) throw new BridgeError('sms_not_configured');
      const result = await this.sms.send(new SendTextMessageCommand({
        DestinationPhoneNumber: command.to,
        OriginationIdentity: config.smsOrigination,
        MessageBody: command.text,
        MessageType: 'TRANSACTIONAL',
        MaxPrice: config.smsMaxPrice,
        DryRun: config.smsDryRun,
        Context: { notify_id: command.notifyId },
      }));
      evidence('notify_result', { id: command.notifyId, requestId: result.$metadata?.requestId, status: result.MessageId ? (config.smsDryRun ? 'sms_dry_run' : 'sms_accepted') : 'sms_uncertain' });
      return result.MessageId ? { state: 'accepted', providerMessageId: result.MessageId } : { state: 'uncertain', errorCode: 'missing_provider_id' };
    } catch (error) {
      if (error instanceof BridgeError) return { state: 'rejected', errorCode: error.code };
      const name = error instanceof Error ? error.name : '';
      // Opted-out recipients, sandbox/unverified identities, price cap and bad parameters: definitive, safe to report.
      const safe = ['AccessDeniedException', 'ValidationException', 'ResourceNotFoundException', 'ConflictException',
        'ServiceQuotaExceededException', 'MessageRejected', 'MailFromDomainNotVerifiedException', 'AccountSuspendedException',
        'SendingPausedException', 'BadRequestException', 'NotFoundException'];
      if (safe.includes(name)) return { state: 'rejected', errorCode: name };
      if (name === 'ThrottlingException' || name === 'TooManyRequestsException') return { state: 'rejected', errorCode: 'provider_throttled' };
      return { state: 'uncertain', errorCode: 'provider_result_unknown' };
    }
  }
}

export interface NotifyPort {
  bindings: Binding[];
  secret(bindingId: string): string | undefined;
  nonce(key: string): Promise<boolean>;
  store: StateStore;
  config: NotifyConfig;
  adapter: { send(command: NotifyCommand, config: NotifyConfig): Promise<SendResult> };
}

/** Synchronous: validates, deduplicates by notifyId (a repeated id returns the first result, never sends twice) and sends. */
export async function notifyRequest(event: APIGatewayProxyEventV2, port: NotifyPort): Promise<APIGatewayProxyStructuredResultV2> {
  try {
    if (event.requestContext.http.method !== 'POST' || event.rawPath !== '/notify') return jsonResponse(404, { error: 'not_found' });
    const body = event.isBase64Encoded ? Buffer.from(event.body ?? '', 'base64').toString('utf8') : event.body ?? '';
    if (Buffer.byteLength(body) > 20_000) return jsonResponse(413, { error: 'payload_too_large' });
    const id = event.headers['x-bridge-binding'] ?? '';
    const secret = port.secret(id);
    if (!secret) return jsonResponse(401, { error: 'unauthorized' });
    const nonce = event.headers['x-bridge-nonce'] ?? '';
    verify(secret, 'POST', '/notify', body, { nonce, timestamp: event.headers['x-bridge-timestamp'] ?? '', signature: event.headers['x-bridge-signature'] ?? '' });
    let value: unknown;
    try { value = JSON.parse(body); } catch { return jsonResponse(400, { error: 'invalid_json' }); }
    const { command } = notifyCommand(value, port.bindings);
    if (command.bindingId !== id) return jsonResponse(403, { error: 'binding_mismatch' });
    if (port.config.allowedRecipients.length && !port.config.allowedRecipients.includes(command.to)) return jsonResponse(422, { error: 'recipient_not_allowed' });
    if (!(command.kind === 'email' ? port.config.emailEnabled : port.config.smsEnabled)) return jsonResponse(422, { error: `${command.kind}_disabled` });
    if (!await port.nonce(stableId(id, nonce))) return jsonResponse(409, { error: 'replayed_request' });
    const key = `notify:${id}:${command.notifyId}`;
    const claim = await port.store.claim(key, stableId(JSON.stringify(command)), true);
    if (claim.state === 'busy') return jsonResponse(409, { error: 'notify_busy' });
    if (claim.state === 'done') return jsonResponse(200, { id: command.notifyId, duplicate: true, ...(claim.result ?? { state: 'uncertain' }) });
    if (port.config.dailyLimit > 0 && !await port.store.spend(`notify:${id}:${command.kind}`, port.config.dailyLimit)) {
      await port.store.release(key, claim.token);
      return jsonResponse(429, { error: 'daily_limit_reached' });
    }
    const result = await port.adapter.send(command, port.config);
    await port.store.complete(key, claim.token, result);
    return jsonResponse(200, { id: command.notifyId, ...result });
  } catch (error) {
    const code = error instanceof BridgeError ? error.code : 'temporary_failure';
    if (code === 'idempotency_conflict') return jsonResponse(409, { error: code });
    const auth = ['expired_signature', 'invalid_signature', 'weak_secret'];
    const validation = ['binding_disabled_or_unknown', 'invalid_notify_id', 'invalid_kind', 'invalid_recipient', 'empty_text', 'invalid_subject',
      'invalid_reference', 'unsupported_version', 'invalid_string', 'invalid_object'];
    return jsonResponse(auth.includes(code) ? 401 : validation.includes(code) ? 422 : 503, { error: code });
  }
}

export function notifyConfig(env: NodeJS.ProcessEnv): NotifyConfig {
  const allowed = (env.NOTIFY_ALLOWED_RECIPIENTS ?? '').split(',').map(s => s.trim()).filter(Boolean);
  if (allowed.length > 20 || !allowed.every(r => PHONE.test(r) || EMAIL.test(r))) throw new BridgeError('invalid_allowlist');
  const dailyLimit = Number(env.NOTIFY_DAILY_LIMIT ?? '20');
  if (!Number.isInteger(dailyLimit) || dailyLimit < 0 || dailyLimit > 100_000) throw new BridgeError('invalid_daily_limit');
  const smsMaxPrice = env.NOTIFY_SMS_MAX_PRICE || '0.10';
  if (!/^\d{1,2}\.\d{1,4}$/.test(smsMaxPrice)) throw new BridgeError('invalid_max_price');
  return {
    emailEnabled: env.NOTIFY_EMAIL_ENABLED === 'true', smsEnabled: env.NOTIFY_SMS_ENABLED === 'true',
    emailFrom: env.NOTIFY_EMAIL_FROM || undefined, emailConfigurationSet: env.NOTIFY_EMAIL_CONFIGURATION_SET || undefined,
    smsOrigination: env.NOTIFY_SMS_ORIGINATION || undefined, smsMaxPrice,
    // Anything but an explicit 'false' keeps SMS in DryRun.
    smsDryRun: env.NOTIFY_SMS_DRY_RUN !== 'false', dailyLimit, allowedRecipients: allowed,
  };
}
