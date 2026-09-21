<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use Illuminate\Console\Command;
use SocketBridge\Operations\WorkerRestart;
use Throwable;

final class RestartCommand extends Command
{
    protected $signature = 'socket-bridge:restart {--only= : Restart only commands or outbox workers}';

    protected $aliases = ['socket:restart'];

    protected $description = 'Ask PHP workers to exit safely after their current operation';

    public function handle(WorkerRestart $restart): int
    {
        try {
            $restart->request($this->option('only'));
            $this->info('Restart requested. PHP workers finish their current operation and exit successfully.');
            $this->line('Your process manager must restart them. Gateway and Laravel queue workers are managed separately.');

            return self::SUCCESS;
        } catch (\InvalidArgumentException $error) {
            $this->error($error->getMessage());
        } catch (Throwable $error) {
            report($error);
            $this->error('Could not publish the restart request. Check Redis and socket-bridge:doctor.');
        }

        return self::FAILURE;
    }
}
