<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

interface RuntimeSettings
{
    public function enabled(): bool;
    /** @return list<int> */
    public function organizations(): array;
}
