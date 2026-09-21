<?php

namespace SocketBridge\Outbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use SocketBridge\DTO\Json;

class OutboxStore
{
    public function database(): ConnectionInterface
    {
        return DB::connection(config('socket-bridge.database_connection'));
    }

    /** Persist inside the caller's transaction to make event and data atomic. */
    public function store(array $envelope): string
    {
        $this->database()->table('socket_bridge_outbox')->insertOrIgnore([
            'id' => $envelope['id'],
            'envelope' => Json::encodeEnvelope($envelope),
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $envelope['id'];
    }
}
