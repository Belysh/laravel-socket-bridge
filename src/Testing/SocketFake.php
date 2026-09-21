<?php

namespace SocketBridge\Testing;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use SocketBridge\Auth\SessionManager;
use SocketBridge\DTO\Envelope;
use SocketBridge\SocketManager;

final class SocketFake extends SocketManager
{
    public function __construct(private readonly RecordingPublisher $recording, SessionManager $sessions)
    {
        parent::__construct($recording, $sessions);
    }

    public function recorded(): Collection
    {
        return collect($this->recording->records);
    }

    /** Callback receives payload, envelope and whether durable() was selected. */
    public function sent(string $event, ?callable $callback = null): Collection
    {
        return $this->recorded()->filter(fn (array $record) => $record['envelope']['type'] === 'socket.emit'
            && $record['envelope']['event'] === $event
            && ($callback === null || $callback($record['envelope']['payload'], $record['envelope'], $record['durable'])))->values();
    }

    public function assertSent(string $event, callable|int|null $callback = null): void
    {
        if (is_int($callback)) {
            Assert::assertCount($callback, $this->sent($event), "Expected {$callback} emissions of [{$event}].");

            return;
        }
        Assert::assertTrue($this->sent($event, $callback)->isNotEmpty(), "Expected event [{$event}] was not emitted.");
    }

    public function assertNotSent(string $event, ?callable $callback = null): void
    {
        Assert::assertCount(0, $this->sent($event, $callback), "Unexpected event [{$event}] was emitted.");
    }

    public function assertSentToRoom(string $room, string $event, ?callable $callback = null): void
    {
        $this->assertSent($event, fn ($payload, $envelope, $durable) => in_array($room, $envelope['rooms'], true)
            && ($callback === null || $callback($payload, $envelope, $durable)));
    }

    public function assertSentToUser(string|int $userId, string $event, ?callable $callback = null): void
    {
        $this->assertSentToRoom(Envelope::userRoom($userId), $event, $callback);
    }

    public function assertControlSent(string $type, ?callable $callback = null): void
    {
        Assert::assertTrue($this->recorded()->contains(fn ($record) => $record['envelope']['type'] === $type
            && ($callback === null || $callback($record['envelope']))), "Expected control [{$type}] was not sent.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertCount(0, $this->recorded(), 'Unexpected Socket Bridge emissions or controls were sent.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded());
    }

    protected function revokeUser(string $userId): void {}

    protected function revokeSession(string $sessionId): void {}
}
