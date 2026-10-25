<?php
declare(strict_types=1);

// This harness never loads the production bootstrap, Dotenv, routes or cached configuration.
$stage = realpath((string) getenv('BRIDGE_STAGE_ROOT'));
if (!$stage || !str_starts_with($stage, '/tmp/muserelay-bridge-phase3-') || !is_file($stage.'/.bridge-staging') || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Explicit disposable bridge staging root required');
}
if (!is_file($stage.'/host-netns') || trim(file_get_contents($stage.'/host-netns')) === readlink('/proc/self/ns/net')) throw new RuntimeException('A separate Linux network namespace is required');
if (!is_file('/proc/net/route') || count(file('/proc/net/route')) > 1) throw new RuntimeException('Run inside an empty network namespace');
$core = $stage.'/core';
require $core.'/vendor/autoload.php';
require __DIR__.'/Simulation.php';
$app = new Illuminate\Foundation\Application($core);
$app->instance('request', Illuminate\Http\Request::create('https://bridge-stage.invalid'));
$app->useStoragePath($stage.'/storage');
$app->useEnvironmentPath($stage.'/no-dotenv');
$app->singleton(Illuminate\Contracts\Console\Kernel::class, Illuminate\Foundation\Console\Kernel::class);
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, Illuminate\Foundation\Exceptions\Handler::class);
$app->bootstrapWith([BridgeStageConfiguration::class, Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    Illuminate\Foundation\Bootstrap\RegisterProviders::class, Illuminate\Foundation\Bootstrap\BootProviders::class]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($request) use ($stage) {
    if ($request->url() !== 'https://synthetic.execute-api.eu-west-1.amazonaws.com/outbound') throw new RuntimeException('Unsimulated HTTP denied');
    $command = json_decode($request->body(), true, 32, JSON_THROW_ON_ERROR);
    MuseRelay\AwsEngagementBridge\BridgeSignature::verify(BridgeStageKeys::OUTBOUND, 'POST', '/outbound', $request->body(), [
        'timestamp' => $request->header('x-bridge-timestamp')[0], 'nonce' => $request->header('x-bridge-nonce')[0], 'signature' => $request->header('x-bridge-signature')[0],
    ]);
    file_put_contents($stage.'/outbound.ndjson', json_encode(['command' => $command, 'body' => $request->body(), 'headers' => [
        'x-bridge-binding' => $request->header('x-bridge-binding')[0], 'x-bridge-timestamp' => $request->header('x-bridge-timestamp')[0],
        'x-bridge-nonce' => $request->header('x-bridge-nonce')[0], 'x-bridge-signature' => $request->header('x-bridge-signature')[0],
    ]])."\n", FILE_APPEND | LOCK_EX);
    return Illuminate\Support\Facades\Http::response(['id' => $command['commandId'], 'status' => 'accepted'], 202);
});
$app->bind(MuseRelay\AwsEngagementBridge\Core\KeySource::class, BridgeStageKeys::class);
// Publisher normally resolves SigningKeys directly; stage injects synthetic in-memory keys only.
$app->bind(MuseRelay\AwsEngagementBridge\Core\Publisher::class, fn () => new MuseRelay\AwsEngagementBridge\Core\Publisher(
    'https://synthetic.execute-api.eu-west-1.amazonaws.com/outbound', new BridgeStageKeys()));
$app->scoped(App\Services\LLM\AgentLLMFactory::class, BridgeStageModelFactory::class);
$app->bind(App\Services\AIAgentUsageTracker::class, BridgeStageUsage::class);
$app['router']->get('/demo-ticket/{token}', fn () => 'synthetic')->name('public.ticket.show');
$app['router']->getRoutes()->refreshNameLookups();
return $app;

final class BridgeStageConfiguration
{
    public function bootstrap($app): void
    {
        $stage = getenv('BRIDGE_STAGE_ROOT');
        $config = [];
        $app->instance('config', new Illuminate\Config\Repository());
        foreach (glob($stage.'/core/config/*.php') as $file) {
            $name = basename($file, '.php'); $config[$name] = require $file; $app['config']->set($name, $config[$name]);
        }
        $config['app']['env'] = 'testing';
        $config['app']['key'] = 'base64:'.base64_encode(str_repeat('S', 32));
        $config['app']['debug'] = false;
        $config['messages_crypto'] = ['key' => 'base64:'.base64_encode(str_repeat('M',32)), 'index_key' => 'base64:'.base64_encode(str_repeat('I',32))];
        $config['app']['url'] = 'https://bridge-stage.invalid';
        $config['app']['providers'] = Illuminate\Support\ServiceProvider::defaultProviders()->merge([
            App\Providers\AppServiceProvider::class, App\Providers\AIToolsServiceProvider::class,
            MuseRelay\AwsEngagementBridge\Core\BridgeServiceProvider::class,
        ])->toArray();
        $pg = ['driver' => 'pgsql', 'host' => $stage.'/pgsock', 'port' => 55432, 'database' => 'bridge_phase3_test',
            'username' => 'bridge_stage', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable'];
        $redis = ['host' => $stage.'/redis.sock', 'port' => 0, 'database' => 0, 'password' => null];
        $config['database'] = ['default' => 'pgsql', 'connections' => ['pgsql' => $pg, 'mysql' => $pg], 'migrations' => 'migrations',
            'redis' => ['client' => 'phpredis', 'options' => ['prefix' => 'bridge_stage:'], 'default' => $redis, 'cache' => $redis]];
        $config['cache'] = ['default' => 'redis', 'prefix' => 'bridge_stage_cache:', 'stores' => [
            'redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'], 'array' => ['driver' => 'array']]];
        $config['queue'] = ['default' => 'redis', 'connections' => ['redis' => ['driver' => 'redis', 'connection' => 'default',
            'queue' => 'bridge_stage', 'retry_after' => 90, 'block_for' => null, 'after_commit' => true]], 'failed' => ['driver' => 'null']];
        $config['logging'] = ['default' => 'stage', 'channels' => ['stage' => ['driver' => 'single', 'path' => $stage.'/laravel.log', 'level' => 'debug']]];
        $config['mail']['default'] = 'array';
        $config['mail']['mailers'] = ['array' => ['transport' => 'array']];
        $config['broadcasting'] = ['default' => 'null', 'connections' => ['null' => ['driver' => 'null']]];
        $config['session']['driver'] = 'array';
        $config['filesystems']['default'] = 'local';
        $config['filesystems']['disks'] = ['local' => ['driver' => 'local', 'root' => $stage.'/storage/app']];
        // Synthetic regression suite explicitly opts in; production defaults remain false.
        $config['aws_bridge'] = ['enabled' => getenv('BRIDGE_STAGE_DISABLED') !== '1', 'organization_ids' => [97101],
            'receive_enabled'=>true,'agent_enabled'=>true,'send_enabled'=>true,
            'outbound_url' => 'https://synthetic.execute-api.eu-west-1.amazonaws.com/outbound'];
        $config['features']['agent_executor_mode'] = 'full';
        $config['features']['tool_escalation']['enabled'] = false;
        $app->instance('config', new Illuminate\Config\Repository($config));
        $app->detectEnvironment(fn () => 'testing');
    }
}
