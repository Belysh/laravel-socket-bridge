<?php

namespace SocketBridge\Tests\Feature;

use Illuminate\Support\Facades\Facade;
use SocketBridge\Commands\CommandConsumer;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Commands\CommandRegistry;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\DTO\Envelope;
use SocketBridge\Tests\FakeRedis;
use SocketBridge\Tests\TestCase;
use SocketBridge\Tests\TestUser;
use SocketBridge\Transport\RedisStreams;

class CommandScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new ScopeBatchRedis($this->app);
        $this->app->instance(RedisStreams::class, $this->redis);
        $this->app->instance(EnvelopeTransport::class, $this->redis);
        $this->app->scoped(CommandScopedState::class);
        $this->app->scoped(ScopedCommandHandler::class);
        $this->app->singleton(CommandScopeTrace::class);
        app(CommandRegistry::class)->register('scope.check', ScopedCommandHandler::class);
    }

    public function test_each_command_has_a_new_scoped_handler_service_and_facade_root_even_in_one_batch(): void
    {
        $registry = app(CommandRegistry::class);
        $trace = app(CommandScopeTrace::class);
        $preexisting = app(CommandScopedState::class);
        $preexisting->userId = 'state-before-consumer';
        self::assertSame($preexisting, CommandScopeFacade::instance());
        $ada = TestUser::create(['name' => 'Ada']);
        $linus = TestUser::create(['name' => 'Linus']);
        $this->redis->entries = [
            $this->entry($ada, '1-0'),
            $this->entry($linus, '2-0'),
            $this->entry($ada, '3-0'),
        ];

        self::assertSame(3, app(CommandConsumer::class)->runOnce('scope-test'));
        self::assertSame(['1-0', '2-0', '3-0'], $this->redis->acknowledged);
        self::assertSame([(string) $ada->id, (string) $linus->id, (string) $ada->id], array_column($trace->calls, 'user_id'));
        foreach ($trace->calls as $call) {
            self::assertNull($call['previous_user']);
            self::assertSame($call['state'], $call['facade_state']);
            self::assertSame($trace, $call['singleton']);
            self::assertNotSame($preexisting, $call['state']);
        }
        self::assertNotSame($trace->calls[0]['state'], $trace->calls[1]['state']);
        self::assertNotSame($trace->calls[1]['state'], $trace->calls[2]['state']);
        self::assertNotSame($trace->calls[0]['handler'], $trace->calls[1]['handler']);
        self::assertNotSame($trace->calls[1]['handler'], $trace->calls[2]['handler']);
        self::assertSame($registry, app(CommandRegistry::class));
        self::assertSame($trace, app(CommandScopeTrace::class));
        // The final command also releases its scope, even if the worker becomes idle afterwards.
        self::assertNull(app(CommandScopedState::class)->userId);
        self::assertDatabaseCount('socket_bridge_command_receipts', 3);
    }

    public function test_failed_handler_scope_does_not_escape_to_the_next_user_or_idle_worker(): void
    {
        $trace = app(CommandScopeTrace::class);
        $ada = TestUser::create(['name' => 'Ada']);
        $linus = TestUser::create(['name' => 'Linus']);
        $this->redis->entries = [$this->entry($ada, '1-0', ['fail' => true]), $this->entry($linus, '2-0')];

        self::assertSame(1, app(CommandConsumer::class)->runOnce('scope-test'));
        self::assertSame(['2-0'], $this->redis->acknowledged);
        self::assertCount(2, $trace->calls);
        self::assertNull($trace->calls[1]['previous_user']);
        self::assertNotSame($trace->calls[0]['state'], $trace->calls[1]['state']);
        self::assertNull(CommandScopeFacade::instance()->userId);
        self::assertDatabaseCount('socket_bridge_command_receipts', 1);
    }

    public function test_an_explicit_handler_object_and_singleton_handler_are_preserved(): void
    {
        $handler = new ExplicitCountingHandler;
        app(CommandRegistry::class)->register('scope.explicit', $handler);
        $this->app->singleton(ExplicitSingletonHandler::class);
        $singleton = app(ExplicitSingletonHandler::class);
        app(CommandRegistry::class)->register('scope.singleton', ExplicitSingletonHandler::class);
        $user = TestUser::create(['name' => 'Ada']);
        $this->redis->entries = [
            $this->entry($user, '1-0', [], 'scope.explicit'), $this->entry($user, '2-0', [], 'scope.explicit'),
            $this->entry($user, '3-0', [], 'scope.singleton'), $this->entry($user, '4-0', [], 'scope.singleton'),
        ];

        self::assertSame(4, app(CommandConsumer::class)->runOnce('scope-test'));
        self::assertSame(2, $handler->calls);
        self::assertSame(2, $singleton->calls);
        self::assertSame($handler, app(CommandRegistry::class)->resolve('scope.explicit'));
        self::assertSame($singleton, app(CommandRegistry::class)->resolve('scope.singleton'));
    }

    private function entry(TestUser $user, string $entryId, array $payload = [], string $command = 'scope.check'): array
    {
        $session = $this->sessionFor($user);
        $envelope = Envelope::make('socket.command', ['command' => $command, 'payload' => $payload, 'context' => [
            'user_id' => (string) $user->id, 'session_id' => $session['session_id'], 'socket_id' => 'origin',
        ]]);

        return ['id' => $entryId, 'envelope' => $envelope, 'raw' => json_encode($envelope, JSON_THROW_ON_ERROR)];
    }
}

class CommandScopedState
{
    public ?string $userId = null;

    public function instance(): self
    {
        return $this;
    }
}

class CommandScopeTrace
{
    public array $calls = [];
}

class CommandScopeFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CommandScopedState::class;
    }
}

class ScopedCommandHandler implements CommandHandler
{
    public function __construct(private readonly CommandScopedState $state, private readonly CommandScopeTrace $trace) {}

    public function handle(array $payload, CommandContext $context): array
    {
        $this->trace->calls[] = [
            'user_id' => $context->userId, 'previous_user' => $this->state->userId,
            'state' => $this->state, 'facade_state' => CommandScopeFacade::instance(),
            'handler' => $this, 'singleton' => $this->trace,
        ];
        $this->state->userId = $context->userId;
        if ($payload['fail'] ?? false) {
            throw new \RuntimeException('Transient handler failure after setting scoped user context.');
        }

        return ['user_id' => $context->userId];
    }
}

class ExplicitCountingHandler implements CommandHandler
{
    public int $calls = 0;

    public function handle(array $payload, CommandContext $context): array
    {
        return ['calls' => ++$this->calls];
    }
}

class ExplicitSingletonHandler extends ExplicitCountingHandler {}

class ScopeBatchRedis extends FakeRedis
{
    public array $entries = [];

    public array $acknowledged = [];

    public function createGroup(string $stream, string $group): void {}

    public function claim(string $stream, string $group, string $consumer, int $idleMs = 30000, int $count = 10): array
    {
        return [];
    }

    public function read(string $stream, string $group, string $consumer, int $count = 10, int $blockMs = 1000): array
    {
        $entries = $this->entries;
        $this->entries = [];

        return $entries;
    }

    public function ack(string $stream, string $group, string $id): void
    {
        $this->acknowledged[] = $id;
    }

    public function attempts(string $stream, string $group, string $id): int
    {
        return 1;
    }
}
