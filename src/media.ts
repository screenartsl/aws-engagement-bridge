import { GetObjectCommand, S3Client } from '@aws-sdk/client-s3';
import { getSignedUrl } from '@aws-sdk/s3-request-presigner';
import { GetWhatsAppMessageMediaCommand, SocialMessagingClient } from '@aws-sdk/client-socialmessaging';
import { Binding, BridgeError, InboundEvent, InboundMedia, MEDIA_MAX_BYTES } from './contracts.js';

/** Core downloads the file within this time; the bucket lifecycle deletes the copy after a day. */
const URL_SECONDS = 3600;

export interface MediaPort { fetch(event: InboundEvent, binding: Binding): Promise<InboundMedia>; }

/**
 * Copies a customer's media from WhatsApp into the private media bucket (GetWhatsAppMessageMedia) and returns a
 * short-lived presigned GET for Core. The file never passes through the Lambda's memory.
 */
export class S3Media implements MediaPort {
  constructor(private readonly social: { send(command: any): Promise<any> }, private readonly bucket: string,
    private readonly presign: (bucket: string, key: string) => Promise<string>) {
    if (!/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/.test(bucket)) throw new BridgeError('invalid_media_bucket');
  }

  static aws(region: string, bucket: string): S3Media {
    const s3 = new S3Client({ region });
    return new S3Media(new SocialMessagingClient({ region, maxAttempts: 2 }), bucket,
      (b, key) => getSignedUrl(s3, new GetObjectCommand({ Bucket: b, Key: key }), { expiresIn: URL_SECONDS }));
  }

  async fetch(event: InboundEvent, binding: Binding): Promise<InboundMedia> {
    const media = event.media;
    if (!media || !event.messageType) throw new BridgeError('invalid_media');
    const originationPhoneNumberId = binding.awsPhoneNumberId;
    // Size first: an oversized file is reported to Core (the inbox shows it as not downloaded), never copied.
    const meta = await this.social.send(new GetWhatsAppMessageMediaCommand({ mediaId: media.id, originationPhoneNumberId, metadataOnly: true }));
    const size = Number(meta.fileSize ?? 0);
    if (!Number.isInteger(size) || size <= 0) throw new BridgeError('media_size_unknown');
    if (size > MEDIA_MAX_BYTES[event.messageType]) return { ...media, size };
    const key = `media/${binding.id}/${event.eventId}`;
    await this.social.send(new GetWhatsAppMessageMediaCommand({ mediaId: media.id, originationPhoneNumberId,
      destinationS3File: { bucketName: this.bucket, key } }));
    return { ...media, size, url: await this.presign(this.bucket, key) };
  }
}
