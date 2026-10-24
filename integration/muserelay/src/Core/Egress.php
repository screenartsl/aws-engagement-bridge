<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class Egress
{
    public function __construct(private Store $store, private Outbox $outbox) {}
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
}
