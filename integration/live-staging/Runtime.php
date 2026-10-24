<?php
declare(strict_types=1);

/** Dedicated demo harness, not a production bootstrap or a public SaaS panel. */
final class BridgeLiveConfig
{
    public const ROOT = '/srv/muserelay-aws-stage';
    public const DATA = '/var/lib/muserelay-aws-stage';
    public const RUN = '/run/muserelay-aws-stage';
    public static array $values;
    public static function protectedJson(string $path): array
    {
        $real = realpath($path);
        if (!$real || !str_starts_with($real, '/etc/muserelay/aws-bridge/') || !is_file($real)
            || !is_readable($real) || (fileperms($real) & 0077) !== 0 || filesize($real) > 64000) throw new RuntimeException('Private staging configuration required');
        return json_decode(file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
    }
    public static function load(): array
    {
        if (getenv('APP_ENV') !== 'bridge-staging' || !is_file(self::ROOT.'/.isolated-bridge-stage')) throw new RuntimeException('Dedicated staging installation required');
        $c = self::protectedJson('/etc/muserelay/aws-bridge/phase4.json');
        if (($c['accountId'] ?? '') !== '365470560685' || ($c['region'] ?? '') !== 'eu-west-1'
            || ($c['coreHostname'] ?? '') !== 'aws-bridge-staging.muserelay.com'
            || !preg_match('/^phase4_[A-Za-z0-9_-]{4,56}$/D', $c['runId'] ?? '')
            || ($c['organizationId'] ?? null) !== 97101 || ($c['workspaceId'] ?? null) !== 10 || ($c['channelId'] ?? null) !== 20
            || ($c['agentId'] ?? null) !== 30 || ($c['ticketInboxId'] ?? null) !== 50
            || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $c['bindingId'] ?? '')
            || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $c['channelRef'] ?? '')
            || !preg_match('~^https://[a-z0-9]+\.execute-api\.eu-west-1\.amazonaws\.com/outbound$~D', $c['outboundUrl'] ?? '')
            || ($c['expiresAt'] ?? 0) <= time() || $c['expiresAt'] > time()+96*3600) throw new RuntimeException('Invalid or expired isolated demo configuration');
        foreach (['appKey','messagesKey','indexKey'] as $key) {
            if (!str_starts_with($c[$key] ?? '', 'base64:') || strlen(base64_decode(substr($c[$key],7),true) ?: '') !== 32) throw new RuntimeException('Fresh stage encryption keys required');
        }
        return self::$values = $c;
    }
}

/** Only explicitly selected metadata can reach the evidence file. No prompts, SQL, headers or phone numbers. */
final class BridgeLiveAudit
{
    public static function write(string $event, array $fields = []): void
    {
        if (!in_array($event, ['bedrock_attempt','bedrock_result','bedrock_failed','demo_usage'], true)) throw new RuntimeException('Unknown audit event');
        $safe=['event'=>$event,'runId'=>BridgeLiveConfig::$values['runId'],'time'=>gmdate('c')];
        foreach (['requestId','eventId','modelId'] as $key) {
            $value=$fields[$key] ?? null;
            if (is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$value)) $safe[$key]=$value;
        }
        foreach (['httpStatus','inputTokens','outputTokens','tokens'] as $key) if (isset($fields[$key]) && is_int($fields[$key]) && $fields[$key]>=0) $safe[$key]=$fields[$key];
        $old=umask(0077);
        try { if (file_put_contents(BridgeLiveConfig::DATA.'/evidence.ndjson',json_encode($safe,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false) throw new RuntimeException('Evidence persistence failed'); }
        finally { umask($old); }
    }
}

/** Real AWS SDK, standard signed HTTPS transport. The observer does not replace the HTTP handler. */
final class BridgeLiveBedrock extends App\Services\BedrockService
{
    private bool $observed=false;
    protected function client(): Aws\BedrockRuntime\BedrockRuntimeClient
    {
        $client=parent::client();
        if (!$this->observed) {
            $this->observed=true;
            $client->getHandlerList()->appendInit(function (callable $next): callable {
                return function ($command, $request=null) use ($next) {
                    $model=$command['modelId'] ?? '';
                    if ($command->getName()!=='Converse' || !in_array($model,['eu.amazon.nova-lite-v1:0','eu.amazon.nova-micro-v1:0'],true)
                        || time()>=BridgeLiveConfig::$values['expiresAt'] || (BridgeLiveConfig::$values['enabled'] ?? false)!==true) throw new RuntimeException('Demo model or activation denied');
                    // Limits apply to this dedicated demo, not customer credits or an AWS spending guarantee.
                    $budget=fopen(BridgeLiveConfig::DATA.'/model-attempts','c+');
                    if (!$budget || !flock($budget,LOCK_EX)) throw new RuntimeException('Model budget unavailable');
                    try {
                        $used=(int)stream_get_contents($budget);
                        if ($used>=40) throw new RuntimeException('Demo model attempt limit');
                        rewind($budget);ftruncate($budget,0);fwrite($budget,(string)($used+1));fflush($budget);
                    } finally { flock($budget,LOCK_UN);fclose($budget); }
                    $command['inferenceConfig']['maxTokens']=min(2048,max(1,(int)($command['inferenceConfig']['maxTokens'] ?? 1024)));
                    $scope=app(MuseRelay\AwsEngagementBridge\Core\Scope::class);
                    $fields=['modelId'=>$model,'eventId'=>$scope->event->id ?? ''];
                    BridgeLiveAudit::write('bedrock_attempt',$fields);
                    return $next($command,$request)->then(function ($result) use ($fields) {
                        $meta=$result['@metadata'] ?? [];
                        BridgeLiveAudit::write('bedrock_result',$fields+[
                            'requestId'=>$meta['headers']['x-amzn-requestid'] ?? $meta['requestId'] ?? '',
                            'httpStatus'=>(int)($meta['statusCode'] ?? 0),
                            'inputTokens'=>(int)($result['usage']['inputTokens'] ?? 0),'outputTokens'=>(int)($result['usage']['outputTokens'] ?? 0)]);
                        return $result;
                    },function ($error) use ($fields) {
                        BridgeLiveAudit::write('bedrock_failed',$fields);
                        return GuzzleHttp\Promise\Create::rejectionFor($error);
                    });
                };
            },'bridge-live-evidence');
        }
        return $client;
    }
}

/** Locks both primary and native classifier to Bedrock; no alternative cloud provider fallback. */
final class BridgeLiveModelFactory extends App\Services\LLM\AgentLLMFactory
{
    private function model(string $name): App\AI\Tools\Contracts\ToolCallableModelInterface
    {
        $provider=App\Models\AIProvider::findOrFail(40);
        if ((int)$provider->organization_id!==97101 || $provider->provider_type!=='aws_bedrock') throw new RuntimeException('Dedicated demo provider required');
        $credentials=BridgeLiveConfig::protectedJson('/etc/muserelay/aws-bridge/bedrock-stage.json');
        if (($credentials['aws_region'] ?? '')!=='eu-west-1' || empty($credentials['aws_access_key_id']) || empty($credentials['aws_secret_access_key'])
            || empty($credentials['aws_session_token'])) throw new RuntimeException('Dedicated temporary Bedrock credentials required');
        $provider->credentials=$credentials; // In-memory only. Never persisted or taken from production.
        return new App\Services\LLM\BedrockToolCallableModel(new BridgeLiveBedrock($provider),$name);
    }
    public function forAgent(App\Models\AIAgent $agent,array $context=[]): App\AI\Tools\Contracts\ToolCallableModelInterface
    {
        if ((int)$agent->id!==30 || (int)$agent->organization_id!==97101 || (int)$agent->ai_provider_id!==40 || $agent->model_name!=='nova-lite') throw new RuntimeException('Dedicated demo agent required');
        return $this->model('nova-lite');
    }
    public function forClassifierGuard(?App\Models\AIAgent $agentForFallback=null): ?App\AI\Tools\Contracts\ToolCallableModelInterface { return $this->model('nova-micro'); }
    public function forInboxAssistant(): ?App\AI\Tools\Contracts\ToolCallableModelInterface { return $this->model('nova-micro'); }
    public function forToolEscalation(App\Models\AIAgent $agent,array $context=[]): ?App\AI\Tools\Contracts\ToolCallableModelInterface { return null; }
}

/** Demo accounting bypass for the synthetic organization only. AWS calls still incur actual costs. */
final class BridgeLiveUsage extends App\Services\AIAgentUsageTracker
{
    public function checkCreditsOrFail(int $organizationId): bool
    { if ($organizationId!==97101) throw new RuntimeException('Demo organization required');return true; }
    public function recordUsage($aiAgentId,array $usageData): void
    { if ((int)$aiAgentId!==30) throw new RuntimeException('Demo agent required');BridgeLiveAudit::write('demo_usage',['tokens'=>(int)($usageData['tokens_total'] ?? 0)]); }
}

final class BridgeLiveDrainJob implements Illuminate\Contracts\Queue\ShouldQueue
{
    use Illuminate\Bus\Queueable,Illuminate\Queue\InteractsWithQueue,Illuminate\Queue\SerializesModels;
    public int $tries=1;
    public int $timeout=480;
    public function handle(): void
    {
        try { Illuminate\Support\Facades\Artisan::call('aws-bridge:drain',['--limit'=>1]); }
        finally { Illuminate\Support\Facades\Cache::forget('phase4_drain_queued'); }
    }
}
