<?php

namespace SocketBridge\Commands;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use SocketBridge\Exceptions\CommandRejected;
use SocketBridge\Operations\Metrics;
use SocketBridge\Transport\RedisStreams;
use Throwable;

class CommandConsumer
{
    private ?string $consumer = null;

    private bool $initialized = false;

    public function __construct(private readonly RedisStreams $redis, private readonly CommandProcessor $processor) {}

    public function runOnce(?string $consumer = null, ?callable $shouldStop = null): int
    {
        if (! $this->initialized) {
            $this->redis->createGroup('commands', 'laravel');
            $this->initialized = true;
        }
        $consumer ??= $this->consumer ??= gethostname().'-'.getmypid().'-'.Str::random(8);
        $entries = $this->redis->claim('commands', 'laravel', $consumer, (int) config('socket-bridge.command_claim_idle_ms', 30000));
        if ($entries === []) {
            $entries = $this->redis->read('commands', 'laravel', $consumer);
        }
        $processed = 0;
        foreach ($entries as $entry) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }
            $this->resetCommandScope();
            if ($entry['envelope'] === null || ! $this->processor->valid($entry['envelope'])) {
                $this->redis->deadLetter('commands', 'laravel', $entry['id'], $entry['raw'], 'Malformed command envelope.');
                app(Metrics::class)->increment('commands_dead_letters_total');

                continue;
            }
            $started = hrtime(true);
            try {
                $result = $this->processor->process($entry['envelope']);
                $this->redis->ack('commands', 'laravel', $entry['id']);
                app(Metrics::class)->increment('commands_processed_total');
                if (! ($result['ok'] ?? false)) {
                    app(Metrics::class)->increment('commands_failed_total');
                }
                $processed++;
            } catch (Throwable $error) {
                report($error);
                if ($this->redis->attempts('commands', 'laravel', $entry['id']) >= (int) config('socket-bridge.command_max_attempts', 5)) {
                    // If even storing the final result fails, leave it pending.
                    $this->processor->process($entry['envelope'], new CommandRejected('command.failed', 'The command could not be completed.'));
                    $this->redis->deadLetter('commands', 'laravel', $entry['id'], $entry['raw'], 'Retry limit reached: '.get_class($error));
                    app(Metrics::class)->increment('commands_dead_letters_total');
                    app(Metrics::class)->increment('commands_failed_total');
                } else {
                    app(Metrics::class)->increment('commands_retried_total');
                }
            } finally {
                app(Metrics::class)->observe('command_duration_seconds', (hrtime(true) - $started) / 1e9);
                $this->resetCommandScope();
            }
        }

        return $processed;
    }

    private function resetCommandScope(): void
    {
        // Like Laravel's queue worker, a command is a new scoped-container lifetime.
        // Facades can cache scoped roots, so dropping only container instances is insufficient.
        // Singletons and explicitly registered handler objects remain under application control.
        app()->forgetScopedInstances();
        Facade::clearResolvedInstances();
    }
}
