<?php

namespace SocketBridge\Contracts;

interface EnvelopeTransport
{
    /** @param array<string, mixed> $envelope */
    public function add(string $stream, array $envelope): string;
}
