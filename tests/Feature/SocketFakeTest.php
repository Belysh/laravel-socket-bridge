<?php

namespace SocketBridge\Tests\Feature;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use SocketBridge\Facades\Socket;
use SocketBridge\Tests\TestCase;

class SocketFakeTest extends TestCase
{
    public function test_fake_captures_emissions_and_controls_without_external_side_effects(): void
    {
        Socket::fake();
        Socket::assertNothingSent();
        Socket::durable()->toUser(42)->exceptSocket('origin')->emit('order.paid', ['id' => 7]);
        Socket::disconnectUser(42);
        Socket::assertSentToUser(42, 'order.paid', fn ($payload, $envelope, $durable) => $payload['id'] === 7 && $durable && $envelope['except_socket'] === 'origin');
        Socket::assertSent('order.paid', 1);
        Socket::assertNotSent('order.cancelled');
        Socket::assertControlSent('socket.disconnect_user', fn ($envelope) => $envelope['user_id'] === '42');
        Socket::assertSentCount(2);
        self::assertSame([], $this->redis->envelopes);
        self::assertSame([], $this->redis->values);
        $this->assertDatabaseCount('socket_bridge_outbox', 0);
    }

    public function test_fake_captures_native_broadcasts_even_if_driver_was_resolved_first(): void
    {
        Broadcast::connection('socketio');
        Socket::fake();
        event(new FakeOrderPaid);
        Socket::assertSentToRoom('private-orders.7', 'order.paid', fn ($payload) => $payload['id'] === 7);
        self::assertSame([], $this->redis->envelopes);
    }

    public function test_generator_creates_namespaced_handler_and_preserves_existing_file(): void
    {
        $path = app_path('SocketCommands/Orders/Pay.php');
        try {
            $this->artisan('make:socket-command', ['name' => 'Orders/Pay'])->assertSuccessful();
            $source = file_get_contents($path);
            self::assertStringContainsString('namespace App\\SocketCommands\\Orders;', $source);
            self::assertStringContainsString('implements CommandHandler', $source);
            file_put_contents($path, '<?php // application edit');
            $this->artisan('make:socket-command', ['name' => 'Orders/Pay'])->run();
            self::assertSame('<?php // application edit', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}

class FakeOrderPaid implements ShouldBroadcastNow
{
    public int $id = 7;

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.7')];
    }

    public function broadcastAs(): string
    {
        return 'order.paid';
    }
}
