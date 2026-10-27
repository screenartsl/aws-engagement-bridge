<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Immediate drain after an inbound event or a queued reply. One job per event: several workers process different
 * conversations at the same time (Store::claim keeps each conversation in order); a job with nothing left just ends.
 */
final class DrainJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 120;
    public function handle(Drainer $drainer): void { $drainer->run(20); }
}
