<?php

namespace SocketBridge\Exceptions;

use RuntimeException;

class CommandRejected extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
