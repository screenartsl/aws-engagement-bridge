<?php
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,DB,Hash,RateLimiter,Route,URL};

// Load committed native names for URL generation, then expose only the inbox read surface.
require BridgePanelPaths::$root.'/core/routes/tenant.php'; // Native URL names used by the shared sidebar; never dispatched below.
require BridgePanelPaths::$root.'/core/routes/central.php';
$router=$app['router'];$native=$router->getRoutes();$native->refreshNameLookups();
$allowed=['session.status','conversations.index','conversations.channel','conversations.api.index','conversations.api.show',
    'conversations.api.channel-badges','conversations.api.load-previous-messages','conversations.api.search-messages',
    'conversations.api.available-agents','messages.api.new','messages.api.mark-read','messages.api.user-activity'];
$safe=new Illuminate\Routing\RouteCollection();
foreach($native as $route) {
    if(str_starts_with($route->uri(),'api/aws-engagement-bridge/')){$safe->add($route);continue;}
    if(!in_array($route->getName(),$allowed,true))continue;
    $clone=clone $route;$action=$clone->getAction();$action['middleware']=['web'];$clone->setAction($action);$safe->add($clone);
}
$router->setRoutes($safe);
$app['view']->addNamespace('bridgepanel',__DIR__.'/views');
Route::middleware('web')->group(function():void {
    Route::get('/login',fn()=>view('bridgepanel::login'))->name('login');
    Route::post('/login',function(Illuminate\Http\Request $request) {
        $key='bridge-panel-login:'.hash('sha256',(string)$request->ip());
        if(RateLimiter::tooManyAttempts($key,5))return response()->json(['error'=>'retry_later'],429);
        RateLimiter::hit($key,300);
        $u=App\Models\User::where('id',97101)->where('organization_id',97101)->where('email','evaluator@example.invalid')->first();
        if(!$u || !Hash::check((string)$request->input('password',''),$u->password))return response()->json(['error'=>'invalid_credentials'],422);
        Auth::login($u);$request->session()->regenerate();RateLimiter::clear($key);
        return redirect('/conversations/channel/20');
    })->name('bridge.stage.login');
    Route::post('/logout',function(Illuminate\Http\Request $request){Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect('/login');})->name('logout');
});
$safe->refreshNameLookups();
// The URL generator may know native menu links; those routes remain unreachable (404) in this panel.
foreach($safe as $route)if(in_array($route->uri(),['login','logout'],true))$native->add($route);
$native->refreshNameLookups();$app['url']->setRoutes($native);URL::forceRootUrl('https://'.BridgePanelPaths::$config['hostname']);URL::forceScheme('https');
