<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;

final class Store
{
    public function __construct(public readonly ConnectionInterface $db, private Encrypter $crypto, private bool $enabled,
        private array $organizations, private ?\Closure $clock = null) {}

    public function now(): int { return $this->clock ? ($this->clock)() : time(); }
    public function isEnabled(): bool { return $this->enabled && $this->organizations !== []; }
    public function bindingIds(): array
    {
        return $this->isEnabled() ? $this->db->table('aws_bridge_bindings')->where('enabled', true)->whereIn('organization_id', $this->organizations)->orderBy('id')->pluck('id')->all() : [];
    }
    public function encode(array $data): string { return $this->crypto->encrypt(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false); }
    public function decode(string $data): array { return json_decode($this->crypto->decrypt($data, false), true, 32, JSON_THROW_ON_ERROR); }

    public function binding(string $id, ?string $channelRef = null): object
    {
        if (!$this->enabled || !$this->organizations) throw new BridgeException('bridge_disabled', 503);
        $b = $this->db->table('aws_bridge_bindings')->where('id', $id)->first();
        if (!$b || !$b->enabled || !in_array((int) $b->organization_id, $this->organizations, true)) throw new BridgeException('binding_disabled', 403);
        if ($channelRef !== null && !hash_equals($b->channel_ref, $channelRef)) throw new BridgeException('binding_mismatch', 403);
        $channel = $this->db->table('channels')->where('id', $b->channel_id)->first();
        $workspace = $this->db->table('workspaces')->where('id', $b->workspace_id)->first();
        $agent = $this->db->table('ai_agents')->where('id', $b->agent_id)->first();
        if (!$channel || !$workspace || !$agent || $channel->type !== 'whatsapp' || $channel->status !== 'active'
            || ($channel->managed_provider ?? '') !== 'aws_social'
            || (int) $channel->organization_id !== (int) $b->organization_id || (int) $channel->workspace_id !== (int) $b->workspace_id
            || (int) $workspace->organization_id !== (int) $b->organization_id
            || (int) $agent->organization_id !== (int) $b->organization_id || (int) $agent->workspace_id !== (int) $b->workspace_id
            || (int) ($channel->ai_agent_id ?? 0) !== (int) $agent->id) throw new BridgeException('ownership_mismatch', 403);
        if ($b->region !== 'eu-west-1' || !preg_match('/^[0-9]{12}$/D', $b->account_id)
            || !preg_match('/^[0-9]+$/D', $b->waba_id) || !preg_match('/^[0-9]+$/D', $b->meta_phone_id)
            || !preg_match('~^arn:aws:social-messaging:'.preg_quote($b->region, '~').':'.preg_quote($b->account_id, '~').':phone-number-id/[a-zA-Z0-9]+$~D', $b->aws_phone_arn)) {
            throw new BridgeException('invalid_aws_identity', 403);
        }
        // The demo must actually use Bedrock, including configured agent fallbacks.
        foreach (['ai_provider_id', 'fallback_provider_id', 'fallback2_provider_id'] as $column) {
            $id = (int) ($agent->$column ?? 0);
            if (!$id && $column !== 'ai_provider_id') continue;
            $provider = $this->db->table('ai_providers')->where('id', $id)->first();
            if (!$provider || !in_array($provider->provider_type, ['bedrock', 'aws_bedrock'], true)) throw new BridgeException('bedrock_agent_required', 403);
            if (isset($provider->is_active) && !$provider->is_active) throw new BridgeException('provider_inactive', 403);
            if (!empty($provider->organization_id) && (int) $provider->organization_id !== (int) $b->organization_id && ($provider->scope ?? '') !== 'global') throw new BridgeException('provider_ownership_mismatch', 403);
        }
        return $b;
    }

    public function bindingForChannel(int $channelId): object
    {
        if (!$this->enabled || !$this->organizations) throw new BridgeException('bridge_disabled', 503);
        $id = $this->db->table('aws_bridge_bindings')->where('channel_id', $channelId)->value('id');
        if (!$id) throw new BridgeException('binding_missing', 403);
        return $this->binding((string) $id);
    }

    public function reserveNonce(string $bindingId, string $nonce, string $path): void
    {
        $id = hash('sha256', $bindingId."\n".$path."\n".$nonce);
        if (!$this->db->table('aws_bridge_nonces')->insertOrIgnore(['id' => $id, 'expires_at' => $this->now() + 600])) throw new BridgeException('replayed_request', 409);
    }

    public function accept(string $authenticatedBinding, array $event): array
    {
        $b = $this->binding($authenticatedBinding, (string) ($event['channelRef'] ?? ''));
        $event = Protocol::event($event, $b, $this->now());
        $digest = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->db->transaction(function () use ($b, $event, $digest): array {
            $this->db->table('aws_bridge_bindings')->where('id', $b->id)->lockForUpdate()->first();
            $old = $this->db->table('aws_bridge_events')->where('id', $event['eventId'])->first();
            if ($old) {
                if ($old->binding_id !== $b->id || !hash_equals($old->digest, $digest)) throw new BridgeException('idempotency_conflict', 409);
                return ['status' => 'duplicate', 'id' => $event['eventId']];
            }
            if ($event['kind'] === 'message') {
                $limit = (int) $b->daily_inbound_limit;
                $used = $this->db->table('aws_bridge_events')->where('binding_id', $b->id)->where('kind', 'message')->where('created_epoch', '>=', (int) (floor($this->now() / 86400) * 86400))->count();
                if ($limit < 1 || $limit > 100 || $used >= $limit) throw new BridgeException('daily_inbound_limit', 429);
            }
            $this->db->table('aws_bridge_events')->insert(['id' => $event['eventId'], 'binding_id' => $b->id, 'kind' => $event['kind'],
                'digest' => $digest, 'payload' => $this->encode($event), 'created_epoch' => $this->now(), 'event_timestamp' => $event['timestamp']]);
            return ['status' => 'accepted', 'id' => $event['eventId']];
        });
    }

    public function claim(): ?object
    {
        if (!$this->enabled || !$this->organizations) return null;
        $bindings = $this->bindingIds();
        foreach ($bindings as $id) {
            $b = $this->binding((string) $id);
            $row = $this->db->transaction(function () use ($b): ?object {
                $lock = $this->db->table('aws_bridge_bindings')->where('id', $b->id)->lockForUpdate()->first();
                if ((int) $lock->lease_until > $this->now()) return null;
                $this->db->table('aws_bridge_events')->where('binding_id', $b->id)->where('attempts', '>=', 3)->where('lease_until', '<=', $this->now())->whereIn('state', ['pending', 'processing'])->update(['state' => 'failed', 'error_code' => 'attempt_limit']);
                $event = $this->db->table('aws_bridge_events')->where('binding_id', $b->id)->whereIn('state', ['pending', 'processing'])
                    ->where('lease_until', '<=', $this->now())->where('attempts', '<', 3)->orderBy('event_timestamp')->orderBy('sequence')->lockForUpdate()->first();
                if (!$event) return null;
                $token = bin2hex(random_bytes(16));
                $this->db->table('aws_bridge_events')->where('id', $event->id)->update(['state' => 'processing', 'lease_token' => $token, 'lease_until' => $this->now() + 600, 'attempts' => $event->attempts + 1]);
                $this->db->table('aws_bridge_bindings')->where('id', $b->id)->update(['lease_token' => $token, 'lease_until' => $this->now() + 600]);
                return $this->db->table('aws_bridge_events')->where('id', $event->id)->first();
            });
            if ($row) return $row;
        }
        return null;
    }

    public function materialize(object $event, callable $create): object
    {
        return $this->db->transaction(function () use ($event, $create): object {
            $row = $this->ownedEvent($event);
            if (!$row->message_id) {
                $ids = $create();
                $this->db->table('aws_bridge_events')->where('id', $row->id)->update(['message_id' => $ids['message_id'], 'conversation_id' => $ids['conversation_id']]);
            }
            return $this->db->table('aws_bridge_events')->where('id', $row->id)->first();
        });
    }

    public function ownedEvent(object $event): object
    {
        $row = $this->db->table('aws_bridge_events')->where('id', $event->id)->lockForUpdate()->first();
        if (!$row || $row->state !== 'processing' || !hash_equals((string) $row->lease_token, (string) $event->lease_token) || (int) $row->lease_until <= $this->now()) throw new BridgeException('event_lease_lost', 409);
        return $row;
    }

    public function finish(object $event, bool $success): void
    {
        $this->db->transaction(function () use ($event, $success): void {
            $row = $this->ownedEvent($event);
            $this->db->table('aws_bridge_events')->where('id', $row->id)->update(['state' => $success ? 'done' : ($row->attempts >= 3 ? 'failed' : 'pending'),
                'lease_until' => 0, 'lease_token' => null, 'error_code' => $success ? null : 'turn_failed']);
            $this->db->table('aws_bridge_bindings')->where('id', $row->binding_id)->where('lease_token', $event->lease_token)->update(['lease_until' => 0, 'lease_token' => null]);
        });
    }

    public function action(object $event, string $tool, callable $execute): array
    {
        return $this->db->transaction(function () use ($event, $tool, $execute): array {
            $row = $this->ownedEvent($event);
            $id = hash('sha256', $row->id.'|'.$tool);
            $old = $this->db->table('aws_bridge_actions')->where('id', $id)->first();
            if ($old) return $this->decode($old->result);
            // Native ticket + audit and this checkpoint commit on the SAME default connection.
            $result = $execute();
            $this->db->table('aws_bridge_actions')->insert(['id' => $id, 'event_id' => $row->id, 'tool_name' => $tool, 'result' => $this->encode($result)]);
            return $result;
        });
    }
}
