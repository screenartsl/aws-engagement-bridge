<?php
declare(strict_types=1);
ini_set('display_errors','0');
try {
    $app=require __DIR__.'/bootstrap.php';$mode=$argv[1]??'';
    if($mode==='seed') {
        require __DIR__.'/schema.php';echo "Synthetic Screen Art Demo seeded; all operation gates and binding disabled.\n";exit;
    }
    if($mode==='drain') {
        $store=$app->make(MuseRelay\AwsEngagementBridge\Core\Store::class);
        if(!$store->operationEnabled('receive')){echo "Reception is disabled.\n";exit;}
        if($store->operationEnabled('agent') || $store->operationEnabled('send'))throw new RuntimeException('Receive-only required');
        $app->make(MuseRelay\AwsEngagementBridge\Core\Worker::class)->once();exit;
    }
    if($mode==='snapshot') {
        $db=$app['db']->connection();$counts=[];foreach(['contacts','conversations','messages','aws_bridge_events','aws_bridge_outbox','tickets']as$table)$counts[$table]=$db->table($table)->count();
        echo json_encode(['kind'=>'RECEIVE_ONLY_STAGE_METADATA','counts'=>$counts,'receiveEnabled'=>$app->make(MuseRelay\AwsEngagementBridge\Core\Store::class)->operationEnabled('receive'),'agentEnabled'=>false,'sendEnabled'=>false,'events'=>$db->table('aws_bridge_events')->get(['id','state','attempts','error_code'])->all()],JSON_THROW_ON_ERROR),"\n";exit;
    }
    throw new RuntimeException('Unknown panel operation');
}catch(Throwable){fwrite(STDERR,"Panel stage operation failed; inspect private redacted metadata.\n");exit(1);}
