<?php
declare(strict_types=1);
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Database\Schema\Blueprint;

// Reuse the Core's committed synthetic ticket schema, never a production dump.
require getenv('BRIDGE_STAGE_ROOT').'/core/tests/Unit/AI/Tools/Tickets/Concerns/BuildsTicketToolFixtures.php';
$fixture = new class {
    use Tests\Unit\AI\Tools\Tickets\Concerns\BuildsTicketToolFixtures;
    public function install(): void { $this->ensureTicketLocalizationSchema(); }
};
$fixture->install();
DB::statement('ALTER TABLE tickets ALTER COLUMN subject TYPE text');
DB::statement('ALTER TABLE ticket_audit_logs ALTER COLUMN changes TYPE text');
function extra(string $table, array $text = [], array $integer = [], array $boolean = [], array $json = [], array $timestamps = []): void
{
    Schema::table($table, function (Blueprint $t) use ($table, $text, $integer, $boolean, $json, $timestamps) {
        foreach (['text' => $text, 'bigInteger' => $integer, 'boolean' => $boolean, 'json' => $json, 'timestamp' => $timestamps] as $method => $columns)
            foreach ($columns as $column) if (!Schema::hasColumn($table, $column)) $t->$method($column)->nullable();
    });
}
extra('users', [], [], [], [], ['deleted_at']);
extra('organizations', ['locale','timezone','status'], ['plan_id','reseller_id','parent_organization_id'], [], ['settings']);
extra('workspaces', [], [], [], ['settings']);
extra('contacts', ['public_id','created_via','channel_username','external_contact_id','contact_type','preferred_language','avatar_url','user_identifier'], ['channel_id'], [], ['channel_profile_data','custom_fields','metadata'], ['spam_at','automated_at','last_user_message_at','last_message_at','last_whatsapp_inbound_at','last_messenger_inbound_at','last_instagram_inbound_at']);
extra('conversations', ['public_id','external_thread_id','customer_language','title'], ['channel_id','ai_agent_id','ai_bot_id','assigned_user_id','agent_id','user_id','current_ai_agent_id'], ['automation_paused'], ['metadata'], ['started_at','last_message_at','last_activity_at']);
extra('messages', ['external_message_id','sender_type','sender_name','media_description','media_disk','media_path','media_mime'], ['sender_id'], ['billable'], [], ['delivered_at','read_at']);
extra('tickets', [], ['sequence','primary_message_id'], [], [], ['customer_activity_at']);
extra('ticket_inboxes', ['number_prefix'], ['number_padding'], ['number_with_year']);
Schema::create('channels', function (Blueprint $t) {
    $t->id(); $t->bigInteger('organization_id'); $t->bigInteger('workspace_id'); $t->bigInteger('ai_agent_id')->nullable();
    foreach (['name','type','status','managed_provider','connection_mode','processing_type','public_id'] as $c) $t->string($c)->nullable();
    foreach (['configuration','automation_config'] as $c) $t->json($c)->nullable();
    $t->boolean('ai_agent_enabled')->default(true); $t->boolean('automation_active')->default(true); $t->timestamps();
});
Schema::create('ai_providers', function (Blueprint $t) {
    $t->id(); $t->bigInteger('organization_id')->nullable(); $t->string('provider_type'); $t->string('name'); $t->string('scope')->default('organization');
    $t->boolean('is_active')->default(true); $t->json('credentials')->nullable(); $t->timestamps();
});
Schema::create('ai_agents', function (Blueprint $t) {
    $t->id();
    foreach (['organization_id','workspace_id','ai_provider_id','ticket_inbox_id','fallback_provider_id','fallback2_provider_id'] as $c) $t->bigInteger($c)->nullable();
    foreach (['name','status','model_name','public_id','system_prompt','description','language','timezone','custom_instructions'] as $c) $t->text($c)->nullable();
    foreach (['native_tools_enabled','ticket_inbox_ids','configuration','settings'] as $c) $t->json($c)->nullable();
    $t->boolean('is_active')->default(true); $t->boolean('supports_chat')->default(true); $t->timestamps();
});
Schema::create('message_search_tokens', function (Blueprint $t) { $t->string('token'); $t->bigInteger('message_id'); $t->bigInteger('conversation_id'); });
Schema::create('record_search_tokens', function (Blueprint $t) { $t->string('entity'); $t->bigInteger('entity_id'); $t->string('token'); });
Schema::create('ai_tool_calls', function (Blueprint $t) {
    $t->id(); foreach (['ai_agent_id','organization_id','workspace_id','conversation_id','message_id','latency_ms','duration_ms'] as $c) $t->bigInteger($c)->nullable();
    foreach (['tool_name','function_name','provider','model','model_used','status','error_code','channel','actor_type','call_id','function_call_id','tool_arguments','tool_result','execution_status','error_message'] as $c) $t->text($c)->nullable();
    foreach (['arguments','result','context','input','output','metadata'] as $c) $t->json($c)->nullable(); $t->timestamps();
});
Schema::create('conversation_assignments', function (Blueprint $t) { $t->id(); $t->bigInteger('conversation_id'); $t->bigInteger('user_id'); $t->boolean('is_active')->default(true); $t->string('role')->nullable(); $t->timestamps(); });
Schema::create('handoff_departments', function (Blueprint $t) { $t->id(); $t->bigInteger('ai_agent_id'); $t->boolean('is_active'); $t->integer('priority')->default(0); $t->softDeletes(); });
Schema::create('teams', function (Blueprint $t) { $t->id(); $t->bigInteger('workspace_id'); $t->boolean('auto_assignment')->default(false); $t->softDeletes(); });
Schema::create('plans', function (Blueprint $t) { $t->id(); $t->string('name'); $t->json('features'); $t->bigInteger('max_active_contacts_month')->nullable(); $t->timestamps(); });
Schema::create('organization_trials', function (Blueprint $t) { $t->id(); $t->bigInteger('organization_id'); $t->string('status'); $t->timestamp('ends_at'); });
Schema::create('workspace_users', function (Blueprint $t) { $t->bigInteger('user_id'); $t->bigInteger('workspace_id'); $t->string('status'); $t->string('role'); });
Schema::create('platform_settings', function (Blueprint $t) { $t->id(); $t->string('setting_group'); $t->string('setting_key'); $t->text('setting_value')->nullable(); $t->boolean('is_encrypted')->default(false); $t->timestamps(); });
Schema::create('ai_models_config', function (Blueprint $t) { $t->id(); $t->string('provider')->nullable(); $t->string('model_name')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
Schema::create('custom_tool_assignments', function (Blueprint $t) { $t->id(); $t->bigInteger('organization_id'); $t->string('tool_group'); });
Schema::create('ai_provider_costs', function (Blueprint $t) { $t->id(); $t->bigInteger('ai_provider_id'); $t->string('model_name'); $t->boolean('is_active'); });
Schema::create('voice_system_flags', function (Blueprint $t) { $t->id(); $t->string('flag_key'); $t->boolean('enabled'); });
// Explicit connector migration, installed package path, on this disposable database only.
$migration = require getenv('BRIDGE_STAGE_ROOT').'/core/vendor/screenartsl/muserelay-aws-engagement-bridge-core/database/migrations/2026_10_24_180000_create_aws_bridge_tables.php';
$migration->up();
$now = now();
DB::table('users')->insert(['id'=>97101,'name'=>'Synthetic demo owner','email'=>'owner@example.invalid','password'=>'unused','created_at'=>$now,'updated_at'=>$now]);
DB::table('organizations')->insert(['id'=>97101,'name'=>'Synthetic Museum Demo','slug'=>'synthetic-museum','locale'=>'es','timezone'=>'Europe/Paris','plan_id'=>60,'privacy_notice'=>'{"chat_first_contact_enabled":false}']);
DB::table('plans')->insert(['id'=>60,'name'=>'Synthetic Demo','features'=>'{"ai_support_tools":true,"ai_tickets_tools":true}']);
DB::table('organizations')->insert(['id'=>97102,'name'=>'Synthetic Other Tenant','slug'=>'synthetic-other','privacy_notice'=>'{}']);
DB::table('workspaces')->insert(['id'=>10,'organization_id'=>97101,'user_id'=>97101,'name'=>'Demo Workspace','slug'=>'demo']);
DB::table('ai_providers')->insert(['id'=>40,'organization_id'=>97101,'name'=>'Simulated Bedrock','provider_type'=>'aws_bedrock','credentials'=>'{"aws_region":"eu-west-1"}']);
DB::table('ai_agents')->insert(['id'=>30,'organization_id'=>97101,'workspace_id'=>10,'ai_provider_id'=>40,'ticket_inbox_id'=>50,'name'=>'Demo museum assistant',
    'model_name'=>'nova-lite','status'=>'active','system_prompt'=>'You are a museum assistant. Use support_create_ticket. Ask for confirmation before claiming registration.',
    'native_tools_enabled'=>'["support"]','ticket_inbox_ids'=>'[50]','language'=>'es','timezone'=>'Europe/Paris']);
DB::table('channels')->insert(['id'=>20,'organization_id'=>97101,'workspace_id'=>10,'ai_agent_id'=>30,'name'=>'Synthetic AWS WhatsApp','type'=>'whatsapp','status'=>'active',
    'managed_provider'=>'aws_social','connection_mode'=>'managed','processing_type'=>'ai_agent','configuration'=>'{}','automation_config'=>'{}']);
DB::table('ticket_inboxes')->insert(['id'=>50,'organization_id'=>97101,'workspace_id'=>10,'name'=>'Museum demo requests','slug'=>'demo','default_email_notifications'=>false,
    'is_active'=>true,'number_prefix'=>'DEMO','number_padding'=>5,'number_with_year'=>true]);
DB::table('aws_bridge_bindings')->insert(['id'=>'demo','channel_ref'=>'demo-channel','organization_id'=>97101,'workspace_id'=>10,'channel_id'=>20,'agent_id'=>30,
    'account_id'=>'111111111111','region'=>'eu-west-1','waba_id'=>'123456','meta_phone_id'=>'789012',
    'aws_phone_arn'=>'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/phone-number-id-stageabc123','allowed_recipients'=>'["+12025550123"]','enabled'=>true]);
echo "Installed synthetic schema and organization 97101\n";
