<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Console\Command;

final class DrainCommand extends Command
{
    protected $signature = 'aws-bridge:drain {--limit=10 : Maximum inbound and outbound attempts per invocation}';
    protected $description = 'Process the isolated AWS Bridge durable inbox/outbox (opt-in only)';
    public function handle(Store $store, Worker $worker, Outbox $outbox, Publisher $publisher, Egress $egress): int
    {
        if (!$store->isEnabled()) { $this->line('AWS Bridge is disabled.'); return self::SUCCESS; }
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 100) { $this->error('Limit must be between 1 and 100.'); return self::FAILURE; }
        for ($i = 0; $i < $limit; $i++) {
            $didWork = $worker->once();
            $published = $outbox->publishOne(fn ($binding, $command) => $publisher->publish($binding, $command),
                fn ($binding, $media) => $egress->uploadQueuedMedia($binding, $media));
            if (!$didWork && !$published) break;
        }
        $egress->refreshTemplates();
        return self::SUCCESS;
    }
}
