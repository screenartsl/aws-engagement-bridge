<?php
declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use MuseRelay\AwsEngagementBridge\BridgeSignature;
use MuseRelay\AwsEngagementBridge\Core\{BridgeException, Controller, CorePort, KeySource, Outbox, Protocol, Publisher, Schema, Scope, SigningKeys, Store, ToolGuard, Worker};
use PHPUnit\Framework\TestCase;

final class FixtureCore implements CorePort
{
    public int $turns = 0;
    public int $received = 0;
    public int $outgoingCalls = 0;
    public array $receipts = [];
    public array $statuses = [];
    public ?Closure $onTurn = null;
    public bool $blocked = false;
    public function __construct(private Store $store) {}
    public function incoming(object $b, array $event): array
    {
        $this->received++;
        $conversation = $this->store->db->table('test_conversations')->insertGetId(['workspace_id' => $b->workspace_id]);
        $message = $this->store->db->table('test_messages')->insertGetId(['conversation_id' => $conversation, 'content' => $this->store->encode(['text' => $event['text']])]);
        return ['message_id' => $message, 'conversation_id' => $conversation];
    }
    public function turn(object $b, object $event): void { $this->turns++; if ($this->onTurn) ($this->onTurn)($b, $event); }
    public function authorizeTurn(object $b, object $event): void { if ($this->blocked) throw new BridgeException('conversation_paused', 409); }
    public function authorizeOutgoing(object $b, object $outbox, array $command): void { if ($this->blocked) throw new BridgeException('conversation_paused', 409); }
    public function outgoing(object $b, string $recipient, string $text, ?int $sourceId, ?int $userId): int
    {
        $this->outgoingCalls++;
        return $this->store->db->table('test_messages')->insertGetId(['conversation_id' => 1, 'content' => $this->store->encode(['text' => $text])]);
    }
    public function receipt(object $b, object $outbox, array $receipt): void { $this->receipts[] = $receipt; }
    public function status(object $b, array $event): void { $this->statuses[] = $event; }
}

final class CoreTest extends TestCase
{
    private Capsule $capsule;
    private Store $store;
    private FixtureCore $core;
    private Outbox $outbox;
    private Scope $scope;
    private int $now = 1792860000;
    private string $secret = 'synthetic-core-signing-key-no-real-credentials';

    protected function setUp(): void
    {
        $app = new Container(); Container::setInstance($app); Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $app->instance('config', new Repository(['app' => ['key' => 'synthetic-only', 'cipher' => 'AES-256-CBC'], 'services' => ['telnyx' => ['api_key' => 'test-only']]]));
        $app->instance('log', new Psr\Log\NullLogger());
        $app->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $this->capsule = new Capsule($app);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $this->capsule->setAsGlobal(); $this->capsule->bootEloquent();
        $db = $this->capsule->getConnection();
        self::assertSame('sqlite', $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
        $s = $db->getSchemaBuilder();
        $s->create('workspaces', function (Blueprint $t) { $t->integer('id')->primary(); $t->integer('organization_id'); });
        $s->create('channels', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->integer('organization_id'); $t->integer('workspace_id'); $t->integer('ai_agent_id');
            $t->string('type'); $t->string('status'); $t->string('managed_provider');
        });
        $s->create('ai_agents', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->integer('organization_id'); $t->integer('workspace_id'); $t->integer('ai_provider_id');
            $t->integer('fallback_provider_id')->nullable(); $t->integer('fallback2_provider_id')->nullable();
        });
        $s->create('ai_providers', function (Blueprint $t) { $t->integer('id')->primary(); $t->integer('organization_id')->nullable(); $t->string('provider_type'); });
        $s->create('test_conversations', function (Blueprint $t) { $t->increments('id'); $t->integer('workspace_id'); });
        $s->create('test_messages', function (Blueprint $t) { $t->increments('id'); $t->integer('conversation_id'); $t->text('content'); });
        $s->create('test_tickets', function (Blueprint $t) { $t->increments('id'); $t->string('subject'); });
        // These are temporary in-memory test tables, never an application migration command.
        Schema::create($s);
        $db->table('workspaces')->insert(['id' => 10, 'organization_id' => 1]);
        $db->table('channels')->insert(['id' => 20, 'organization_id' => 1, 'workspace_id' => 10, 'ai_agent_id' => 30, 'type' => 'whatsapp', 'status' => 'active', 'managed_provider' => 'aws_social']);
        $db->table('ai_agents')->insert(['id' => 30, 'organization_id' => 1, 'workspace_id' => 10, 'ai_provider_id' => 40]);
        $db->table('ai_providers')->insert(['id' => 40, 'organization_id' => null, 'provider_type' => 'aws_bedrock']);
        $db->table('aws_bridge_bindings')->insert(['id' => 'demo', 'channel_ref' => 'demo-channel', 'organization_id' => 1, 'workspace_id' => 10, 'channel_id' => 20, 'agent_id' => 30,
            'account_id' => '111111111111', 'region' => 'eu-west-1', 'waba_id' => '123456', 'meta_phone_id' => '789012',
            'aws_phone_arn' => 'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/abc123', 'allowed_recipients' => '["+12025550123"]', 'enabled' => true]);
        $this->store = new Store($db, $app['encrypter'], true, [1], fn () => $this->now);
        $this->core = new FixtureCore($this->store); $this->outbox = new Outbox($this->store, $this->core); $this->scope = new Scope();
    }

    private function event(string $id = 'wamid.synthetic1', string $kind = 'message'): array
    {
        $parts = ['demo', $kind, $id]; if ($kind === 'status') $parts = array_merge($parts, ['read', (string) $this->now]);
        return ['version' => 1, 'eventId' => hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES)), 'bindingId' => 'demo', 'channelRef' => 'demo-channel',
            'resource' => ['accountId' => '111111111111', 'region' => 'eu-west-1', 'wabaId' => '123456', 'metaPhoneNumberId' => '789012', 'awsPhoneNumberArn' => 'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/abc123'],
            'kind' => $kind, 'providerMessageId' => $id, 'timestamp' => $this->now] + ($kind === 'message' ? ['sender' => '+12025550123', 'text' => 'Synthetic activity request'] : ['status' => 'read']);
    }
    private function context(object $event): array { return ['channel_id' => 20, 'organization_id' => 1, 'workspace_id' => 10, 'conversation_id' => $event->conversation_id]; }
    private function controller(): Controller
    {
        $secret = $this->secret;
        $keys = new class($secret) implements KeySource { public function __construct(private string $key) {} public function get(string $b, string $d): string { return $this->key; } };
        return new Controller($this->store, $this->outbox, $keys);
    }
    private function request(array $event): Request
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $path = '/api/aws-engagement-bridge/events';
        $headers = BridgeSignature::sign($this->secret, 'POST', $path, $body, $this->now);
        $r = Request::create($path, 'POST', [], [], [], [], $body);
        $r->headers->set('x-bridge-binding', 'demo');
        foreach ($headers as $key => $value) $r->headers->set('x-bridge-'.$key, $value);
        return $r;
    }

    public function testPersistentEventDeduplicationAndPayloadConflict(): void
    {
        self::assertSame('accepted', $this->store->accept('demo', $this->event())['status']);
        self::assertSame('duplicate', $this->store->accept('demo', $this->event())['status']);
        self::assertSame(1, $this->store->db->table('aws_bridge_events')->count());
        $raw = $this->store->db->table('aws_bridge_events')->first()->payload;
        self::assertStringNotContainsString('Synthetic activity', $raw);
        $this->expectExceptionMessage('idempotency_conflict');
        $this->store->accept('demo', array_merge($this->event(), ['text' => 'changed']));
    }
    public function testOwnershipMismatchRejectsBeforePersisting(): void
    {
        $this->store->db->table('channels')->where('id', 20)->update(['workspace_id' => 999]);
        $this->expectExceptionMessage('ownership_mismatch'); $this->store->accept('demo', $this->event());
    }
    public function testCrossAccountAwsIdentityIsRejected(): void
    {
        $this->store->db->table('aws_bridge_bindings')->update(['aws_phone_arn' => 'arn:aws:social-messaging:eu-west-1:222222222222:phone-number-id/abc']);
        $this->expectExceptionMessage('invalid_aws_identity'); $this->store->binding('demo');
    }
    public function testNonBedrockAgentCannotRunThisDemo(): void
    {
        $this->store->db->table('ai_providers')->update(['provider_type' => 'azure_openai']);
        $this->expectExceptionMessage('bedrock_agent_required'); $this->store->binding('demo');
    }
    public function testSignedResourceTupleMustMatchTheCoreBinding(): void
    {
        $event = $this->event(); $event['resource']['wabaId'] = '999999';
        $this->expectExceptionMessage('aws_resource_mismatch'); $this->store->accept('demo', $event);
    }
    public function testDisabledFeatureNeverQueriesModuleTables(): void
    {
        Schema::drop($this->store->db->getSchemaBuilder());
        $disabled = new Store($this->store->db, Container::getInstance()['encrypter'], false, []);
        self::assertNull($disabled->claim()); self::assertFalse((new Outbox($disabled, $this->core))->publishOne(fn () => self::fail('must not publish')));
        $this->expectExceptionMessage('bridge_disabled'); $disabled->binding('demo');
    }
    public function testEnabledBindingStillRequiresOrganizationOptIn(): void
    {
        $disabled = new Store($this->store->db, Container::getInstance()['encrypter'], true, [2]);
        $this->expectExceptionMessage('binding_disabled'); $disabled->binding('demo');
    }
    public function testHttpSignatureReplayAndFreshNonceDuplicate(): void
    {
        $r = $this->request($this->event()); $controller = $this->controller();
        self::assertSame(202, $controller->events($r)->getStatusCode());
        self::assertSame(409, $controller->events($r)->getStatusCode());
        self::assertSame(200, $controller->events($this->request($this->event()))->getStatusCode());
    }
    public function testBadHttpSignatureCannotCreateAnEvent(): void
    {
        $r = $this->request($this->event()); $r->headers->set('x-bridge-signature', str_repeat('0', 64));
        self::assertSame(401, $this->controller()->events($r)->getStatusCode());
        self::assertSame(0, $this->store->db->table('aws_bridge_events')->count());
    }
    public function testTamperedEventIdAndUnallowlistedSenderAreRejected(): void
    {
        foreach ([['eventId' => str_repeat('0', 64)], ['sender' => '+12025550124'], ['bindingId' => 'other']] as $change) {
            try { $this->store->accept('demo', array_merge($this->event(), $change)); self::fail('rejected'); } catch (BridgeException) {}
        }
        self::assertSame(0, $this->store->db->table('aws_bridge_events')->count());
    }
    public function testWorkerCreatesOneConversationAndDoesNotRerunDuplicateEvents(): void
    {
        $this->store->accept('demo', $this->event()); $this->store->accept('demo', $this->event());
        $worker = new Worker($this->store, $this->core, $this->scope);
        self::assertTrue($worker->once()); self::assertFalse($worker->once());
        self::assertSame(1, $this->core->received); self::assertSame(1, $this->core->turns); self::assertNull($this->scope->event);
    }
    public function testEqualTimestampEventsKeepTheirAcceptedOrderInsteadOfHashOrder(): void
    {
        $this->store->accept('demo', $this->event('wamid.first'));
        $this->store->accept('demo', $this->event('wamid.second'));
        $first = $this->store->claim(); self::assertSame('wamid.first', $this->store->decode($first->payload)['providerMessageId']);
        $this->store->finish($first, true);
        $second = $this->store->claim(); self::assertSame('wamid.second', $this->store->decode($second->payload)['providerMessageId']);
    }
    public function testStatusesNeverInvokeTheAgent(): void
    {
        $this->store->accept('demo', $this->event('wamid.out', 'status'));
        (new Worker($this->store, $this->core, $this->scope))->once();
        self::assertSame(0, $this->core->turns); self::assertCount(1, $this->core->statuses);
    }
    public function testTicketCheckpointSurvivesAnInterruptedTurnWithoutDuplicatingTheAction(): void
    {
        $this->store->accept('demo', $this->event());
        $guard = new ToolGuard($this->store, $this->scope, $this->core); $attempt = 0;
        $this->core->onTurn = function (object $b, object $event) use ($guard, &$attempt): void {
            $result = $guard->execute('support_create_ticket', $this->context($event), function (array $ctx): array {
                self::assertGreaterThan(0, $ctx['message_id']);
                $id = $this->store->db->table('test_tickets')->insertGetId(['subject' => 'Synthetic registration request']);
                return ['ok' => true, 'number' => 'TEST-'.$id];
            });
            self::assertTrue($result['ok']);
            if (++$attempt === 1) throw new RuntimeException('simulated crash after committed business action');
            $this->outbox->enqueue($b, '+12025550123', 'Synthetic request registered', (int) $event->message_id, null);
        };
        $worker = new Worker($this->store, $this->core, $this->scope); $worker->once(); $worker->once();
        self::assertSame(2, $attempt); self::assertSame(1, $this->store->db->table('test_tickets')->count());
        self::assertSame(1, $this->core->received); self::assertSame(1, $this->store->db->table('aws_bridge_outbox')->count());
    }
    public function testActionAndCheckpointRollbackTogether(): void
    {
        $this->store->accept('demo', $this->event()); $row = $this->store->claim();
        try { $this->store->action($row, 'support_create_ticket', function () {
            $this->store->db->table('test_tickets')->insert(['subject' => 'Synthetic rollback']); throw new RuntimeException('simulated_db_failure');
        }); self::fail('must throw'); } catch (RuntimeException $e) { self::assertSame('simulated_db_failure', $e->getMessage()); }
        self::assertSame(0, $this->store->db->table('test_tickets')->count()); self::assertSame(0, $this->store->db->table('aws_bridge_actions')->count());
    }
    public function testSourceMessageReenqueueUsesSameOutboxCommandAndMessage(): void
    {
        $b = $this->store->binding('demo');
        $a = $this->outbox->enqueue($b, '+12025550123', 'Synthetic reply', 123, null);
        $again = $this->outbox->enqueue($b, '+12025550123', 'different LLM wording after retry', 123, null);
        self::assertSame($a['message_id'], $again['message_id']); self::assertSame(1, $this->core->outgoingCalls);
    }
    public function testUnallowlistedOutgoingRecipientDoesNotCreateAMessage(): void
    {
        try { $this->outbox->enqueue($this->store->binding('demo'), '+12025550124', 'Synthetic', null, null); self::fail('must reject'); }
        catch (BridgeException $e) { self::assertSame('recipient_not_allowed', $e->reason); }
        self::assertSame(0, $this->core->outgoingCalls);
    }
    public function testReceiptArrivingDuringPublishIsNotOverwrittenByQueueAcknowledgement(): void
    {
        $out = $this->outbox->enqueue($this->store->binding('demo'), '+12025550123', 'Synthetic', 123, null);
        $this->outbox->publishOne(function ($binding, $command): void {
            $this->outbox->receipt($binding, ['version' => 1, 'bindingId' => $binding, 'commandId' => $command['commandId'], 'state' => 'accepted', 'providerMessageId' => 'aws.synthetic']);
        });
        self::assertSame('accepted', $this->store->db->table('aws_bridge_outbox')->where('id', $out['command_id'])->value('state'));
    }
    public function testPolicyIsRecheckedBeforePublishingAPreviouslyQueuedReply(): void
    {
        $out = $this->outbox->enqueue($this->store->binding('demo'), '+12025550123', 'Synthetic', 123, null);
        $this->core->blocked = true;
        $this->outbox->publishOne(fn () => self::fail('must not publish after policy blocks'));
        self::assertSame('blocked', $this->store->db->table('aws_bridge_outbox')->where('id', $out['command_id'])->value('state'));
        self::assertSame('rejected', $this->core->receipts[0]['state']);
    }
    public function testWorkerRetriesAreBoundedAndScopeIsAlwaysCleared(): void
    {
        $this->store->accept('demo', $this->event());
        $this->core->onTurn = fn () => throw new RuntimeException('synthetic_failure');
        $worker = new Worker($this->store, $this->core, $this->scope);
        for ($i = 0; $i < 3; $i++) self::assertTrue($worker->once());
        self::assertFalse($worker->once()); self::assertSame(3, $this->core->turns); self::assertNull($this->scope->binding);
        self::assertSame('failed', $this->store->db->table('aws_bridge_events')->first()->state);
    }
    public function testPublicationRetryRetainsCommandIdentityAndReceiptCannotBeDowngraded(): void
    {
        $b = $this->store->binding('demo'); $out = $this->outbox->enqueue($b, '+12025550123', 'Synthetic', 456, null);
        $ids = [];
        $this->outbox->publishOne(function ($binding, $command) use (&$ids) { $ids[] = $command['commandId']; throw new RuntimeException('simulated_timeout'); });
        $this->now += 31;
        $this->outbox->publishOne(function ($binding, $command) use (&$ids) { $ids[] = $command['commandId']; });
        self::assertSame([$out['command_id'], $out['command_id']], $ids);
        $receipt = ['version' => 1, 'bindingId' => 'demo', 'commandId' => $out['command_id'], 'state' => 'accepted', 'providerMessageId' => 'aws.synthetic'];
        $this->outbox->receipt('demo', $receipt); $this->outbox->receipt('demo', $receipt);
        $this->expectExceptionMessage('receipt_conflict'); $this->outbox->receipt('demo', array_merge($receipt, ['state' => 'uncertain']));
    }
    public function testConversationLeaseSerializesBindingAndCanRecoverAfterCrash(): void
    {
        $this->store->accept('demo', $this->event()); $first = $this->store->claim(); self::assertNotNull($first);
        self::assertNull($this->store->claim()); $this->now += 601;
        $second = $this->store->claim(); self::assertNotNull($second); self::assertNotSame($first->lease_token, $second->lease_token);
        $this->expectExceptionMessage('event_lease_lost'); $this->store->finish($first, true);
    }
    public function testUnsupportedOrCrossTenantToolsCannotExecute(): void
    {
        $this->store->accept('demo', $this->event()); $row = $this->store->claim();
        $this->scope->binding = $this->store->binding('demo'); $this->scope->event = $row;
        $guard = new ToolGuard($this->store, $this->scope, $this->core); $called = false;
        $dispatch = function () use (&$called) { $called = true; return ['ok' => true]; };
        self::assertFalse($guard->execute('calendar_book_appointment', $this->context($row), $dispatch)['ok']);
        self::assertFalse($guard->execute('support_create_ticket', array_merge($this->context($row), ['workspace_id' => 999]), $dispatch)['ok']);
        self::assertFalse($called);
    }
    public function testDailyBudgetsBlockAdditionalNewWorkButNotDuplicates(): void
    {
        $this->store->db->table('aws_bridge_bindings')->update(['daily_inbound_limit' => 1, 'daily_outbound_limit' => 1]);
        $this->store->accept('demo', $this->event()); self::assertSame('duplicate', $this->store->accept('demo', $this->event())['status']);
        try { $this->store->accept('demo', $this->event('wamid.two')); self::fail('must reject'); } catch (BridgeException $e) { self::assertSame(429, $e->httpStatus); }
        $b = $this->store->binding('demo'); $this->outbox->enqueue($b, '+12025550123', 'Synthetic one', 1, null);
        $this->expectExceptionMessage('daily_outbound_limit'); $this->outbox->enqueue($b, '+12025550123', 'Synthetic two', 2, null);
    }
    public function testSigningKeysCannotComeFromDevelopmentOrReplicatedDirectory(): void
    {
        $this->expectExceptionMessage('protected_signing_keys_required');
        (new SigningKeys(__FILE__))->get('demo', 'core');
    }
    public function testPublisherSignsExactCommandBytesAgainstAFakeHttpTransport(): void
    {
        $secret = $this->secret;
        $keys = new class($secret) implements KeySource { public function __construct(private string $secret) {} public function get(string $binding, string $direction): string { return $this->secret; } };
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function ($request) use ($secret) {
            BridgeSignature::verify($secret, 'POST', '/outbound', $request->body(), [
                'timestamp' => $request->header('x-bridge-timestamp')[0], 'nonce' => $request->header('x-bridge-nonce')[0], 'signature' => $request->header('x-bridge-signature')[0],
            ]);
            return Illuminate\Support\Facades\Http::response(['id' => 'synthetic-command', 'status' => 'accepted'], 202);
        });
        (new Publisher('https://synthetic.execute-api.eu-west-1.amazonaws.com/outbound', $keys))->publish('demo', ['commandId' => 'synthetic-command']);
        Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->expectExceptionMessage('invalid_outbound_url');
        (new Publisher('https://attacker.invalid/outbound', $keys))->publish('demo', ['commandId' => 'synthetic-command']);
    }
    public function testActualLegacyMetaAndTelnyxConfigurationBranchesRemainIntact(): void
    {
        $sender = new App\Services\WhatsAppSenderService();
        $method = new ReflectionMethod($sender, 'getChannelConfig');
        foreach (['meta', 'telnyx', null] as $provider) {
            $channel = new App\Models\Channel();
            $channel->setRawAttributes(['id' => 20, 'type' => 'whatsapp', 'status' => 'active', 'connection_mode' => $provider === null ? 'byoa' : 'managed', 'managed_provider' => $provider,
                'configuration' => json_encode(['phone_number_id' => 'test-phone', 'business_account_id' => 'test-waba', 'access_token' => 'test-token', 'api_key' => 'test-key', 'from_phone_number' => '+12025550123'])]);
            $config = $method->invoke($sender, $channel);
            self::assertSame($provider === 'telnyx' ? 'managed_telnyx' : 'byoa', $config['mode']);
        }
        $aws = new App\Models\Channel(); $aws->setRawAttributes(['type' => 'whatsapp', 'managed_provider' => 'aws_social']);
        self::assertNull($method->invoke($sender, $aws));
    }
}
