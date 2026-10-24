<?php
declare(strict_types=1);
ini_set('display_errors','0');
try {
    $app=require dirname(__DIR__).'/bootstrap.php';
    $request=Illuminate\Http\Request::capture();
    $app->instance('request',$request);
    $response=$app['router']->dispatch($request);
    $response->send();
} catch (Throwable $error) {
    http_response_code(503);header('Content-Type: application/json');
    echo '{"error":"stage_unavailable"}'; // Never expose exception messages, request bodies or headers.
}
