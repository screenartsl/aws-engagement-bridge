<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

/**
 * Confirmation of a business operation (appointment, ticket…) by email (Amazon SES) or SMS (AWS End User Messaging SMS),
 * sent through the binding of the AWS WhatsApp channel the customer used. Independent of the WhatsApp outbox.
 *
 * Only to customers who chose that channel themselves (contacts.preferred_reminder_channel, recorded by the agent's
 * calendar_record_notification_preference tool). One send per operation, kind and recipient: repeating returns the first result.
 */
final class Notifier
{
    private const REFERENCES = ['appointment', 'ticket', 'operation'];

    public function __construct(private Store $store, private Publisher $publisher) {}

    /** @return array{success: bool, state?: string, id?: string, error?: string|null, duplicate?: bool} */
    public function send(object $channel, object $contact, string $kind, ?string $subject, string $text, string $referenceType, string $referenceId, ?int $userId = null): array
    {
        try {
            $this->store->requireOperation('notify');
            $binding = $this->store->bindingForChannel((int) $channel->id);
            if ((int) $contact->workspace_id !== (int) $binding->workspace_id || (int) $contact->organization_id !== (int) $binding->organization_id) throw new BridgeException('contact_ownership_mismatch', 403);
            if (!in_array($kind, ['email', 'sms'], true)) throw new BridgeException('invalid_kind', 422);
            if (!in_array($referenceType, self::REFERENCES, true) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $referenceId)) throw new BridgeException('invalid_reference', 422);
            // Consent: the customer picked this channel. A different or missing choice (including 'none') never sends.
            if (($contact->preferred_reminder_channel ?? null) !== $kind) throw new BridgeException('no_consent', 403);
            $to = $kind === 'email' ? strtolower(trim((string) $contact->email)) : '+'.preg_replace('/\D+/', '', (string) $contact->phone);
            if ($kind === 'email' ? !filter_var($to, FILTER_VALIDATE_EMAIL) : !preg_match('/^\+[1-9][0-9]{6,14}$/D', $to)) throw new BridgeException('no_recipient', 422);
            if (trim($text) === '' || ($kind === 'sms' && mb_strlen($text) > 600)) throw new BridgeException('invalid_text', 422);
            if ($kind === 'email' && (!is_string($subject) || trim($subject) === '' || mb_strlen($subject) > 200 || preg_match('/[\r\n]/', $subject))) throw new BridgeException('invalid_subject', 422);

            $id = hash('sha256', implode('|', [$binding->id, $kind, $referenceType, $referenceId, $to]));
            $db = $this->store->db;
            $row = $db->table('aws_bridge_notifications')->where('id', $id)->first();
            if ($row && $row->state !== 'pending') {
                return ['success' => $row->state === 'accepted', 'state' => $row->state, 'id' => $id, 'error' => $row->error_code, 'duplicate' => true];
            }
            $now = time();
            if (!$row) $db->table('aws_bridge_notifications')->insertOrIgnore(['id' => $id, 'binding_id' => $binding->id, 'organization_id' => $binding->organization_id,
                'workspace_id' => $binding->workspace_id, 'contact_id' => $contact->id ?? null, 'user_id' => $userId, 'kind' => $kind,
                'recipient_hash' => hash('sha256', $to), 'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'state' => 'pending', 'created_epoch' => $now, 'updated_epoch' => $now]);

            $command = ['version' => 1, 'notifyId' => $id, 'bindingId' => $binding->id, 'kind' => $kind, 'to' => $to, 'text' => $text,
                'reference' => ['type' => $referenceType, 'id' => $referenceId]] + ($kind === 'email' ? ['subject' => $subject] : []);
            // A transport failure leaves the row pending: retrying reuses the same notifyId and AWS never sends twice.
            $result = $this->publisher->notify($binding->id, $command);
            $state = $result['state'];
            $db->table('aws_bridge_notifications')->where('id', $id)->where('state', 'pending')->update(['state' => $state,
                'provider_message_id' => $result['providerMessageId'] ?? null, 'error_code' => $result['errorCode'] ?? null, 'updated_epoch' => time()]);
            return ['success' => $state === 'accepted', 'state' => $state, 'id' => $id, 'error' => $result['errorCode'] ?? null];
        } catch (BridgeException $e) { return ['success' => false, 'error' => $e->reason]; }
        catch (\Throwable) { return ['success' => false, 'error' => 'bridge_unavailable']; }
    }
}
