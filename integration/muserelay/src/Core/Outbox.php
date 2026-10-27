<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class Outbox
{
    public function __construct(private Store $store, private CorePort $core) {}

    public function enqueue(object $binding, string $recipient, string $text, ?int $sourceId, ?int $userId, ?array $template = null, ?array $media = null): array
    {
        $this->store->requireOperation('send');
        $b = $this->store->binding($binding->id, $binding->channel_ref);
        // Empty allowed_recipients = open number; a non-empty list restricts recipients.
        $allowed = json_decode($b->allowed_recipients, true, 8, JSON_THROW_ON_ERROR);
        if (!preg_match('/^\+[1-9][0-9]{6,14}$/D', $recipient) || ($allowed !== [] && !in_array($recipient, $allowed, true))) throw new BridgeException('recipient_not_allowed', 403);
        if (trim($text) === '' || mb_strlen($text) > 4096) throw new BridgeException('invalid_text');
        if ($template !== null) $template = self::template($template);
        if ($media !== null) {
            if ($template !== null) throw new BridgeException('invalid_media');
            $media = self::media($media, $b->id);
        }
        $id = $sourceId ? hash('sha256', $b->id.'|reply|'.$sourceId) : bin2hex(random_bytes(32));
        $result = $this->store->db->transaction(function () use ($b, $recipient, $text, $sourceId, $userId, $id, $template, $media): array {
            $this->store->db->table('aws_bridge_bindings')->where('id', $b->id)->lockForUpdate()->first();
            $old = $this->store->db->table('aws_bridge_outbox')->where('id', $id)->first();
            if ($old) return ['success' => true, 'message_id' => (int) $old->message_id, 'queued' => true, 'duplicate' => true];
            $limit = (int) $b->daily_outbound_limit; // 0 = no daily cap
            if ($limit > 0 && $this->store->db->table('aws_bridge_outbox')->where('binding_id', $b->id)->where('created_epoch', '>=', (int) (floor($this->store->now() / 86400) * 86400))->count() >= $limit) throw new BridgeException('daily_outbound_limit', 429);
            $messageId = $this->core->outgoing($b, $recipient, $text, $sourceId, $userId, $template, $media);
            $payload = ['version' => 1, 'commandId' => $id, 'bindingId' => $b->id, 'channelRef' => $b->channel_ref, 'recipient' => $recipient, 'text' => $text]
                + ($template ? ['template' => $template] : []) + ($media ? ['media' => $media] : []);
            $this->store->db->table('aws_bridge_outbox')->insert(['id' => $id, 'binding_id' => $b->id, 'message_id' => $messageId,
                'source_message_id' => $sourceId, 'payload' => $this->store->encode($payload), 'created_epoch' => $this->store->now()]);
            return ['success' => true, 'message_id' => $messageId, 'queued' => true, 'command_id' => $id];
        });
        // Published right away by the queued drain (the scheduled one is only the safety net).
        if (empty($result['duplicate'])) Drainer::kick();
        return $result;
    }

    /** Same rules as the AWS side (contracts.ts templateRef): Meta template name, language code, up to 20 body parameters. */
    public static function template(array $t): array
    {
        $name = $t['name'] ?? null; $language = $t['language'] ?? null; $parameters = $t['parameters'] ?? [];
        if (!is_string($name) || strlen($name) > 512 || !preg_match('/^[a-z0-9_]+$/D', $name)
            || !is_string($language) || !preg_match('/^[a-z]{2,3}(?:_[A-Za-z]{2,4})?$/D', $language)
            || !is_array($parameters) || !array_is_list($parameters) || count($parameters) > 20) throw new BridgeException('invalid_template');
        foreach ($parameters as $p) if (!is_string($p) || trim($p) === '' || mb_strlen($p) > 1024) throw new BridgeException('invalid_template');
        return ['name' => $name, 'language' => $language, 'parameters' => $parameters];
    }

    /** Same rules as the AWS side (contracts.ts outboundMedia): a file uploaded through POST /media for this binding. */
    public static function media(array $m, string $bindingId): array
    {
        $type = $m['type'] ?? null; $key = $m['key'] ?? null; $mime = $m['mimeType'] ?? null; $source = $m['source'] ?? null;
        if (!is_string($type) || !isset(Egress::OUTBOUND_MEDIA[$type]) || !is_string($mime) || !preg_match(Egress::OUTBOUND_MEDIA[$type][0], $mime)) throw new BridgeException('invalid_media');
        // Either already uploaded (key) or still a local file of the inbox that the drain uploads before publishing (source).
        if ($key !== null) {
            if (!is_string($key) || !preg_match('~^outbound/'.preg_quote($bindingId, '~').'/[a-f0-9]{32}/file$~D', $key)) throw new BridgeException('invalid_media');
            $out = ['type' => $type, 'key' => $key, 'mimeType' => $mime];
        } else {
            $disk = $source['disk'] ?? null; $path = $source['path'] ?? null; $size = $source['size'] ?? null;
            if (!is_string($disk) || !preg_match('/^[a-z0-9_-]{1,40}$/D', $disk) || !is_string($path) || $path === '' || str_contains($path, '..') || strlen($path) > 500
                || !is_int($size) || $size < 1 || $size > Egress::OUTBOUND_MEDIA[$type][1]) throw new BridgeException('invalid_media');
            $out = ['type' => $type, 'mimeType' => $mime, 'source' => ['disk' => $disk, 'path' => $path, 'size' => $size]];
        }
        $name = $m['fileName'] ?? null; $caption = $m['caption'] ?? null;
        if ($name !== null) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,119}$/D', $name)) throw new BridgeException('invalid_media');
            $out['fileName'] = $name;
        }
        if ($caption !== null && $type !== 'audio') {
            if (!is_string($caption) || trim($caption) === '' || mb_strlen($caption) > 1024) throw new BridgeException('invalid_media');
            $out['caption'] = $caption;
        }
        return $out;
    }

    public function receipt(string $authenticatedBinding, array $receipt): array
    {
        $b = $this->store->binding($authenticatedBinding);
        $id = $receipt['commandId'] ?? '';
        if (($receipt['version'] ?? null) !== 1 || ($receipt['bindingId'] ?? '') !== $b->id || !is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)
            || !in_array($receipt['state'] ?? '', ['accepted', 'rejected', 'uncertain'], true)) throw new BridgeException('invalid_receipt');
        if ($receipt['state'] === 'accepted' && (!is_string($receipt['providerMessageId'] ?? null) || strlen($receipt['providerMessageId']) > 256 || $receipt['providerMessageId'] === '')) throw new BridgeException('missing_provider_id');
        return $this->store->db->transaction(function () use ($b, $id, $receipt): array {
            $row = $this->store->db->table('aws_bridge_outbox')->where('id', $id)->where('binding_id', $b->id)->lockForUpdate()->first();
            if (!$row) throw new BridgeException('command_not_found', 404);
            if ($row->state === 'accepted' && ($receipt['state'] !== 'accepted' || $row->provider_message_id !== ($receipt['providerMessageId'] ?? null))) throw new BridgeException('receipt_conflict', 409);
            $code = $receipt['errorCode'] ?? null;
            if ($code !== null && (!is_string($code) || !preg_match('/^[a-zA-Z0-9_]{1,80}$/D', $code))) throw new BridgeException('invalid_error_code');
            $this->store->db->table('aws_bridge_outbox')->where('id', $id)->update(['state' => $receipt['state'], 'provider_message_id' => $receipt['providerMessageId'] ?? null,
                'error_code' => $code, 'lease_until' => 0, 'lease_token' => null]);
            $this->core->receipt($b, $row, $receipt);
            return ['status' => 'accepted', 'id' => $id];
        });
    }

    /**
     * $upload(bindingId, media) → key: uploads a pending file to the binding's media slot. Runs here, in the drain
     * (the only process that holds the signing keys), never in the web request.
     */
    public function publishOne(callable $publish, ?callable $upload = null): bool
    {
        // No queries at all when disabled; reuse Store's gate before selecting a binding.
        if (!$this->store->operationEnabled('send')) return false;
        $selected = null;
        $rows = $this->store->db->table('aws_bridge_outbox')->whereIn('binding_id', $this->store->bindingIds())->whereIn('state', ['pending', 'publishing'])->where('lease_until', '<=', $this->store->now())
            ->where('attempts', '<', 3)->orderBy('created_epoch')->limit(10)->get();
        foreach ($rows as $candidate) {
            try { $this->store->binding($candidate->binding_id); } catch (BridgeException) { continue; }
            $selected = $this->store->db->transaction(function () use ($candidate): ?object {
                $row = $this->store->db->table('aws_bridge_outbox')->where('id', $candidate->id)->lockForUpdate()->first();
                if (!$row || !in_array($row->state, ['pending', 'publishing'], true) || $row->lease_until > $this->store->now()) return null;
                $token = bin2hex(random_bytes(16));
                $this->store->db->table('aws_bridge_outbox')->where('id', $row->id)->update(['state' => 'publishing', 'lease_token' => $token, 'lease_until' => $this->store->now() + 60, 'attempts' => $row->attempts + 1]);
                $row->lease_token = $token; return $row;
            });
            if ($selected) break;
        }
        if (!$selected) return false;
        try {
            $this->store->requireOperation('send');
            $binding = $this->store->binding($selected->binding_id);
            $payload = $this->store->decode($selected->payload);
            $this->core->authorizeOutgoing($binding, $selected, $payload);
            if (isset($payload['media']['source'])) {
                if (!$upload) throw new BridgeException('media_upload_unavailable', 503);
                $key = $upload($selected->binding_id, $payload['media']);
                $media = $payload['media']; unset($media['source']); $media['key'] = $key;
                $payload['media'] = self::media($media, $selected->binding_id);
                // Saved before publishing: a retry reuses this upload and the same command (AWS rejects a changed one).
                $this->store->db->table('aws_bridge_outbox')->where('id', $selected->id)->where('lease_token', $selected->lease_token)->update(['payload' => $this->store->encode($payload)]);
            }
            $publish($selected->binding_id, $payload);
            $this->store->db->table('aws_bridge_outbox')->where('id', $selected->id)->where('state', 'publishing')->where('lease_token', $selected->lease_token)
                ->update(['state' => 'queued', 'lease_token' => null, 'lease_until' => 0]);
        } catch (\Throwable $error) {
            $blocked = $error instanceof BridgeException && $error->httpStatus < 500;
            $this->store->db->table('aws_bridge_outbox')->where('id', $selected->id)->where('state', 'publishing')->where('lease_token', $selected->lease_token)
                ->update(['state' => $blocked ? 'blocked' : ($selected->attempts + 1 >= 3 ? 'failed' : 'pending'), 'error_code' => $blocked ? 'policy_blocked' : 'publish_failed', 'lease_token' => null, 'lease_until' => $this->store->now() + 30]);
            if ($blocked && isset($binding)) {
                try { $this->store->db->transaction(fn () => $this->core->receipt($binding, $selected, ['state' => 'rejected', 'errorCode' => 'policy_blocked'])); }
                catch (\Throwable) { /* Outbox remains blocked; reconcile its UI receipt in staging/admin tooling. */ }
            }
        }
        return true;
    }
}
