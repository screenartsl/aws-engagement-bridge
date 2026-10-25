<?php
declare(strict_types=1);
require_once __DIR__.'/Paths.php';
$c=BridgePanelPaths::load();$core=BridgePanelPaths::$root.'/core';
require $core.'/vendor/autoload.php';
require_once __DIR__.'/Runtime.php';
spl_autoload_register(function(string $class): void {
    $prefix='MuseRelay\\AwsEngagementBridge\\Core\\';
    if (str_starts_with($class,$prefix)) {
        $file=dirname(__DIR__).'/muserelay/src/Core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file;
    } elseif ($class==='MuseRelay\\AwsEngagementBridge\\BridgeSignature') require dirname(__DIR__).'/muserelay/BridgeSignature.php';
},true,true);
$app=new Illuminate\Foundation\Application($core);
$app->useStoragePath(BridgePanelPaths::$data.'/storage');$app->useEnvironmentPath(BridgePanelPaths::$root.'/no-dotenv');
$app->instance('request',Illuminate\Http\Request::create('https://aws-bridge-staging.muserelay.com'));
$app->singleton(Illuminate\Contracts\Http\Kernel::class,BridgePanelKernel::class);
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class,BridgePanelExceptionHandler::class);
$app->bootstrapWith([BridgePanelConfiguration::class,Illuminate\Foundation\Bootstrap\RegisterFacades::class,Illuminate\Foundation\Bootstrap\RegisterProviders::class,Illuminate\Foundation\Bootstrap\BootProviders::class]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(fn()=>throw new RuntimeException('HTTP is disabled in receive-only panel staging'));
// Even an incorrectly enabled agent fails before a provider/credential is resolved.
$app->bind(App\Services\LLM\AgentLLMFactory::class,fn()=>throw new RuntimeException('Inference not installed in receive-only staging'));
$db=$app['db']->connection();$identity=$db->selectOne('select current_database() db, current_user usr');
if ($identity->db!==$c['database'] || $identity->usr!==$c['databaseUser'])throw new RuntimeException('Isolated database identity mismatch');
require __DIR__.'/routes.php';
return $app;
