<?php

namespace SocketBridge\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\SocketBridgeServiceProvider;
use SocketBridge\Transport\RedisStreams;

abstract class TestCase extends Orchestra
{
    protected FakeRedis $redis;

    protected function getPackageProviders($app): array
    {
        return [SocketBridgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => TestUser::class]);
        $app['config']->set('broadcasting.default', 'socketio');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('socket-bridge.internal_secret', str_repeat('s', 64));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new FakeRedis($this->app);
        $this->app->instance(RedisStreams::class, $this->redis);
        $this->app->instance(EnvelopeTransport::class, $this->redis);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
        });
        (require __DIR__.'/../database/migrations/2026_09_21_000001_create_socket_bridge_tables.php')->up();
    }

    protected function sessionFor(TestUser $user): array
    {
        $id = hash('sha256', 'session:'.$user->id);
        $evidence = ['user_id' => (string) $user->id, 'session_id' => $id, 'guard' => 'web', 'provider' => 'users', 'user_version' => 0, 'expires_at' => time() + 3600];
        $this->redis->raw('SET', $this->redis->key('session:'.$id), json_encode($evidence), 'EX', 3600);

        return $evidence;
    }
}

class TestUser extends User
{
    use Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class FakeRedis extends RedisStreams
{
    public array $values = [];

    public array $envelopes = [];

    public bool $failAdds = false;

    public function raw(string $command, mixed ...$arguments): mixed
    {
        return match (strtoupper($command)) {
            'GET' => $this->values[$arguments[0]] ?? false,
            'SET' => $this->values[$arguments[0]] = $arguments[1],
            'INCR' => $this->values[$arguments[0]] = ((int) ($this->values[$arguments[0]] ?? 0)) + 1,
            'DEL' => $this->delete($arguments[0]),
            'GETDEL' => $this->getdel($arguments[0]),
            default => throw new \LogicException('Unsupported fake command '.$command),
        };
    }

    public function add(string $stream, array $envelope): string
    {
        if ($this->failAdds) {
            throw new \RuntimeException('Transport unavailable.');
        }
        $this->envelopes[] = ['stream' => $stream, 'envelope' => $envelope];

        return count($this->envelopes).'-0';
    }

    private function delete(string $key): int
    {
        $found = isset($this->values[$key]);
        unset($this->values[$key]);

        return (int) $found;
    }

    private function getdel(string $key): mixed
    {
        $value = $this->values[$key] ?? false;
        unset($this->values[$key]);

        return $value;
    }
}
