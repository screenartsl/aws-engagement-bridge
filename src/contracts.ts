import { createHash } from 'node:crypto';

export class BridgeError extends Error {
  constructor(public readonly code: string) { super(code); this.name = 'BridgeError'; }
}

export interface Binding {
  id: string;
  topicArn: string;
  wabaId: string;
  metaPhoneNumberId: string;
  awsPhoneNumberId: string;
  region: string;
  channelRef: string;
  enabled: boolean;
  allowedRecipients: string[];
}

export interface InboundEvent {
  version: 1;
  eventId: string;
  bindingId: string;
  channelRef: string;
  resource: { accountId: string; region: string; wabaId: string; metaPhoneNumberId: string; awsPhoneNumberArn: string };
  kind: 'message' | 'status';
  providerMessageId: string;
  timestamp: number;
  sender?: string;
  text?: string;
  status?: 'sent' | 'delivered' | 'read' | 'failed';
}

export interface OutboundCommand {
  version: 1;
  commandId: string;
  bindingId: string;
  channelRef: string;
  recipient: string;
  text: string;
}

export interface SendResult {
  state: 'accepted' | 'rejected' | 'uncertain';
  providerMessageId?: string;
  errorCode?: string;
}

export function record(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new BridgeError('invalid_object');
  return value as Record<string, unknown>;
}

export function string(value: unknown, max = 256): string {
  if (typeof value !== 'string' || !value || value.length > max) throw new BridgeError('invalid_string');
  return value;
}

export function stableId(...parts: string[]): string {
  return createHash('sha256').update(JSON.stringify(parts)).digest('hex');
}

export function parseBindings(raw: string): Binding[] {
  const data: unknown = JSON.parse(raw);
  if (!Array.isArray(data) || data.length === 0) throw new BridgeError('missing_bindings');
  const ids = new Set<string>();
  const identities = new Set<string>();
  const channels = new Set<string>();
  return data.map(value => {
    const b = record(value);
    const id = string(b.id, 80);
    const topicArn = string(b.topicArn);
    const region = string(b.region, 40);
    if (!/^[a-z]{2}(?:-[a-z]+)+-\d$/.test(region)) throw new BridgeError('invalid_region');
    if (!/^[a-zA-Z0-9_-]+$/.test(id)) throw new BridgeError('invalid_binding_id');
    if (!new RegExp(`^arn:aws:sns:${region}:[0-9]{12}:[A-Za-z0-9_-]+$`).test(topicArn)) throw new BridgeError('invalid_topic');
    const wabaId = string(b.wabaId, 40);
    const metaPhoneNumberId = string(b.metaPhoneNumberId, 40);
    if (!/^\d+$/.test(wabaId) || !/^\d+$/.test(metaPhoneNumberId)) throw new BridgeError('invalid_meta_identity');
    const awsPhoneNumberId = string(b.awsPhoneNumberId);
    const account = topicArn.split(':')[4];
    if (!new RegExp(`^arn:aws:social-messaging:${region}:${account}:phone-number-id/[a-zA-Z0-9]+$`).test(awsPhoneNumberId)) {
      throw new BridgeError('invalid_aws_phone_arn');
    }
    const identity = `${topicArn}:${wabaId}:${metaPhoneNumberId}`;
    const channelRef = string(b.channelRef, 80);
    if (ids.has(id) || identities.has(identity) || channels.has(channelRef)) throw new BridgeError('duplicate_binding');
    ids.add(id); identities.add(identity); channels.add(channelRef);
    if (!Array.isArray(b.allowedRecipients) || b.allowedRecipients.length > 20 || !b.allowedRecipients.every(p => typeof p === 'string' && /^\+[1-9]\d{6,14}$/.test(p))) {
      throw new BridgeError('invalid_allowlist');
    }
    return { id, topicArn, region, wabaId, metaPhoneNumberId, awsPhoneNumberId,
      channelRef, enabled: b.enabled === true, allowedRecipients: b.allowedRecipients as string[] };
  });
}

export function outbound(value: unknown, bindings: Binding[]): { command: OutboundCommand; binding: Binding } {
  const c = record(value);
  if (c.version !== 1) throw new BridgeError('unsupported_version');
  const bindingId = string(c.bindingId, 80);
  const binding = bindings.find(b => b.id === bindingId && b.enabled);
  if (!binding) throw new BridgeError('binding_disabled_or_unknown');
  const commandId = string(c.commandId, 100);
  if (!/^[a-zA-Z0-9_-]{8,100}$/.test(commandId)) throw new BridgeError('invalid_command_id');
  if (c.channelRef !== binding.channelRef) throw new BridgeError('channel_mismatch');
  const recipient = string(c.recipient, 16);
  if (!binding.allowedRecipients.includes(recipient)) throw new BridgeError('recipient_not_allowed');
  const text = string(c.text, 4096);
  if (!text.trim()) throw new BridgeError('empty_text');
  return { binding, command: { version: 1, commandId, bindingId, channelRef: binding.channelRef, recipient, text } };
}
