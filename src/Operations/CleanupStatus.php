<?php

namespace SocketBridge\Operations;

use SocketBridge\Transport\RedisStreams;
use Throwable;

/** Last completed real cleanup run, with aggregate values only. */
final class CleanupStatus
{
    public function __construct(private readonly RedisStreams $redis) {}

    public function record(array $report): void
    {
        $resources = $report['streams'] + ['receipts' => $report['receipts'], 'published_outbox' => $report['published_outbox']];
        $snapshot = [
            'completed_at' => time(),
            'duration_seconds' => $report['duration_seconds'],
            'time_limit_reached' => $report['time_limit_reached'],
            'limit' => $report['limit'],
            'batch_size' => $report['batch_size'],
            'max_seconds' => $report['max_seconds'],
            'resources' => [],
        ];
        foreach ($resources as $name => $resource) {
            $snapshot['resources'][$name] = array_intersect_key($resource, array_flip(['scanned', 'eligible', 'deleted', 'has_more', 'retention_lag_seconds', 'protected']));
        }
        try {
            $this->redis->raw('SET', $this->redis->key('operations:prune'), json_encode($snapshot, JSON_THROW_ON_ERROR), 'EX', 604800);
        } catch (Throwable) {
            // A telemetry outage must not turn completed cleanup into a failed operation.
        }
    }

    public function snapshot(): ?array
    {
        $raw = $this->redis->raw('GET', $this->redis->key('operations:prune'));
        $value = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($value) && isset($value['completed_at'], $value['resources']) ? $value : null;
    }

    /** Internal traversal state is never included in status/metrics. */
    public function receiptCursor(): ?array
    {
        try {
            $raw = $this->redis->raw('GET', $this->redis->key('operations:prune:receipts:cursor'));
            $cursor = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($cursor) && array_is_list($cursor) && count($cursor) === 3 && count(array_filter($cursor, 'is_string')) === 3 ? $cursor : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function rememberReceiptCursor(?array $cursor): void
    {
        try {
            $key = $this->redis->key('operations:prune:receipts:cursor');
            if ($cursor === null) {
                $this->redis->raw('DEL', $key);
            } else {
                $this->redis->raw('SET', $key, json_encode($cursor, JSON_THROW_ON_ERROR), 'EX', 604800);
            }
        } catch (Throwable) {
            // Losing scan progress delays cleanup but never permits unsafe deletion.
        }
    }
}
