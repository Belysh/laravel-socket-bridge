<?php

namespace SocketBridge\Contracts;

use SocketBridge\Commands\CommandContext;

interface CommandHandler
{
    /** @return array<string, mixed> */
    public function handle(array $payload, CommandContext $context): array;
}
