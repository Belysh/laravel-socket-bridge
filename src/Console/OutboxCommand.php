<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use Illuminate\Console\Command;
use SocketBridge\Outbox\OutboxRelay;

final class OutboxCommand extends Command
{
    protected $aliases = ['socket:outbox'];

    protected $signature = 'socket-bridge:outbox
        {--once : Publish one batch and exit}
        {--stop-when-empty : Exit after an empty batch}
        {--sleep=1 : Seconds to wait after an empty batch}
        {--limit=100 : Maximum outbox rows in one batch}
        {--max-time= : Exit between operations after this many seconds; zero disables the limit}
        {--memory= : Exit between operations above this memory usage in MiB; zero disables the limit}
        {--max-messages=0 : Exit after publishing this many events; zero means unlimited}';

    protected $description = 'Publish committed Socket Bridge outbox rows to Redis Streams';

    public function handle(OutboxRelay $relay): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('--limit must be between 1 and 10000.');

            return self::FAILURE;
        }

        return (new WorkerLoop)->run($this, fn (callable $shouldStop): int => $relay->runOnce($limit, $shouldStop), 'outbox');
    }
}
