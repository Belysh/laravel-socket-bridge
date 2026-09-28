<?php

namespace SocketBridge\Commands;

use Illuminate\Contracts\Container\Container;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\DTO\Envelope;
use SocketBridge\Exceptions\CommandRejected;

class CommandRegistry
{
    private array $handlers = [];

    private array $lifetimes = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<CommandHandler>|CommandHandler $handler */
    public function register(string $name, string|CommandHandler $handler, ?int $ttlSeconds = null): self
    {
        Envelope::event($name);
        if (! preg_match('/^[a-zA-Z0-9_.:-]{1,200}$/D', $name) || in_array($name, ['room:join', 'room:leave', 'session:refresh', 'command'], true)) {
            throw new \InvalidArgumentException('Invalid command name.');
        }
        if (is_string($handler) && ! is_a($handler, CommandHandler::class, true)) {
            throw new \InvalidArgumentException('Command handlers must implement '.CommandHandler::class.'.');
        }
        if ($ttlSeconds !== null && ($ttlSeconds < 1 || $ttlSeconds > 86400)) {
            throw new \InvalidArgumentException('Command lifetime must be between 1 and 86400 seconds.');
        }
        $this->lifetimes[$name] = $ttlSeconds;
        $this->handlers[$name] = $handler;

        return $this;
    }

    public function resolve(string $name): CommandHandler
    {
        $handler = $this->handlers[$name] ?? throw new CommandRejected('command.unknown', 'This command is not registered.');

        return is_string($handler) ? $this->container->make($handler) : $handler;
    }

    public function lifetime(string $name): ?int
    {
        return $this->lifetimes[$name] ?? null;
    }

    public function names(): array
    {
        return array_keys($this->handlers);
    }
}
