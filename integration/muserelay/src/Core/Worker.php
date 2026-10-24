<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class Worker
{
    public function __construct(private Store $store, private CorePort $core, private Scope $scope) {}
    public function once(): bool
    {
        $row = $this->store->claim();
        if (!$row) return false;
        try {
            $binding = $this->store->binding($row->binding_id);
            $event = $this->store->decode($row->payload);
            $this->scope->event = $row; $this->scope->binding = $binding;
            if ($event['kind'] === 'status') $this->core->status($binding, $event);
            else {
                $row = $this->store->materialize($row, fn () => $this->core->incoming($binding, $event));
                $this->scope->event = $row;
                $replyExists = $this->store->db->table('aws_bridge_outbox')->where('binding_id', $binding->id)->where('source_message_id', $row->message_id)->exists();
                if (!$replyExists) $this->core->turn($binding, $row);
            }
            $this->store->finish($row, true);
        } catch (\Throwable $error) {
            // No customer data, SQL, provider bodies or secrets are logged here.
            $this->store->finish($row, $error instanceof BridgeException && $error->reason === 'conversation_paused');
        } finally { $this->scope->clear(); }
        return true;
    }
}
