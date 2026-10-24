<?php
declare(strict_types=1);

final class BridgeStageKeys implements MuseRelay\AwsEngagementBridge\Core\KeySource
{
    public const CORE = 'synthetic-stage-core-key-no-live-credentials';
    public const OUTBOUND = 'synthetic-stage-outbound-key-independent-only';
    public function get(string $binding, string $direction): string { return $direction === 'core' ? self::CORE : self::OUTBOUND; }
}

final class BridgeStageBedrock extends App\Services\BedrockService
{
    public function __construct()
    {
        parent::__construct();
        $this->runtime = new Aws\BedrockRuntime\BedrockRuntimeClient([
            'version' => 'latest', 'region' => 'eu-west-1', 'credentials' => false, 'retries' => 0,
            'http_handler' => function ($request) {
                $params = json_decode((string) $request->getBody(), true, 64, JSON_THROW_ON_ERROR);
                $messages = $params['messages']; $last = end($messages);
                $toolResult = $last['content'][0]['toolResult'] ?? null;
                if (empty($params['toolConfig']['tools'])) {
                    $system = json_encode($params['system'] ?? []);
                    $answer = str_contains($system, 'ACCEPTS a proposal') ? '{"answer":"yes"}' : '{"intent":"none","confidence":1,"has_claim":false}';
                    $content = [['text' => $answer]]; $reason = 'end_turn';
                } elseif ($toolResult) {
                    $result = json_decode($toolResult['content'][0]['text'], true) ?? [];
                    $ok = $result['ok'] ?? $result['success'] ?? false;
                    $text = $ok ? 'Solicitud registrada. Referencia: '.($result['number'] ?? $result['data']['number'] ?? 'DEMO') : '¿Quieres que registre esta solicitud?';
                    $content = [['text' => $text]]; $reason = 'end_turn';
                } else {
                    $content = [['toolUse' => ['toolUseId' => 'stage-ticket-tool', 'name' => 'support_create_ticket', 'input' => [
                        'subject' => 'Synthetic museum accessibility request', 'description' => 'Synthetic demonstration only; no booking or charge.',
                        'confirmation' => 'confirmed', 'customer_name' => 'Demo Visitor',
                    ]]]]; $reason = 'tool_use';
                }
                file_put_contents(getenv('BRIDGE_STAGE_ROOT').'/bedrock.ndjson', json_encode(['operation' => 'Converse', 'tools' => array_column(array_column($params['toolConfig']['tools'] ?? [], 'toolSpec'), 'name'), 'stopReason' => $reason])."\n", FILE_APPEND | LOCK_EX);
                return GuzzleHttp\Promise\Create::promiseFor(new GuzzleHttp\Psr7\Response(200, ['content-type' => 'application/json'], json_encode([
                    'output' => ['message' => ['role' => 'assistant', 'content' => $content]], 'stopReason' => $reason,
                    'usage' => ['inputTokens' => 10, 'outputTokens' => 10, 'totalTokens' => 20], 'metrics' => ['latencyMs' => 1],
                ])));
            },
        ]);
    }
}

final class BridgeStageModelFactory extends App\Services\LLM\AgentLLMFactory
{
    public function forAgent(App\Models\AIAgent $agent, array $context = []): App\AI\Tools\Contracts\ToolCallableModelInterface
    {
        if ($agent->ai_provider_id !== 40 && (int) $agent->ai_provider_id !== 40) throw new RuntimeException('Synthetic Bedrock provider required');
        return new App\Services\LLM\BedrockToolCallableModel(new BridgeStageBedrock(), 'nova-lite');
    }
    public function forClassifierGuard(?App\Models\AIAgent $agentForFallback = null): ?App\AI\Tools\Contracts\ToolCallableModelInterface
    { return new App\Services\LLM\BedrockToolCallableModel(new BridgeStageBedrock(), 'nova-micro'); }
    public function forInboxAssistant(): ?App\AI\Tools\Contracts\ToolCallableModelInterface
    { return $this->forClassifierGuard(); }
    public function forToolEscalation(App\Models\AIAgent $agent, array $context = []): ?App\AI\Tools\Contracts\ToolCallableModelInterface { return null; }
}

final class BridgeStageDrainJob implements Illuminate\Contracts\Queue\ShouldQueue
{
    use Illuminate\Bus\Queueable, Illuminate\Queue\InteractsWithQueue, Illuminate\Queue\SerializesModels;
    public int $tries = 1;
    public function handle(): void { Illuminate\Support\Facades\Artisan::call('aws-bridge:drain', ['--limit' => 10]); }
}

/** Billing is a controlled local simulation; this harness does not validate charging. */
final class BridgeStageUsage extends App\Services\AIAgentUsageTracker
{
    public function checkCreditsOrFail(int $organizationId): bool
    {
        if ($organizationId !== 97101) throw new RuntimeException('Unknown staging organization');
        return true;
    }
    public function recordUsage($aiAgentId, array $usageData): void
    {
        file_put_contents(getenv('BRIDGE_STAGE_ROOT').'/usage.ndjson', json_encode(['agent_id'=>$aiAgentId,'tokens'=>$usageData['tokens_total'] ?? 0])."\n", FILE_APPEND | LOCK_EX);
    }
}
