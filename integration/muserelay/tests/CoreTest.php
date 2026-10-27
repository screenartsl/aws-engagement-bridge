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
use MuseRelay\AwsEngagementBridge\Core\{BridgeException, Controller, CorePort, KeySource, Notifier, Outbox, Protocol, Publisher, Schema, Scope, SigningKeys, Store, ToolGuard, Worker};
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
    public ?array $lastTemplate = null;
    public ?array $lastMedia = null;
    public function outgoing(object $b, string $recipient, string $text, ?int $sourceId, ?int $userId, ?array $template = null, ?array $media = null): int
    {
        $this->outgoingCalls++; $this->lastTemplate = $template; $this->lastMedia = $media;
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
    private array $controls = ['receive'=>true, 'agent'=>true, 'send'=>true];

    protected function setUp(): void
    {
        $app = new Container(); Container::setInstance($app); Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $app->instance('config', new Repository(['app' => ['key' => 'synthetic-only', 'cipher' => 'AES-256-CBC'], 'services' => ['telnyx' => ['api_key' => 'test-only']]]));
        $app->instance('log', new Psr\Log\NullLogger());
        $app->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $this->capsule = new Capsule($app);
        $pgSocket = getenv('BRIDGE_TEST_PG_SOCKET');
        if ($pgSocket) {
            $root = realpath(dirname($pgSocket));
            if (!$root || !str_starts_with($root, '/tmp/muserelay-bridge-phase3-') || !is_file($root.'/.bridge-staging') || $pgSocket !== $root.'/pgsock') throw new RuntimeException('Disposable PostgreSQL socket required');
            $this->capsule->addConnection(['driver' => 'pgsql', 'host' => $pgSocket, 'port' => 55432, 'database' => 'bridge_unit_test', 'username' => 'bridge_stage', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'disable']);
        } else {
            $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        }
        $this->capsule->setAsGlobal(); $this->capsule->bootEloquent();
        $app->instance('db', $this->capsule->getDatabaseManager());
        $app->bind('db.schema', fn () => $this->capsule->getConnection()->getSchemaBuilder());
        $db = $this->capsule->getConnection();
        self::assertSame($pgSocket ? 'pgsql' : 'sqlite', $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($pgSocket) {
            self::assertSame('bridge_unit_test', $db->selectOne('select current_database() db')->db);
            self::assertSame('bridge_stage', $db->selectOne('select current_user u')->u);
            $db->getSchemaBuilder()->dropAllTables(); // Only the fixed, private unit database above.
        }
        $s = $db->getSchemaBuilder();
        $s->create('workspaces', function (Blueprint $t) { $t->integer('id')->primary(); $t->integer('organization_id'); });
        $s->create('channels', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->integer('organization_id'); $t->integer('workspace_id'); $t->integer('ai_agent_id');
            $t->string('type'); $t->string('status'); $t->string('managed_provider');
        });
        $s->create('ai_agents', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->integer('organization_id'); $t->integer('workspace_id'); $t->integer('ai_provider_id');
            $t->integer('fallback_provider_id')->nullable(); $t->integer('fallback2_provider_id')->nullable();
            $t->integer('vision_provider_id')->nullable(); $t->integer('audio_provider_id')->nullable();
        });
        $s->create('ai_providers', function (Blueprint $t) { $t->integer('id')->primary(); $t->integer('organization_id')->nullable(); $t->string('provider_type'); });
        $s->create('test_conversations', function (Blueprint $t) { $t->increments('id'); $t->integer('workspace_id'); });
        $s->create('test_messages', function (Blueprint $t) { $t->increments('id'); $t->integer('conversation_id'); $t->text('content'); });
        $s->create('test_tickets', function (Blueprint $t) { $t->increments('id'); $t->string('subject'); });
        // Disposable synthetic tables, never an application migration command.
        Schema::create($s);
        Schema::createNotifications($s);
        Schema::addThreadKey($s);
        $db->table('workspaces')->insert(['id' => 10, 'organization_id' => 1]);
        $db->table('channels')->insert(['id' => 20, 'organization_id' => 1, 'workspace_id' => 10, 'ai_agent_id' => 30, 'type' => 'whatsapp', 'status' => 'active', 'managed_provider' => 'aws_social']);
        $db->table('ai_agents')->insert(['id' => 30, 'organization_id' => 1, 'workspace_id' => 10, 'ai_provider_id' => 40, 'vision_provider_id' => 40]);
        $db->table('ai_providers')->insert(['id' => 40, 'organization_id' => null, 'provider_type' => 'aws_bedrock']);
        $db->table('aws_bridge_bindings')->insert(['id' => 'demo', 'channel_ref' => 'demo-channel', 'organization_id' => 1, 'workspace_id' => 10, 'channel_id' => 20, 'agent_id' => 30,
            'account_id' => '111111111111', 'region' => 'eu-west-1', 'waba_id' => '123456', 'meta_phone_id' => '789012',
            'aws_phone_arn' => 'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/abc123', 'allowed_recipients' => '["+12025550123"]', 'enabled' => true]);
        $this->store = new Store($db, $app['encrypter'], true, [1], fn () => $this->now, fn () => $this->controls);
        $this->core = new FixtureCore($this->store); $this->outbox = new Outbox($this->store, $this->core); $this->scope = new Scope();
    }

    protected function tearDown(): void
    {
        $this->capsule->getConnection()->disconnect();
        parent::tearDown();
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
    public function testAwsPrefixedPhoneIdentifierIsAcceptedWithoutWeakeningAccountScope(): void
    {
        $arn = 'arn:aws:social-messaging:eu-west-1:111111111111:phone-number-id/phone-number-id-stageabc123';
        $this->store->db->table('aws_bridge_bindings')->update(['aws_phone_arn' => $arn]);
        $event = $this->event(); $event['resource']['awsPhoneNumberArn'] = $arn;
        self::assertSame('accepted', $this->store->accept('demo', $event)['status']);
        $this->store->db->table('aws_bridge_bindings')->update(['aws_phone_arn' => $arn.'/other']);
        $this->expectExceptionMessage('invalid_aws_identity'); $this->store->binding('demo');
    }
    public function testAgentWithoutBedrockVisionOrWithAnotherCloudForAudioCannotAnswer(): void
    {
        $db = $this->store->db; $db->table('ai_providers')->insert(['id' => 41, 'organization_id' => null, 'provider_type' => 'azure_openai']);
        foreach ([['vision_provider_id' => null], ['vision_provider_id' => 41], ['audio_provider_id' => 41]] as $change) {
            $db->table('ai_agents')->where('id', 30)->update(['vision_provider_id' => 40, 'audio_provider_id' => null]);
            $db->table('ai_agents')->where('id', 30)->update($change);
            try { $this->store->agentFor($this->store->binding('demo')); self::fail('accepted '.json_encode($change)); }
            catch (BridgeException $e) { self::assertSame('bedrock_agent_required', $e->reason); }
        }
        $db->table('ai_agents')->where('id', 30)->update(['vision_provider_id' => 40, 'audio_provider_id' => null]);
        self::assertSame(30, (int) $this->store->agentFor($this->store->binding('demo'))->id);
    }
    public function testNonBedrockAgentCannotRunThisDemo(): void
    {
        $this->store->db->table('ai_providers')->update(['provider_type' => 'azure_openai']);
        $this->expectExceptionMessage('bedrock_agent_required'); $this->store->agentFor($this->store->binding('demo'));
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
    public function testExistingStoreSeesDisconnectWithoutRestartAndStopsBeforeModuleQueries(): void
    {
        $enabled=true;$organizations=[1];
        $dynamic=new Store($this->store->db,Container::getInstance()['encrypter'],function() use (&$enabled){return $enabled;},function() use (&$organizations){return $organizations;});
        self::assertTrue($dynamic->isEnabled());
        $enabled=false;
        Schema::drop($this->store->db->getSchemaBuilder());
        self::assertNull($dynamic->claim());
        self::assertFalse((new Outbox($dynamic,$this->core))->publishOne(fn()=>self::fail('must not publish')));
        $this->expectExceptionMessage('bridge_disabled');$dynamic->accept('demo',$this->event());
    }
    public function testExistingStoreSeesOrganizationRevocationImmediately(): void
    {
        $organizations=[1];
        $dynamic=new Store($this->store->db,Container::getInstance()['encrypter'],true,function() use (&$organizations){return $organizations;});
        self::assertSame('demo',$dynamic->binding('demo')->id);
        $organizations=[2];
        $this->expectExceptionMessage('binding_disabled');$dynamic->binding('demo');
    }
    public function testHttpSignatureReplayAndFreshNonceDuplicate(): void
    {
        $r = $this->request($this->event()); $controller = $this->controller();
        self::assertSame(202, $controller->events($r)->getStatusCode());
        self::assertSame(409, $controller->events($r)->getStatusCode());
        self::assertSame(200, $controller->events($this->request($this->event()))->getStatusCode());
    }
    public function testDormantActivationNeedsNoBindingOrAwsSecretAndRejectsCallbacksBeforeNonceWrites(): void
    {
        $this->controls=['receive'=>false,'agent'=>false,'send'=>false];
        $this->store->db->table('aws_bridge_bindings')->delete();
        self::assertTrue($this->store->isEnabled());
        self::assertSame([], (new \MuseRelay\AwsEngagementBridge\Core\CoreReadiness())->problems());
        $response=$this->controller()->events($this->request($this->event()));
        self::assertSame(503,$response->getStatusCode());
        self::assertSame('bridge_receive_disabled',$response->getData(true)['error']);
        self::assertSame(0,$this->store->db->table('aws_bridge_nonces')->count());
        self::assertSame(0,$this->store->db->table('aws_bridge_events')->count());
    }
    public function testReceiveReadinessStillRequiresBinding(): void
    {
        config(['aws_bridge.receive_enabled'=>true]);
        $this->store->db->table('aws_bridge_bindings')->delete();
        self::assertSame(['demo_binding_missing'], (new \MuseRelay\AwsEngagementBridge\Core\CoreReadiness())->problems());
    }
    public function testReceiveReadinessStillRequiresSigningKey(): void
    {
        config(['aws_bridge.receive_enabled'=>true, 'aws_bridge.signing_keys_file'=>'']);
        $this->store->db->table('aws_bridge_bindings')->update(['account_id'=>'365470560685']);
        self::assertSame(['signing_keys_missing'], (new \MuseRelay\AwsEngagementBridge\Core\CoreReadiness())->problems());
    }
    public function testDormantReadinessStillRequiresInstalledSchema(): void
    {
        $this->store->db->getSchemaBuilder()->drop('aws_bridge_outbox');
        self::assertSame(['bridge_schema_missing'], (new \MuseRelay\AwsEngagementBridge\Core\CoreReadiness())->problems());
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
    public function testMediaEventCarriesOnlyAPresignedUrlForThisBindingsOwnCopy(): void
    {
        $event = $this->event('wamid.img'); unset($event['text']);
        $url = 'https://bridge-media.s3.eu-west-1.amazonaws.com/media/demo/'.$event['eventId'].'/m1.jpeg?X-Amz-Signature=synthetic';
        $media = ['id' => 'm1', 'mimeType' => 'image/jpeg', 'size' => 2048, 'url' => $url];
        $this->store->accept('demo', $event + ['messageType' => 'image', 'media' => $media]);
        $row = $this->store->db->table('aws_bridge_events')->first();
        self::assertSame($url, $this->store->decode($row->payload)['media']['url']);
        $bad = [['url' => 'http://bridge-media.s3.eu-west-1.amazonaws.com/media/demo/'.$event['eventId'].'?x'],
            ['url' => 'https://bridge-media.s3.eu-west-1.amazonaws.com/media/other/'.$event['eventId'].'?x'],
            ['url' => 'https://attacker.example/media/demo/'.$event['eventId'].'?x'],
            ['url' => 'https://bridge-media.s3.us-east-1.amazonaws.com/media/demo/'.$event['eventId'].'?x'],
            ['url' => 'https://bridge-media.s3.eu-west-1.amazonaws.com/media/demo/'.$event['eventId'].'/../x?x'],
            ['url' => 'https://bridge-media.s3.eu-west-1.amazonaws.com/media/demo/'.$event['eventId'].'?x'],
            ['size' => 6 * 1024 * 1024], ['mimeType' => 'application/zip'], ['id' => '../m1']];
        foreach ($bad as $i => $change) {
            $e = $this->event('wamid.bad'.$i); unset($e['text']);
            $m = array_merge($media, $change);
            if (isset($change['url']) || !isset($change['size'])) $m['url'] = str_replace($event['eventId'], $e['eventId'], $m['url']);
            try { $this->store->accept('demo', $e + ['messageType' => 'image', 'media' => $m]); self::fail('rejected '.$i); } catch (BridgeException) {}
        }
        // Oversized: reported without a URL, still accepted so the inbox shows it.
        $big = $this->event('wamid.big'); unset($big['text']);
        $this->store->accept('demo', $big + ['messageType' => 'image', 'media' => ['id' => 'm2', 'mimeType' => 'image/png', 'size' => 6 * 1024 * 1024]]);
        self::assertSame(2, $this->store->db->table('aws_bridge_events')->count());
    }
    public function testSenderProfileNameIsOptionalAndValidated(): void
    {
        $this->store->accept('demo', $this->event('wamid.named') + ['senderName' => 'Ana Pérez']);
        self::assertSame('Ana Pérez', $this->store->decode($this->store->db->table('aws_bridge_events')->first()->payload)['senderName']);
        foreach (["Ana\nPérez", str_repeat('x', 257), '  ', 7] as $i => $bad) {
            try { $this->store->accept('demo', $this->event('wamid.badname'.$i) + ['senderName' => $bad]); self::fail('accepted '.$i); } catch (BridgeException) {}
        }
    }
    public function testWorkerCreatesOneConversationAndDoesNotRerunDuplicateEvents(): void
    {
        $this->store->accept('demo', $this->event()); $this->store->accept('demo', $this->event());
        $worker = new Worker($this->store, $this->core, $this->scope);
        self::assertTrue($worker->once()); self::assertFalse($worker->once());
        self::assertSame(1, $this->core->received); self::assertSame(1, $this->core->turns); self::assertNull($this->scope->event);
    }
    public function testMissingOperationFlagsFailClosedBeforeModuleQueries(): void
    {
        $store = new Store($this->store->db, Container::getInstance()['encrypter'], true, [1]);
        Schema::drop($store->db->getSchemaBuilder());
        self::assertNull($store->claim());
        self::assertFalse((new Outbox($store,$this->core))->publishOne(fn()=>self::fail('must not publish')));
        $this->expectExceptionMessage('bridge_receive_disabled');$store->accept('demo',$this->event());
    }
    public function testReceiveOnlyNeedsNoAgentOrProviderAndDoesNotReplayWhenAgentIsLaterEnabled(): void
    {
        $this->controls=['receive'=>true,'agent'=>false,'send'=>false];
        $agent=(array)$this->store->db->table('ai_agents')->first();$provider=(array)$this->store->db->table('ai_providers')->first();
        $this->store->db->table('ai_agents')->delete();$this->store->db->table('ai_providers')->delete();
        self::assertSame('accepted',$this->store->accept('demo',$this->event())['status']);
        self::assertSame('duplicate',$this->store->accept('demo',$this->event())['status']);
        $worker=new Worker($this->store,$this->core,$this->scope);
        self::assertTrue($worker->once());self::assertFalse($worker->once());
        self::assertSame(1,$this->core->received);self::assertSame(0,$this->core->turns);
        self::assertSame(0,$this->store->db->table('aws_bridge_outbox')->count());
        $this->controls['agent']=true;
        $this->store->db->table('ai_agents')->insert($agent);$this->store->db->table('ai_providers')->insert($provider);
        // No pending rows: completed receive-only messages cannot suddenly trigger an old turn.
        self::assertFalse($worker->once());self::assertSame(0,$this->core->turns);
    }
    public function testAgentProblemsNeverBlockReceptionAndAgentComesFromTheChannel(): void
    {
        $this->controls=['receive'=>true,'agent'=>true,'send'=>false];
        self::assertSame(30,(int)$this->store->agentFor($this->store->binding('demo'))->id);
        // Agent unlinked from the channel in the panel: reception still works, only the AI turn is refused.
        $this->store->db->table('channels')->where('id',20)->update(['ai_agent_id'=>0]);
        self::assertSame('accepted',$this->store->accept('demo',$this->event())['status']);
        try { $this->store->agentFor($this->store->binding('demo')); self::fail('must reject'); } catch (BridgeException $e) { self::assertSame('ownership_mismatch',$e->reason); }
        // Non-Bedrock provider: same, reception unaffected.
        $this->store->db->table('channels')->where('id',20)->update(['ai_agent_id'=>30]);
        $this->store->db->table('ai_providers')->update(['provider_type'=>'openai']);
        self::assertSame('accepted',$this->store->accept('demo',$this->event('wamid.two'))['status']);
        $this->expectExceptionMessage('bedrock_agent_required'); $this->store->agentFor($this->store->binding('demo'));
    }
    public function testSendRevocationStopsPendingOutboxWithoutChangingItsState(): void
    {
        $out=$this->outbox->enqueue($this->store->binding('demo'),'+12025550123','Synthetic',123,null);
        $this->controls['send']=false;
        self::assertFalse($this->outbox->publishOne(fn()=>self::fail('must not publish')));
        self::assertSame('pending',$this->store->db->table('aws_bridge_outbox')->where('id',$out['command_id'])->value('state'));
        $this->expectExceptionMessage('bridge_send_disabled');
        $this->outbox->enqueue($this->store->binding('demo'),'+12025550123','Synthetic',null,99);
    }
    public function testHumanReplyDoesNotRequireAgentProvider(): void
    {
        $this->controls=['receive'=>false,'agent'=>false,'send'=>true];
        $this->store->db->table('ai_agents')->delete();$this->store->db->table('ai_providers')->delete();
        $out=$this->outbox->enqueue($this->store->binding('demo'),'+12025550123','Synthetic human reply',null,99);
        self::assertTrue($out['queued']);self::assertSame(0,$this->core->turns);
    }
    public function testAgentRevocationBlocksToolDispatchEvenInExistingScope(): void
    {
        $this->store->accept('demo',$this->event());$row=$this->store->claim();
        $this->scope->binding=$this->store->binding('demo');$this->scope->event=$row;
        $this->controls['agent']=false;
        $this->expectExceptionMessage('bridge_agent_disabled');
        (new ToolGuard($this->store,$this->scope,$this->core))->execute('support_create_ticket',$this->context($row),fn()=>self::fail('tool must not run'));
    }
    public function testStringTrueDoesNotEnableAnOperation(): void
    {
        $this->controls=['receive'=>'true','agent'=>'true','send'=>'true'];
        foreach (['receive','agent','send'] as $operation) self::assertFalse($this->store->operationEnabled($operation));
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
    public function testStatusCarriesTheEchoedCommandRefAndRejectsAForgedOne(): void
    {
        // Meta returns biz_opaque_callback_data (our outbox commandId): the only link from the wamid to the outbox row.
        $this->store->accept('demo', $this->event('wamid.out', 'status') + ['commandRef' => 'cmd_synthetic_0001']);
        (new Worker($this->store, $this->core, $this->scope))->once();
        self::assertSame('cmd_synthetic_0001', $this->core->statuses[0]['commandRef'] ?? null);
        $this->expectException(BridgeException::class);
        $this->store->accept('demo', $this->event('wamid.out2', 'status') + ['commandRef' => '../../x']);
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
    private function eventFrom(string $sender, string $id): array
    {
        $e = $this->event($id); $e['sender'] = $sender; return $e;
    }
    public function testDifferentConversationsRunInParallelAndEachKeepsItsOrder(): void
    {
        $this->store->db->table('aws_bridge_bindings')->where('id', 'demo')->update(['allowed_recipients' => '[]']);
        $this->store->accept('demo', $this->eventFrom('+34600000001', 'wamid.ana1'));
        $this->now += 1; $this->store->accept('demo', $this->eventFrom('+34600000001', 'wamid.ana2'));
        $this->now += 1; $this->store->accept('demo', $this->eventFrom('+34600000002', 'wamid.luis1'));
        $ana1 = $this->store->claim(); $luis = $this->store->claim();
        self::assertSame('wamid.ana1', $this->store->decode($ana1->payload)['providerMessageId']);
        self::assertSame('wamid.luis1', $this->store->decode($luis->payload)['providerMessageId'], 'Luis does not wait for Ana');
        self::assertNull($this->store->claim(), "Ana's second message waits for her first");
        $this->store->finish($ana1, true);
        self::assertSame('wamid.ana2', $this->store->decode($this->store->claim()->payload)['providerMessageId']);
    }
    public function testConversationLeaseSerializesBindingAndCanRecoverAfterCrash(): void
    {
        $this->store->accept('demo', $this->event()); $first = $this->store->claim(); self::assertNotNull($first);
        self::assertNull($this->store->claim()); $this->now += 601;
        $second = $this->store->claim(); self::assertNotNull($second); self::assertNotSame($first->lease_token, $second->lease_token);
        $this->expectExceptionMessage('event_lease_lost'); $this->store->finish($first, true);
    }
    public function testCrossTenantToolsCannotExecute(): void
    {
        $this->store->accept('demo', $this->event()); $row = $this->store->claim();
        $this->scope->binding = $this->store->binding('demo'); $this->scope->event = $row;
        $guard = new ToolGuard($this->store, $this->scope, $this->core); $called = false;
        $dispatch = function () use (&$called) { $called = true; return ['ok' => true]; };
        self::assertFalse($guard->execute('calendar_book_appointment', array_merge($this->context($row), ['organization_id' => 999]), $dispatch)['ok']);
        self::assertFalse($guard->execute('support_create_ticket', array_merge($this->context($row), ['workspace_id' => 999]), $dispatch)['ok']);
        self::assertFalse($called);
    }
    public function testAnyNativeToolRunsInContextAndIdenticalRetriesDoNotActTwice(): void
    {
        $this->store->accept('demo', $this->event()); $row = $this->store->claim();
        $this->scope->binding = $this->store->binding('demo'); $this->scope->event = $row;
        $guard = new ToolGuard($this->store, $this->scope, $this->core); $calls = 0;
        $dispatch = function () use (&$calls) { $calls++; return ['ok' => true, 'n' => $calls]; };
        $book = ['date' => '2026-11-02', 'time' => '10:00'];
        self::assertSame(1, $guard->execute('calendar_book_appointment', $this->context($row), $dispatch, $book)['n']);
        self::assertSame(1, $guard->execute('calendar_book_appointment', $this->context($row), $dispatch, $book)['n']);
        self::assertSame(2, $guard->execute('calendar_book_appointment', $this->context($row), $dispatch, ['date' => '2026-11-03', 'time' => '10:00'])['n']);
        self::assertSame(3, $guard->execute('knowledge_search', $this->context($row), $dispatch, ['q' => 'x'])['n']);
        self::assertSame(4, $guard->execute('knowledge_search', $this->context($row), $dispatch, ['q' => 'x'])['n']);
    }
    public function testDailyBudgetsBlockAdditionalNewWorkButNotDuplicates(): void
    {
        $this->store->db->table('aws_bridge_bindings')->update(['daily_inbound_limit' => 1, 'daily_outbound_limit' => 1]);
        $this->store->accept('demo', $this->event()); self::assertSame('duplicate', $this->store->accept('demo', $this->event())['status']);
        try { $this->store->accept('demo', $this->event('wamid.two')); self::fail('must reject'); } catch (BridgeException $e) { self::assertSame(429, $e->httpStatus); }
        $b = $this->store->binding('demo'); $this->outbox->enqueue($b, '+12025550123', 'Synthetic one', 1, null);
        $this->expectExceptionMessage('daily_outbound_limit'); $this->outbox->enqueue($b, '+12025550123', 'Synthetic two', 2, null);
    }
    public function testOpenBindingAcceptsAnySenderWithoutDailyCap(): void
    {
        // Empty allowed_recipients = open number; daily limits 0 = no cap.
        $this->store->db->table('aws_bridge_bindings')->update(['allowed_recipients' => '[]', 'daily_inbound_limit' => 0, 'daily_outbound_limit' => 0]);
        foreach (['wamid.open1', 'wamid.open2', 'wamid.open3'] as $id) {
            $event = $this->event($id); $event['sender'] = '+34600000001';
            self::assertSame('accepted', $this->store->accept('demo', $event)['status']);
        }
        $b = $this->store->binding('demo');
        self::assertArrayHasKey('message_id', $this->outbox->enqueue($b, '+12025550124', 'Synthetic open reply', null, null));
        try { $this->outbox->enqueue($b, 'not-a-phone', 'Synthetic', null, null); self::fail('must reject'); }
        catch (BridgeException $e) { self::assertSame('recipient_not_allowed', $e->reason); }
    }
    public function testSigningKeysCannotComeFromDevelopmentOrReplicatedDirectory(): void
    {
        $this->expectExceptionMessage('protected_signing_keys_required');
        (new SigningKeys(__FILE__))->get('demo', 'core');
    }
    public function testTemplateReplyCarriesTheValidatedTemplateToCoreAndTheAwsCommand(): void
    {
        $this->controls=['receive'=>false,'agent'=>false,'send'=>true];
        $template=['name'=>'appointment_reminder','language'=>'es','parameters'=>['Ana']];
        $out=$this->outbox->enqueue($this->store->binding('demo'),'+12025550123','Hola Ana, te recordamos tu cita',null,99,$template);
        self::assertTrue($out['queued']); self::assertSame($template,$this->core->lastTemplate);
        $row=$this->store->db->table('aws_bridge_outbox')->where('id',$out['command_id'])->first();
        self::assertSame($template,$this->store->decode($row->payload)['template']);
        foreach ([['name'=>'Bad Name','language'=>'es','parameters'=>[]],['name'=>'ok','language'=>'es; x','parameters'=>[]],['name'=>'ok','language'=>'es','parameters'=>['']]] as $bad) {
            try { $this->outbox->enqueue($this->store->binding('demo'),'+12025550123','x',null,99,$bad); self::fail('accepted '.json_encode($bad)); }
            catch (BridgeException $e) { self::assertSame('invalid_template',$e->reason); }
        }
    }
    public function testPublisherListsTemplatesWithASignatureBoundToTheTemplatesPath(): void
    {
        $secret = $this->secret;
        $keys = new class($secret) implements KeySource { public function __construct(private string $secret) {} public function get(string $binding, string $direction): string { return $this->secret; } };
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function ($request) use ($secret) {
            self::assertSame('https://synthetic.execute-api.eu-west-1.amazonaws.com/templates', $request->url());
            BridgeSignature::verify($secret, 'POST', '/templates', $request->body(), [
                'timestamp' => $request->header('x-bridge-timestamp')[0], 'nonce' => $request->header('x-bridge-nonce')[0], 'signature' => $request->header('x-bridge-signature')[0],
            ]);
            return Illuminate\Support\Facades\Http::response(['templates' => [['name' => 'hello_world', 'language' => 'en_US', 'category' => 'UTILITY', 'components' => []]]], 200);
        });
        $list = (new Publisher('https://synthetic.execute-api.eu-west-1.amazonaws.com/outbound', $keys))->templates('demo');
        self::assertSame('hello_world', $list[0]['name']);
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
    private function notifier(array &$sent, callable $respond): Notifier
    {
        $secret = $this->secret;
        $keys = new class($secret) implements KeySource { public function __construct(private string $secret) {} public function get(string $binding, string $direction): string { return $this->secret; } };
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function ($request) use ($secret, &$sent, $respond) {
            self::assertSame('https://synthetic.execute-api.eu-west-1.amazonaws.com/notify', $request->url());
            BridgeSignature::verify($secret, 'POST', '/notify', $request->body(), [
                'timestamp' => $request->header('x-bridge-timestamp')[0], 'nonce' => $request->header('x-bridge-nonce')[0], 'signature' => $request->header('x-bridge-signature')[0],
            ]);
            $sent[] = json_decode($request->body(), true);
            return $respond(end($sent));
        });
        return new Notifier($this->store, new Publisher('https://synthetic.execute-api.eu-west-1.amazonaws.com/outbound', $keys));
    }
    private function contact(?string $choice): object
    {
        return (object) ['id' => 5, 'organization_id' => 1, 'workspace_id' => 10, 'phone' => '34600000001', 'email' => 'Customer@Example.com', 'preferred_reminder_channel' => $choice];
    }
    public function testNotifyNeedsTheCustomersOwnChoiceAndSendsOncePerOperation(): void
    {
        $this->controls['notify'] = true; $sent = [];
        $n = $this->notifier($sent, fn ($c) => Illuminate\Support\Facades\Http::response(['id' => $c['notifyId'], 'state' => 'accepted', 'providerMessageId' => 'sms-1'], 200));
        $channel = (object) ['id' => 20];
        foreach ([null, 'none', 'whatsapp', 'email'] as $choice) {
            self::assertSame('no_consent', $n->send($channel, $this->contact($choice), 'sms', null, 'Confirmed', 'appointment', '42')['error']);
        }
        self::assertSame([], $sent);
        $first = $n->send($channel, $this->contact('sms'), 'sms', null, 'Your appointment is confirmed.', 'appointment', '42');
        self::assertTrue($first['success']);
        self::assertSame(['+34600000001', 'sms', ['type' => 'appointment', 'id' => '42']], [$sent[0]['to'], $sent[0]['kind'], $sent[0]['reference']]);
        $again = $n->send($channel, $this->contact('sms'), 'sms', null, 'Your appointment is confirmed.', 'appointment', '42');
        self::assertTrue($again['duplicate']); self::assertCount(1, $sent);
        $row = $this->store->db->table('aws_bridge_notifications')->first();
        self::assertSame(['accepted', 'sms-1', hash('sha256', '+34600000001')], [$row->state, $row->provider_message_id, $row->recipient_hash]);
        $email = $n->send($channel, $this->contact('email'), 'email', 'Appointment confirmed', 'See you on Tuesday.', 'appointment', '42');
        self::assertTrue($email['success']); self::assertSame(['customer@example.com', 'Appointment confirmed'], [$sent[1]['to'], $sent[1]['subject']]);
        self::assertSame('invalid_subject', $n->send($channel, $this->contact('email'), 'email', "Hi\r\nBcc: x@y.z", 'x', 'ticket', '7')['error']);
    }
    public function testNotifyIsOffByDefaultAndKeepsFailedTransportsRetryableWithTheSameId(): void
    {
        $sent = []; $replies = [503, 200];
        $n = $this->notifier($sent, function ($c) use (&$replies) {
            $status = array_shift($replies);
            return $status === 200 ? Illuminate\Support\Facades\Http::response(['id' => $c['notifyId'], 'state' => 'accepted', 'providerMessageId' => 'ses-1'], 200)
                : Illuminate\Support\Facades\Http::response(['error' => 'temporary_failure'], 503);
        });
        $channel = (object) ['id' => 20];
        self::assertSame('bridge_notify_disabled', $n->send($channel, $this->contact('email'), 'email', 'S', 'T', 'ticket', '7')['error']);
        $this->controls['notify'] = true;
        self::assertSame('notify_unavailable', $n->send($channel, $this->contact('email'), 'email', 'S', 'T', 'ticket', '7')['error']);
        self::assertSame('pending', $this->store->db->table('aws_bridge_notifications')->value('state'));
        self::assertTrue($n->send($channel, $this->contact('email'), 'email', 'S', 'T', 'ticket', '7')['success']);
        self::assertSame($sent[0]['notifyId'], $sent[1]['notifyId']);
        $other = (object) ['id' => 6, 'organization_id' => 2, 'workspace_id' => 99, 'phone' => '34600000002', 'email' => 'x@example.com', 'preferred_reminder_channel' => 'sms'];
        self::assertSame('contact_ownership_mismatch', $n->send($channel, $other, 'sms', null, 'T', 'ticket', '7')['error']);
    }
    public function testOutboundFileTravelsAsAValidatedMediaReferenceForThisBindingOnly(): void
    {
        $key = 'outbound/demo/'.str_repeat('a', 32).'/file';
        $media = ['type' => 'document', 'key' => $key, 'mimeType' => 'application/pdf', 'fileName' => 'invoice.pdf', 'caption' => 'Your invoice'];
        $r = $this->outbox->enqueue($this->store->binding('demo'), '+12025550123', 'Your invoice', null, 99, null, $media);
        self::assertTrue($r['success']); self::assertSame($media, $this->core->lastMedia);
        $row = $this->store->db->table('aws_bridge_outbox')->where('id', $r['command_id'])->first();
        self::assertSame($media, $this->store->decode($row->payload)['media']);
        foreach ([['key' => str_replace('/demo/', '/other/', $key)], ['mimeType' => 'application/zip'], ['type' => 'image'], ['fileName' => '../x'], ['caption' => str_repeat('x', 1025)]] as $bad) {
            try { $this->outbox->enqueue($this->store->binding('demo'), '+12025550123', 'x', null, 99, null, array_merge($media, $bad)); self::fail('accepted '.json_encode($bad)); }
            catch (BridgeException $e) { self::assertSame('invalid_media', $e->reason); }
        }
        try { $this->outbox->enqueue($this->store->binding('demo'), '+12025550123', 'x', null, 99, ['name' => 'a', 'language' => 'es', 'parameters' => []], $media); self::fail('template + file'); }
        catch (BridgeException $e) { self::assertSame('invalid_media', $e->reason); }
    }
    public function testQueuedFileIsUploadedByTheDrainOnceAndPublishedWithItsKeyOnly(): void
    {
        $media = ['type' => 'image', 'mimeType' => 'image/png', 'source' => ['disk' => 'media_local', 'path' => 'org/1/out/a.png', 'size' => 2048], 'caption' => 'Router'];
        $r = $this->outbox->enqueue($this->store->binding('demo'), '+12025550123', 'Router', null, 99, null, $media);
        $uploads = 0; $published = []; $failFirstPublish = true;
        $upload = function ($binding, $m) use (&$uploads) { $uploads++; self::assertSame(['disk' => 'media_local', 'path' => 'org/1/out/a.png', 'size' => 2048], $m['source']); return 'outbound/demo/'.str_repeat('c', 32).'/file'; };
        $publish = function ($binding, $command) use (&$published, &$failFirstPublish) {
            if ($failFirstPublish) { $failFirstPublish = false; throw new RuntimeException('network'); }
            $published[] = $command;
        };
        self::assertTrue($this->outbox->publishOne($publish, $upload));
        $this->now += 31;
        self::assertTrue($this->outbox->publishOne($publish, $upload));
        self::assertSame(1, $uploads, 'a retry reuses the saved upload');
        self::assertSame(['type' => 'image', 'key' => 'outbound/demo/'.str_repeat('c', 32).'/file', 'mimeType' => 'image/png', 'caption' => 'Router'], $published[0]['media']);
        self::assertArrayNotHasKey('source', $published[0]['media']);
        self::assertSame($r['command_id'], $published[0]['commandId']);
        foreach ([['path' => '../etc/x'], ['disk' => 'Bad Disk'], ['size' => 6 * 1024 * 1024]] as $bad) {
            try { $this->outbox->enqueue($this->store->binding('demo'), '+12025550123', 'x', null, 99, null, ['source' => array_merge($media['source'], $bad)] + $media); self::fail('accepted '.json_encode($bad)); }
            catch (BridgeException $e) { self::assertSame('invalid_media', $e->reason); }
        }
    }
    public function testUploadSlotMustPointAtThisBindingsKeyInAnS3BucketInTheRegion(): void
    {
        $secret = $this->secret; $key = 'outbound/demo/'.str_repeat('b', 32).'/file'; $reply = [];
        $keys = new class($secret) implements KeySource { public function __construct(private string $secret) {} public function get(string $binding, string $direction): string { return $this->secret; } };
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function ($request) use ($secret, &$reply) {
            if ($request->method() === 'PUT') { self::assertSame('application/pdf', $request->header('Content-Type')[0]); return Illuminate\Support\Facades\Http::response('', 200); }
            BridgeSignature::verify($secret, 'POST', '/media', $request->body(), [
                'timestamp' => $request->header('x-bridge-timestamp')[0], 'nonce' => $request->header('x-bridge-nonce')[0], 'signature' => $request->header('x-bridge-signature')[0],
            ]);
            return Illuminate\Support\Facades\Http::response($reply, 200);
        });
        $p = new Publisher('https://synthetic.execute-api.eu-west-1.amazonaws.com/outbound', $keys);
        $reply = ['key' => $key, 'url' => 'https://bridge-media.s3.eu-west-1.amazonaws.com/'.$key.'?X-Amz-Signature=t'];
        $slot = $p->uploadSlot('demo', 'document', 'application/pdf', 10);
        $p->upload($slot, '%PDF-synthetic', 'application/pdf');
        foreach (['https://attacker.example/'.$key.'?x', 'https://bridge-media.s3.us-east-1.amazonaws.com/'.$key.'?x', 'https://bridge-media.s3.eu-west-1.amazonaws.com/outbound/demo/other?x'] as $url) {
            $reply = ['key' => $key, 'url' => $url];
            try { $p->uploadSlot('demo', 'document', 'application/pdf', 10); self::fail('accepted '.$url); } catch (BridgeException $e) { self::assertSame('media_slot_unavailable', $e->reason); }
        }
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
