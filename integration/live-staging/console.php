<?php
declare(strict_types=1);
ini_set('display_errors','0');
try {
    $app=require __DIR__.'/bootstrap.php';
    $db=$app['db']->connection();
    if ($db->getDatabaseName()!=='bridge_phase4_test' || $db->getConfig('host')!==BridgeLiveConfig::RUN.'/pgsock'
        || $db->selectOne('select current_user u')->u!=='muserelay-aws-stage') throw new RuntimeException('Dedicated database required');
    $mode=$argv[1] ?? '';
    if ($mode==='seed') {
        if ((BridgeLiveConfig::$values['enabled'] ?? false)!==false || $db->getSchemaBuilder()->hasTable('organizations')) throw new RuntimeException('Empty disabled demo database required');
        $c=BridgeLiveConfig::$values;
        if (!preg_match('/^[0-9]+$/D',$c['metaWabaId'] ?? '') || !preg_match('/^[0-9]+$/D',$c['metaPhoneNumberId'] ?? '')
            || !preg_match('~^arn:aws:social-messaging:eu-west-1:365470560685:phone-number-id/[A-Za-z0-9-]+$~D',$c['socialPhoneArn'] ?? '')
            || !preg_match('/^\+[1-9][0-9]{6,14}$/D',$c['recipient'] ?? '')) throw new RuntimeException('Verified demo phone identifiers required');
        $db->transaction(function () use ($db,$c) {
        putenv('BRIDGE_STAGE_ROOT='.BridgeLiveConfig::ROOT);
        ob_start();require dirname(__DIR__).'/staging/schema.php';ob_end_clean();
        $db->table('aws_bridge_bindings')->where('id','demo')->update(['id'=>$c['bindingId'],'channel_ref'=>$c['channelRef'],
            'account_id'=>'365470560685','waba_id'=>$c['metaWabaId'],'meta_phone_id'=>$c['metaPhoneNumberId'],'aws_phone_arn'=>$c['socialPhoneArn'],
            'allowed_recipients'=>json_encode([$c['recipient']]),'enabled'=>false,'daily_inbound_limit'=>6,'daily_outbound_limit'=>6]);
        $db->table('ai_providers')->where('id',40)->update(['name'=>'Dedicated live Bedrock demo','credentials'=>'{"aws_region":"eu-west-1"}']);
        $db->table('ai_agents')->where('id',30)->update(['system_prompt'=>'Eres el asistente de una demostración sintética de museo. Usa support_create_ticket para registrar una solicitud. Llama a la herramienta en el primer turno para que ella solicite la confirmación; no la omitas. Conserva exactamente el asunto y la descripción propuestos cuando el cliente confirme. Antes de crearla, resume asunto y descripción y pide confirmación explícita. No afirmes que está creada sin el resultado correcto de la herramienta. No hagas reservas ni cobros. Nunca inventes nombres, datos personales ni referencias.']);
        });
        echo "Synthetic native demo fixtures installed; binding remains disabled.\n";exit;
    }
    if ($mode==='snapshot') {
        $label=$argv[2] ?? '';
        if (!in_array($label,['before','after_first','after_second'],true)) throw new RuntimeException('Snapshot label required');
        $counts=[];foreach (['aws_bridge_events','aws_bridge_outbox','messages','tickets','ticket_audit_logs'] as $table) $counts[$table]=$db->table($table)->count();
        $events=$db->table('aws_bridge_events')->where('kind','message')->orderBy('sequence')->get(['id','state','attempts'])->all();
        $actions=[];
        foreach ($db->table('aws_bridge_actions')->get() as $row) {
            $r=$app->make(MuseRelay\AwsEngagementBridge\Core\Store::class)->decode($row->result);
            $code=$r['error_code'] ?? $r['error'] ?? '';
            $actions[]=['eventId'=>$row->event_id,'ok'=>($r['ok'] ?? false)===true,
                'code'=>is_string($code)&&preg_match('/^[a-z_]{0,80}$/D',$code)?$code:'redacted',
                'referenceHash'=>isset($r['number'])?hash('sha256',(string)$r['number']):null];
        }
        $outbox=$db->table('aws_bridge_outbox')->get(['id','state','source_message_id'])->all();
        echo json_encode(['kind'=>'NATIVE_STAGE_SNAPSHOT','runId'=>BridgeLiveConfig::$values['runId'],'time'=>gmdate('c'),'label'=>$label,
            'counts'=>$counts,'events'=>$events,'actions'=>$actions,'outbox'=>$outbox],JSON_THROW_ON_ERROR),"\n";exit;
    }
    if ($mode==='enqueue') {
        // The timer never accumulates jobs when inference is slower than its cadence.
        if ($app['cache']->add('phase4_drain_queued',true,570)) $app['queue']->connection()->push(new BridgeLiveDrainJob(),'', 'bridge_phase4');
        exit;
    }
    if ($mode==='worker') {
        $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
        exit($kernel->handle(new Symfony\Component\Console\Input\ArrayInput(['command'=>'queue:work','connection'=>'redis','--queue'=>'bridge_phase4',
            '--sleep'=>1,'--tries'=>1,'--timeout'=>480,'--max-jobs'=>1,'--max-time'=>540])));
    }
    throw new RuntimeException('Unknown stage operation');
} catch (Throwable $error) { fwrite(STDERR,"Stage operation failed; inspect redacted evidence and configuration.\n");exit(1); }
