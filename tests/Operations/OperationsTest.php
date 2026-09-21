<?php

namespace SocketBridge\Tests\Operations;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use SocketBridge\Commands\CommandConsumer;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Commands\CommandProcessor;
use SocketBridge\Commands\CommandRegistry;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\DTO\Envelope;
use SocketBridge\Exceptions\CommandRejected;
use SocketBridge\Operations\FailedMessages;
use SocketBridge\Operations\HealthService;
use SocketBridge\Operations\Maintenance;
use SocketBridge\Operations\Metrics;
use SocketBridge\Operations\StreamRetention;
use SocketBridge\Operations\WorkerHeartbeat;
use SocketBridge\Operations\WorkerRestart;
use SocketBridge\Outbox\OutboxRelay;
use SocketBridge\Outbox\OutboxStore;
use SocketBridge\Tests\TestCase;
use SocketBridge\Tests\TestUser;
use SocketBridge\Transport\RedisStreams;

class OperationsTest extends TestCase
{
    private ?RedisStreams $real = null;

    private ?WorkerHeartbeat $heartbeat = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('SOCKET_BRIDGE_TEST_REDIS_URL')) {
            $this->markTestSkipped('Real standalone Redis required.');
        }
        config(['socket-bridge.prefix' => 'socket-bridge:operations:'.bin2hex(random_bytes(8)),
            'socket-bridge.redis_url' => getenv('SOCKET_BRIDGE_TEST_REDIS_URL'), 'socket-bridge.redis_connection' => 'operations',
            'database.redis.client' => getenv('SOCKET_BRIDGE_TEST_REDIS_CLIENT') ?: 'predis', 'database.redis.operations' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0]]);
        $this->real = new RedisStreams($this->app);
        $this->app->instance(RedisStreams::class, $this->real);
        $this->app->instance(EnvelopeTransport::class, $this->real);
    }

    protected function tearDown(): void
    {
        $this->heartbeat?->stop();
        if ($this->real) {
            $cursor = '0';
            $keys = [];
            do {
                [$cursor, $batch] = $this->real->raw('SCAN', $cursor, 'MATCH', $this->real->key('*'), 'COUNT', 100);
                $keys = array_merge($keys, $batch);
            } while ((string) $cursor !== '0');
            foreach ($keys as $key) {
                $this->real->raw('DEL', $key);
            }
        }
        parent::tearDown();
    }

    private function addOld(string $stream, int $id): void
    {
        $this->real->raw('XADD', $this->real->key($stream), $id.'-0', 'envelope', '{}');
    }

    public function test_trim_honors_every_groups_pending_and_unread_floor(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->addOld('events', $i);
        }
        $this->real->createGroup('events', 'a');
        $this->real->createGroup('events', 'b');
        $this->real->read('events', 'a', 'one', 6, 1);
        foreach (['1-0', '2-0', '3-0', '5-0', '6-0'] as $id) {
            $this->real->ack('events', 'a', $id);
        }
        $this->real->read('events', 'b', 'two', 2, 1);
        foreach (['1-0', '2-0'] as $id) {
            $this->real->ack('events', 'b', $id);
        }
        $retention = app(StreamRetention::class);
        self::assertSame(1, $retention->prune('events', 100)['deleted']);
        self::assertSame('2-0', $this->real->range('events')[0]['id']);
        $this->real->read('events', 'b', 'two', 6, 1);
        foreach (['3-0', '4-0', '5-0', '6-0', '7-0', '8-0'] as $id) {
            $this->real->ack('events', 'b', $id);
        }
        self::assertSame(2, $retention->prune('events', 100)['deleted']);
        self::assertSame('4-0', $this->real->range('events')[0]['id']);
        self::assertSame(1, $this->real->raw('XPENDING', $this->real->key('events'), 'a')[0]);
        self::assertSame('4-0', $this->real->claim('events', 'a', 'recovery', 0)[0]['id']);
    }

    public function test_dry_run_limit_and_ungrouped_stream_safety(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->addOld('commands', $i);
            $this->addOld('dead:commands', $i);
        }
        $retention = app(StreamRetention::class);
        self::assertSame(0, $retention->prune('commands', 100)['deleted']);
        self::assertSame(2, $retention->prune('dead:commands', 100, true, 2, true)['eligible']);
        self::assertCount(6, $this->real->range('dead:commands'));
        self::assertSame(2, $retention->prune('dead:commands', 100, false, 2, true)['deleted']);
        self::assertCount(4, $this->real->range('dead:commands'));
    }

    private function command(): array
    {
        $user = TestUser::create(['name' => 'Before']);
        $session = hash('sha256', 'ops-session');
        $this->real->raw('SET', $this->real->key('session:'.$session), json_encode(['user_id' => (string) $user->id, 'session_id' => $session, 'guard' => 'web', 'provider' => 'users', 'user_version' => 0, 'expires_at' => time() + 3600]), 'EX', 3600);
        app(CommandRegistry::class)->register('update', new class implements CommandHandler
        {
            public function handle(array $payload, CommandContext $context): array
            {
                DB::table('users')->where('id', $context->userId)->update(['name' => 'After']);

                return ['updated' => true];
            }
        });

        return Envelope::make('socket.command', ['command' => 'update', 'payload' => [], 'context' => ['session_id' => $session, 'user_id' => (string) $user->id, 'socket_id' => 'original']]);
    }

    public function test_receipts_wait_for_result_publication_and_old_commands_do_not_repeat(): void
    {
        $command = $this->command();
        app(CommandProcessor::class)->process($command);
        config(['socket-bridge.retention.receipts_seconds' => 10, 'socket-bridge.retention.published_outbox_seconds' => 10]);
        DB::table('socket_bridge_command_receipts')->update(['updated_at' => now()->subSeconds(30)]);
        self::assertSame(0, app(Maintenance::class)->prune()['receipts']['deleted']);
        DB::table('socket_bridge_outbox')->update(['published_at' => now()->subSeconds(30)]);
        $report = app(Maintenance::class)->prune();
        self::assertSame(1, $report['receipts']['deleted']);
        self::assertSame(1, $report['published_outbox']['deleted']);
        DB::table('users')->update(['name' => 'Reset']);
        $command['created_at'] = now()->subSeconds(30)->toISOString();
        self::assertSame('command.expired', app(CommandProcessor::class)->process($command)['error']['code']);
        self::assertSame('Reset', DB::table('users')->value('name'));
        self::assertSame(0, app(Maintenance::class)->prune()['published_outbox']['deleted']);
    }

    private function failed(array $envelope, string $stream = 'commands'): string
    {
        return $this->real->add('dead:'.$stream, ['source_id' => '1-0', 'raw' => json_encode($envelope), 'reason' => 'Retry limit', 'failed_at' => now()->toISOString()]);
    }

    public function test_replay_keeps_identity_deduplicates_request_and_reexecutes_only_transient_failure(): void
    {
        $command = $this->command();
        app(CommandProcessor::class)->process($command, new CommandRejected('command.failed', 'Retry limit'));
        $dead = $this->failed($command);
        $failed = app(FailedMessages::class);
        self::assertSame($command['id'], $failed->inspect('commands')[0]['original']['id']);
        $replayed = $failed->replay('commands', $dead);
        self::assertFalse($replayed['already_replayed']);
        self::assertTrue($failed->replay('commands', $dead)['already_replayed']);
        self::assertCount(1, $this->real->range('commands'));
        self::assertSame($command, $this->real->range('commands')[0]['envelope']);
        self::assertDatabaseCount('socket_bridge_outbox', 0);
        self::assertTrue(app(CommandProcessor::class)->process($command)['ok']);
        $second = $this->failed($command);
        $failed->replay('commands', $second);
        DB::table('users')->update(['name' => 'No repeat']);
        app(CommandProcessor::class)->process($command);
        self::assertSame('No repeat', DB::table('users')->value('name'));
    }

    public function test_revoked_original_session_prevents_replay(): void
    {
        $command = $this->command();
        $dead = $this->failed($command);
        $this->real->raw('DEL', $this->real->key('session:'.$command['context']['session_id']));
        $this->expectException(AuthenticationException::class);
        app(FailedMessages::class)->replay('commands', $dead);
    }

    public function test_event_replay_preserves_uuid_and_payload(): void
    {
        $envelope = Envelope::make('socket.emit', ['event' => 'changed', 'rooms' => ['private-room.1'], 'payload' => ['value' => 2]]);
        $id = $this->failed($envelope, 'events');
        app(FailedMessages::class)->replay('events', $id);
        self::assertSame($envelope, $this->real->range('events')[0]['envelope']);
    }

    public function test_gateway_dead_letter_wrapper_is_inspected_and_replayed(): void
    {
        $envelope = Envelope::make('socket.emit', ['event' => 'changed', 'rooms' => ['private-room.1'], 'payload' => ['value' => 2]]);
        $id = $this->real->add('dead:events', ['source_id' => '1-0', 'failed_at' => now()->toISOString(), 'attempts' => 5, 'error' => 'Delivery failed', 'envelope' => $envelope]);
        $failed = app(FailedMessages::class);
        self::assertSame('Delivery failed', $failed->inspect('events')[0]['reason']);
        $failed->replay('events', $id);
        self::assertSame($envelope, $this->real->range('events')[0]['envelope']);
    }

    public function test_signal_renews_heartbeat_during_a_busy_batch(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('PCNTL is required for busy-batch heartbeat renewal.');
        }
        config(['socket-bridge.health.interval_seconds' => 1, 'socket-bridge.health.ttl_seconds' => 3]);
        $async = pcntl_async_signals(true);
        try {
            $this->heartbeat = app(WorkerHeartbeat::class);
            $this->heartbeat->start('commands', 'busy');
            $key = $this->real->key('health:worker:commands:busy');
            $before = json_decode($this->real->raw('GET', $key), true);
            $until = microtime(true) + 2.1;
            while (microtime(true) < $until) {
                usleep(100000);
            }
            $after = json_decode($this->real->raw('GET', $key), true);
            self::assertNotSame($before['updated_at'], $after['updated_at']);
            self::assertGreaterThanOrEqual(2, $this->real->raw('TTL', $key));
        } finally {
            $this->heartbeat?->stop();
            pcntl_async_signals($async);
        }
    }

    public function test_health_reports_live_workers_gateway_lag_pending_and_outbox(): void
    {
        self::assertFalse(app(HealthService::class)->snapshot()['healthy']);
        $this->heartbeat = app(WorkerHeartbeat::class);
        $this->heartbeat->start('commands', 'test-worker');
        $this->real->raw('SET', $this->real->key('health:worker:outbox:other'), json_encode(['protocol' => 1, 'role' => 'outbox', 'id' => 'other']), 'EX', 15);
        $this->real->raw('SET', $this->real->key('health:gateway:one'), json_encode(['protocol' => 1, 'instance_id' => 'one', 'connections' => 3, 'subscriptions' => 2]), 'EX', 15);
        $this->real->createGroup('commands', 'laravel');
        $this->real->add('commands', $this->command());
        $this->real->add('commands', $this->command());
        $this->real->read('commands', 'laravel', 'test', 1, 1);
        app(OutboxStore::class)->store(Envelope::make('socket.emit', ['event' => 'x', 'rooms' => ['public-x'], 'payload' => []]));
        DB::table('socket_bridge_outbox')->update(['created_at' => now()->subMinute(), 'attempts' => 2]);
        $snapshot = app(HealthService::class)->snapshot();
        self::assertTrue($snapshot['healthy']);
        self::assertSame(1, $snapshot['streams']['commands']['groups'][0]['pending']);
        self::assertSame(1, $snapshot['streams']['commands']['groups'][0]['lag']);
        self::assertSame(1, $snapshot['outbox']['pending']);
        self::assertSame(1, $snapshot['outbox']['failed']);
        self::assertGreaterThanOrEqual(60, $snapshot['outbox']['oldest_age_seconds']);
        self::assertGreaterThan(0, $this->real->raw('TTL', $this->real->key('health:worker:commands:test-worker')));
        $this->heartbeat->stop();
        self::assertFalse(app(HealthService::class)->snapshot()['healthy']);
    }

    public function test_restart_is_scoped_persistent_and_does_not_stop_new_workers(): void
    {
        $restart = app(WorkerRestart::class);
        self::assertSame('', $restart->generation('commands'));
        $first = $restart->request('commands');
        self::assertSame($first, $restart->generation('commands'));
        self::assertSame('', $restart->generation('outbox'));
        self::assertSame(-1, $this->real->raw('TTL', $this->real->key('workers:restart:commands')));
        $relay = \Mockery::mock(OutboxRelay::class);
        $relay->shouldReceive('runOnce')->once()->andReturnUsing(function (int $limit, callable $shouldStop) use ($restart): int {
            self::assertFalse($shouldStop());
            $restart->request('outbox');
            usleep(1100000);
            self::assertTrue($shouldStop());

            return 1;
        });
        $this->app->instance(OutboxRelay::class, $relay);
        $this->artisan('socket-bridge:outbox', ['--memory' => 0, '--max-time' => 0])->expectsOutput('Worker stopped safely: restart request.')->assertSuccessful();
        $fresh = \Mockery::mock(OutboxRelay::class);
        $fresh->shouldReceive('runOnce')->once()->andReturnUsing(function (int $limit, callable $shouldStop): int {
            self::assertFalse($shouldStop());

            return 0;
        });
        $this->app->instance(OutboxRelay::class, $fresh);
        $this->artisan('socket-bridge:outbox', ['--once' => true, '--memory' => 0])->assertSuccessful();
        $this->artisan('socket-bridge:restart')->assertSuccessful();
        self::assertSame($restart->generation('commands'), $restart->generation('outbox'));
        self::assertNotSame($first, $restart->generation('commands'));
    }

    public function test_worker_resource_limits_exit_cleanly_before_the_next_batch(): void
    {
        $relay = \Mockery::mock(OutboxRelay::class);
        $relay->shouldNotReceive('runOnce');
        $this->app->instance(OutboxRelay::class, $relay);
        $this->artisan('socket-bridge:outbox', ['--memory' => 1])->expectsOutput('Worker stopped safely: memory limit.')->assertSuccessful();
        $this->artisan('socket-bridge:outbox', ['--max-time' => -1])->assertFailed();
        $relay = \Mockery::mock(OutboxRelay::class);
        $relay->shouldReceive('runOnce')->once()->andReturn(0);
        $this->app->instance(OutboxRelay::class, $relay);
        $started = microtime(true);
        $this->artisan('socket-bridge:outbox', ['--max-time' => 1, '--memory' => 0, '--sleep' => 10])->expectsOutput('Worker stopped safely: time limit.')->assertSuccessful();
        self::assertLessThan(4, microtime(true) - $started);
    }

    public function test_consumer_stop_keeps_unprocessed_claimed_entries_recoverable(): void
    {
        config(['socket-bridge.command_claim_idle_ms' => 0]);
        $this->real->add('commands', $this->command());
        $this->real->add('commands', $this->command());
        $consumer = app(CommandConsumer::class);
        $checked = 0;
        self::assertSame(1, $consumer->runOnce('first', function () use (&$checked): bool {
            return ++$checked > 1;
        }));
        self::assertSame(1, $this->real->raw('XPENDING', $this->real->key('commands'), 'laravel')[0]);
        self::assertSame(1, $consumer->runOnce('replacement'));
        self::assertSame(0, $this->real->raw('XPENDING', $this->real->key('commands'), 'laravel')[0]);
    }

    public function test_metrics_record_real_processing_and_use_protected_fixed_cardinality_export(): void
    {
        $token = str_repeat('m', 48);
        $this->get('/socket-bridge/metrics')->assertNotFound();
        config(['socket-bridge.metrics.enabled' => true, 'socket-bridge.metrics.token' => $token]);
        $this->get('/socket-bridge/metrics')->assertUnauthorized();
        $this->real->add('commands', $this->command());
        self::assertSame(1, app(CommandConsumer::class)->runOnce('metrics'));
        self::assertSame(1, app(OutboxRelay::class)->runOnce());
        $metrics = app(Metrics::class);
        $metrics->observe('command_duration_seconds', 0.01);
        $metrics->observe('not_an_allowed_label', 1);
        $metrics->observe('outbox_lag_seconds', INF);
        $values = $metrics->values();
        self::assertSame('1', $values['commands_processed_total']);
        self::assertSame('1', $values['outbox_published_total']);
        self::assertSame('2', $values['command_duration_seconds_count']);
        self::assertSame($values['command_duration_seconds_count'], $values['command_duration_seconds_bucket_inf']);
        self::assertGreaterThan(0, $this->real->raw('TTL', $this->real->key('metrics:php')));
        $response = $this->withHeader('Authorization', 'Bearer '.$token)->get('/socket-bridge/metrics')->assertOk();
        self::assertStringContainsString('socket_bridge_command_duration_seconds_bucket{le="+Inf"} 2', $response->getContent());
        self::assertStringContainsString('socket_bridge_outbox_pending 0', $response->getContent());
        self::assertStringNotContainsString($token, $response->getContent());
        config(['socket-bridge.metrics.token' => 'too-short']);
        $this->get('/socket-bridge/metrics')->assertNotFound();
    }

    public function test_optional_metrics_failure_does_not_prevent_outbox_publication(): void
    {
        config(['socket-bridge.metrics.enabled' => true]);
        $metrics = new Metrics($this->redis);
        $this->app->instance(Metrics::class, $metrics);
        app(OutboxStore::class)->store(Envelope::make('socket.emit', ['event' => 'x', 'rooms' => ['public-x'], 'payload' => []]));
        self::assertSame(1, app(OutboxRelay::class)->runOnce());
        self::assertSame(1, $this->real->raw('XLEN', $this->real->key('events')));
        self::assertSame(0, DB::table('socket_bridge_outbox')->whereNull('published_at')->count());
    }
}
