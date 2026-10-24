<?php
declare(strict_types=1);
use Illuminate\Support\Facades\{DB, Http};
use App\AI\Tools\Registry\ToolsProviderRegistry;
use App\Models\{AIAgent,Channel,Contact};
use MuseRelay\AwsEngagementBridge\Core\{Store,ToolGuard};
function regression(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo 'PASS '.$label."\n"; }
$providers = new class extends ToolsProviderRegistry {
    public int $calls = 0;
    public function dispatch(string $functionName, array $arguments, array $context, AIAgent $agent): array
    { $this->calls++; return ['ok'=>true,'provider'=>'synthetic-legacy-dispatch']; }
};
$app->instance(ToolsProviderRegistry::class, $providers);
$app->bind(ToolGuard::class, fn () => throw new RuntimeException('Legacy transport entered AWS ToolGuard'));
$executor = $app->make(App\AI\AgentExecutor::class);
$execute = new ReflectionMethod($executor, 'executeToolCall');
$agent = AIAgent::findOrFail(30);
foreach (['meta'=>'whatsapp','telnyx'=>'whatsapp','instagram'=>'instagram','messenger'=>'messenger','sms'=>'sms','telegram'=>'telegram','email'=>'email','chatbot'=>'web','voice_sip'=>'voice_sip'] as $provider=>$type) {
    $id = 200 + $providers->calls;
    DB::table('channels')->updateOrInsert(['id'=>$id], ['organization_id'=>97101,'workspace_id'=>10,'name'=>'Synthetic legacy '.$provider,'type'=>$type,'status'=>'active',
        'managed_provider'=>in_array($provider,['meta','telnyx']) ? $provider : null,'connection_mode'=>$provider==='telnyx'?'managed':'byoa','configuration'=>json_encode([
            'access_token'=>'synthetic-meta-only','api_key'=>'synthetic-telnyx-only','phone_number_id'=>'synthetic-phone','business_account_id'=>'synthetic-waba','from_phone_number'=>'+12025550123'])]);
    $before = $providers->calls;
    $result = $execute->invoke($executor, ['name'=>'synthetic_legacy_tool','arguments'=>[]], $agent, ['channel'=>$type,'channel_id'=>$id,'organization_id'=>97101,'workspace_id'=>10], 'synthetic');
    regression($providers->calls === $before+1 && $result['ok'], $provider.' retains legacy tool dispatch with no bridge tables');
}
Http::swap(new Illuminate\Http\Client\Factory());
Http::preventStrayRequests();
Http::fake(['https://graph.facebook.com/*'=>Http::response(['messages'=>[['id'=>'wamid.legacy-regression']]],200)]);
$contact = Contact::firstOrFail(); $contact->update(['last_user_message_at'=>now()]);
$sender = new App\Services\WhatsAppSenderService();
$meta = Channel::where('managed_provider','meta')->firstOrFail();
$result = $sender->sendTextMessage($meta,$contact,'Synthetic direct Meta regression');
if (!($result['success'] ?? false)) throw new RuntimeException('Meta fixture failure: '.json_encode($result));
regression(true, 'actual direct Meta sender succeeds against HTTP fake');
Http::assertSent(fn ($r) => str_contains($r->url(),'graph.facebook.com/') && $r['text']['body']==='Synthetic direct Meta regression');
Http::assertSentCount(1);
Illuminate\Support\Facades\Bus::fake([App\Jobs\SendAIChannelResponse::class]);
$meta->update(['ai_agent_id'=>30,'ai_agent_enabled'=>true,'processing_type'=>'ai_agent','automation_active'=>true]);
$conversation = App\Models\Conversation::where('channel_id',$meta->id)->firstOrFail();
$conversation->update(['current_ai_agent_id'=>30,'automation_paused'=>false]);
$inbound = App\Models\Message::create(['conversation_id'=>$conversation->id,'channel_id'=>$meta->id,'direction'=>'inbound','sender_type'=>'user',
    'message_type'=>'text','content'=>'Synthetic legacy automation regression','status'=>'received']);
$processed = $app->make(App\Services\AutomationService::class)->sendToAIAgent($inbound,$conversation,$meta);
regression($processed, 'actual non-AWS AutomationService completes without bridge tables');
Illuminate\Support\Facades\Bus::assertDispatched(App\Jobs\SendAIChannelResponse::class, fn ($job) => $job->channelId===$meta->id);
regression(true, 'non-AWS AI reply retains original asynchronous sender job');
$telnyx = Channel::where('managed_provider','telnyx')->firstOrFail();
try { $sender->sendTextMessage($telnyx,$contact,'Synthetic Telnyx regression'); throw new RuntimeException('Expected baseline Telnyx limitation'); }
catch (Exception $e) { regression($e->getMessage()==='Media WhatsApp aún no implementado para modo guiado.', 'existing baseline Telnyx limitation is unchanged'); }
Http::assertSentCount(1);
$app->instance(Store::class, new Store(DB::connection(),$app['encrypter'],false,[]));
$result = $sender->sendTextMessage(Channel::findOrFail(20),$contact,'Synthetic disabled AWS');
regression(!$result['success'] && $result['error']==='bridge_disabled','disabled AWS text fails closed');
try { $sender->sendMediaMessage(Channel::findOrFail(20),$contact,'image','synthetic-media'); throw new RuntimeException('Expected AWS media rejection'); }
catch (Exception $e) { regression($e->getMessage()==='Channel configuration invalid','AWS media cannot fall back to Meta'); }
Http::assertSentCount(1);
echo "Legacy regression complete; no real network was available\n";
