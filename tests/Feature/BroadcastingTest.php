<?php

namespace SocketBridge\Tests\Feature;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Facades\Socket;
use SocketBridge\Outbox\OutboxRelay;
use SocketBridge\Tests\TestCase;
use SocketBridge\Tests\TestUser;

class BroadcastingTest extends TestCase
{
    public function test_native_broadcast_preserves_private_channel_and_origin_exclusion(): void
    {
        $this->app['request']->headers->set('X-Socket-ID', 'origin-socket');
        broadcast(new ExampleEvent)->toOthers();
        $envelope = $this->redis->envelopes[0]['envelope'];
        self::assertSame('socket.emit', $envelope['type']);
        self::assertSame(['private-chat.42'], $envelope['rooms']);
        self::assertSame('origin-socket', $envelope['except_socket']);
        self::assertSame(['text' => 'hello'], $envelope['payload']);
    }

    public function test_command_context_carries_origin_without_an_http_socket_header(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        $context = new CommandContext($user, (string) $user->id, hash('sha256', 'session'), 'verified-command-origin', (string) Str::uuid());
        $context->broadcast(new ExampleEvent);
        self::assertSame('verified-command-origin', $this->redis->envelopes[0]['envelope']['except_socket']);
    }

    public function test_native_after_commit_event_is_discarded_on_rollback(): void
    {
        DB::beginTransaction();
        event(new ExampleEvent);
        self::assertEmpty($this->redis->envelopes);
        DB::rollBack();
        self::assertEmpty($this->redis->envelopes);
        DB::transaction(fn () => event(new ExampleEvent));
        self::assertCount(1, $this->redis->envelopes);
    }

    public function test_native_notification_uses_default_private_user_channel(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        $user->notify(new ExampleNotification);
        $envelope = $this->redis->envelopes[0]['envelope'];
        self::assertSame(['private-SocketBridge.Tests.TestUser.'.$user->id], $envelope['rooms']);
        self::assertSame('notification.created', $envelope['event']);
        self::assertSame('Welcome', $envelope['payload']['message']);
        self::assertArrayHasKey('id', $envelope['payload']);
    }

    public function test_durable_events_rollback_and_publish_once_after_commit(): void
    {
        DB::beginTransaction();
        Socket::durable()->toUser(7)->emit('updated', ['id' => 7]);
        DB::rollBack();
        self::assertDatabaseCount('socket_bridge_outbox', 0);
        $id = DB::transaction(fn () => Socket::durable()->toRoom('private-chat.1')->emit('updated', ['id' => 7]));
        self::assertEmpty($this->redis->envelopes);
        self::assertSame(1, app(OutboxRelay::class)->runOnce());
        self::assertSame($id, $this->redis->envelopes[0]['envelope']['id']);
        self::assertSame(0, app(OutboxRelay::class)->runOnce());
    }

    public function test_failed_outbox_delivery_stays_retryable_with_same_id(): void
    {
        $id = Socket::durable()->toUser(1)->emit('updated');
        $this->redis->failAdds = true;
        self::assertSame(0, app(OutboxRelay::class)->runOnce());
        self::assertDatabaseHas('socket_bridge_outbox', ['id' => $id, 'published_at' => null, 'attempts' => 1]);
        $this->redis->failAdds = false;
        $this->travel(6)->seconds();
        self::assertSame(1, app(OutboxRelay::class)->runOnce());
        self::assertSame($id, $this->redis->envelopes[0]['envelope']['id']);
    }

    public function test_control_invalidation_changes_authoritative_version_before_publish(): void
    {
        Socket::invalidateUser('3');
        self::assertSame(1, $this->redis->values[$this->redis->key('user-version:'.hash('sha256', '3'))]);
        self::assertSame('socket.auth.invalidate', $this->redis->envelopes[0]['envelope']['type']);
    }
}

class ExampleEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use InteractsWithSockets;

    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.42')];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.created';
    }

    public function broadcastWith(): array
    {
        return ['text' => 'hello'];
    }
}

class ExampleNotification extends Notification
{
    public function via($notifiable): array
    {
        return ['broadcast'];
    }

    public function toArray($notifiable): array
    {
        return ['message' => 'Welcome'];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }
}
