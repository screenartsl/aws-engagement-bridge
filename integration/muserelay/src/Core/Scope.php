<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class Scope
{
    public ?object $event = null;
    public ?object $binding = null;
    public function clear(): void { $this->event = null; $this->binding = null; }
}
