<?php

namespace SocketBridge\Console;

use Illuminate\Console\Command;
use SocketBridge\Operations\HealthService;

final class StatusCommand extends Command
{
    protected $signature = 'socket-bridge:status {--json : Print machine-readable JSON}';

    protected $aliases = ['socket:status'];

    protected $description = 'Inspect gateway and worker heartbeats, stream lag, pending entries and outbox';

    public function handle(HealthService $health): int
    {
        try {
            $snapshot = $health->snapshot();
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $snapshot['healthy'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $error) {
            report($error);
            $this->line(json_encode(['healthy' => false, 'error' => 'Health check unavailable; check application logs and Redis/database connectivity.'], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
