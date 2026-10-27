<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Console\Command;

final class DrainCommand extends Command
{
    protected $signature = 'aws-bridge:drain {--limit=10 : Maximum inbound and outbound attempts per invocation}';
    protected $description = 'Process the isolated AWS Bridge durable inbox/outbox (opt-in only)';
    public function handle(Store $store, Drainer $drainer): int
    {
        if (!$store->isEnabled()) { $this->line('AWS Bridge is disabled.'); return self::SUCCESS; }
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 100) { $this->error('Limit must be between 1 and 100.'); return self::FAILURE; }
        $drainer->run($limit);
        return self::SUCCESS;
    }
}
