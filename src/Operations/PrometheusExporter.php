<?php

namespace SocketBridge\Operations;

final class PrometheusExporter
{
    public function __construct(private readonly Metrics $metrics, private readonly HealthService $health) {}

    public function render(): string
    {
        $values = $this->metrics->values();
        $lines = [];
        foreach (Metrics::COUNTERS as $name) {
            $lines[] = '# TYPE socket_bridge_'.$name.' counter';
            $lines[] = 'socket_bridge_'.$name.' '.($values[$name] ?? 0);
        }
        foreach (Metrics::HISTOGRAMS as $name) {
            $metric = 'socket_bridge_'.$name;
            $lines[] = '# TYPE '.$metric.' histogram';
            foreach ([...Metrics::BUCKETS, 'inf'] as $bucket) {
                $lines[] = $metric.'_bucket{le="'.($bucket === 'inf' ? '+Inf' : $bucket).'"} '.($values[$name.'_bucket_'.$bucket] ?? 0);
            }
            $lines[] = $metric.'_sum '.($values[$name.'_sum'] ?? 0);
            $lines[] = $metric.'_count '.($values[$name.'_count'] ?? 0);
        }
        $health = $this->health->snapshot();
        $gauges = [
            'healthy' => [(int) $health['healthy']],
            'gateways' => [count($health['gateways'])],
            'workers' => array_map('count', $health['workers']),
            'stream_pending' => [], 'stream_lag' => [], 'dead_letters' => [],
            'outbox_pending' => [$health['outbox']['pending']],
            'outbox_oldest_age_seconds' => [$health['outbox']['oldest_age_seconds'] ?? 0],
        ];
        foreach ($health['streams'] as $stream => $state) {
            $gauges['stream_pending'][$stream] = array_sum(array_column($state['groups'], 'pending'));
            // Redis may return unknown lag after an administrative group reset.
            $lags = array_column($state['groups'], 'lag');
            $gauges['stream_lag'][$stream] = in_array(null, $lags, true) ? 'NaN' : array_sum($lags);
            $gauges['dead_letters'][$stream] = $state['dead_letters'];
        }
        $cleanup = $health['cleanup'];
        $gauges['cleanup_last_run_timestamp_seconds'] = [$cleanup['completed_at'] ?? 0];
        $gauges['cleanup_duration_seconds'] = [$cleanup['duration_seconds'] ?? 'NaN'];
        $gauges['cleanup_time_limit_reached'] = [$cleanup === null ? 'NaN' : (int) $cleanup['time_limit_reached']];
        foreach (['scanned', 'eligible', 'deleted', 'has_more', 'retention_lag_seconds', 'protected'] as $field) {
            foreach (['events', 'commands', 'dead:events', 'dead:commands', 'receipts', 'published_outbox'] as $resource) {
                $value = $cleanup['resources'][$resource][$field] ?? null;
                $gauges['cleanup_'.$field][$resource] = $value === null ? 'NaN' : (int) $value;
            }
        }
        foreach ($gauges as $name => $samples) {
            $lines[] = '# TYPE socket_bridge_'.$name.' gauge';
            foreach ($samples as $label => $value) {
                $labelName = str_starts_with($name, 'cleanup_') ? 'resource' : ($name === 'workers' ? 'role' : 'stream');
                $labels = is_string($label) ? '{'.$labelName.'="'.$label.'"}' : '';
                $lines[] = 'socket_bridge_'.$name.$labels.' '.$value;
            }
        }

        return implode("\n", $lines)."\n";
    }
}
