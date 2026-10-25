<?php
declare(strict_types=1);
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Hash,Schema};
$c=BridgePanelPaths::$config;$db=DB::connection();
if($c['enabled']!==false || ($c['receiveEnabled']??false)!==false || Schema::hasTable('organizations'))throw new RuntimeException('Empty disabled synthetic database required');
// Committed synthetic fixture only, not a production schema dump or database copy.
require BridgePanelPaths::$root.'/core/tests/Unit/AI/Tools/Tickets/Concerns/BuildsTicketToolFixtures.php';
(new class {use Tests\Unit\AI\Tools\Tickets\Concerns\BuildsTicketToolFixtures;public function install(){ $this->ensureTicketLocalizationSchema(); }})->install();
function panelExtra(string $table,array $columns):void {
    Schema::table($table,function(Blueprint $t)use($table,$columns){foreach($columns as $name=>$type)if(!Schema::hasColumn($table,$name))$t->$type($name)->nullable();});
}
panelExtra('users',['organization_id'=>'bigInteger','role'=>'string','deleted_at'=>'timestamp','current_organization_id'=>'bigInteger','last_activity_at'=>'timestamp','email_verified_at'=>'timestamp','avatar'=>'text','avatar_url'=>'text','locale'=>'string','settings'=>'json','remember_token'=>'string']);
panelExtra('organizations',['locale'=>'string','timezone'=>'string','status'=>'string','plan_id'=>'bigInteger','reseller_id'=>'bigInteger','parent_organization_id'=>'bigInteger','owner_id'=>'bigInteger','settings'=>'json']);
panelExtra('workspaces',['status'=>'string','settings'=>'json']);
panelExtra('contacts',['public_id'=>'string','created_via'=>'string','channel_username'=>'string','external_contact_id'=>'string','contact_type'=>'string','preferred_language'=>'string','avatar_url'=>'text','user_identifier'=>'string','channel_id'=>'bigInteger','channel_profile_data'=>'json','custom_fields'=>'json','metadata'=>'json','spam_at'=>'timestamp','automated_at'=>'timestamp','last_user_message_at'=>'timestamp','last_message_at'=>'timestamp','last_whatsapp_inbound_at'=>'timestamp']);
panelExtra('conversations',['unread_count'=>'integer','public_id'=>'string','external_thread_id'=>'string','customer_language'=>'string','title'=>'text','channel_id'=>'bigInteger','ai_agent_id'=>'bigInteger','agent_id'=>'bigInteger','assigned_user_id'=>'bigInteger','user_id'=>'bigInteger','ai_bot_id'=>'bigInteger','current_ai_agent_id'=>'bigInteger','automation_paused'=>'boolean','metadata'=>'json','started_at'=>'timestamp','last_message_at'=>'timestamp','last_activity_at'=>'timestamp','closed_at'=>'timestamp','closed_by'=>'bigInteger','automation_paused_by'=>'bigInteger','automation_paused_at'=>'timestamp','human_request_state'=>'string']);
panelExtra('messages',['external_message_id'=>'text','sender_type'=>'string','sender_name'=>'text','sender_id'=>'bigInteger','billable'=>'boolean','delivered_at'=>'timestamp','read_at'=>'timestamp','media_description'=>'text','media_disk'=>'string','media_path'=>'text','media_mime'=>'string']);
panelExtra('tickets',['customer_activity_at'=>'timestamp','sequence'=>'bigInteger','primary_message_id'=>'bigInteger']);
panelExtra('ticket_inboxes',['number_prefix'=>'string','number_padding'=>'integer','number_with_year'=>'boolean']);
Schema::create('channels',function(Blueprint $t){$t->id();foreach(['organization_id','workspace_id','ai_agent_id']as$c)$t->bigInteger($c)->nullable();foreach(['name','type','status','managed_provider','connection_mode','processing_type','public_id','external_identifier']as$c)$t->string($c)->nullable();foreach(['configuration','automation_config']as$c)$t->json($c)->nullable();$t->boolean('ai_agent_enabled')->default(false);$t->boolean('automation_active')->default(false);$t->timestamps();});
Schema::create('workspace_users',function(Blueprint $t){$t->bigInteger('user_id');$t->bigInteger('workspace_id');$t->string('status');$t->string('role');$t->timestamps();});
panelExtra('workspace_users',['workspace_role_id'=>'bigInteger','invited_by'=>'bigInteger','joined_at'=>'timestamp','permissions'=>'json']);
Schema::create('teams',function(Blueprint $t){$t->id();$t->bigInteger('workspace_id');$t->boolean('auto_assignment')->default(false);$t->softDeletes();});
Schema::create('user_notification_preferences',function(Blueprint $t){$t->id();$t->bigInteger('user_id');$t->bigInteger('organization_id');$t->string('notification_type');foreach(['in_app_header_enabled','email_enabled','telegram_enabled']as$c)$t->boolean($c)->default(false);$t->timestamps();});
Schema::create('agent_notifications',function(Blueprint $t){$t->id();foreach(['user_id','organization_id','workspace_id','conversation_id','contact_id']as$c)$t->bigInteger($c)->nullable();foreach(['notification_type','type','title','message','link']as$c)$t->text($c)->nullable();$t->boolean('is_read')->default(false);$t->json('data')->nullable();$t->timestamps();});
Schema::create('personal_access_tokens',function(Blueprint $t){$t->id();$t->string('tokenable_type');$t->bigInteger('tokenable_id');$t->string('name');$t->string('token',64)->unique();$t->text('abilities')->nullable();$t->timestamp('last_used_at')->nullable();$t->timestamp('expires_at')->nullable();$t->timestamps();});
Schema::create('panel_clones',function(Blueprint $t){$t->id();foreach(['status','app_domain','cms_domain','domain_base']as$c)$t->string($c)->nullable();});
Schema::create('workspace_roles',function(Blueprint $t){$t->id();$t->bigInteger('workspace_id')->nullable();$t->json('hidden_modules')->nullable();});
Schema::create('platform_settings',function(Blueprint $t){$t->id();$t->string('setting_group');$t->string('setting_key');$t->text('setting_value')->nullable();$t->boolean('is_encrypted')->default(false);$t->text('description')->nullable();$t->timestamps();});
Schema::create('organization_trials',function(Blueprint $t){$t->id();$t->bigInteger('organization_id');$t->string('status');$t->timestamp('ends_at')->nullable();});
Schema::create('plans',function(Blueprint $t){$t->id();$t->string('name');$t->json('features');$t->timestamps();});
Schema::create('conversation_assignments',function(Blueprint $t){$t->id();$t->bigInteger('conversation_id');$t->bigInteger('user_id');$t->boolean('is_active')->default(true);$t->boolean('is_primary')->default(false);$t->string('role')->nullable();$t->timestamps();});
Schema::create('ticket_reads',function(Blueprint $t){$t->bigInteger('ticket_id');$t->bigInteger('user_id');$t->timestamp('read_at')->nullable();$t->primary(['ticket_id','user_id']);$t->timestamps();});
Schema::create('message_reads',function(Blueprint $t){$t->id();$t->bigInteger('message_id');$t->bigInteger('user_id');$t->timestamp('read_at')->nullable();$t->timestamps();});
Schema::create('message_search_tokens',function(Blueprint $t){$t->string('token');$t->bigInteger('message_id');$t->bigInteger('conversation_id');});
Schema::create('record_search_tokens',function(Blueprint $t){$t->string('entity');$t->bigInteger('entity_id');$t->string('token');});
Schema::create('ai_reply_drafts',function(Blueprint $t){$t->id();$t->bigInteger('conversation_id');$t->string('status');});
Schema::create('calendar_appointments',function(Blueprint $t){$t->id();$t->timestamp('start_at')->nullable();$t->timestamp('end_at')->nullable();$t->bigInteger('conversation_id')->nullable();$t->string('status');$t->string('owner_approval')->nullable();$t->softDeletes();});
Schema::create('calendars',function(Blueprint $t){$t->id();$t->bigInteger('organization_id');$t->bigInteger('workspace_id');$t->string('name');$t->boolean('is_active')->default(true);$t->softDeletes();});
Schema::create('ai_bots',function(Blueprint $t){$t->id();$t->boolean('is_active')->default(false);$t->boolean('menu_is_disabled')->default(false);$t->bigInteger('organization_id');$t->bigInteger('workspace_id');$t->string('name');$t->string('status')->nullable();$t->softDeletes();});
MuseRelay\AwsEngagementBridge\Core\Schema::create(Schema::getFacadeRoot());
$passwordFile=getenv('APP_ENV')==='testing'?BridgePanelPaths::$root.'/evaluation-local.json':'/etc/muserelay/aws-bridge/evaluator-stage.json';
if(realpath($passwordFile)!==$passwordFile || (fileperms($passwordFile)&0077)!==0)throw new RuntimeException('Private evaluation password required');
$password=json_decode(file_get_contents($passwordFile),true,8,JSON_THROW_ON_ERROR)['password']??'';
if(strlen($password)<24)throw new RuntimeException('Fresh evaluation password required');
$now=now();DB::table('users')->insert(['id'=>97101,'name'=>'Screen Art Evaluator','email'=>'evaluator@example.invalid','password'=>Hash::make($password),'role'=>'owner','organization_id'=>97101,'email_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
DB::table('organizations')->insert(['id'=>97101,'name'=>'Screen Art Demo','slug'=>'screenart-demo','owner_id'=>97101,'locale'=>'es','timezone'=>'Europe/Paris','status'=>'active','plan_id'=>60,'privacy_notice'=>'{"chat_first_contact_enabled":false}']);
DB::table('organizations')->insert(['id'=>97102,'name'=>'Synthetic Other Organization','slug'=>'synthetic-other','privacy_notice'=>'{}']);
DB::table('plans')->insert(['id'=>60,'name'=>'Demo','features'=>'{}']);
DB::table('workspaces')->insert(['id'=>10,'organization_id'=>97101,'user_id'=>97101,'name'=>'Screen Art Demo','slug'=>'screenart-demo','status'=>'active']);
DB::table('workspace_users')->insert(['workspace_id'=>10,'user_id'=>97101,'role'=>'owner','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
DB::table('channels')->insert(['id'=>20,'organization_id'=>97101,'workspace_id'=>10,'name'=>'Screen Art Demo — AWS Social','public_id'=>'ch_AwsBridgeDemo','type'=>'whatsapp','status'=>'active','managed_provider'=>'aws_social','processing_type'=>'none','configuration'=>'{}','automation_config'=>'{}']);
DB::table('aws_bridge_bindings')->insert(['id'=>'screenart-demo','channel_ref'=>'screenart-demo-aws','organization_id'=>97101,'workspace_id'=>10,'channel_id'=>20,'agent_id'=>0,'account_id'=>'365470560685','region'=>'eu-west-1','waba_id'=>'29207063275646749','meta_phone_id'=>'1383587584839516','aws_phone_arn'=>'arn:aws:social-messaging:eu-west-1:365470560685:phone-number-id/b1e7f9e53c454f15be065a2e2b5a1f00','allowed_recipients'=>'[]','enabled'=>false,'daily_inbound_limit'=>6,'daily_outbound_limit'=>6]);

foreach(array_keys(App\Services\Notifications\NotificationPreferences::TYPES) as $type)DB::table('user_notification_preferences')->insert(['user_id'=>97101,'organization_id'=>97101,'notification_type'=>$type,'in_app_header_enabled'=>false,'email_enabled'=>false,'telegram_enabled'=>false]);
