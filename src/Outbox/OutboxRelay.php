<?php

namespace SocketBridge\Outbox;

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
                try {
                    $this->transport->add('events', Json::decodeEnvelope($row->envelope, legacyOutbox: true));
                    $query->update(['published_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null, 'updated_at' => now()]);
                    $lag = max(0, now()->parse($row->created_at)->diffInSeconds(now()));

                    return 1;
                } catch (Throwable $error) {
                    $failed = true;
                    $delay = min(3600, (int) config('socket-bridge.outbox_retry_seconds', 5) * (2 ** min($row->attempts, 10)));
                    $query->update(['attempts' => $row->attempts + 1, 'available_at' => now()->addSeconds($delay), 'last_error' => substr($error->getMessage(), 0, 2000), 'updated_at' => now()]);
                    report($error);

                    return 0;
                }
            });
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
