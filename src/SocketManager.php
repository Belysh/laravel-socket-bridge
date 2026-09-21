<?php

namespace SocketBridge;

use SocketBridge\Auth\SessionManager;
use SocketBridge\DTO\Envelope;

class SocketManager
{
    public function __construct(private readonly EnvelopePublisher $publisher, private readonly SessionManager $sessions) {}

    public function toRoom(string|array $room): PendingEmission
    {
        return $this->pending()->toRoom($room);
    }

    public function toUser(string|int $user): PendingEmission
    {
        return $this->pending()->toUser($user);
    }

    public function toTenant(string|int $tenant): PendingEmission
    {
        return $this->pending()->toTenant($tenant);
    }

    public function durable(): PendingEmission
    {
        return $this->pending()->durable();
    }

    public function invalidateUser(string|int $userId, string $reason = 'authorization_changed'): string
    {
        $userId = Envelope::identifier($userId, 191);
        $this->revokeUser($userId);

        return $this->control('socket.auth.invalidate', ['user_id' => (string) $userId, 'reason' => $reason]);
    }

    public function disconnectUser(string|int $userId, string $reason = 'disconnected'): string
    {
        // Prevent a reconnect using an already-issued ticket/session.
        $userId = Envelope::identifier($userId, 191);
        $this->revokeUser($userId);

        return $this->control('socket.disconnect_user', ['user_id' => (string) $userId, 'reason' => $reason]);
    }

    public function disconnectSession(string $sessionId, string $reason = 'logged_out'): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $sessionId)) {
            throw new \InvalidArgumentException('Session identifier must be an opaque bridge session id.');
        }
        $this->revokeSession($sessionId);

        return $this->control('socket.disconnect_session', ['session_id' => $sessionId, 'reason' => $reason]);
    }

    public function leaveRoom(string|int $userId, string $room): string
    {
        return $this->control('socket.leave_room', ['user_id' => Envelope::identifier($userId, 191), 'room' => Envelope::room($room)]);
    }

    public function joinRoom(string|int $userId, string $room): string
    {
        return $this->control('socket.join_room', ['user_id' => Envelope::identifier($userId, 191), 'room' => Envelope::room($room)]);
    }

    protected function revokeUser(string $userId): void
    {
        $this->sessions->invalidateUser($userId);
    }

    protected function revokeSession(string $sessionId): void
    {
        $this->sessions->disconnectSession($sessionId);
    }

    private function pending(): PendingEmission
    {
        return new PendingEmission($this->publisher);
    }

    private function control(string $type, array $fields): string
    {
        return $this->publisher->publish(Envelope::make($type, $fields));
    }
}
