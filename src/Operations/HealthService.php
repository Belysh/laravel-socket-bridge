<?php

namespace SocketBridge\Operations;

use SocketBridge\Outbox\OutboxStore;
use SocketBridge\Transport\RedisStreams;

final class HealthService
{
    public function __construct(private readonly RedisStreams $redis, private readonly OutboxStore $outbox) {}

    public function snapshot(): array
    {
        $workers = ['commands' => [], 'outbox' => []];
        $gateways = [];
        foreach ($this->keys('health:*') as $key) {
            $value = $this->redis->raw('GET', $key);
            $record = is_string($value) ? json_decode($value, true) : null;
            if (! is_array($record) || (int) $this->redis->raw('TTL', $key) < 0) {
                continue;
            }
            if (str_starts_with($key, $this->redis->key('health:gateway:'))) {
                $gateways[] = $record;
            } elseif (isset($workers[$record['role'] ?? ''])) {
                $workers[$record['role']][] = $record;
            }
        }
        $streams = [];
        foreach (['commands', 'events'] as $stream) {
            $groups = [];
            if ($this->redis->raw('EXISTS', $this->redis->key($stream))) {
                foreach ($this->redis->raw('XINFO', 'GROUPS', $this->redis->key($stream)) as $group) {
                    $group = self::fields($group);
                    $pending = $this->redis->raw('XPENDING', $this->redis->key($stream), $group['name']);
                    $groups[] = ['name' => $group['name'], 'pending' => (int) $group['pending'], 'lag' => $group['lag'] ?? null, 'last_delivered_id' => $group['last-delivered-id'], 'oldest_pending_id' => $pending[1] ?? null];
                }
            }
            $streams[$stream] = ['length' => (int) $this->redis->raw('XLEN', $this->redis->key($stream)), 'groups' => $groups, 'dead_letters' => (int) $this->redis->raw('XLEN', $this->redis->key('dead:'.$stream))];
        }
        $query = $this->outbox->database()->table('socket_bridge_outbox')->whereNull('published_at');
        $oldest = (clone $query)->min('created_at');

        return [
            'protocol' => 1, 'healthy' => $workers['commands'] !== [] && $workers['outbox'] !== [] && $gateways !== [],
            'prefix' => config('socket-bridge.prefix'), 'workers' => $workers, 'gateways' => $gateways, 'streams' => $streams,
            'outbox' => ['pending' => (clone $query)->count(), 'failed' => (clone $query)->where('attempts', '>', 0)->count(), 'oldest_age_seconds' => $oldest === null ? null : (int) max(0, now()->parse($oldest)->diffInSeconds(now()))],
            'deduplication_window_seconds' => max(1, (int) config('socket-bridge.retention.receipts_seconds', 604800)),
            'cleanup' => app(CleanupStatus::class)->snapshot(),
        ];
    }

    private function keys(string $pattern): array
    {
        $cursor = '0';
        $keys = [];
        do {
            [$cursor, $batch] = $this->redis->raw('SCAN', $cursor, 'MATCH', $this->redis->key($pattern), 'COUNT', 100);
            $keys = array_merge($keys, $batch);
        } while ((string) $cursor !== '0');

        return array_values(array_unique($keys));
    }

    public static function fields(array $fields): array
    {
        if (! array_is_list($fields)) {
            return $fields;
        }
        $result = [];
        for ($i = 0; $i < count($fields); $i += 2) {
            $result[$fields[$i]] = $fields[$i + 1];
        }

        return $result;
    }
}
