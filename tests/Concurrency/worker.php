<?php

// Standalone processes deliberately use separate PDO/Redis connections.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use SocketBridge\Auth\AuthenticatedSession;
use SocketBridge\Auth\SessionManager;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Commands\CommandProcessor;
use SocketBridge\Commands\CommandRegistry;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\DTO\Envelope;
use SocketBridge\Operations\Maintenance;
use SocketBridge\Operations\StreamRetention;
use SocketBridge\Outbox\OutboxRelay;
use SocketBridge\Outbox\OutboxStore;
use SocketBridge\Transport\RedisStreams;

$settings = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$action = $argv[2];
$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Repository([
    'database' => ['default' => 'concurrency', 'connections' => ['concurrency' => ['driver' => $settings['driver'], 'url' => $settings['url'], 'prefix' => $settings['prefix'], 'charset' => $settings['driver'] === 'mysql' ? 'utf8mb4' : 'utf8', 'collation' => 'utf8mb4_unicode_ci']],
        'redis' => ['client' => 'predis', 'default' => ['url' => $settings['redis_url']]]],
    'socket-bridge' => ['database_connection' => 'concurrency', 'prefix' => $settings['prefix'], 'redis_connection' => 'default', 'retention' => ['receipts_seconds' => 604800, 'published_outbox_seconds' => 86400]],
]));
$app->register(EventServiceProvider::class);
$app->register(DatabaseServiceProvider::class);
Facade::setFacadeApplication($app);
$app->singleton(ExceptionHandler::class, Handler::class);
$store = new OutboxStore;
$redis = new RedisStreams($app);
$sessions = new class extends SessionManager
{
    public function __construct() {}

    public function resolve(string $id): AuthenticatedSession
    {
        return new AuthenticatedSession(new GenericUser(['id' => 1]), ['user_id' => '1', 'session_id' => $id]);
    }
};
$registry = new CommandRegistry($app);
$registry->register('counter.increment', new class implements CommandHandler
{
    public function handle(array $payload, CommandContext $context): array
    {
        DB::table('counter')->where('id', 1)->increment('value');
        usleep(150000);

        return ['value' => (int) DB::table('counter')->where('id', 1)->value('value')];
    }
});
$processor = new CommandProcessor($sessions, $registry, $store);
$command = ['v' => 1, 'id' => $settings['command_id'], 'type' => 'socket.command', 'created_at' => $settings['created_at'], 'command' => 'counter.increment', 'payload' => [], 'context' => ['user_id' => '1', 'session_id' => hash('sha256', 'db-test'), 'socket_id' => $settings['socket'] ?? 'original']];
if ($action === 'prepare') {
    (require dirname(__DIR__, 2).'/database/migrations/2026_09_21_000001_create_socket_bridge_tables.php')->up();
    Schema::create('counter', function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('value');
    });
    DB::table('counter')->insert(['id' => 1, 'value' => 0]);
    $result = ['ready' => true];
} elseif ($action === 'process' || $action === 'commit-crash') {
    $result = $processor->process($command);
    if ($action === 'commit-crash') {
        exit(86);
    }
} elseif ($action === 'store') {
    $store->store(Envelope::make('socket.emit', ['event' => 'counter.changed', 'rooms' => ['private-counter'], 'payload' => ['value' => 1]], $settings['event_id']));
    $result = ['stored' => true];
} elseif ($action === 'relay' || $action === 'publish-crash') {
    $transport = $action === 'relay' ? $redis : new class($redis) implements EnvelopeTransport
    {
        public function __construct(private RedisStreams $redis) {}

        public function add(string $stream, array $envelope): string
        {
            $this->redis->add($stream, $envelope);
            exit(87);
        }
    };
    $result = ['published' => (new OutboxRelay($store, $transport))->runOnce()];
} elseif ($action === 'prune') {
    config(['socket-bridge.retention.receipts_seconds' => 10, 'socket-bridge.retention.published_outbox_seconds' => 10]);
    DB::table('socket_bridge_command_receipts')->update(['updated_at' => now()->subMinute()]);
    $before = (new Maintenance(new StreamRetention($redis), $store))->prune();
    DB::table('socket_bridge_outbox')->update(['published_at' => now()->subMinute()]);
    $after = (new Maintenance(new StreamRetention($redis), $store))->prune();
    $command['created_at'] = now()->subMinute()->toISOString();
    $result = ['before' => $before, 'after' => $after, 'old_result' => $processor->process($command)];
} elseif ($action === 'status') {
    $result = ['counter' => (int) DB::table('counter')->where('id', 1)->value('value'), 'receipts' => DB::table('socket_bridge_command_receipts')->count(), 'outbox' => DB::table('socket_bridge_outbox')->count(), 'pending_outbox' => DB::table('socket_bridge_outbox')->whereNull('published_at')->count(), 'events' => array_column($redis->range('events'), 'envelope')];
} elseif ($action === 'cleanup') {
    Schema::dropIfExists('counter');
    Schema::dropIfExists('socket_bridge_command_receipts');
    Schema::dropIfExists('socket_bridge_outbox');
    $cursor = '0';
    $keys = [];
    do {
        [$cursor, $batch] = $redis->raw('SCAN', $cursor, 'MATCH', $redis->key('*'), 'COUNT', 100);
        $keys = array_merge($keys, $batch);
    } while ((string) $cursor !== '0');
    foreach ($keys as $key) {
        $redis->raw('DEL', $key);
    }
    $result = ['cleaned' => true];
} else {
    throw new RuntimeException('Unknown test action.');
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
