<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

interface CorePort
{
    public function incoming(object $binding, array $event): array;
    public function turn(object $binding, object $event): void;
    public function authorizeTurn(object $binding, object $event): void;
    public function outgoing(object $binding, string $recipient, string $text, ?int $sourceId, ?int $userId): int;
    public function authorizeOutgoing(object $binding, object $outbox, array $command): void;
    public function receipt(object $binding, object $outbox, array $receipt): void;
    public function status(object $binding, array $event): void;
}
