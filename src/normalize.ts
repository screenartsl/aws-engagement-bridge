import { Binding, BridgeError, InboundEvent, MEDIA_MIME, MediaType, record, stableId, string } from './contracts.js';

/** WhatsApp message types handled; voice notes arrive as `audio`. Video, stickers, locations… are ignored for now. */
const MEDIA_TYPES: Record<string, MediaType> = { image: 'image', audio: 'audio', document: 'document' };

/** Input is an SNS envelope delivered ONLY by the policy-restricted SQS queue. */
export function normalize(envelope: unknown, bindings: Binding[]): InboundEvent[] {
  const sns = record(envelope);
  if (sns.Type !== 'Notification') throw new BridgeError('unsupported_sns_type');
  const topic = string(sns.TopicArn);
  const candidates = bindings.filter(b => b.enabled && b.topicArn === topic);
  if (!candidates.length) throw new BridgeError('untrusted_topic');
  const social = record(JSON.parse(string(sns.Message, 240_000)));
  const raw = social.whatsAppWebhookEntry;
  const entry = record(typeof raw === 'string' ? JSON.parse(raw) : raw);
  const wabaId = string(entry.id, 40);
  if (!Array.isArray(entry.changes) || entry.changes.length > 100) throw new BridgeError('invalid_changes');
  const events: InboundEvent[] = [];
  for (const item of entry.changes) {
    const change = record(item);
    // Calls, account updates and system notifications never trigger agents.
    if (change.field !== 'messages') continue;
    const value = record(change.value);
    const phone = string(record(value.metadata).phone_number_id, 40);
    const binding = candidates.find(b => b.wabaId === wabaId && b.metaPhoneNumberId === phone);
    if (!binding) throw new BridgeError('unmapped_identity');
    const resource = { accountId: binding.topicArn.split(':')[4]!, region: binding.region, wabaId: binding.wabaId,
      metaPhoneNumberId: binding.metaPhoneNumberId, awsPhoneNumberArn: binding.awsPhoneNumberId };
    for (const msgValue of list(value.messages)) {
      const msg = record(msgValue);
      const type = String(msg.type);
      if (type !== 'text' && !MEDIA_TYPES[type]) continue;
      const from = string(msg.from, 15);
      if (!/^[1-9]\d{6,14}$/.test(from)) throw new BridgeError('invalid_sender');
      // Empty list = open number (anyone may write); a non-empty list restricts senders.
      if (binding.allowedRecipients.length && !binding.allowedRecipients.includes(`+${from}`)) continue;
      const id = string(msg.id);
      const base: InboundEvent = { version: 1, eventId: stableId(binding.id, 'message', id), bindingId: binding.id,
        channelRef: binding.channelRef, resource, kind: 'message', providerMessageId: id,
        timestamp: timestamp(msg.timestamp), sender: `+${from}` };
      if (type === 'text') { events.push({ ...base, text: string(record(msg.text).body, 4096) }); continue; }
      const messageType = MEDIA_TYPES[type]!;
      const m = record(msg[type]);
      // "audio/ogg; codecs=opus" → "audio/ogg"; a type outside the allowlist is not downloaded or shown.
      const mimeType = string(m.mime_type, 100).split(';')[0]!.trim().toLowerCase();
      if (!MEDIA_MIME[messageType].test(mimeType)) continue;
      const mediaId = string(m.id, 128);
      if (!/^[a-zA-Z0-9_.-]+$/.test(mediaId)) throw new BridgeError('invalid_media_id');
      const event: InboundEvent = { ...base, messageType, media: { id: mediaId, mimeType } };
      if (typeof m.filename === 'string' && m.filename.trim()) event.media!.fileName = m.filename.trim().slice(0, 240);
      if (typeof m.caption === 'string' && m.caption.trim()) event.text = m.caption.slice(0, 1024);
      events.push(event);
    }
    for (const statusValue of list(value.statuses)) {
      const status = record(statusValue);
      if (!['sent', 'delivered', 'read', 'failed'].includes(String(status.status))) continue;
      const id = string(status.id);
      const at = timestamp(status.timestamp);
      const state = status.status as NonNullable<InboundEvent['status']>;
      const event: InboundEvent = { version: 1, eventId: stableId(binding.id, 'status', id, state, String(at)), bindingId: binding.id,
        channelRef: binding.channelRef, resource, kind: 'status', providerMessageId: id, timestamp: at, status: state };
      const ref = status.biz_opaque_callback_data;
      if (typeof ref === 'string' && /^[a-zA-Z0-9_-]{8,100}$/.test(ref)) event.commandRef = ref;
      events.push(event);
    }
  }
  return events;
}

function list(value: unknown): unknown[] {
  if (value === undefined) return [];
  if (!Array.isArray(value) || value.length > 100) throw new BridgeError('invalid_event_list');
  return value;
}
function timestamp(value: unknown): number {
  if (!/^\d{10}$/.test(String(value))) throw new BridgeError('invalid_timestamp');
  return Number(value);
}
