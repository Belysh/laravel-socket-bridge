<?php

namespace SocketBridge\Console;

use Illuminate\Console\Command;
use SocketBridge\Operations\Maintenance;

final class PruneCommand extends Command
{
    protected $signature = 'socket-bridge:prune
        {--dry-run : Inspect eligible records without removing them}
        {--limit= : Maximum records scanned per stream/table; defaults to retention.limit}
        {--batch-size= : Records per atomic batch; defaults to retention.batch_size}
        {--max-seconds= : Cooperative run time budget; defaults to retention.max_seconds}
        {--json : Print machine-readable JSON}';

    protected $aliases = ['socket:prune'];

    protected $description = 'Prune acknowledged stream entries and expired published records safely';

    public function handle(Maintenance $maintenance): int
    {
        $limit = filter_var($this->option('limit') ?? config('socket-bridge.retention.limit', 10000), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($limit === false) {
            $this->error('--limit must be between 1 and 1000000.');

            return self::FAILURE;
        }
        $batchSize = filter_var($this->option('batch-size') ?? config('socket-bridge.retention.batch_size', 100), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($batchSize === false) {
            $this->error('--batch-size must be between 1 and 1000.');

            return self::FAILURE;
        }
        $maxSeconds = filter_var($this->option('max-seconds') ?? config('socket-bridge.retention.max_seconds', 10), FILTER_VALIDATE_FLOAT);
        if ($maxSeconds === false || ! is_finite($maxSeconds) || $maxSeconds < 0.01 || $maxSeconds > 300) {
            $this->error('--max-seconds must be between 0.01 and 300.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($maintenance->prune((bool) $this->option('dry-run'), $limit, $batchSize, $maxSeconds), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Pruning failed. Check application logs and socket-bridge:doctor.');

            return self::FAILURE;
        }
    }
}
