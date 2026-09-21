<?php

namespace SocketBridge\DTO;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class Envelope
{
    private const RESERVED_EVENTS = ['connect', 'connect_error', 'disconnect', 'disconnecting', 'newListener', 'removeListener'];

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    public static function make(string $type, array $fields = [], ?string $id = null): array
    {
        if ($id !== null && ! self::validId($id)) {
            throw new InvalidArgumentException('Envelope id must be a UUID.');
        }

        return ['v' => 1, 'id' => $id === null ? (string) Str::uuid() : strtolower($id), 'type' => $type, 'created_at' => now()->utc()->toISOString()] + $fields;
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) === 1;
    }

    public static function identifier(string|int $id, int $maximum = 200): string
    {
        $id = (string) $id;
        if ($id === '' || strlen($id) > $maximum) {
            throw new InvalidArgumentException('Recipient identifier is empty or exceeds the protocol limit.');
        }

        return $id;
    }

    public static function event(string $event): void
    {
        if ($event === '' || strlen($event) > 200 || in_array($event, self::RESERVED_EVENTS, true) || str_starts_with($event, 'bridge.')) {
            throw new InvalidArgumentException('Invalid or reserved Socket.IO event name.');
        }
    }

    public static function room(string $room, bool $internal = false): string
    {
        if (! preg_match('/^[a-zA-Z0-9_.:-]{1,200}$/D', $room) || (! $internal && str_starts_with($room, '__'))) {
            throw new InvalidArgumentException('Invalid or reserved channel name.');
        }

        return $room;
    }

    public static function userRoom(string|int $id): string
    {
        return '__user:'.hash('sha256', self::identifier($id, 191));
    }

    /** @param array<string, mixed> $payload */
    public static function payload(array $payload): void
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidArgumentException('Socket payload must be an object.');
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        if (strlen($encoded) > (int) config('socket-bridge.max_payload_bytes', 65536)) {
            throw new InvalidArgumentException('Socket payload exceeds the configured size limit.');
        }
    }
}
