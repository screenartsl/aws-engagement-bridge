<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

final class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/aws_bridge.php', 'aws_bridge');
        $this->app->bindIf(RuntimeSettings::class,fn($app)=>new ConfigRuntimeSettings($app['config']));
        $this->app->scoped(Store::class, fn ($app) => new Store($app['db']->connection(), $app['encrypter'],
            fn()=>$app->make(RuntimeSettings::class)->enabled(),fn()=>$app->make(RuntimeSettings::class)->organizations()));
        $this->app->scoped(Scope::class, fn () => new Scope());
        $this->app->bind(CorePort::class, NativeCore::class);
        $this->app->bind(SigningKeys::class, fn ($app) => new SigningKeys((string) $app['config']->get('aws_bridge.signing_keys_file', '')));
        $this->app->bind(KeySource::class, fn ($app) => $app->make(SigningKeys::class));
        $this->app->bind(Publisher::class, fn ($app) => new Publisher((string) $app['config']->get('aws_bridge.outbound_url', ''), $app->make(SigningKeys::class)));
    }
    public function boot(): void
    {
        if ($this->app->runningInConsole()) $this->commands([DrainCommand::class]);
        // Migrations are deliberately not auto-registered: apply the reviewed path only after authorization.
        // Installed routes remain registered: runtime guards enforce disconnect even in long-lived workers.
        if ($this->app->routesAreCached()) return;
        Route::prefix('api/aws-engagement-bridge')->middleware('throttle:30,1')->group(function (): void {
            Route::post('events', [Controller::class, 'events']);
            Route::post('receipts', [Controller::class, 'receipts']);
        });
    }
}
