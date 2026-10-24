<?php
declare(strict_types=1);
use Illuminate\Support\Facades\{DB, Redis};
use MuseRelay\AwsEngagementBridge\Core\{BridgeException, Store, CorePort, Scope, ToolGuard, Outbox};
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); echo 'PASS '.$message."\n"; }
function denied(callable $fn, string $reason): void {
    try { $fn(); } catch (BridgeException $e) { check($e->reason === $reason, $reason); return; }
    throw new RuntimeException('Expected '.$reason);
}
$store = $app->make(Store::class); $b = $store->binding('demo');
check(DB::selectOne('select current_database() db, current_user u')->db === 'bridge_phase3_test', 'private PostgreSQL database');
check(!DB::selectOne("select rolsuper from pg_roles where rolname = current_user")->rolsuper, 'staging SQL role is not superuser');
check(DB::table('tickets')->count() === 1 && DB::table('ticket_audit_logs')->count() === 1, 'two-turn demo created exactly one native ticket and audit');
check(DB::table('messages')->where('direction','inbound')->where('sender_type','user')->count() === 2, 'native customer message type supports confirmation');
check(DB::table('aws_bridge_outbox')->where('delivery_status','read')->count() === 2, 'signed receipts and read statuses updated both native replies');
$raw = DB::table('messages')->where('direction','inbound')->value('content');
check(!str_contains($raw, 'Necesito registrar'), 'native message content is encrypted at rest');
check(!str_contains(DB::table('tickets')->value('subject'), 'Synthetic museum'), 'native ticket subject is encrypted at rest');
$orgDisabled = new Store($store->db, $app['encrypter'], true, [97102]);
denied(fn () => $orgDisabled->binding('demo'), 'binding_disabled');
DB::transaction(function () use ($store) {
    DB::table('channels')->where('id',20)->update(['organization_id'=>97102]);
    denied(fn () => $store->binding('demo'), 'ownership_mismatch');
    DB::table('channels')->where('id',20)->update(['organization_id'=>97101]);
});
// The concurrency test left one real, owned lease for a third synthetic input.
$event = DB::table('aws_bridge_events')->where('state','processing')->first();
check($event !== null && (int) $event->attempts === 1, 'one PostgreSQL lease survived competing processes');
$confirmation = new App\AI\Tools\Support\CustomerConfirmation(App\AI\Tools\Tickets\CreateTicketTool::CONFIRM_PREFIX);
$conversationId = (int) DB::table('conversations')->value('id');
$confirmation->remember($conversationId, '', 'Register a different synthetic request.');
$native = $app->make(CorePort::class);
$event = $store->materialize($event, fn () => $native->incoming($b, $store->decode($event->payload)));
$scope = $app->make(Scope::class); $scope->binding = $b; $scope->event = $event;
$guard = $app->make(ToolGuard::class);
$ctx = ['channel'=>'whatsapp','channel_id'=>20,'organization_id'=>97101,'workspace_id'=>10,'conversation_id'=>$conversationId,
    'contact_id'=>(int) DB::table('contacts')->value('id'),'ticket_inbox_id'=>50,'ai_agent_id'=>30,'last_user_message'=>'Sí, registra una solicitud diferente.','user_locale'=>'es'];
$counts = [DB::table('tickets')->count(), DB::table('ticket_audit_logs')->count(), DB::table('aws_bridge_actions')->count()];
try {
    $store->action($event, 'support_create_ticket', function () use ($app, $ctx) {
        $result = $app->make(App\AI\Tools\Tickets\CreateTicketTool::class)->execute(['subject'=>'Synthetic rollback request','description'=>'Disposable rollback proof',
            'is_new_issue'=>true,'confirmation'=>'confirmed'], $ctx);
        if (!($result['ok'] ?? false)) throw new RuntimeException('Native tool failed: '.json_encode($result));
        check(true, 'actual native ticket tool executed before injected transaction failure');
        throw new RuntimeException('injected_after_native_ticket');
    });
    throw new RuntimeException('Expected injected failure');
} catch (RuntimeException $e) { if ($e->getMessage() !== 'injected_after_native_ticket') throw $e; check(true, 'failure injection reached native action transaction'); }
check($counts === [DB::table('tickets')->count(), DB::table('ticket_audit_logs')->count(), DB::table('aws_bridge_actions')->count()], 'native ticket, audit and bridge checkpoint roll back together');
$called = false;
$result = $guard->execute('support_create_ticket', array_merge($ctx, ['organization_id'=>97102]), function () use (&$called) { $called=true; return ['ok'=>true]; });
check(!$called && !$result['ok'], 'cross-organization tool call cannot dispatch');
DB::table('conversations')->where('id',$conversationId)->update(['automation_paused'=>true]);
denied(fn () => $guard->execute('support_create_ticket', $ctx, function () use (&$called) { $called=true; return ['ok'=>true]; }), 'conversation_paused');
check(!$called, 'human takeover blocks native ticket dispatch');
DB::table('conversations')->where('id',$conversationId)->update(['automation_paused'=>false]);
DB::table('messages')->where('direction','inbound')->update(['created_at'=>now()->subHours(25)]);
denied(fn () => $native->outgoing($b,'+12025550123','Synthetic expired-window reply',(int)$event->message_id,null),'customer_window_closed');
$store->finish($event, true); $scope->clear();
// Connector rollback touches only the connector tables; native records survive.
$before = [DB::table('messages')->count(),DB::table('tickets')->count(),DB::table('channels')->count()];
$migration = require getenv('BRIDGE_STAGE_ROOT').'/core/vendor/screenartsl/muserelay-aws-engagement-bridge-core/database/migrations/2026_10_24_180000_create_aws_bridge_tables.php';
$migration->down();
check(!DB::getSchemaBuilder()->hasTable('aws_bridge_events') && $before === [DB::table('messages')->count(),DB::table('tickets')->count(),DB::table('channels')->count()], 'connector schema rollback preserves native messages, tickets and channels');
echo "Native staging assertions complete\n";
