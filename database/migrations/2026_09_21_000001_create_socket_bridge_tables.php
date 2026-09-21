<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('socket-bridge.database_connection'));
        $schema->create('socket_bridge_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->json('envelope');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        $schema->create('socket_bridge_command_receipts', function (Blueprint $table): void {
            $table->string('session_id', 64);
            $table->uuid('command_id');
            $table->string('fingerprint', 64);
            $table->string('user_id', 191);
            $table->json('result')->nullable();
            $table->timestamps();
            $table->primary(['session_id', 'command_id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('socket-bridge.database_connection'));
        $schema->dropIfExists('socket_bridge_command_receipts');
        $schema->dropIfExists('socket_bridge_outbox');
    }
};
