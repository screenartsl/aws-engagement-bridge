<?php
declare(strict_types=1);
ini_set('display_errors','0');
try {
    $app=require dirname(__DIR__).'/bootstrap.php';
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $request=Illuminate\Http\Request::capture();$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
} catch(Throwable) {http_response_code(503);header('Content-Type: application/json');echo '{"error":"stage_unavailable"}';}
