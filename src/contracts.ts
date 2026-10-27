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
  /** AWS id of the linked WABA (waba-…); needed only to list its message templates. */
  awsWabaId?: string;
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
  /** WhatsApp profile name of the sender (contacts[].profile.name), as Meta sends it. Never a profile picture: the API has none. */
  senderName?: string;
  /** Text body, or the caption of a media message. */
  text?: string;
  /** Message only; absent = text. */
  messageType?: MediaType;
  media?: InboundMedia;
  status?: 'sent' | 'delivered' | 'read' | 'failed';
  /** Status only: our outbox commandId, echoed back by Meta as biz_opaque_callback_data. */
  commandRef?: string;
}

export type MediaType = 'image' | 'audio' | 'document';
/** The Meta media id; `url` is a short-lived presigned S3 GET once the Lambda has copied the file to the media bucket. */
export interface InboundMedia { id: string; mimeType: string; fileName?: string; size?: number; url?: string; }

/** Media MuseRelay can show and pass to the agent (vision, audio transcription, PDF). Others are ignored. */
export const MEDIA_MIME: Record<MediaType, RegExp> = {
  image: /^image\/(jpeg|png|webp)$/,
  audio: /^audio\/(ogg|mpeg|mp4|aac|amr)$/,
  document: /^application\/pdf$/,
};
/** WhatsApp's own limits are 5 MB (image) and 16 MB (audio); PDFs are capped at 20 MB, what the agent can read inline. */
export const MEDIA_MAX_BYTES: Record<MediaType, number> = { image: 5 * 1024 * 1024, audio: 16 * 1024 * 1024, document: 20 * 1024 * 1024 };

export interface OutboundCommand {
  version: 1;
  commandId: string;
  bindingId: string;
  channelRef: string;
  recipient: string;
  /** Plain text, or the rendered body of the template (kept for the inbox; not what is sent). */
  text: string;
  template?: TemplateRef;
}

export interface TemplateRef { name: string; language: string; parameters: string[]; }

export interface TemplateSummary { name: string; language: string; category: string; components: unknown[]; }

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
    if (!new RegExp(`^arn:aws:social-messaging:${region}:${account}:phone-number-id/[a-zA-Z0-9][a-zA-Z0-9-]*$`).test(awsPhoneNumberId)) {
      throw new BridgeError('invalid_aws_phone_arn');
    }
    let awsWabaId: string | undefined;
    if (b.awsWabaId !== undefined) {
      awsWabaId = string(b.awsWabaId, 80);
      if (!/^waba-[a-f0-9]{32}$/.test(awsWabaId)) throw new BridgeError('invalid_aws_waba_id');
    }
    const identity = `${topicArn}:${wabaId}:${metaPhoneNumberId}`;
    const channelRef = string(b.channelRef, 80);
    if (ids.has(id) || identities.has(identity) || channels.has(channelRef)) throw new BridgeError('duplicate_binding');
    ids.add(id); identities.add(identity); channels.add(channelRef);
    if (!Array.isArray(b.allowedRecipients) || b.allowedRecipients.length > 20 || !b.allowedRecipients.every(p => typeof p === 'string' && /^\+[1-9]\d{6,14}$/.test(p))) {
      throw new BridgeError('invalid_allowlist');
    }
    return { id, topicArn, region, wabaId, metaPhoneNumberId, awsPhoneNumberId, ...(awsWabaId ? { awsWabaId } : {}),
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
  if (!/^\+[1-9]\d{6,14}$/.test(recipient)) throw new BridgeError('invalid_recipient');
  if (binding.allowedRecipients.length && !binding.allowedRecipients.includes(recipient)) throw new BridgeError('recipient_not_allowed');
  const text = string(c.text, 4096);
  if (!text.trim()) throw new BridgeError('empty_text');
  const command: OutboundCommand = { version: 1, commandId, bindingId, channelRef: binding.channelRef, recipient, text };
  if (c.template !== undefined) command.template = templateRef(c.template);
  return { binding, command };
}

export function templateRef(value: unknown): TemplateRef {
  const t = record(value);
  const name = string(t.name, 512);
  const language = string(t.language, 15);
  if (!/^[a-z0-9_]+$/.test(name)) throw new BridgeError('invalid_template');
  if (!/^[a-z]{2,3}(?:_[A-Za-z]{2,4})?$/.test(language)) throw new BridgeError('invalid_template');
  if (!Array.isArray(t.parameters) || t.parameters.length > 20
    || !t.parameters.every(p => typeof p === 'string' && p.trim() !== '' && p.length <= 1024)) throw new BridgeError('invalid_template');
  return { name, language, parameters: t.parameters as string[] };
}
