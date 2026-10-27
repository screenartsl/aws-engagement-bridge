<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

/** One pass over the durable inbox/outbox: shared by the scheduled drain and the immediate DrainJob. */
final class Drainer
{
    public function __construct(private Store $store, private Worker $worker, private Outbox $outbox, private Publisher $publisher, private Egress $egress) {}

    public function run(int $limit): void
    {
        if (!$this->store->isEnabled()) return;
        for ($i = 0; $i < $limit; $i++) {
            $didWork = $this->worker->once();
            $published = $this->outbox->publishOne(fn ($binding, $command) => $this->publisher->publish($binding, $command),
                fn ($binding, $media) => $this->egress->uploadQueuedMedia($binding, $media));
            if (!$didWork && !$published) break;
        }
        $this->egress->refreshTemplates();
    }

    /**
     * Process new work now instead of waiting for the next scheduled drain (up to 10 s, plus any AI turn ahead of it).
     * Best effort: if the queue is unavailable, the scheduled drain still picks the work up.
     */
    public static function kick(): void
    {
        try { DrainJob::dispatch()->onQueue((string) config('aws_bridge.queue', 'chat-high')); } catch (\Throwable) {}
    }
}
