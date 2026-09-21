<?php

namespace SocketBridge\Tests\Feature;

use Illuminate\Support\Facades\DB;
use SocketBridge\Commands\CommandConsumer;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Commands\CommandRegistry;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\DTO\Envelope;
use SocketBridge\Outbox\OutboxRelay;
use SocketBridge\Tests\TestCase;
use SocketBridge\Tests\TestUser;
use SocketBridge\Transport\RedisStreams;

class RedisStreamsTest extends TestCase
{
    private ?RedisStreams $real = null;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('SOCKET_BRIDGE_TEST_REDIS_URL');
        if (! $url) {
            $this->markTestSkipped('Set SOCKET_BRIDGE_TEST_REDIS_URL to a disposable standalone Redis >=7.');
        }
        $this->prefix = 'socket-bridge:php-tests:'.bin2hex(random_bytes(8));
        config(['socket-bridge.prefix' => $this->prefix, 'socket-bridge.redis_url' => $url, 'socket-bridge.redis_connection' => 'bridge-tests',
            'database.redis.client' => getenv('SOCKET_BRIDGE_TEST_REDIS_CLIENT') ?: 'predis', 'database.redis.options.prefix' => 'application-prefix:',
            'database.redis.bridge-tests' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0]]);
        $this->real = new RedisStreams($this->app);
    }

    protected function tearDown(): void
    {
        if ($this->real !== null) {
            $cursor = '0';
            do {
                [$cursor, $keys] = $this->real->raw('SCAN', $cursor, 'MATCH', $this->prefix.':*', 'COUNT', 100);
                foreach ($keys as $key) {
                    $this->real->raw('DEL', $key);
                }
            } while ((string) $cursor !== '0');
        }
        parent::tearDown();
    }

    public function test_stream_json_is_prefix_free_and_pending_entries_are_claimable(): void
    {
        $this->real->createGroup('events', 'gateways');
        $this->real->createGroup('events', 'gateways');
        $envelope = Envelope::make('socket.emit', ['event' => 'test', 'rooms' => ['private-room.1'], 'payload' => []]);
        $id = $this->real->add('events', $envelope);
        self::assertSame(1, $this->real->raw('EXISTS', $this->prefix.':events'));
        self::assertSame(0, $this->real->raw('EXISTS', 'application-prefix:'.$this->prefix.':events'));
        $entries = $this->real->read('events', 'gateways', 'a', 1, 1);
        self::assertSame($id, $entries[0]['id']);
        self::assertStringContainsString('"payload":{}', $entries[0]['raw']);
        self::assertSame(1, $this->real->attempts('events', 'gateways', $id));
        $claimed = $this->real->claim('events', 'gateways', 'b', 0, 1);
        self::assertSame($id, $claimed[0]['id']);
        self::assertSame(2, $this->real->attempts('events', 'gateways', $id));
        $this->real->ack('events', 'gateways', $id);
        self::assertSame(0, $this->real->raw('XPENDING', $this->prefix.':events', 'gateways')[0]);
    }

    public function test_real_consumer_recovers_transient_failure_and_commits_one_result(): void
    {
        $this->app->instance(RedisStreams::class, $this->real);
        $this->app->instance(EnvelopeTransport::class, $this->real);
        config(['socket-bridge.command_claim_idle_ms' => 0]);
        $user = TestUser::create(['name' => 'Original']);
        $sessionId = hash('sha256', 'redis-consumer-session');
        $this->real->raw('SET', $this->real->key('session:'.$sessionId), json_encode([
            'user_id' => (string) $user->id, 'session_id' => $sessionId, 'guard' => 'web', 'provider' => 'users', 'user_version' => 0, 'expires_at' => time() + 60,
        ]), 'EX', 60);
        $handler = new class implements CommandHandler
        {
            public bool $fail = true;

            public function handle(array $payload, CommandContext $context): array
            {
                DB::table('users')->where('id', $context->userId)->update(['name' => 'Changed']);
                if ($this->fail) {
                    $this->fail = false;
                    throw new \RuntimeException('Retry me');
                }

                return ['name' => 'Changed'];
            }
        };
        app(CommandRegistry::class)->register('user.update', $handler);
        $command = Envelope::make('socket.command', ['command' => 'user.update', 'payload' => [], 'context' => [
            'user_id' => (string) $user->id, 'session_id' => $sessionId, 'socket_id' => 'socket-1',
        ]]);
        $this->real->add('commands', $command);
        $consumer = app(CommandConsumer::class);
        self::assertSame(0, $consumer->runOnce('worker-a'));
        self::assertSame('Original', $user->fresh()->name);
        self::assertSame(1, $this->real->raw('XPENDING', $this->prefix.':commands', 'laravel')[0]);
        self::assertSame(1, $consumer->runOnce('worker-b'));
        self::assertSame('Changed', $user->fresh()->name);
        self::assertDatabaseCount('socket_bridge_command_receipts', 1);
        self::assertDatabaseCount('socket_bridge_outbox', 1);
        self::assertSame(0, $this->real->raw('XPENDING', $this->prefix.':commands', 'laravel')[0]);
        self::assertSame(1, app(OutboxRelay::class)->runOnce());
        self::assertSame(1, $this->real->raw('XLEN', $this->prefix.':events'));
    }

    public function test_protocol_errors_throw_but_missing_keys_and_read_timeouts_are_normal(): void
    {
        $key = $this->prefix.':wrongtype';
        $this->real->raw('SET', $key, 'value');
        $caught = null;
        try {
            $this->real->raw('XADD', $key, '*', 'envelope', '{}');
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught, 'Redis errors must never be mistaken for successful processing.');
        self::assertStringContainsString('WRONGTYPE', $caught->getMessage());
        self::assertFalse((bool) $this->real->raw('GET', $this->prefix.':absent'));
        $this->real->createGroup('empty', 'readers');
        self::assertSame([], $this->real->read('empty', 'readers', 'test', 1, 1));
    }

    public function test_dead_letter_retains_invalid_message_before_acknowledging_it(): void
    {
        $this->real->createGroup('commands', 'laravel');
        $id = $this->real->raw('XADD', $this->prefix.':commands', '*', 'envelope', '{broken');
        $entries = $this->real->read('commands', 'laravel', 'a', 1, 1);
        self::assertNull($entries[0]['envelope']);
        $this->real->deadLetter('commands', 'laravel', $id, $entries[0]['raw'], 'Malformed');
        self::assertSame(1, $this->real->raw('XLEN', $this->prefix.':dead:commands'));
        self::assertSame(0, $this->real->raw('XPENDING', $this->prefix.':commands', 'laravel')[0]);
    }
}
