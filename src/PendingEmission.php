<?php

namespace SocketBridge;

use SocketBridge\DTO\Envelope;

final class PendingEmission
{
    private array $rooms = [];

    private ?string $exceptSocket = null;

    private ?string $exceptUser = null;

    private ?string $id = null;

    private bool $durable = false;

    private ?int $expiresAt = null;

    private ?string $correlationId = null;

    public function __construct(private readonly EnvelopePublisher $publisher) {}

    public function toRoom(string|array $rooms): self
    {
        $clone = clone $this;
        foreach ((array) $rooms as $room) {
            $clone->rooms[] = Envelope::room($room);
        }
        $clone->rooms = array_values(array_unique($clone->rooms));

        return $clone;
    }

    public function toUser(string|int $id): self
    {
        $clone = clone $this;
        $clone->rooms[] = Envelope::userRoom($id);

        return $clone;
    }

    public function toTenant(string|int $id): self
    {
        return $this->toRoom('private-tenant.'.$id);
    }

    public function exceptSocket(string $id): self
    {
        $clone = clone $this;
        $clone->exceptSocket = Envelope::identifier($id);

        return $clone;
    }

    public function exceptUser(string|int $id): self
    {
        $clone = clone $this;
        $clone->exceptUser = Envelope::identifier($id, 191);

        return $clone;
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function ephemeral(int $ttlSeconds = 5): self
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 300) {
            throw new \InvalidArgumentException('Ephemeral lifetime must be between 1 and 300 seconds.');
        }
        $clone = clone $this;
        $clone->expiresAt = time() + $ttlSeconds;

        return $clone;
    }

    public function correlate(string $id): self
    {
        Envelope::correlation($id);
        $clone = clone $this;
        $clone->correlationId = $id;

        return $clone;
    }

    public function durable(): self
    {
        $clone = clone $this;
        $clone->durable = true;

        return $clone;
    }

    public function emit(string $event, array|\stdClass $payload = []): string
    {
        if ($this->durable && $this->expiresAt !== null) {
            throw new \InvalidArgumentException('Ephemeral emissions cannot use the durable outbox.');
        }
        Envelope::event($event);
        Envelope::payload($payload);
        if ($this->rooms === [] || count(array_unique($this->rooms)) > 1000) {
            throw new \InvalidArgumentException('Specify between one and 1000 recipient channels.');
        }
        $fields = ['event' => $event, 'rooms' => array_values(array_unique($this->rooms)), 'payload' => $payload];
        if ($this->expiresAt !== null) {
            $fields['expires_at'] = $this->expiresAt;
        }
        if ($this->correlationId !== null) {
            $fields['correlation_id'] = $this->correlationId;
        }
        if ($this->exceptSocket !== null) {
            $fields['except_socket'] = $this->exceptSocket;
        }
        if ($this->exceptUser !== null) {
            $fields['except_user'] = $this->exceptUser;
        }

        return $this->publisher->publish(Envelope::make('socket.emit', $fields, $this->id), $this->durable);
    }
}
