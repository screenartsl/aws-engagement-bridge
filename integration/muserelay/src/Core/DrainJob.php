<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Immediate drain after an inbound event or a queued reply. Unique while waiting: a burst of events queues one job. */
final class DrainJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 120;
    public int $uniqueFor = 60;

    public function uniqueId(): string { return 'aws-bridge-drain'; }

    public function handle(Drainer $drainer): void { $drainer->run(20); }
}
