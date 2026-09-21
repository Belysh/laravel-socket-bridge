<?php

namespace SocketBridge\Operations;

use SocketBridge\Transport\RedisStreams;
use Throwable;

/** Best-effort, fixed-cardinality operational telemetry. */
final class Metrics
{
    public const COUNTERS = ['commands_processed_total', 'commands_failed_total', 'commands_retried_total', 'commands_dead_letters_total', 'outbox_published_total', 'outbox_failed_total'];

    public const HISTOGRAMS = ['command_duration_seconds', 'outbox_lag_seconds'];

    public const BUCKETS = ['0.005', '0.01', '0.025', '0.05', '0.1', '0.25', '0.5', '1', '2.5', '5', '10', '30', '60'];

    public function __construct(private readonly RedisStreams $redis) {}

    public function increment(string $name): void
    {
        if (in_array($name, self::COUNTERS, true)) {
            $this->record([$name => 1]);
        }
    }

    public function observe(string $name, float $seconds): void
    {
        if (! in_array($name, self::HISTOGRAMS, true) || ! is_finite($seconds) || $seconds < 0) {
            return;
        }
        $fields = [$name.'_sum' => $seconds, $name.'_count' => 1, $name.'_bucket_inf' => 1];
        foreach (self::BUCKETS as $bucket) {
            if ($seconds <= (float) $bucket) {
                $fields[$name.'_bucket_'.$bucket] = 1;
            }
        }
        $this->record($fields);
    }

    public function values(): array
    {
        return HealthService::fields($this->redis->raw('HGETALL', $this->redis->key('metrics:php')) ?: []);
    }

    private function record(array $fields): void
    {
        if (! config('socket-bridge.metrics.enabled', false)) {
            return;
        }
        try {
            $arguments = [max(60, (int) config('socket-bridge.metrics.ttl_seconds', 86400))];
            foreach ($fields as $field => $value) {
                array_push($arguments, $field, $value);
            }
            $this->redis->raw('EVAL', "for i=2,#ARGV,2 do redis.call('HINCRBYFLOAT',KEYS[1],ARGV[i],ARGV[i+1]) end redis.call('EXPIRE',KEYS[1],ARGV[1]) return 1", 1, $this->redis->key('metrics:php'), ...$arguments);
        } catch (Throwable) {
            // Telemetry must never roll back work or prevent acknowledging a command.
        }
    }
}
