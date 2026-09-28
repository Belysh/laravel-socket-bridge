<?php

namespace SocketBridge\Tests\Feature;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Commands\CommandProcessor;
use SocketBridge\Commands\CommandRegistry;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\DTO\Envelope;
use SocketBridge\Facades\Socket;
use SocketBridge\Tests\TestCase;
use SocketBridge\Tests\TestUser;

final class DeliveryControlsTest extends TestCase
{
    public function test_ephemeral_deadline_and_correlation_reach_the_transport(): void
    {
        Socket::toRoom('private-chat.1')->ephemeral(5)->correlate('request-123')->emit('chat.typing', ['active' => true]);
        $e = $this->redis->envelopes[0]['envelope'];
        self::assertSame('request-123', $e['correlation_id']);
        self::assertLessThanOrEqual(time() + 5, $e['expires_at']);
        self::assertGreaterThan(time(), $e['expires_at']);
        $this->expectException(\InvalidArgumentException::class);
        Socket::toRoom('private-chat.1')->ephemeral()->durable()->emit('chat.typing');
    }

    public function test_expired_registered_command_is_not_executed_and_fresh_command_inherits_correlation(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        $session = $this->sessionFor($user);
        $handler = new class implements CommandHandler
        {
            public int $calls = 0;

            public function handle(array $payload, CommandContext $context): array
            {
                $this->calls++;
                Socket::toUser($context->userId)->emit('chat.typing', ['active' => true]);

                return ['sent' => true];
            }
        };
        app(CommandRegistry::class)->register('chat.typing', $handler, ttlSeconds: 5);
        $correlation = (string) Str::uuid();
        $e = Envelope::make('socket.command', ['command' => 'chat.typing', 'payload' => ['active' => true], 'context' => ['user_id' => (string) $user->id, 'session_id' => $session['session_id'], 'socket_id' => 'socket', 'request_id' => $correlation]]);
        $e['created_at'] = now()->subSeconds(10)->toISOString();
        $result = app(CommandProcessor::class)->process($e);
        self::assertSame('command.expired', $result['error']['code']);
        self::assertSame(0, $handler->calls);
        $e['id'] = (string) Str::uuid();
        $e['created_at'] = now()->toISOString();
        Context::addHidden('socket_bridge.correlation_id', 'outer-request');
        self::assertTrue(app(CommandProcessor::class)->process($e)['ok']);
        self::assertSame(1, $handler->calls);
        self::assertSame($correlation, $this->redis->envelopes[0]['envelope']['correlation_id']);
        self::assertSame('outer-request', Context::getHidden('socket_bridge.correlation_id'));
        $reply = json_decode(DB::table('socket_bridge_outbox')->orderByDesc('id')->value('envelope'), true);
        self::assertSame($correlation, $reply['correlation_id']);
        Context::forgetHidden('socket_bridge.correlation_id');
    }
}
