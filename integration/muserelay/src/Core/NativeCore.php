<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AutomationService;
use App\Services\ContactManagerService;
use App\Services\InboxBroadcaster;
use Carbon\Carbon;

/** Reuses the existing application; this class is loaded only after installation/activation. */
final class NativeCore implements CorePort
{
    public function __construct(private Store $store) {}

    public function incoming(object $b, array $event): array
    {
        $this->sameConnection();
        $channel = Channel::findOrFail($b->channel_id);
        $contact = app(ContactManagerService::class)->findOrCreateFromWhatsApp(ltrim($event['sender'], '+'), $channel);
        if ((int) $contact->workspace_id !== (int) $b->workspace_id || (int) $contact->organization_id !== (int) $b->organization_id) throw new BridgeException('contact_ownership_mismatch', 403);
        $conversation = Conversation::where('channel_id', $b->channel_id)->where('workspace_id', $b->workspace_id)
            ->where('organization_id', $b->organization_id)->where('contact_id', $contact->id)->where('external_thread_id', ltrim($event['sender'], '+'))->first();
        $created = !$conversation;
        if (!$conversation) $conversation = Conversation::create(['organization_id' => $b->organization_id, 'workspace_id' => $b->workspace_id,
            'channel_id' => $b->channel_id, 'channel_type' => 'whatsapp', 'external_thread_id' => ltrim($event['sender'], '+'), 'contact_id' => $contact->id,
            'user_identifier' => ltrim($event['sender'], '+'), 'status' => 'active']);
        // A new inbound message may reopen a closed thread, never unpause a human takeover.
        $conversation->update(['last_message_at' => now(), 'status' => $conversation->status === 'closed' ? 'active' : $conversation->status]);
        $message = Message::where('channel_id', $b->channel_id)->where('external_message_id', $event['providerMessageId'])->first();
        if ($message && (int) $message->conversation_id !== (int) $conversation->id) throw new BridgeException('provider_id_conflict', 409);
        if (!$message) {
            $message = new Message();
            $message->forceFill(['conversation_id' => $conversation->id, 'channel_id' => $b->channel_id, 'external_message_id' => $event['providerMessageId'],
            'direction' => 'inbound', 'message_type' => 'text', 'content' => $event['text'], 'status' => 'received', 'sender_type' => 'user', 'is_from_bot' => false,
            'created_at' => Carbon::createFromTimestampUTC($event['timestamp']), 'metadata' => ['aws_bridge' => ['event_id' => $event['eventId'], 'binding_id' => $b->id]]]);
            $message->save();
        }
        $this->store->db->afterCommit(function () use ($created, $message, $conversation, $channel): void {
            try {
                if ($created) InboxBroadcaster::conversationCreated($conversation);
                InboxBroadcaster::messageReceived($message, $conversation, $channel);
            } catch (\Throwable) { /* persisted data can be reloaded even if WS is unavailable */ }
        });
        return ['message_id' => (int) $message->id, 'conversation_id' => (int) $conversation->id];
    }

    public function turn(object $b, object $event): void
    {
        $this->store->binding($b->id);
        $this->authorizeTurn($b, $event);
        $channel = Channel::findOrFail($b->channel_id);
        $conversation = Conversation::where('id', $event->conversation_id)->where('channel_id', $b->channel_id)->where('workspace_id', $b->workspace_id)->where('organization_id', $b->organization_id)->firstOrFail();
        $message = Message::where('id', $event->message_id)->where('conversation_id', $conversation->id)->where('channel_id', $channel->id)->firstOrFail();
        if (config('features.agent_executor_mode') !== 'full') throw new BridgeException('agent_executor_required', 503);
        $automation = app(AutomationService::class);
        if (!$automation->shouldProcessWithAutomation($channel, $conversation)) return;
        if ($channel->processing_type !== 'ai_agent' || !$channel->ai_agent_enabled) throw new BridgeException('agent_channel_required');
        if (!$automation->sendToAIAgent($message, $conversation, $channel, [], 0, 'text')) throw new BridgeException('agent_turn_failed', 503);
    }

    public function authorizeTurn(object $b, object $event): void
    {
        $conversation = Conversation::where('id', $event->conversation_id)->where('channel_id', $b->channel_id)->where('workspace_id', $b->workspace_id)->where('organization_id', $b->organization_id)->lockForUpdate()->firstOrFail();
        if ($conversation->automation_paused || $conversation->status === 'closed'
            || Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->where('sender_type', 'agent')->where('id', '>', $event->message_id)->exists()) {
            throw new BridgeException('conversation_paused', 409);
        }
    }

    public function outgoing(object $b, string $recipient, string $text, ?int $sourceId, ?int $userId): int
    {
        $this->sameConnection();
        $channel = Channel::findOrFail($b->channel_id);
        $contact = Contact::where('workspace_id', $b->workspace_id)->where('organization_id', $b->organization_id)
            ->where(fn ($q) => $q->where('phone', $recipient)->orWhere('external_contact_id', ltrim($recipient, '+')))->firstOrFail();
        $conversation = Conversation::where('channel_id', $b->channel_id)->where('workspace_id', $b->workspace_id)->where('organization_id', $b->organization_id)
            ->where('contact_id', $contact->id)->latest('last_message_at')->firstOrFail();
        if ($sourceId) {
            $source = Message::where('id', $sourceId)->where('channel_id', $channel->id)->where('direction', 'inbound')->firstOrFail();
            if ((int) $source->conversation_id !== (int) $conversation->id) throw new BridgeException('source_ownership_mismatch', 403);
        }
        if ($conversation->status === 'closed' || (!$userId && $conversation->automation_paused)) throw new BridgeException('conversation_paused', 409);
        if ($userId && !$conversation->userCanWrite(\App\Models\User::findOrFail($userId))) throw new BridgeException('write_not_authorized', 403);
        $lastInbound = Message::where('channel_id', $channel->id)->where('conversation_id', $conversation->id)->where('direction', 'inbound')->latest('created_at')->first();
        if (!$lastInbound || Carbon::parse($lastInbound->created_at)->getTimestamp() < $this->store->now() - 86400) throw new BridgeException('customer_window_closed', 409);
        $payload = ['messaging_product' => 'whatsapp', 'type' => 'text', 'to' => ltrim($recipient, '+'), 'text' => ['body' => $text]];
        app(\App\Services\MetaMessagingPolicyService::class)->assertPayloadAllowed($channel, $contact, $payload, $userId);
        if (app(\App\Services\Billing\WhatsAppPlanLimits::class)->blockMessage($channel, $contact, $payload) !== null) throw new BridgeException('plan_limit', 409);
        $message = Message::create(['conversation_id' => $conversation->id, 'channel_id' => $channel->id, 'direction' => 'outbound', 'message_type' => 'text',
            'content' => $text, 'status' => 'pending', 'sender_type' => $userId ? 'agent' : 'bot', 'sender_id' => $userId, 'is_from_bot' => !$userId,
            'metadata' => ['sent_via' => 'aws_engagement_bridge', 'source_message_id' => $sourceId]]);
        $conversation->update(['last_message_at' => now()]);
        $this->store->db->afterCommit(function () use ($message, $conversation, $userId): void {
            try { InboxBroadcaster::messageSent($message, $conversation, $userId); } catch (\Throwable) {}
        });
        return (int) $message->id;
    }

    public function receipt(object $b, object $outbox, array $receipt): void
    {
        $message = Message::where('id', $outbox->message_id)->where('channel_id', $b->channel_id)->lockForUpdate()->firstOrFail();
        $message->update(['external_message_id' => $receipt['providerMessageId'] ?? $message->external_message_id,
            'status' => $receipt['state'] === 'accepted' ? ($message->read_at ? 'read' : ($message->delivered_at ? 'delivered' : 'sent')) : 'failed',
            'sent_at' => $receipt['state'] === 'accepted' ? ($message->sent_at ?? now()) : $message->sent_at,
            'metadata' => array_merge($message->metadata ?? [], ['aws_bridge_state' => $receipt['state'], 'aws_bridge_error' => $receipt['errorCode'] ?? null])]);
    }

    public function authorizeOutgoing(object $b, object $outbox, array $command): void
    {
        $message = Message::where('id', $outbox->message_id)->where('channel_id', $b->channel_id)->firstOrFail();
        $conversation = Conversation::where('id', $message->conversation_id)->where('workspace_id', $b->workspace_id)->where('organization_id', $b->organization_id)->firstOrFail();
        $channel = Channel::findOrFail($b->channel_id);
        if ($conversation->status === 'closed' || ($outbox->source_message_id && ($conversation->automation_paused || $channel->aiRepliesAsDraft()))) throw new BridgeException('conversation_paused', 409);
        if ($outbox->source_message_id) $this->authorizeTurn($b, (object) ['conversation_id' => $conversation->id, 'message_id' => $outbox->source_message_id]);
        if ($message->sender_id && !$conversation->userCanWrite(\App\Models\User::findOrFail($message->sender_id))) throw new BridgeException('write_not_authorized', 403);
        $last = Message::where('conversation_id', $conversation->id)->where('channel_id', $b->channel_id)->where('direction', 'inbound')->latest('created_at')->first();
        if (!$last || Carbon::parse($last->created_at)->getTimestamp() < $this->store->now() - 86400) throw new BridgeException('customer_window_closed', 409);
    }

    public function status(object $b, array $event): void
    {
        // Match only inside this binding. Real AWS/Meta id correlation still needs a live fixture.
        $this->store->db->transaction(function () use ($event, $b): void {
            $row = $this->store->db->table('aws_bridge_outbox')->where('binding_id', $b->id)->where('provider_message_id', $event['providerMessageId'])->lockForUpdate()->first();
            if (!$row) return;
            $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
            $current = $row->delivery_status;
            if ($current && ($rank[$current] ?? 0) >= ($rank[$event['status']] ?? 0)) return;
            if ($event['status'] === 'failed' && in_array($current, ['delivered', 'read'], true)) return;
            $this->store->db->table('aws_bridge_outbox')->where('id', $row->id)->update(['delivery_status' => $event['status']]);
            $message = Message::where('id', $row->message_id)->where('channel_id', $b->channel_id)->lockForUpdate()->firstOrFail();
            $changes = ['status' => $event['status']];
            if ($event['status'] === 'delivered') $changes['delivered_at'] = $message->delivered_at ?? Carbon::createFromTimestampUTC($event['timestamp']);
            if ($event['status'] === 'read') $changes['read_at'] = $message->read_at ?? Carbon::createFromTimestampUTC($event['timestamp']);
            $message->update($changes);
        });
    }

    private function sameConnection(): void
    {
        foreach ([new Message(), new Conversation(), new \App\Models\Ticket(), new \App\Models\TicketAuditLog()] as $model) {
            if ($model->getConnection()->getPdo() !== $this->store->db->getPdo()) throw new BridgeException('atomic_connection_required', 503);
        }
    }
}
