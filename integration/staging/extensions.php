<?php
declare(strict_types=1);
use App\Services\Extensions\ExtensionManager;
use App\Providers\ExtensionServiceProvider;
use App\Http\Controllers\SuperAdmin\ExtensionsController;
use Illuminate\Support\Facades\{DB,Auth,Schema,View};

function extensionCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message);echo 'PASS '.$message,"\n"; }
extensionCheck(DB::connection()->getDatabaseName()==='bridge_phase3_test','extension tests use private PostgreSQL');
$root=getenv('BRIDGE_STAGE_ROOT');
// Synthetic trusted packages exercise registration isolation without any external transport.
final class SyntheticExtensionProvider extends Illuminate\Support\ServiceProvider {
 public function register():void {}
 public function boot():void { $this->app['router']->get('/extension-control-healthy',fn()=>response()->json(['healthy'=>true])); }
}
final class BrokenExtensionProvider extends Illuminate\Support\ServiceProvider {
 public function register():void { throw new RuntimeException('private_exception_must_not_be_logged'); }
 public function boot():void {}
}
$modules=$root.'/core/modules';
foreach (['synthetic-control'=>SyntheticExtensionProvider::class,'broken-control'=>BrokenExtensionProvider::class] as $id=>$provider) {
 $dir=$modules.'/'.$id;if (!is_dir($dir.'/src')) mkdir($dir.'/src',0700,true);
 file_put_contents($dir.'/module.php','<?php return '.var_export(['id'=>$id,'core_api'=>1,'version'=>'1.0.0','provider'=>$provider,'autoload'=>['MuseRelay\\SyntheticControl\\'=>'src']],true).';');
}
$config=require $root.'/core/config/extensions.php';
$config['modules']['synthetic-control']=['name'=>'Synthetic control','path'=>$modules.'/synthetic-control'];
$config['modules']['broken-control']=['name'=>'Broken control','path'=>$modules.'/broken-control'];
$config['modules']['outside-control']=['name'=>'Outside control','path'=>$root.'/core/app'];
$app['config']->set('extensions',$config);
$provider=new ExtensionServiceProvider($app);$provider->register();$provider->boot();
$manager=$app->make(ExtensionManager::class);
extensionCheck($manager->manifest('aws-engagement-bridge')['version']==='0.5.0','installed module manifest and API version resolved');
extensionCheck(!$manager->enabled('aws-engagement-bridge'),'Bridge stays off without any stored opt-in');
extensionCheck($manager->problems('broken-control')===['extension_register_failed'],'bad provider fails closed');
extensionCheck($manager->problems('outside-control')===['extension_register_failed'],'outside module path rejected');
$healthy=Illuminate\Http\Request::create('/extension-control-healthy');$app->instance('request',$healthy);
extensionCheck($app['router']->dispatch($healthy)->getStatusCode()===200,'healthy routes survive another extension failure');
extensionCheck(!str_contains((string)@file_get_contents($root.'/laravel.log'),'private_exception_must_not_be_logged'),'provider failure logs omit exception body');
extensionCheck(in_array('aws_identity_mismatch',$manager->problems('aws-engagement-bridge'),true) && in_array('signing_keys_missing',$manager->problems('aws-engagement-bridge'),true),'AWS local prerequisites inspected without API calls');
try {$manager->update('aws-engagement-bridge',true,[97101],97101);throw new RuntimeException('unexpected enable');}
catch (DomainException $error) { extensionCheck($error->getMessage()==='extension_not_ready','cannot connect an unconfigured AWS module'); }
try {$manager->update('synthetic-control',true,[999999],97101);throw new RuntimeException('unexpected organization');}
catch (DomainException $error) { extensionCheck($error->getMessage()==='invalid_organizations','unknown organization rejected'); }
$manager->update('synthetic-control',true,[97101],97101);
extensionCheck($manager->enabled('synthetic-control') && $manager->organizationIds('synthetic-control')===[97101],'explicit module and organization enablement');
$manager->update('synthetic-control',false,[97101],97101);
extensionCheck(!$manager->enabled('synthetic-control'),'same manager immediately sees disconnect');
// Same Store instance sees SQL state changes; nothing is sent or invoked.
DB::table('platform_settings')->updateOrInsert(['setting_group'=>'extensions','setting_key'=>'aws-engagement-bridge'],['setting_value'=>'{"enabled":true,"organization_ids":[97101]}','is_encrypted'=>false]);
$store=$app->make(MuseRelay\AwsEngagementBridge\Core\Store::class);
extensionCheck($store->isEnabled(),'installed connector receives Core runtime state');
$manager->update('aws-engagement-bridge',false,[97101],97101);
extensionCheck(!$store->isEnabled() && $store->claim()===null,'same connector store sees SQL disconnect without restart');
$count=DB::table('aws_bridge_events')->count();
$app['router']->aliasMiddleware('throttle',Illuminate\Routing\Middleware\ThrottleRequests::class);
$request=Illuminate\Http\Request::create('/api/aws-engagement-bridge/events','POST',[],[],[],[],'{}');$request->headers->set('x-bridge-binding','demo');$app->instance('request',$request);
$disabledResponse=$app['router']->dispatch($request);
echo json_encode(['disabledCallbackStatus'=>$disabledResponse->getStatusCode(),'eventCountUnchanged'=>DB::table('aws_bridge_events')->count()===$count,'storeEnabled'=>$store->isEnabled()]),"\n";
extensionCheck($disabledResponse->getStatusCode()===503 && DB::table('aws_bridge_events')->count()===$count,'disconnected installed callback persists no events');
$messageCount=DB::table('messages')->count();
$send=app(App\Services\WhatsAppSenderService::class)->sendTextMessage(App\Models\Channel::findOrFail(20),App\Models\Contact::firstOrFail(),'Synthetic disabled reply');
extensionCheck(($send['error']??'')==='aws_bridge_disconnected' && DB::table('messages')->count()===$messageCount,'Core sender rejects disconnected extension before package egress');
$manager->update('broken-control',false,[],97101);
extensionCheck(!$manager->enabled('broken-control'),'disconnect works even when a package is broken');
// Actual controller, auth middleware and password rule; synthetic users only.
if (!Schema::hasColumn('users','role')) Schema::table('users',fn($t)=>$t->string('role')->nullable());
$password='synthetic-control-passphrase';
DB::table('users')->where('id',97101)->update(['role'=>'superadmin','password'=>app('hash')->make($password)]);
$user=App\Models\User::findOrFail(97101);
$router=$app['router'];$router->aliasMiddleware('auth',Illuminate\Auth\Middleware\Authenticate::class);$router->aliasMiddleware('superadmin',App\Http\Middleware\EnsureSuperAdmin::class);
$router->get('/login',fn()=>response('login'))->name('login');
$router->get('/megadmin/extensions',[ExtensionsController::class,'index'])->middleware(['auth','superadmin'])->name('superadmin.extensions.index');
$router->put('/megadmin/extensions/{extension}',[ExtensionsController::class,'update'])->middleware(['auth','superadmin'])->name('superadmin.extensions.update');
$router->getRoutes()->refreshNameLookups();
function extensionRequest($app,string $method,string $path,array $data=[]): Illuminate\Http\Request {
 $r=Illuminate\Http\Request::create($path,$method,$data);$r->setUserResolver(fn()=>Auth::user());$r->headers->set('accept','application/json');$r->headers->set('referer','https://bridge-stage.invalid/megadmin/extensions');$app->instance('request',$r);return $r;
}
Auth::logout();
$guest=$router->dispatch(extensionRequest($app,'GET','/megadmin/extensions'));
extensionCheck($guest->getStatusCode()===401,'guest cannot access extension control');
$user->role='owner';Auth::setUser($user);
$owner=$router->dispatch(extensionRequest($app,'GET','/megadmin/extensions'));
extensionCheck($owner->getStatusCode()===403,'reseller/customer role cannot control extensions');
$user->role='superadmin';Auth::setUser($user);
$wrong=$router->dispatch(extensionRequest($app,'PUT','/megadmin/extensions/synthetic-control',['enabled'=>1,'organization_ids'=>[97101],'password'=>'wrong-synthetic-passphrase']));
extensionCheck($wrong->getStatusCode()===422 && isset(json_decode($wrong->getContent(),true)['errors']['password']),'admin password confirmation enforced');
$response=$router->dispatch(extensionRequest($app,'PUT','/megadmin/extensions/synthetic-control',['enabled'=>1,'organization_ids'=>[97101],'password'=>$password]));
extensionCheck($response->isRedirect() && $manager->enabled('synthetic-control'),'authenticated admin can connect a configured extension');
$router->dispatch(extensionRequest($app,'PUT','/megadmin/extensions/synthetic-control',['enabled'=>0,'organization_ids'=>[97101],'password'=>$password]));
extensionCheck(!$manager->enabled('synthetic-control'),'authenticated admin can disconnect');
// Render actual page body in both locales, using a minimal layout to avoid unrelated SaaS fixture tables.
$views=$root.'/extension-views';if (!is_dir($views.'/superadmin/layouts')) mkdir($views.'/superadmin/layouts',0700,true);file_put_contents($views.'/superadmin/layouts/layout.blade.php','<!doctype html><html><body>@yield("content")</body></html>');
$app['view']->getFinder()->setPaths([$views,$root.'/core/resources/views']);
View::share('errors',new Illuminate\Support\ViewErrorBag());
foreach (['es','en'] as $locale) {
 app('translator')->setLocale($locale);
 $page=(new ExtensionsController)->index($manager);
 $html=$page->render();
 extensionCheck(str_contains($html,__('megadmin_extensions.title')) && str_contains($html,'name="password"') && str_contains($html,'name="_token"') && str_contains($html,'name="organization_ids[]"'),'extension page renders protected controls in '.$locale);
 extensionCheck(!str_contains($html,$password) && !str_contains($html,'private_exception_must_not_be_logged'),'extension page omits secrets in '.$locale);
}
// No customer-facing module status was edited.
extensionCheck(DB::table('platform_settings')->where('setting_group','modules')->count()===0,'existing panel module settings untouched');
$app['config']->set('extensions.enabled',false);
extensionCheck(!$manager->enabled('synthetic-control'),'server emergency flag fails closed');
echo "Extension controls complete; AWS stayed unconfigured/disconnected\n";
