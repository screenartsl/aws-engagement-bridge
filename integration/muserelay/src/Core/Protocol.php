<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class Protocol
{
    public static function event(array $input, object $b, int $now): array
    {
        if (($input['version'] ?? null) !== 1 || ($input['bindingId'] ?? '') !== $b->id || ($input['channelRef'] ?? '') !== $b->channel_ref) throw new BridgeException('binding_mismatch', 403);
        $resource = ['accountId' => $b->account_id, 'region' => $b->region, 'wabaId' => $b->waba_id,
            'metaPhoneNumberId' => $b->meta_phone_id, 'awsPhoneNumberArn' => $b->aws_phone_arn];
        foreach ($resource as $key => $value) {
            if (($input['resource'][$key] ?? null) !== $value) throw new BridgeException('aws_resource_mismatch', 403);
        }
        $kind = $input['kind'] ?? '';
        $id = $input['providerMessageId'] ?? '';
        $timestamp = $input['timestamp'] ?? null;
        if (!in_array($kind, ['message', 'status'], true) || !is_string($id) || !preg_match('~^[a-zA-Z0-9_.:+/=-]{1,256}$~D', $id)
            || !is_int($timestamp) || $timestamp < 1000000000 || $timestamp > $now + 300) throw new BridgeException('invalid_event');
        $parts = [$b->id, $kind, $id];
        if ($kind === 'status') {
            if (!in_array($input['status'] ?? '', ['sent', 'delivered', 'read', 'failed'], true)) throw new BridgeException('invalid_status');
            $parts[] = $input['status']; $parts[] = (string) $timestamp;
        }
        $eventId = hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (!hash_equals($eventId, (string) ($input['eventId'] ?? ''))) throw new BridgeException('invalid_event_id');
        $event = ['version' => 1, 'eventId' => $eventId, 'bindingId' => $b->id, 'channelRef' => $b->channel_ref, 'resource' => $resource, 'kind' => $kind, 'providerMessageId' => $id, 'timestamp' => $timestamp];
        if ($kind === 'message') {
            $sender = $input['sender'] ?? '';
            $text = $input['text'] ?? '';
            // Empty allowed_recipients = open number; a non-empty list restricts senders.
            $allowed = json_decode($b->allowed_recipients, true, 8, JSON_THROW_ON_ERROR);
            if (!is_string($sender) || !preg_match('/^\+[1-9][0-9]{6,14}$/D', $sender) || ($allowed !== [] && !in_array($sender, $allowed, true))) throw new BridgeException('recipient_not_allowed', 403);
            $type = $input['messageType'] ?? 'text';
            if ($type === 'text') {
                if (!is_string($text) || trim($text) === '' || mb_strlen($text) > 4096) throw new BridgeException('invalid_text');
                $event += ['sender' => $sender, 'text' => $text];
            } else {
                // Media: optional caption + the file reference; the URL may only point at this binding's copy in an S3 bucket in the region.
                if (!is_string($text) || mb_strlen($text) > 1024) throw new BridgeException('invalid_text');
                $event += ['sender' => $sender, 'text' => $text, 'messageType' => $type, 'media' => self::media($type, $input['media'] ?? null, $b, $eventId)];
            }
        } else {
            $event['status'] = $input['status'];
            // Optional: our outbox commandId, echoed by Meta (biz_opaque_callback_data). Statuses carry the wamid,
            // not the AWS messageId stored at send time, so this is the reliable link back to the outbox row.
            $ref = $input['commandRef'] ?? null;
            if ($ref !== null && (!is_string($ref) || !preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $ref))) throw new BridgeException('invalid_event');
            if ($ref !== null) $event['commandRef'] = $ref;
        }
        return $event;
    }

    /** Same allowlist and limits as MEDIA_MIME / MEDIA_MAX_BYTES in src/contracts.ts. */
    private const MEDIA = ['image' => ['~^image/(jpeg|png|webp)$~D', 5242880], 'audio' => ['~^audio/(ogg|mpeg|mp4|aac|amr)$~D', 16777216],
        'document' => ['~^application/pdf$~D', 20971520]];

    private static function media(mixed $type, mixed $m, object $b, string $eventId): array
    {
        if (!is_string($type) || !isset(self::MEDIA[$type]) || !is_array($m)) throw new BridgeException('invalid_media');
        [$mime, $max] = self::MEDIA[$type];
        $id = $m['id'] ?? ''; $mimeType = $m['mimeType'] ?? ''; $size = $m['size'] ?? null; $name = $m['fileName'] ?? null; $url = $m['url'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $id) || !is_string($mimeType) || !preg_match($mime, $mimeType)
            || ($size !== null && (!is_int($size) || $size < 1)) || ($name !== null && (!is_string($name) || mb_strlen($name) > 240))) throw new BridgeException('invalid_media');
        $out = ['id' => $id, 'mimeType' => $mimeType];
        if ($size !== null) $out['size'] = $size;
        if ($name !== null) $out['fileName'] = $name;
        if ($url !== null) {
            $prefix = '/media/'.$b->id.'/'.$eventId.'?';
            if (!is_string($url) || strlen($url) > 4096 || $size === null || $size > $max
                || !preg_match('~^https://[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\.s3\.'.preg_quote($b->region, '~').'\.amazonaws\.com(/.*)$~D', $url, $hit)
                || !str_starts_with($hit[1], $prefix)) throw new BridgeException('invalid_media_url');
            $out['url'] = $url;
        }
        return $out;
    }
}
