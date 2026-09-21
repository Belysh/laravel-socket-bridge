<?php

namespace SocketBridge\Contracts;

use SocketBridge\Commands\CommandContext;

interface CommandHandler
{
    /** @param array<array-key, mixed> $payload @return array<array-key, mixed>|\stdClass */
    public function handle(array $payload, CommandContext $context): array|\stdClass;
}
