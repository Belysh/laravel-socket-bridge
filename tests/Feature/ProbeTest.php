<?php

namespace SocketBridge\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SocketBridge\Commands\CommandProcessor;
use SocketBridge\DTO\Envelope;
use SocketBridge\Tests\TestCase;
use SocketBridge\Tests\TestUser;

class ProbeTest extends TestCase
{
    public function test_probe_uses_authenticated_receipt_and_outbox_pipeline_without_application_mutations(): void
    {
        $user = TestUser::create(['name' => 'Probe']);
        $session = $this->sessionFor($user);
        $nonce = (string) Str::uuid();
        $command = Envelope::make('socket.command', [
            'command' => 'socket-bridge.probe', 'payload' => ['nonce' => $nonce],
            'context' => ['user_id' => (string) $user->id, 'session_id' => $session['session_id'], 'socket_id' => 'probe'],
        ]);
        $result = app(CommandProcessor::class)->process($command);
        self::assertSame(['ok' => true, 'data' => ['probe' => 'socket-bridge', 'nonce' => $nonce]], $result);
        self::assertDatabaseCount('users', 1);
        self::assertDatabaseCount('socket_bridge_command_receipts', 1);
        $reply = json_decode(DB::table('socket_bridge_outbox')->value('envelope'), true);
        self::assertSame($result, $reply['result']);

        $command['id'] = (string) Str::uuid();
        $command['context']['session_id'] = str_repeat('0', 64);
        self::assertFalse(app(CommandProcessor::class)->process($command)['ok']);
    }

    public function test_configuration_failure_does_not_expose_header_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bridge-probe-');
        $secret = 'private-test-value';
        file_put_contents($path, json_encode(['Authorization' => $secret."\r\ninvalid"]));
        try {
            self::assertSame(1, Artisan::call('socket-bridge:probe', ['--headers' => $path, '--json' => true]));
            $output = Artisan::output();
            self::assertStringNotContainsString($secret, $output);
            self::assertSame('probe.configuration', json_decode($output, true)['error']['code']);
        } finally {
            unlink($path);
        }
    }
}
