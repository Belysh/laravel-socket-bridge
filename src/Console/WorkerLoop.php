<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use Illuminate\Console\Command;
use SocketBridge\Operations\WorkerHeartbeat;
use SocketBridge\Operations\WorkerRestart;
use Throwable;

final class WorkerLoop
{
    public function run(Command $command, callable $batch, string $role, ?string $id = null): int
    {
        $sleep = filter_var($command->option('sleep'), FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 60]]);
        $maximum = filter_var($command->option('max-messages'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $maxTime = filter_var($command->option('max-time') ?? config('socket-bridge.workers.max_time', 3600), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $memory = filter_var($command->option('memory') ?? config('socket-bridge.workers.memory_mb', 128), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($sleep === false || $maximum === false || $maxTime === false || $memory === false) {
            $command->error('--sleep must be between 0 and 60; --max-messages, --max-time and --memory must be nonnegative integers.');

            return Command::FAILURE;
        }
        $stop = false;
        $handlers = [];
        $async = null;
        if (function_exists('pcntl_async_signals')) {
            $async = pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static function () use (&$stop): void {
                    $stop = true;
                });
            }
        }
        $processed = 0;
        $heartbeat = app(WorkerHeartbeat::class);
        try {
            $restart = app(WorkerRestart::class);
            $generation = $restart->generation($role);
            $started = microtime(true);
            $lastCheck = 0.0;
            $reason = null;
            $shouldStop = function () use (&$stop, &$lastCheck, &$reason, $restart, $generation, $role, $started, $maxTime, $memory): bool {
                if ($stop) {
                    return true;
                }
                if ($maxTime > 0 && microtime(true) - $started >= $maxTime) {
                    $reason = 'time limit';

                    return $stop = true;
                }
                if ($memory > 0 && memory_get_usage(true) >= $memory * 1024 * 1024) {
                    $reason = 'memory limit';

                    return $stop = true;
                }
                if (microtime(true) - $lastCheck >= 1) {
                    $lastCheck = microtime(true);
                    if ($restart->generation($role) !== $generation) {
                        $reason = 'restart request';

                        return $stop = true;
                    }
                }

                return false;
            };
            $heartbeat->start($role, $id ?? gethostname().'-'.getmypid().'-'.bin2hex(random_bytes(4)));
            while (! $shouldStop()) {
                $heartbeat->tick();
                $count = $batch($shouldStop);
                $heartbeat->tick();
                $processed += $count;
                if ($command->option('once') || ($maximum > 0 && $processed >= $maximum) || ($count === 0 && $command->option('stop-when-empty'))) {
                    break;
                }
                if ($count === 0 && $sleep > 0) {
                    $until = microtime(true) + $sleep;
                    while (! $shouldStop() && microtime(true) < $until) {
                        usleep((int) (min(1, max(0, $until - microtime(true))) * 1000000));
                        $heartbeat->tick();
                    }
                }
            }

            if ($reason !== null) {
                $command->line('Worker stopped safely: '.$reason.'.');
            }

            return Command::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $command->error('Socket Bridge worker failed. Check Redis/database availability, run migrations and socket-bridge:doctor, then restart the worker.');

            return Command::FAILURE;
        } finally {
            $heartbeat->stop();
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($async !== null) {
                pcntl_async_signals($async);
            }
        }
    }
}
