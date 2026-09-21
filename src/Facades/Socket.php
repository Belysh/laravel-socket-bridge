<?php

namespace SocketBridge\Facades;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Facade;
use SocketBridge\Auth\SessionManager;
use SocketBridge\EnvelopePublisher;
use SocketBridge\SocketManager;
use SocketBridge\Testing\RecordingPublisher;
use SocketBridge\Testing\SocketFake;

/**
 * @method static \SocketBridge\PendingEmission toRoom(string|array $room)
 * @method static \SocketBridge\PendingEmission toUser(string|int $user)
 * @method static \SocketBridge\PendingEmission toTenant(string|int $tenant)
 * @method static \SocketBridge\PendingEmission durable()
 * @method static string invalidateUser(string|int $userId, string $reason = 'authorization_changed')
 * @method static string disconnectUser(string|int $userId, string $reason = 'disconnected')
 * @method static string disconnectSession(string $sessionId, string $reason = 'logged_out')
 * @method static string leaveRoom(string|int $userId, string $room)
 * @method static string joinRoom(string|int $userId, string $room)
 *
 * @see SocketManager
 */
class Socket extends Facade
{
    /** Capture facade emissions, controls and native socketio broadcasts without Redis or outbox writes. */
    public static function fake(): SocketFake
    {
        $publisher = new RecordingPublisher;
        static::$app->instance(EnvelopePublisher::class, $publisher);
        $fake = new SocketFake($publisher, static::$app->make(SessionManager::class));
        static::swap($fake);
        static::$app->make(BroadcastManager::class)->purge(config('socket-bridge.broadcast_connection', 'socketio'));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return SocketManager::class;
    }
}
