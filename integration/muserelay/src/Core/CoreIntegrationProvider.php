<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

/** Loaded by the Core's trusted extension registry; staging can still use BridgeServiceProvider alone. */
final class CoreIntegrationProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        (new BridgeServiceProvider($this->app))->register();
        $this->app->bind(RuntimeSettings::class,fn($app)=>new class($app) implements RuntimeSettings {
            public function __construct(private $app) {}
            public function enabled(): bool { return $this->app->make(\App\Services\Extensions\ExtensionManager::class)->enabled('aws-engagement-bridge'); }
            public function organizations(): array { return $this->app->make(\App\Services\Extensions\ExtensionManager::class)->organizationIds('aws-engagement-bridge'); }
        });
    }
    public function boot(): void { (new BridgeServiceProvider($this->app))->boot(); }
}
