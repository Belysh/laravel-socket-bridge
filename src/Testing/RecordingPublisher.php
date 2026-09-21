<?php

namespace SocketBridge\Testing;

use SocketBridge\EnvelopePublisher;

final class RecordingPublisher extends EnvelopePublisher
{
    /** @var list<array{envelope:array,durable:bool}> */
    public array $records = [];

    public function __construct() {}

    public function publish(array $envelope, bool $durable = false): string
    {
        $this->records[] = compact('envelope', 'durable');

        return $envelope['id'];
    }
}
