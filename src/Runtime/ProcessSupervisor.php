<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ProcessSupervisor
{
    /** @param array<string, Process> $processes */
    public function run(array $processes, callable $output): int
    {
        if ($processes === []) {
            throw new RuntimeException('No Socket Bridge processes were configured.');
        }
        $signal = null;
        $handlers = [];
        $previousAsync = null;
        if (function_exists('pcntl_async_signals')) {
            $previousAsync = pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM, SIGHUP] as $number) {
                $handlers[$number] = pcntl_signal_get_handler($number);
                pcntl_signal($number, static function (int $received) use (&$signal): void {
                    $signal = $received;
                });
            }
        }

        try {
            foreach ($processes as $name => $process) {
                $process->setTimeout(null);
                $process->start(static fn (string $type, string $buffer) => $output($name, $type, $buffer));
            }
            while ($signal === null) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        // Any child exit stops the group. Supervisors should restart the whole group.
                        return $process->getExitCode() ?? 1;
                    }
                    // Logs were already forwarded through the callback; avoid unbounded retention.
                    $process->clearOutput();
                    $process->clearErrorOutput();
                }
                usleep(100000);
            }

            return 128 + $signal;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    try {
                        $process->signal($signal ?? SIGTERM);
                    } catch (\Symfony\Component\Process\Exception\RuntimeException) {
                        // The child may have exited between the state check and signal delivery.
                    }
                }
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(10, SIGKILL);
                }
            }
            foreach ($handlers as $number => $handler) {
                pcntl_signal($number, $handler);
            }
            if ($previousAsync !== null) {
                pcntl_async_signals($previousAsync);
            }
        }
    }
}
