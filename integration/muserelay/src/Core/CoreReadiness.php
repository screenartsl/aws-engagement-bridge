<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

/** Local inspection only: no AWS, inference, registration, messages or key contents in output. */
final class CoreReadiness
{
    public function problems(): array
    {
        $problems=[];
        foreach (['aws_bridge_bindings','aws_bridge_events','aws_bridge_actions','aws_bridge_nonces','aws_bridge_outbox'] as $table) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)) return ['bridge_schema_missing'];
        }
        // A scoped, dormant extension can be enabled before connecting any AWS channel.
        // This validates installation only; it must not be advertised as connected messaging.
        if (config('aws_bridge.receive_enabled',false)!==true && config('aws_bridge.agent_enabled',false)!==true
            && config('aws_bridge.send_enabled',false)!==true) return [];
        $send=config('aws_bridge.send_enabled',false)===true;
        $url=(string)config('aws_bridge.outbound_url','');
        if ($send && !preg_match('~^https://[a-z0-9]+\.execute-api\.eu-west-1\.amazonaws\.com/outbound$~D',$url)) $problems[]='aws_endpoint_missing';
        $bindings=\Illuminate\Support\Facades\DB::table('aws_bridge_bindings')->where('enabled',true)->get();
        if ($bindings->isEmpty()) $problems[]='demo_binding_missing';
        foreach ($bindings as $b) {
            if ($b->account_id!=='365470560685' || $b->region!=='eu-west-1') $problems[]='aws_identity_mismatch';
            try {
                $keys=new SigningKeys((string)config('aws_bridge.signing_keys_file',''));
                $keys->get($b->id,'core');if ($send) $keys->get($b->id,'outbound');
            } catch (\Throwable) { $problems[]='signing_keys_missing'; }
        }
        return array_values(array_unique($problems));
    }
}
