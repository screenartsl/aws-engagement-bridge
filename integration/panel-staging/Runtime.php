<?php
declare(strict_types=1);

final class BridgePanelConfiguration
{
    public function bootstrap($app): void
    {
        $c=BridgePanelPaths::$config;$root=BridgePanelPaths::$root;$config=[];
        $app->instance('config',new Illuminate\Config\Repository());
        foreach(glob($root.'/core/config/*.php') as $file){$name=basename($file,'.php');$config[$name]=require $file;$app['config']->set($name,$config[$name]);}
        $config['app']['env']=getenv('APP_ENV');$config['app']['debug']=false;$config['app']['key']=$c['appKey'];$config['app']['url']=$c['hostname'];$config['app']['locale']='es';
        $config['app']['providers']=Illuminate\Support\ServiceProvider::defaultProviders()->merge([App\Providers\AppServiceProvider::class,MuseRelay\AwsEngagementBridge\Core\BridgeServiceProvider::class])->toArray();
        $config['messages_crypto']=['key'=>$c['messagesKey'],'index_key'=>$c['indexKey']];
        $pg=['driver'=>'pgsql','host'=>$c['socket'],'port'=>$c['port'],'database'=>$c['database'],'username'=>$c['databaseUser'],'password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable'];
        $redis=['host'=>getenv('APP_ENV')==='testing'?$root.'/redis.sock':'/run/muserelay-aws-stage/redis.sock','port'=>0,'database'=>0,'password'=>null];
        $config['database']=['default'=>'pgsql','connections'=>['pgsql'=>$pg,'mysql'=>$pg],'redis'=>['client'=>'phpredis','options'=>['prefix'=>'bridge_panel:'],'default'=>$redis,'cache'=>$redis]];
        $config['cache']=['default'=>'redis','prefix'=>'bridge_panel_cache:','stores'=>['redis'=>['driver'=>'redis','connection'=>'cache','lock_connection'=>'default'],'array'=>['driver'=>'array']]];
        $config['queue']=['default'=>'redis','connections'=>['redis'=>['driver'=>'redis','connection'=>'default','queue'=>'bridge_panel','retry_after'=>600,'after_commit'=>true]],'failed'=>['driver'=>'null']];
        $config['session']=array_merge($config['session'],['driver'=>'file','files'=>BridgePanelPaths::$data.'/storage/framework/sessions','cookie'=>'muserelay_bridge_stage','domain'=>$c['hostname'],'secure'=>true,'http_only'=>true,'same_site'=>'strict','lifetime'=>30]);
        $config['view']['compiled']=BridgePanelPaths::$data.'/storage/framework/views';
        $config['logging']=['default'=>'null','channels'=>['null'=>['driver'=>'monolog','handler'=>Monolog\Handler\NullHandler::class]]];
        $config['mail']=['default'=>'array','mailers'=>['array'=>['transport'=>'array']]];$config['broadcasting']=['default'=>'null','connections'=>['null'=>['driver'=>'null']]];
        $config['filesystems']=['default'=>'local','disks'=>['local'=>['driver'=>'local','root'=>BridgePanelPaths::$data.'/storage/app']]];
        $config['tenancy']['central_domains']=[$c['hostname']];$config['services']=[];$config['features']['agent_executor_mode']='full';
        $config['aws_bridge']=['enabled'=>($c['enabled']??false)===true,'receive_enabled'=>($c['receiveEnabled']??false)===true,'agent_enabled'=>false,'send_enabled'=>false,'organization_ids'=>($c['enabled']??false)===true?[97101]:[],
            'signing_keys_file'=>'/etc/muserelay/aws-bridge/signing-stage.json','outbound_url'=>''];
        $app->instance('config',new Illuminate\Config\Repository($config));$app->detectEnvironment(fn()=>getenv('APP_ENV'));
    }
}

final class BridgePanelKernel extends Illuminate\Foundation\Http\Kernel
{
    protected $middleware=[];
    protected $middlewareGroups=['web'=>[
        Illuminate\Cookie\Middleware\EncryptCookies::class,Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        Illuminate\Session\Middleware\StartSession::class,Illuminate\View\Middleware\ShareErrorsFromSession::class,
        BridgePanelCsrf::class,BridgePanelAccess::class,Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]];
    protected $middlewareAliases=['throttle'=>Illuminate\Routing\Middleware\ThrottleRequests::class];
    protected function bootstrappers(){return [];}
}
final class BridgePanelCsrf extends Illuminate\Foundation\Http\Middleware\VerifyCsrfToken
{
    protected function runningUnitTests(){return false;} // Local tests must prove CSRF, too.
}
final class BridgePanelAccess
{
    public function handle($request,Closure $next)
    {
        $c=BridgePanelPaths::$config;
        if($request->getHost()!==$c['hostname'])abort(404);
        if($request->is('login') || $request->is('session/status'))return $next($request);
        $u=Illuminate\Support\Facades\Auth::user();
        if(!$u)return $request->expectsJson()?response()->json(['error'=>'unauthenticated'],401):redirect('/login');
        if((int)$u->id!==$c['userId'] || (int)$u->organization_id!==$c['organizationId'] || !$u->workspaces()->where('workspaces.id',10)->wherePivot('status','active')->exists())abort(403);
        session(['current_workspace_id'=>10,'current_workspace'=>App\Models\Workspace::findOrFail(10),'current_workspace_role'=>'owner']);
        if($request->has('channel_id') && (string)$request->query('channel_id')!=='20')abort(404);
        if($request->filled('conversation_id') && !Illuminate\Support\Facades\DB::table('conversations')->where('id',$request->input('conversation_id'))->where('organization_id',97101)->where('workspace_id',10)->where('channel_id',20)->exists())abort(404);
        foreach(['channel'=>'channels','conversation'=>'conversations'] as $parameter=>$table) {
            $value=$request->route($parameter);if($value===null)continue;
            $q=Illuminate\Support\Facades\DB::table($table)->where('organization_id',97101)->where('workspace_id',10);
            if($table==='conversations')$q->where('channel_id',20);else $q->where('id',20);
            $q->where(ctype_digit((string)$value)?'id':'public_id',(string)$value);
            if(!$q->exists())abort(404);
        }
        $response=$next($request);$response->headers->set('Cache-Control','no-store');$response->headers->set('X-Robots-Tag','noindex, nofollow');
        // Prevent browsers sending demo content to external scripts, sockets or analytics.
        $response->headers->set('Content-Security-Policy',"default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");
        return $response;
    }
}

final class BridgePanelExceptionHandler extends Illuminate\Foundation\Exceptions\Handler
{
    public function render($request,Throwable $error)
    {
        $this->report($error);
        return parent::render($request,$error);
    }
    public function report(Throwable $error)
    {
        if(getenv('APP_ENV')==='testing') {
            $message=explode("\n",explode('(Connection:',$error->getMessage())[0])[0];
            file_put_contents(BridgePanelPaths::$root.'/private-errors.log',get_class($error).': '.$message."\n",FILE_APPEND);
        }
        // Receive-only technical profile never logs request bodies, cookies or credentials.
    }
}
