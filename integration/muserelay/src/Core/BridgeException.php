<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class BridgeException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 422)
    {
        parent::__construct($reason);
    }
}
