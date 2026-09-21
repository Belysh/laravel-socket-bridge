<?php

namespace SocketBridge\DTO;

use stdClass;

/** JSON objects with ambiguous PHP array keys keep an explicit object type. */
final class Json
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS;

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    public static function decode(string $json): mixed
    {
        return self::hydrate(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /** Decode a wire envelope without turning an invalid root list into an object. */
    public static function decodeEnvelope(string $json, bool $legacyOutbox = false): array
    {
        $envelope = self::decode($json);
        // Earlier SQL outbox rows used [] for an empty root object. This exception
        // applies only to trusted stored rows, never to incoming Redis commands.
        if ($legacyOutbox && is_array($envelope) && ($envelope['payload'] ?? null) === []) {
            $envelope['payload'] = (object) [];
        }
        if (! self::isEnvelope($envelope)) {
            throw new \JsonException('Invalid JSON envelope object or payload object.');
        }

        return $envelope;
    }

    public static function isEnvelope(mixed $envelope): bool
    {
        if (! is_array($envelope) || array_is_list($envelope)) {
            return false;
        }
        if (in_array($envelope['type'] ?? null, ['socket.command', 'socket.emit'], true)) {
            $payload = $envelope['payload'] ?? null;

            return $payload instanceof stdClass || (is_array($payload) && ! array_is_list($payload));
        }

        return true;
    }

    public static function encodeEnvelope(array $envelope): string
    {
        if (isset($envelope['payload']) && is_array($envelope['payload'])) {
            $envelope['payload'] = (object) $envelope['payload'];
        }
        if (isset($envelope['result']) && is_array($envelope['result'])) {
            $envelope['result'] = self::result($envelope['result']);
        }

        return self::encode($envelope);
    }

    /** Handler result arrays are field maps, including numeric-key maps. */
    public static function result(array $result): array
    {
        if (array_key_exists('data', $result) && is_array($result['data'])) {
            $result['data'] = self::object($result['data']);
        }

        return $result;
    }

    public static function object(array|stdClass $value): array|stdClass
    {
        if (is_array($value) && self::numericKeys($value)) {
            return (object) $value;
        }

        return $value;
    }

    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass || (is_array($value) && ! array_is_list($value))) {
            $fields = (array) $value;
            ksort($fields, SORT_STRING);

            return (object) array_map(self::canonical(...), $fields);
        }
        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }

        return $value;
    }

    private static function hydrate(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = array_map(self::hydrate(...), (array) $value);

            return self::object($fields);
        }
        if (is_array($value)) {
            return array_map(self::hydrate(...), $value);
        }

        return $value;
    }

    private static function numericKeys(array $fields): bool
    {
        foreach (array_keys($fields) as $key) {
            if (is_string($key)) {
                return false;
            }
        }

        return true;
    }
}
