<?php

namespace SocketBridge\Commands;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

final readonly class CommandContext
{
    public function __construct(public Authenticatable $user, public string $userId, public string $sessionId, public string $socketId, public string $commandId) {}

    /** Carry the verified origin into Laravel's queued BroadcastEvent. */
    public function broadcast(ShouldBroadcast $event): void
    {
        if (! property_exists($event, 'socket')) {
            throw new \InvalidArgumentException('Use InteractsWithSockets on the broadcast event.');
        }
        $event->socket = $this->socketId;
        event($event);
    }
}
