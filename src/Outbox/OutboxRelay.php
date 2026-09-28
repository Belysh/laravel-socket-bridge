<?php

namespace SocketBridge\Outbox;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\DTO\Json;
use SocketBridge\Operations\Metrics;
use Throwable;

class OutboxRelay
{
    public function __construct(private readonly OutboxStore $store, private readonly EnvelopeTransport $transport) {}

    public function runOnce(int $limit = 100, ?callable $shouldStop = null): int
    {
        $published = 0;
        $ids = $this->store->database()->table('socket_bridge_outbox')
            ->whereNull('published_at')->where('available_at', '<=', now())
            ->orderBy('created_at')->limit(max(1, min($limit, 1000)))->pluck('id');
        foreach ($ids as $id) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }
            $lag = null;
            $failed = false;
            $count = $this->store->database()->transaction(function () use ($id, &$lag, &$failed): int {
                $query = $this->store->database()->table('socket_bridge_outbox')->where('id', $id);
                $row = (clone $query)->lockForUpdate()->first();
                if ($row === null || $row->published_at !== null || now()->lessThan($row->available_at)) {
                    return 0;
                }
                $trace = json_decode($row->envelope, true);
                $correlation = is_string($trace['correlation_id'] ?? null) && preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $trace['correlation_id']) ? $trace['correlation_id'] : null;
                try {
                    $this->transport->add('events', Json::decodeEnvelope($row->envelope, legacyOutbox: true));
                    $query->update(['published_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null, 'updated_at' => now()]);
                    $lag = max(0, now()->parse($row->created_at)->diffInSeconds(now()));

                    return 1;
                } catch (QueryException $error) {
                    // Retry the entire transaction after a deadlock; a PostgreSQL
                    // transaction cannot record a failure after a SQL error.
                    throw $error;
                } catch (Throwable $error) {
                    $failed = true;
                    $delay = min(3600, (int) config('socket-bridge.outbox_retry_seconds', 5) * (2 ** min($row->attempts, 10)));
                    $query->update(['attempts' => $row->attempts + 1, 'available_at' => now()->addSeconds($delay), 'last_error' => 'outbox.publish_failed: '.$error::class, 'updated_at' => now()]);
                    Log::warning('socket_bridge.outbox.failed', ['event_id' => $id, 'correlation_id' => $correlation, 'exception_class' => $error::class]);

                    return 0;
                }
            }, 5);
            $published += $count;
            if ($count > 0) {
                app(Metrics::class)->increment('outbox_published_total');
                app(Metrics::class)->observe('outbox_lag_seconds', (float) $lag);
            } elseif ($failed) {
                app(Metrics::class)->increment('outbox_failed_total');
            }
        }

        return $published;
    }

    public function flush(int $limit = 100): int
    {
        return $this->runOnce($limit);
    }
}
