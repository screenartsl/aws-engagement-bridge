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
            if (!is_string($text) || trim($text) === '' || mb_strlen($text) > 4096) throw new BridgeException('invalid_text');
            $event += ['sender' => $sender, 'text' => $text];
        } else $event['status'] = $input['status'];
        return $event;
    }
}
