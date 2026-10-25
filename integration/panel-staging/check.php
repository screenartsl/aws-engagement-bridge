<?php
declare(strict_types=1);
function ensure($condition,string $message):void {if(!$condition)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
try {
    $app=require __DIR__.'/bootstrap.php';
    require __DIR__.'/schema.php';
    $db=$app['db']->connection();
    ensure($db->table('aws_bridge_bindings')->where('enabled',true)->count()===0,'initial binding disabled');
    $store=$app->make(MuseRelay\AwsEngagementBridge\Core\Store::class);
    ensure(!$store->isEnabled() && !$store->operationEnabled('receive') && !$store->operationEnabled('agent') && !$store->operationEnabled('send'),'all controls initially disabled');
    ensure(!Illuminate\Support\Facades\Auth::check(),'reception needs no evaluator session');
    ensure(!App\Models\Channel::findOrFail(20)->isConfigured(),'disabled AWS binding is not advertised as ready');
    $legacy=new App\Models\Channel();$legacy->forceFill(['type'=>'whatsapp','managed_provider'=>'meta','connection_mode'=>'byoa','configuration'=>['access_token'=>'synthetic-test-only','phone_number_id'=>'synthetic','business_account_id'=>'synthetic']]);
    ensure($legacy->isConfigured(),'existing Meta configuration recognition unchanged');
    // Synthetic local input only. No network receiver, polling worker or live agent is started.
    $app['config']->set('aws_bridge.enabled',true);$app['config']->set('aws_bridge.organization_ids',[97101]);$app['config']->set('aws_bridge.receive_enabled',true);
    $db->table('aws_bridge_bindings')->where('id','screenart-demo')->update(['enabled'=>true,'allowed_recipients'=>'["+12025550123"]']);
    $binding=$store->binding('screenart-demo');$now=time();
    $event=['version'=>1,'eventId'=>hash('sha256',json_encode(['screenart-demo','message','wamid.synthetic-panel'],JSON_UNESCAPED_SLASHES)),
        'bindingId'=>'screenart-demo','channelRef'=>'screenart-demo-aws','resource'=>['accountId'=>'365470560685','region'=>'eu-west-1','wabaId'=>'29207063275646749','metaPhoneNumberId'=>'1383587584839516','awsPhoneNumberArn'=>$binding->aws_phone_arn],
        'kind'=>'message','providerMessageId'=>'wamid.synthetic-panel','timestamp'=>$now,'sender'=>'+12025550123','text'=>'Synthetic receipt visible in native MuseRelay inbox'];
    $store->accept('screenart-demo',$event);$store->accept('screenart-demo',$event);
    ensure(App\Models\Channel::findOrFail(20)->isConfigured(),'AWS channel readiness uses binding without Meta token');
    $worker=$app->make(MuseRelay\AwsEngagementBridge\Core\Worker::class);$worker->once();$worker->once();
    ensure($db->table('messages')->count()===1 && $db->table('contacts')->count()===1 && $db->table('conversations')->count()===1,'native receive-only idempotent message contact and conversation');
    ensure($db->table('aws_bridge_events')->value('state')==='done','receive-only processing completed without agent/provider');
    ensure($db->table('aws_bridge_outbox')->count()===0 && $db->table('tickets')->count()===0,'no output or business action');
    $private=BridgePanelPaths::$config;$private['enabled']=true;$private['receiveEnabled']=true;unset($private['database'],$private['databaseUser'],$private['socket'],$private['port']);
    file_put_contents(BridgePanelPaths::$root.'/panel-local.json',json_encode($private,JSON_THROW_ON_ERROR));
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$jar=[];
    $request=function(string $method,string $path,array $data=[],bool $json=false)use(&$jar){
        $process=proc_open([PHP_BINARY,__DIR__.'/request-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fwrite($pipes[0],json_encode(['method'=>$method,'path'=>$path,'data'=>$data,'cookies'=>$jar,'json'=>$json],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $raw=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException($err);
        $payload=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$jar=array_merge($jar,$payload['cookies']);
        if($payload['status']>=500)echo 'HTTP DEBUG '.json_encode($payload['debug'])."\n";
        return new Illuminate\Http\Response($payload['body'],$payload['status'],$payload['headers']);
    };
    ensure($request('GET','/conversations/api/list',[],true)->getStatusCode()===401,'guest cannot read inbox');
    $login=$request('GET','/login');ensure($login->getStatusCode()===200,'evaluation login renders');
    preg_match('/name="_token"[^>]*value="([^"]+)"/',$login->getContent(),$match);$token=$match[1]??'';ensure(strlen($token)>20,'login CSRF token present');
    ensure($request('POST','/login',['password'=>'not-the-password'],true)->getStatusCode()===419,'CSRF enforced even in local tests');
    $credentials=json_decode(file_get_contents(BridgePanelPaths::$root.'/evaluation-local.json'),true,8,JSON_THROW_ON_ERROR);
    ensure($request('POST','/login',['_token'=>$token,'password'=>$credentials['password']])->getStatusCode()===302,'dedicated evaluator login accepted');
    $panel=$request('GET','/conversations/channel/20');
    if($panel->getStatusCode()!==200)throw new RuntimeException('Native panel status '.$panel->getStatusCode().' '.(is_file(BridgePanelPaths::$root.'/private-errors.log')?file_get_contents(BridgePanelPaths::$root.'/private-errors.log'):''));
    ensure(str_contains($panel->getContent(),'Screen Art Demo') && str_contains($panel->getContent(),'conversations/api/list'),'native inbox view renders with native API URLs');
    file_put_contents(BridgePanelPaths::$root.'/native-panel.html',$panel->getContent());
    $list=$request('GET','/conversations/api/list',['channel_id'=>20],true);
    ensure($list->getStatusCode()===200,'native inbox list endpoint responds');$rows=json_decode($list->getContent(),true,512,JSON_THROW_ON_ERROR);
    ensure(count($rows['data'])===1,'native inbox lists the received conversation');
    $id=$db->table('conversations')->value('id');$detail=$request('GET','/conversations/api/'.$id,[],true);
    ensure($detail->getStatusCode()===200 && str_contains($detail->getContent(),'Synthetic receipt visible'),'native detail exposes received message');
    $db->table('workspaces')->insert(['id'=>11,'organization_id'=>97102,'user_id'=>97101,'name'=>'Synthetic Other Workspace','slug'=>'synthetic-other-workspace','status'=>'active']);
    $db->table('channels')->insert(['id'=>21,'organization_id'=>97102,'workspace_id'=>11,'name'=>'Synthetic Other Channel','type'=>'whatsapp','status'=>'active','configuration'=>'{}']);
    $db->table('contacts')->insert(['id'=>999,'organization_id'=>97102,'workspace_id'=>11,'first_name'=>'Synthetic Other Contact','source'=>'whatsapp']);
    $db->table('conversations')->insert(['id'=>999,'organization_id'=>97102,'workspace_id'=>11,'channel_id'=>21,'contact_id'=>999,'channel_type'=>'whatsapp','status'=>'active']);
    ensure($request('GET','/conversations/api/999',[],true)->getStatusCode()===404,'existing foreign organization conversation rejected');
    ensure($request('GET','/conversations/channel/21')->getStatusCode()===404,'other channel rejected');
    ensure($request('GET','/conversations/api/list',['channel_id'=>21],true)->getStatusCode()===404,'cross-channel query rejected');
    ensure($request('GET','/megadmin')->getStatusCode()===404,'administration routes not exposed');
    ensure($request('POST','/messages/send',['_token'=>$token],true)->getStatusCode()===404,'send route not exposed');
    ensure($panel->headers->get('X-Robots-Tag')==='noindex, nofollow' && str_contains($panel->headers->get('Content-Security-Policy'),'connect-src'),'privacy and browser egress headers present');
    if(getenv('BRIDGE_PANEL_BROWSER_CHECK')!=='1') {
    $private['enabled']=false;$private['receiveEnabled']=false;file_put_contents(BridgePanelPaths::$root.'/panel-local.json',json_encode($private,JSON_THROW_ON_ERROR));
    $db->table('aws_bridge_bindings')->update(['enabled'=>false]);
    }
    echo json_encode(['result'=>'PASS','kind'=>'SYNTHETIC_NATIVE_PANEL','realAws'=>false,'productionModified'=>false,'browserTested'=>false,'messageCount'=>$db->table('messages')->count(),'outboxCount'=>$db->table('aws_bridge_outbox')->count()]),"\n";
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.explode("\n",explode('(Connection:',$e->getMessage())[0])[0]."\n");exit(1);}
