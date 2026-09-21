<?php

declare(strict_types=1);

namespace SocketBridge\Commands;

use SocketBridge\Contracts\CommandHandler;
use SocketBridge\DTO\Envelope;
use SocketBridge\Exceptions\CommandRejected;

/** Uses the normal authenticated command transaction without application mutations. */
final class ProbeHandler implements CommandHandler
{
    public function handle(array $payload, CommandContext $context): array
    {
        if (array_keys($payload) !== ['nonce'] || ! is_string($payload['nonce']) || ! Envelope::validId($payload['nonce'])) {
            throw new CommandRejected('probe.invalid', 'A diagnostic nonce UUID is required.');
        }

        return ['probe' => 'socket-bridge', 'nonce' => $payload['nonce']];
    }
}
