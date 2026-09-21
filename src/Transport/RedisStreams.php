<?php

namespace SocketBridge\Transport;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use RuntimeException;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\DTO\Json;
use Throwable;

class RedisStreams implements EnvelopeTransport
{
    private ?Connection $connection = null;

    private array $claimCursors = [];

    public function __construct(private readonly Application $app) {}

    public function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $name = (string) config('socket-bridge.redis_connection', 'default');
        $source = config('database.redis.'.$name);
        if (! is_array($source)) {
            throw new RuntimeException('Socket Bridge requires a configured standalone Redis connection.');
        }
        if (config('socket-bridge.redis_url')) {
            $source['url'] = config('socket-bridge.redis_url');
        }
        $source['prefix'] = '';
        $source['options'] = array_replace($source['options'] ?? [], ['prefix' => '']);
        $options = array_replace(config('database.redis.options', []), ['prefix' => '']);
        // A dedicated manager avoids mutating application connections or a manager
        // that was already resolved before this package booted.
        $manager = new RedisManager($this->app, config('database.redis.client', 'phpredis'), [
            'options' => $options, 'socket-bridge' => $source,
        ]);
        $this->connection = $manager->connection('socket-bridge');
        $client = $this->connection->client();
        if ($client instanceof \Redis) {
            $client->setOption(\Redis::OPT_PREFIX, '');
            $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            if (defined('Redis::OPT_COMPRESSION')) {
                $client->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_NONE);
            }
        }

        return $this->connection;
    }

    public function key(string $suffix): string
    {
        return rtrim((string) config('socket-bridge.prefix'), ':').':'.$suffix;
    }

    /** Raw wire commands normalize Predis and PhpRedis signatures/replies. */
    public function raw(string $command, mixed ...$arguments): mixed
    {
        $client = $this->connection()->client();
        $args = array_map(static fn ($value) => (string) $value, $arguments);
        if ($client instanceof \Redis) {
            $client->clearLastError();
            $result = $client->rawCommand(strtoupper($command), ...$args);
            $error = $client->getLastError();
            if (is_string($error) && $error !== '') {
                throw new RuntimeException('Redis '.strtoupper($command).' failed: '.$error);
            }

            return $result;
        }

        $error = false;
        $result = $client->executeRaw([strtoupper($command), ...$args], $error);
        if ($error) {
            throw new RuntimeException('Redis '.strtoupper($command).' failed: '.(string) $result);
        }

        return $result;
    }

    public function add(string $stream, array $envelope): string
    {
        $result = $this->raw('XADD', $this->key($stream), '*', 'envelope', Json::encodeEnvelope($envelope));
        if (! is_string($result)) {
            throw new RuntimeException('Redis did not acknowledge the stream write.');
        }

        return $result;
    }

    public function createGroup(string $stream, string $group): void
    {
        try {
            $this->raw('XGROUP', 'CREATE', $this->key($stream), $group, '0', 'MKSTREAM');
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /** @return list<array{id:string,envelope:array<string,mixed>|null,raw:string}> */
    public function read(string $stream, string $group, string $consumer, int $count = 10, int $blockMs = 1000): array
    {
        $reply = $this->raw('XREADGROUP', 'GROUP', $group, $consumer, 'COUNT', $count, 'BLOCK', max(1, $blockMs), 'STREAMS', $this->key($stream), '>');
        if (! is_array($reply) || $reply === []) {
            return [];
        }
        $entries = array_is_list($reply) ? ($reply[0][1] ?? []) : ($reply[$this->key($stream)] ?? []);

        return $this->entries($entries);
    }

    /** @return list<array{id:string,envelope:array<string,mixed>|null,raw:string}> */
    public function claim(string $stream, string $group, string $consumer, int $idleMs = 30000, int $count = 10): array
    {
        $cursorKey = $stream.':'.$group;
        $reply = $this->raw('XAUTOCLAIM', $this->key($stream), $group, $consumer, $idleMs, $this->claimCursors[$cursorKey] ?? '0-0', 'COUNT', $count);
        $this->claimCursors[$cursorKey] = is_array($reply) ? (string) ($reply[0] ?? '0-0') : '0-0';

        return $this->entries(is_array($reply) ? ($reply[1] ?? []) : []);
    }

    public function ack(string $stream, string $group, string $id): void
    {
        $this->raw('XACK', $this->key($stream), $group, $id);
    }

    public function attempts(string $stream, string $group, string $id): int
    {
        $reply = $this->raw('XPENDING', $this->key($stream), $group, $id, $id, 1);

        return (int) ($reply[0][3] ?? 1);
    }

    public function deadLetter(string $stream, string $group, string $id, string $raw, string $reason): void
    {
        $this->add('dead:'.$stream, ['source_id' => $id, 'raw' => $raw, 'reason' => $reason, 'failed_at' => now()->utc()->toISOString()]);
        $this->ack($stream, $group, $id);
    }

    public function range(string $stream, string $start = '-', string $end = '+', int $limit = 20, bool $reverse = false): array
    {
        $reply = $this->raw($reverse ? 'XREVRANGE' : 'XRANGE', $this->key($stream), ...[...($reverse ? [$end, $start] : [$start, $end]), 'COUNT', max(1, min($limit, 10000))]);

        return $this->entries(is_array($reply) ? $reply : []);
    }

    private function entries(array $entries): array
    {
        $result = [];
        foreach ($entries as $key => $entry) {
            if (is_string($key)) {
                $id = $key;
                $fields = $entry;
            } else {
                [$id, $fields] = $entry;
            }
            $raw = $fields['envelope'] ?? null;
            if ($raw === null && is_array($fields)) {
                for ($i = 0; $i < count($fields); $i += 2) {
                    if (($fields[$i] ?? null) === 'envelope') {
                        $raw = $fields[$i + 1] ?? '';
                    }
                }
            }
            $raw = is_string($raw) ? $raw : '';
            try {
                $decoded = Json::decodeEnvelope($raw);
            } catch (\JsonException) {
                $decoded = null;
            }
            $result[] = ['id' => (string) $id, 'raw' => $raw, 'envelope' => is_array($decoded) ? $decoded : null];
        }

        return $result;
    }
}
