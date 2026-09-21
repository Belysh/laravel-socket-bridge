<?php

namespace SocketBridge\Console;

use Illuminate\Console\Command;
use SocketBridge\Operations\Maintenance;

final class PruneCommand extends Command
{
    protected $signature = 'socket-bridge:prune {--dry-run : Inspect eligible records without removing them} {--limit=1000 : Maximum records per stream/table} {--json : Print machine-readable JSON}';

    protected $aliases = ['socket:prune'];

    protected $description = 'Prune acknowledged stream entries and expired published records safely';

    public function handle(Maintenance $maintenance): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('--limit must be between 1 and 10000.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($maintenance->prune((bool) $this->option('dry-run'), $limit), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Pruning failed. Check application logs and socket-bridge:doctor.');

            return self::FAILURE;
        }
    }
}
