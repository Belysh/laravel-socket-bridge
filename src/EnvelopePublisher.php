<?php

namespace SocketBridge;

use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\Outbox\OutboxStore;

class EnvelopePublisher
{
    public function __construct(private readonly EnvelopeTransport $transport, private readonly OutboxStore $outbox) {}

    public function publish(array $envelope, bool $durable = false): string
    {
        if ($durable) {
            return $this->outbox->store($envelope);
        }
        $this->transport->add('events', $envelope);

        return $envelope['id'];
    }
}
