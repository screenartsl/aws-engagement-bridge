import { SocialMessagingClient, SendWhatsAppMessageCommand, ListWhatsAppMessageTemplatesCommand, GetWhatsAppMessageTemplateCommand } from '@aws-sdk/client-socialmessaging';
import { evidence } from './evidence.js';
import { Binding, BridgeError, OutboundCommand, SendResult, TemplateSummary, outbound } from './contracts.js';

export interface SocialTransport { send(command: any): Promise<any>; }
export class SocialAdapter {
  constructor(private readonly client: SocialTransport, private readonly metaApiVersion: string, private readonly liveEnabled = false) {}

  static aws(region: string, metaApiVersion: string, liveEnabled: boolean): SocialAdapter {
    // Never automatically retry POST sends: a timeout can mean Meta already accepted it.
    return new SocialAdapter(new SocialMessagingClient({ region, maxAttempts: 1 }), metaApiVersion, liveEnabled);
  }

  async send(command: OutboundCommand, binding: Binding): Promise<SendResult> {
    outbound(command, [binding]);
    if (!this.liveEnabled) throw new BridgeError('live_sending_disabled');
    if (!/^v\d+\.\d+$/.test(this.metaApiVersion)) throw new BridgeError('invalid_meta_api_version');
    // A template opens a conversation outside the 24 h window; Meta has already approved its text.
    const content = command.template
      ? { type: 'template', template: { name: command.template.name, language: { code: command.template.language },
          ...(command.template.parameters.length ? { components: [{ type: 'body', parameters: command.template.parameters.map(text => ({ type: 'text', text })) }] } : {}) } }
      : { type: 'text', text: { body: command.text, preview_url: false } };
    try {
      const result = await this.client.send(new SendWhatsAppMessageCommand({
        originationPhoneNumberId: binding.awsPhoneNumberId,
        metaApiVersion: this.metaApiVersion,
        message: new TextEncoder().encode(JSON.stringify({ messaging_product: 'whatsapp',
          // AWS End User Messaging Social requires E.164 WITH '+' ("Invalid destination phone number" without it).
          to: command.recipient, ...content,
          // Meta echoes this in every status webhook: the only reliable link between the AWS messageId and the wamid.
          biz_opaque_callback_data: command.commandId })),
      }));
      evidence('social_api_result', { id: command.commandId, requestId: result.$metadata?.requestId, httpStatus: result.$metadata?.httpStatusCode, status: result.messageId ? 'accepted' : 'uncertain' });
      if (!result.messageId) return { state: 'uncertain', errorCode: 'missing_provider_id' };
      return { state: 'accepted', providerMessageId: result.messageId };
    } catch (error) {
      const name = error instanceof Error ? error.name : '';
      const safe = ['AccessDeniedException', 'InvalidParametersException', 'ValidationException', 'ResourceNotFoundException'];
      if (safe.includes(name)) return { state: 'rejected', errorCode: name };
      // Explicit throttle rejection is safe for the caller to retry. No retry inside SDK.
      if (name === 'ThrottledRequestException') throw new BridgeError('provider_throttled');
      return { state: 'uncertain', errorCode: 'provider_result_unknown' };
    }
  }

  /** Approved templates of the binding's WABA, read-only. Templates are created and approved in AWS/Meta, not here. */
  async templates(binding: Binding, max = 50): Promise<TemplateSummary[]> {
    if (!binding.awsWabaId) throw new BridgeError('templates_not_configured');
    const approved: { metaTemplateId: string; name: string; language: string; category: string }[] = [];
    let nextToken: string | undefined;
    do {
      const page = await this.client.send(new ListWhatsAppMessageTemplatesCommand({ id: binding.awsWabaId, maxResults: 100, nextToken }));
      for (const t of page.templates ?? []) {
        if (String(t.templateStatus ?? '').toUpperCase() === 'APPROVED' && t.metaTemplateId && t.templateName && t.templateLanguage) {
          approved.push({ metaTemplateId: t.metaTemplateId, name: t.templateName, language: t.templateLanguage, category: t.templateCategory ?? '' });
        }
      }
      nextToken = page.nextToken;
    } while (nextToken && approved.length < max);
    const result: TemplateSummary[] = [];
    for (const t of approved.slice(0, max)) {
      // The summary has no text; the full definition carries the components shown and filled in the inbox.
      const full = await this.client.send(new GetWhatsAppMessageTemplateCommand({ id: binding.awsWabaId, metaTemplateId: t.metaTemplateId }));
      let components: unknown[] = [];
      try {
        const parsed = JSON.parse(full.template ?? '{}');
        if (Array.isArray(parsed?.components)) components = parsed.components;
      } catch { /* keep the template listed without components */ }
      result.push({ name: t.name, language: t.language, category: t.category, components });
    }
    return result;
  }
}
