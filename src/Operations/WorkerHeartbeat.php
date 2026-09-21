<?php

namespace SocketBridge\Operations;

use Illuminate\Contracts\Foundation\Application;
use SocketBridge\Transport\RedisStreams;
use Throwable;

final class WorkerHeartbeat
{
    private RedisStreams $redis;

    private ?string $key = null;

    private array $record = [];

    private float $last = 0;

    private mixed $previousHandler = null;

    private int $previousAlarm = 0;

    private bool $alarmInstalled = false;

    private ?Throwable $failure = null;

    public function __construct(Application $app)
    {
        // Signal callbacks must not share a socket with an interrupted stream read.
        $this->redis = new RedisStreams($app);
    }

    public function start(string $role, string $id): void
    {
        if (! in_array($role, ['commands', 'outbox'], true) || ! preg_match('/^[a-zA-Z0-9_.:-]{1,200}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid worker heartbeat identity.');
        }
        $this->key = $this->redis->key('health:worker:'.$role.':'.$id);
        $this->record = ['protocol' => 1, 'role' => $role, 'id' => $id, 'pid' => getmypid(), 'started_at' => now()->utc()->toISOString()];
        $this->tick(true);
        if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
            $this->previousHandler = pcntl_signal_get_handler(SIGALRM);
            pcntl_signal(SIGALRM, function (): void {
                try {
                    $this->tick(true);
                } catch (Throwable $error) {
                    $this->failure = $error;
                }
                if ($this->key !== null) {
                    pcntl_alarm($this->interval());
                }
            });
            $this->previousAlarm = pcntl_alarm($this->interval());
            $this->alarmInstalled = true;
        }
    }

    public function tick(bool $force = false): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->key === null || (! $force && microtime(true) - $this->last < $this->interval())) {
            return;
        }
        $this->record['updated_at'] = now()->utc()->toISOString();
        $this->redis->raw('SET', $this->key, json_encode($this->record, JSON_THROW_ON_ERROR), 'EX', max($this->interval() * 2 + 1, (int) config('socket-bridge.health.ttl_seconds', 15)));
        $this->last = microtime(true);
    }

    public function stop(): void
    {
        if ($this->alarmInstalled) {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $this->previousHandler);
            if ($this->previousAlarm > 0) {
                pcntl_alarm($this->previousAlarm);
            }
            $this->alarmInstalled = false;
        }
        if ($this->key !== null) {
            try {
                $this->redis->raw('DEL', $this->key);
            } catch (Throwable) { /* TTL removes an unreachable worker. */
            }
        }
        $this->key = null;
        $this->failure = null;
    }

    private function interval(): int
    {
        return max(1, (int) config('socket-bridge.health.interval_seconds', 5));
    }
}
