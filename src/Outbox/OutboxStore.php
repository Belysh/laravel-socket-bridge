<?php

namespace SocketBridge\Outbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

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
            'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS),
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $envelope['id'];
    }
}
