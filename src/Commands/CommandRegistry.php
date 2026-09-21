<?php

namespace SocketBridge\Commands;

use Illuminate\Contracts\Container\Container;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\DTO\Envelope;
use SocketBridge\Exceptions\CommandRejected;

class CommandRegistry
{
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<CommandHandler>|CommandHandler $handler */
    public function register(string $name, string|CommandHandler $handler): self
    {
        Envelope::event($name);
        if (! preg_match('/^[a-zA-Z0-9_.:-]{1,200}$/D', $name) || in_array($name, ['room:join', 'room:leave', 'session:refresh', 'command'], true)) {
            throw new \InvalidArgumentException('Invalid command name.');
        }
        if (is_string($handler) && ! is_a($handler, CommandHandler::class, true)) {
            throw new \InvalidArgumentException('Command handlers must implement '.CommandHandler::class.'.');
        }
        $this->handlers[$name] = $handler;

        return $this;
    }

    public function resolve(string $name): CommandHandler
    {
        $handler = $this->handlers[$name] ?? throw new CommandRejected('command.unknown', 'This command is not registered.');

        return is_string($handler) ? $this->container->make($handler) : $handler;
    }

    public function names(): array
    {
        return array_keys($this->handlers);
    }
}
