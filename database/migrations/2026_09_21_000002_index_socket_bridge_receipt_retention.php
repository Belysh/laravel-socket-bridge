<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('socket-bridge.database_connection'))
            ->table('socket_bridge_command_receipts', function (Blueprint $table): void {
                $table->index('updated_at', 'socket_bridge_receipts_retention');
            });
    }

    public function down(): void
    {
        Schema::connection(config('socket-bridge.database_connection'))
            ->table('socket_bridge_command_receipts', function (Blueprint $table): void {
                $table->dropIndex('socket_bridge_receipts_retention');
            });
    }
};
