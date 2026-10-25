<?php
declare(strict_types=1);
// CLI-only synthetic HTTP probe. Cookie/password material travels over private process pipes, never logs.
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing')exit(1);
try {
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $app=require __DIR__.'/bootstrap.php';
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $request=Illuminate\Http\Request::create('https://aws-bridge-staging.muserelay.com'.$input['path'],$input['method'],$input['data'],$input['cookies']);
    foreach(['x-csrf-token','x-requested-with'] as $name)if(isset($input['headers'][$name]))$request->headers->set($name,$input['headers'][$name]);
    if($input['json'])$request->headers->set('accept','application/json');
    $response=$kernel->handle($request);$kernel->terminate($request,$response);
    if(isset($response->exception))$app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)->report($response->exception);
    $debug=['responseClass'=>get_class($response),'handlerClass'=>get_class($app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)),'action'=>$request->route()?->getActionName(),'env'=>getenv('APP_ENV'),'exceptionClass'=>isset($response->exception)?get_class($response->exception):null,'exceptionSummary'=>isset($response->exception)?substr(explode('(Connection:',$response->exception->getMessage())[0],0,250):null,'hasException'=>isset($response->exception),'bodyLength'=>strlen($response->getContent())];
    $cookies=[];foreach($response->headers->getCookies()as$cookie)$cookies[$cookie->getName()]=$cookie->getValue();
    echo json_encode(['status'=>$response->getStatusCode(),'body'=>$response->getContent(),'headers'=>$response->headers->all(),'cookies'=>$cookies,'debug'=>$debug],JSON_THROW_ON_ERROR);
}catch(Throwable){fwrite(STDERR,"Synthetic request probe failed\n");exit(1);}
