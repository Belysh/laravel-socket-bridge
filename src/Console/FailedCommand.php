<?php

namespace SocketBridge\Console;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Command;
use SocketBridge\Operations\FailedMessages;

final class FailedCommand extends Command
{
    protected $signature = 'socket-bridge:failed {stream : commands or events} {--replay= : Dead-letter Redis entry ID to replay} {--limit=20 : Maximum entries to inspect} {--json : Print machine-readable JSON}';

    protected $aliases = ['socket:failed'];

    protected $description = 'Inspect dead letters or replay one with its original identity and authorization';

    public function handle(FailedMessages $failed): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('--limit must be between 1 and 10000.');

            return self::FAILURE;
        }
        try {
            $result = $this->option('replay') ? $failed->replay((string) $this->argument('stream'), (string) $this->option('replay')) : $failed->inspect((string) $this->argument('stream'), $limit);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\InvalidArgumentException|AuthenticationException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Failed-message operation unavailable; check application logs.');

            return self::FAILURE;
        }
    }
}
