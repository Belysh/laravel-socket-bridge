<?php

declare(strict_types=1);

namespace SocketBridge\Operations;

use SocketBridge\Transport\RedisStreams;

/** Persistent, project-scoped generations avoid wall-clock and same-second races. */
final class WorkerRestart
{
    public function __construct(private readonly RedisStreams $redis) {}

    public function generation(string $role): string
    {
        $this->validateRole($role);

        return (string) ($this->redis->raw('GET', $this->redis->key('workers:restart:'.$role)) ?: '');
    }

    public function request(?string $role = null): string
    {
        $roles = $role === null ? ['commands', 'outbox'] : [$role];
        $generation = bin2hex(random_bytes(16));
        $arguments = [];
        foreach ($roles as $target) {
            $this->validateRole($target);
            $arguments[] = $this->redis->key('workers:restart:'.$target);
            $arguments[] = $generation;
        }
        $this->redis->raw('MSET', ...$arguments);

        return $generation;
    }

    private function validateRole(string $role): void
    {
        if (! in_array($role, ['commands', 'outbox'], true)) {
            throw new \InvalidArgumentException('Worker role must be commands or outbox.');
        }
    }
}
