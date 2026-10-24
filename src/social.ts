import { SocialMessagingClient, SendWhatsAppMessageCommand } from '@aws-sdk/client-socialmessaging';
import { evidence } from './evidence.js';
import { Binding, BridgeError, OutboundCommand, SendResult, outbound } from './contracts.js';

export interface SocialTransport { send(command: SendWhatsAppMessageCommand): Promise<{ messageId?: string; $metadata?: { requestId?: string; httpStatusCode?: number } }>; }
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
    try {
      const result = await this.client.send(new SendWhatsAppMessageCommand({
        originationPhoneNumberId: binding.awsPhoneNumberId,
        metaApiVersion: this.metaApiVersion,
        message: new TextEncoder().encode(JSON.stringify({ messaging_product: 'whatsapp',
          to: command.recipient.slice(1), type: 'text', text: { body: command.text, preview_url: false } })),
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
}
