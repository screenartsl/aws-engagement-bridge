<?php
declare(strict_types=1);
$root=realpath((string)getenv('BRIDGE_STAGE_ROOT'));
if (!$root || !str_starts_with($root,'/tmp/muserelay-bridge-phase3-') || is_file($root.'/core/.env') || count(file('/proc/net/route'))>1) throw new RuntimeException('Private offline source required');
require $root.'/core/vendor/autoload.php';
if (class_exists(MuseRelay\AwsEngagementBridge\Core\CoreIntegrationProvider::class)) throw new RuntimeException('Test requires dependencies without the Composer Bridge package');
$app=new Illuminate\Foundation\Application($root.'/core');
$app->instance('config',new Illuminate\Config\Repository(['extensions'=>require $root.'/core/config/extensions.php']));
$app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$provider=new App\Providers\ExtensionServiceProvider($app);$provider->register();$provider->boot();
if (!class_exists(MuseRelay\AwsEngagementBridge\Core\CoreIntegrationProvider::class) || !class_exists(MuseRelay\AwsEngagementBridge\BridgeSignature::class)) throw new RuntimeException('Module folder autoload failed');
$manager=$app->make(App\Services\Extensions\ExtensionManager::class);
if ($manager->enabled('aws-engagement-bridge') || $app->make(MuseRelay\AwsEngagementBridge\Core\RuntimeSettings::class)->enabled()) throw new RuntimeException('Unconfigured module enabled');
$paths=[];foreach($app['router']->getRoutes() as $route)$paths[]=$route->uri();
if (!in_array('api/aws-engagement-bridge/events',$paths,true) || !in_array('api/aws-engagement-bridge/receipts',$paths,true)) throw new RuntimeException('Installed module routes absent');
echo "PASS Core loads the independent module folder without Composer Bridge installation; both callbacks registered, runtime disabled\n";
