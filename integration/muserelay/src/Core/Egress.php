<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class Egress
{
    /** Same as OUTBOUND_MEDIA in src/contracts.ts: MIME types WhatsApp accepts and size limit per type (documents capped at 20 MB). */
    public const OUTBOUND_MEDIA = [
        'image' => ['~^image/(jpeg|png)$~D', 5242880],
        'audio' => ['~^audio/(aac|mp4|mpeg|amr|ogg)$~D', 16777216],
        'video' => ['~^video/(mp4|3gpp)$~D', 16777216],
        'document' => ['~^(application/(pdf|msword|vnd\.ms-excel|vnd\.ms-powerpoint|vnd\.openxmlformats-officedocument\.(wordprocessingml\.document|spreadsheetml\.sheet|presentationml\.presentation))|text/plain)$~D', 20971520],
    ];

    public function __construct(private Store $store, private Outbox $outbox, private Publisher $publisher) {}
    public function sendText(object $channel, object $contact, string $text, ?int $userId = null, ?int $sourceId = null): array
    {
        try {
            $binding = $this->store->bindingForChannel((int) $channel->id);
            if ((int) $contact->workspace_id !== (int) $binding->workspace_id || (int) $contact->organization_id !== (int) $binding->organization_id) throw new BridgeException('contact_ownership_mismatch', 403);
            $recipient = '+'.preg_replace('/\D+/', '', (string) ($contact->phone ?: $contact->external_contact_id));
            return $this->outbox->enqueue($binding, $recipient, $text, $sourceId, $userId);
        } catch (BridgeException $e) { return ['success' => false, 'error' => $e->reason]; }
        catch (\Throwable) { return ['success' => false, 'error' => 'bridge_unavailable']; }
    }

    /** Approved template; $text is its rendered body, kept in the inbox. Allowed outside the 24 h window. */
    public function sendTemplate(object $channel, object $contact, string $name, string $language, array $parameters, string $text, ?int $userId = null): array
    {
        try {
            $binding = $this->store->bindingForChannel((int) $channel->id);
            if ((int) $contact->workspace_id !== (int) $binding->workspace_id || (int) $contact->organization_id !== (int) $binding->organization_id) throw new BridgeException('contact_ownership_mismatch', 403);
            $recipient = '+'.preg_replace('/\D+/', '', (string) ($contact->phone ?: $contact->external_contact_id));
            return $this->outbox->enqueue($binding, $recipient, $text, null, $userId, ['name' => $name, 'language' => $language, 'parameters' => array_values($parameters)]);
        } catch (BridgeException $e) { return ['success' => false, 'error' => $e->reason]; }
        catch (\Throwable) { return ['success' => false, 'error' => 'bridge_unavailable']; }
    }

    /**
     * A file from the inbox: uploaded to this binding's slot in the media bucket (POST /media + presigned PUT), then queued
     * like any reply; the Sender Lambda hands it to WhatsApp (PostWhatsAppMessageMedia). Needs the 24 h window, like text.
     * $file = the inbox's stored attachment (disk, path, mime, size, original_name).
     */
    public function sendMedia(object $channel, object $contact, array $file, string $type, string $caption, ?int $userId = null): array
    {
        try {
            $this->store->requireOperation('send');
            $binding = $this->store->bindingForChannel((int) $channel->id);
            if ((int) $contact->workspace_id !== (int) $binding->workspace_id || (int) $contact->organization_id !== (int) $binding->organization_id) throw new BridgeException('contact_ownership_mismatch', 403);
            $mime = strtolower(trim(explode(';', (string) ($file['mime'] ?? ''))[0]));
            $size = (int) ($file['size'] ?? 0);
            if (!isset(self::OUTBOUND_MEDIA[$type]) || !preg_match(self::OUTBOUND_MEDIA[$type][0], $mime)) throw new BridgeException('unsupported_media', 422);
            if ($size < 1 || $size > self::OUTBOUND_MEDIA[$type][1]) throw new BridgeException('media_too_large', 422);
            $recipient = '+'.preg_replace('/\D+/', '', (string) ($contact->phone ?: $contact->external_contact_id));
            $slot = $this->publisher->uploadSlot($binding->id, $type, $mime, $size);
            $this->publisher->upload($slot, \Illuminate\Support\Facades\Storage::disk($file['disk'])->get($file['path']), $mime);
            $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) ($file['original_name'] ?? ''));
            $name = ltrim(mb_substr((string) $name, 0, 120), '._ -');
            $media = ['type' => $type, 'key' => $slot['key'], 'mimeType' => $mime] + ($name !== '' ? ['fileName' => $name] : [])
                + (trim($caption) !== '' && $type !== 'audio' ? ['caption' => mb_substr($caption, 0, 1024)] : []);
            return $this->outbox->enqueue($binding, $recipient, trim($caption) !== '' ? $caption : ($name !== '' ? $name : '['.$type.']'), null, $userId, null, $media);
        } catch (BridgeException $e) { return ['success' => false, 'error' => $e->reason]; }
        catch (\Throwable) { return ['success' => false, 'error' => 'bridge_unavailable']; }
    }

    /** @return array{templates?: array, error?: string} */
    public function templates(object $channel): array
    {
        try {
            $this->store->requireOperation('send');
            $binding = $this->store->bindingForChannel((int) $channel->id);
            return ['templates' => $this->publisher->templates($binding->id)];
        } catch (BridgeException $e) { return ['error' => $e->reason]; }
        catch (\Throwable) { return ['error' => 'bridge_unavailable']; }
    }
}
