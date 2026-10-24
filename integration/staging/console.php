<?php
declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{DB, Redis};
use MuseRelay\AwsEngagementBridge\Core\{Store, Outbox, Worker};
$mode = $argv[1] ?? '';
if ($mode === 'rebuild') {
    if (DB::connection()->getDatabaseName() !== 'bridge_phase3_test' || DB::selectOne('select current_user u')->u !== 'bridge_stage') throw new RuntimeException('Unsafe database');
    DB::connection()->getSchemaBuilder()->dropAllTables();
    Redis::flushdb(); foreach (glob(getenv('BRIDGE_STAGE_ROOT').'/*.ndjson') as $f) unlink($f);
    require __DIR__.'/schema.php'; exit;
}
if ($mode === 'setup') { require __DIR__.'/schema.php'; exit; }
if ($mode === 'accept') {
    $packet = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $request = Illuminate\Http\Request::create('/api/aws-engagement-bridge/'.$packet['endpoint'], 'POST', [], [], [], [], $packet['body']);
    foreach ($packet['headers'] as $k => $v) $request->headers->set($k, $v);
    $request->headers->set('content-type', 'application/json');
    $app->instance('request', $request);
    $app['router']->aliasMiddleware('throttle', Illuminate\Routing\Middleware\ThrottleRequests::class);
    $response = $app['router']->dispatch($request);
    echo json_encode(['statusCode' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]), "\n"; exit;
}
if ($mode === 'dispatch') { $app['queue']->connection()->push(new BridgeStageDrainJob(), '', 'bridge_stage'); echo "Queued synthetic drain job\n"; exit; }
if ($mode === 'worker') {
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    exit($kernel->handle(new Symfony\Component\Console\Input\ArrayInput(['command'=>'queue:work','connection'=>'redis','--queue'=>'bridge_stage',
        '--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1,'--timeout'=>45,'--max-time'=>50])));
}
if ($mode === 'regression') { require __DIR__.'/regression.php'; exit; }
if ($mode === 'assertions') { require __DIR__.'/assertions.php'; exit; }
if ($mode === 'claim') { $row = $app->make(Store::class)->claim(); echo json_encode(['claimed' => $row !== null]), PHP_EOL; exit; }
if ($mode === 'disabled') {
    if ($app->make(Store::class)->isEnabled() || $app->make(Store::class)->claim() !== null) throw new RuntimeException('Disabled worker performed work');
    foreach ($app['router']->getRoutes() as $route) if (str_starts_with($route->uri(), 'api/aws-engagement-bridge/')) throw new RuntimeException('Disabled bridge exposed routes');
    echo "Disabled connector has no routes and no worker claims\n"; exit;
}
if ($mode === 'inspect') {
    $counts=[]; foreach(['aws_bridge_events','aws_bridge_actions','aws_bridge_outbox','contacts','conversations','messages','tickets','ticket_audit_logs'] as $t) $counts[$t]=DB::table($t)->count();
    $actions = []; foreach (DB::table('aws_bridge_actions')->get() as $action) { $r = $app->make(Store::class)->decode($action->result); $actions[] = ['ok'=>$r['ok'] ?? false,'number'=>$r['number'] ?? null,'error'=>$r['error_code'] ?? $r['error'] ?? null]; }
    echo json_encode(['actions'=>$actions,'counts'=>$counts,'events'=>DB::table('aws_bridge_events')->get(['state','error_code','attempts'])->all(), 'outbox'=>DB::table('aws_bridge_outbox')->get(['id','state','provider_message_id'])->all()]),"\n"; exit;
}
throw new RuntimeException('Unknown staging operation');
