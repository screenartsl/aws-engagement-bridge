<?php
declare(strict_types=1);
// No production bootstrap, Dotenv, cached config, routes or auto-discovered third-party providers.
$core='/srv/muserelay-aws-stage/core';
if (realpath($core)!==$core || !is_file('/srv/muserelay-aws-stage/.isolated-bridge-stage')) throw new RuntimeException('Dedicated source archive required');
require $core.'/vendor/autoload.php';
require __DIR__.'/Runtime.php';
BridgeLiveConfig::load();
$app=new Illuminate\Foundation\Application($core);
$app->instance('request',Illuminate\Http\Request::create('https://aws-bridge-staging.muserelay.com'));
$app->useStoragePath(BridgeLiveConfig::DATA.'/storage');
$app->useEnvironmentPath(BridgeLiveConfig::ROOT.'/no-dotenv');
$app->singleton(Illuminate\Contracts\Console\Kernel::class,Illuminate\Foundation\Console\Kernel::class);
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class,Illuminate\Foundation\Exceptions\Handler::class);
$app->bootstrapWith([BridgeLiveConfiguration::class,Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    Illuminate\Foundation\Bootstrap\RegisterProviders::class,Illuminate\Foundation\Bootstrap\BootProviders::class]);
$app['router']->aliasMiddleware('throttle',Illuminate\Routing\Middleware\ThrottleRequests::class);
$app->scoped(App\Services\LLM\AgentLLMFactory::class,BridgeLiveModelFactory::class);
$app->bind(App\Services\AIAgentUsageTracker::class,BridgeLiveUsage::class);
$app['router']->get('/healthz',fn()=>response()->json(['service'=>'aws-bridge-stage','ready'=>true]));
// Native ticket tool generates its normal reference URL. This technical endpoint exposes no ticket UI.
$app['router']->get('/demo-ticket/{token}',fn()=>abort(404))->name('public.ticket.show');
$app['router']->getRoutes()->refreshNameLookups();
Illuminate\Support\Facades\Http::globalRequestMiddleware(function ($request) {
    if ((string)$request->getUri()!==BridgeLiveConfig::$values['outboundUrl'] || $request->getMethod()!=='POST') throw new RuntimeException('Non-demo HTTP destination denied');
    return $request;
});
return $app;

final class BridgeLiveConfiguration
{
    public function bootstrap($app): void
    {
        $c=BridgeLiveConfig::$values;$config=[];
        $app->instance('config',new Illuminate\Config\Repository());
        foreach (glob(BridgeLiveConfig::ROOT.'/core/config/*.php') as $file) { $name=basename($file,'.php');$config[$name]=require $file;$app['config']->set($name,$config[$name]); }
        $config['app']['env']='bridge-staging';$config['app']['debug']=false;$config['app']['key']=$c['appKey'];$config['app']['url']='https://'.$c['coreHostname'];
        $config['app']['providers']=Illuminate\Support\ServiceProvider::defaultProviders()->merge([App\Providers\AppServiceProvider::class,App\Providers\AIToolsServiceProvider::class,
            MuseRelay\AwsEngagementBridge\Core\BridgeServiceProvider::class])->toArray();
        $config['messages_crypto']=['key'=>$c['messagesKey'],'index_key'=>$c['indexKey']];
        $pg=['driver'=>'pgsql','host'=>BridgeLiveConfig::RUN.'/pgsock','port'=>55434,'database'=>'bridge_phase4_test','username'=>'muserelay-aws-stage',
            'password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable'];
        $redis=['host'=>BridgeLiveConfig::RUN.'/redis.sock','port'=>0,'database'=>0,'password'=>null];
        $config['database']=['default'=>'pgsql','connections'=>['pgsql'=>$pg,'mysql'=>$pg],'migrations'=>'migrations',
            'redis'=>['client'=>'phpredis','options'=>['prefix'=>'bridge_phase4:'],'default'=>$redis,'cache'=>$redis]];
        $config['cache']=['default'=>'redis','prefix'=>'bridge_phase4_cache:','stores'=>['redis'=>['driver'=>'redis','connection'=>'cache','lock_connection'=>'default'],'array'=>['driver'=>'array']]];
        $config['queue']=['default'=>'redis','connections'=>['redis'=>['driver'=>'redis','connection'=>'default','queue'=>'bridge_phase4','retry_after'=>600,'block_for'=>2,'after_commit'=>true]],'failed'=>['driver'=>'null']];
        $config['logging']=['default'=>'null','channels'=>['null'=>['driver'=>'monolog','handler'=>Monolog\Handler\NullHandler::class]]];
        $config['mail']=['default'=>'array','mailers'=>['array'=>['transport'=>'array']]];
        $config['broadcasting']=['default'=>'null','connections'=>['null'=>['driver'=>'null']]];$config['session']['driver']='array';
        $config['filesystems']=['default'=>'local','disks'=>['local'=>['driver'=>'local','root'=>BridgeLiveConfig::DATA.'/storage/app']]];
        $config['aws_bridge']=['enabled'=>($c['enabled'] ?? false)===true,'organization_ids'=>[97101],
            'receive_enabled'=>($c['receiveEnabled'] ?? false)===true,'agent_enabled'=>($c['agentEnabled'] ?? false)===true,'send_enabled'=>($c['sendEnabled'] ?? false)===true,
            'outbound_url'=>$c['outboundUrl'],'signing_keys_file'=>'/etc/muserelay/aws-bridge/signing-stage.json'];
        $config['features']['agent_executor_mode']='full';$config['features']['tool_escalation']['enabled']=false;
        $app->instance('config',new Illuminate\Config\Repository($config));$app->detectEnvironment(fn()=>'bridge-staging');
    }
}
