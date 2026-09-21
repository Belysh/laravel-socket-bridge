<?php

namespace SocketBridge\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Commands\CommandProcessor;
use SocketBridge\Commands\CommandRegistry;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\DTO\Envelope;
use SocketBridge\DTO\Json;
use SocketBridge\Tests\TestCase;
use SocketBridge\Tests\TestUser;

class CommandsTest extends TestCase
{
    private array $command;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('counter', function (Blueprint $table): void {
            $table->id();
            $table->integer('value');
        });
        DB::table('counter')->insert(['value' => 0]);
        $user = TestUser::create(['name' => 'Ada']);
        $session = $this->sessionFor($user);
        $this->command = Envelope::make('socket.command', ['command' => 'counter.increment', 'payload' => ['amount' => 2], 'context' => [
            'user_id' => (string) $user->id, 'session_id' => $session['session_id'], 'socket_id' => 'origin',
        ]]);
        app(CommandRegistry::class)->register('counter.increment', Increment::class);
    }

    public function test_duplicate_command_replies_to_new_socket_without_repeating_mutation(): void
    {
        $processor = app(CommandProcessor::class);
        self::assertSame(['ok' => true, 'data' => ['value' => 2]], $processor->process($this->command));
        $retry = $this->command;
        $retry['context']['socket_id'] = 'reconnected';
        self::assertSame(['ok' => true, 'data' => ['value' => 2]], $processor->process($retry));
        self::assertSame(2, DB::table('counter')->value('value'));
        self::assertDatabaseCount('socket_bridge_command_receipts', 1);
        self::assertDatabaseCount('socket_bridge_outbox', 2);
        $replies = DB::table('socket_bridge_outbox')->pluck('envelope')->map(fn ($raw) => json_decode($raw, true));
        self::assertContains('reconnected', $replies->pluck('socket_id')->all());
        self::assertEmpty($this->redis->envelopes);
    }

    public function test_nested_object_and_list_shapes_have_distinct_receipts_and_retained_results(): void
    {
        $handler = new class implements CommandHandler
        {
            public int $calls = 0;

            public function handle(array $payload, CommandContext $context): array
            {
                $this->calls++;

                return $payload;
            }
        };
        app(CommandRegistry::class)->register('json.echo', $handler);
        foreach ([['{"value":{}}', '{"value":[]}'], ['{"value":{"0":"zero"}}', '{"value":["zero"]}']] as [$original, $changed]) {
            $command = $this->command;
            $command['id'] = (string) Str::uuid();
            $command['command'] = 'json.echo';
            $command['payload'] = Json::decode($original);
            $first = app(CommandProcessor::class)->process($command);
            $retry = app(CommandProcessor::class)->process($command);
            self::assertSame($original, Json::encode($first['data']));
            self::assertSame($original, Json::encode($retry['data']));
            $command['payload'] = Json::decode($changed);
            self::assertSame('command.id_conflict', app(CommandProcessor::class)->process($command)['error']['code']);
        }
        self::assertSame(2, $handler->calls);
    }

    public function test_handler_can_return_explicit_object_without_changing_existing_array_handlers(): void
    {
        app(CommandRegistry::class)->register('json.object', new class implements CommandHandler
        {
            public function handle(array $payload, CommandContext $context): \stdClass
            {
                return (object) ['0' => (object) [], '1' => []];
            }
        });
        $command = $this->command;
        $command['command'] = 'json.object';
        foreach ([1, 2] as $_) {
            $result = app(CommandProcessor::class)->process($command);
            self::assertSame('{"0":{},"1":[]}', Json::encode($result['data']));
        }
    }

    public function test_empty_root_payload_keeps_the_previous_fingerprint_for_existing_receipts(): void
    {
        $command = $this->command;
        $command['payload'] = [];
        $fingerprint = hash('sha256', json_encode(['command' => $command['command'], 'payload' => []]));
        DB::table('socket_bridge_command_receipts')->insert([
            'session_id' => $command['context']['session_id'], 'command_id' => $command['id'],
            'fingerprint' => $fingerprint, 'user_id' => $command['context']['user_id'],
            'result' => '{"ok":true,"data":{"previous":true}}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $command = Json::decodeEnvelope(Json::encodeEnvelope($command));
        self::assertSame(['ok' => true, 'data' => ['previous' => true]], app(CommandProcessor::class)->process($command));
        self::assertSame(0, DB::table('counter')->value('value'));
    }

    public function test_uuid_case_variation_cannot_repeat_the_same_command(): void
    {
        app(CommandProcessor::class)->process($this->command);
        $this->command['id'] = strtoupper($this->command['id']);
        self::assertTrue(app(CommandProcessor::class)->process($this->command)['ok']);
        self::assertSame(2, DB::table('counter')->value('value'));
        self::assertDatabaseCount('socket_bridge_command_receipts', 1);
    }

    public function test_multilingual_payload_limit_counts_utf8_wire_bytes(): void
    {
        $this->command['payload'] = ['text' => str_repeat('я/', 18000)];
        self::assertLessThan(65536, strlen(json_encode($this->command['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        self::assertTrue(app(CommandProcessor::class)->valid($this->command));
        Envelope::payload($this->command['payload']);
        $this->command['payload'] = ['text' => str_repeat('я/', 23000)];
        self::assertFalse(app(CommandProcessor::class)->valid($this->command));
    }

    public function test_reusing_id_with_different_payload_conflicts(): void
    {
        app(CommandProcessor::class)->process($this->command);
        $this->command['payload']['amount'] = 5;
        $result = app(CommandProcessor::class)->process($this->command);
        self::assertSame('command.id_conflict', $result['error']['code']);
        self::assertSame(2, DB::table('counter')->value('value'));
    }

    public function test_each_delivery_keeps_its_own_reply_correlation_without_repeating_business_work(): void
    {
        $processor = app(CommandProcessor::class);
        $requests = [];
        foreach ([2, 2, 5] as $amount) {
            $request = (string) Str::uuid();
            $requests[] = $request;
            $this->command['context']['request_id'] = $request;
            $this->command['payload']['amount'] = $amount;
            $processor->process($this->command);
        }
        $replies = DB::table('socket_bridge_outbox')->pluck('envelope')
            ->map(fn ($raw) => json_decode($raw, true))->keyBy('request_id');
        self::assertCount(3, $replies);
        self::assertTrue($replies[$requests[0]]['result']['ok']);
        self::assertTrue($replies[$requests[1]]['result']['ok']);
        self::assertSame('command.id_conflict', $replies[$requests[2]]['result']['error']['code']);
        self::assertSame(2, DB::table('counter')->value('value'));
        self::assertDatabaseCount('socket_bridge_command_receipts', 1);
    }

    public function test_malformed_reply_correlation_is_rejected_before_execution(): void
    {
        $this->command['context']['request_id'] = 'invalid';
        self::assertFalse(app(CommandProcessor::class)->valid($this->command));
        self::assertSame(0, DB::table('counter')->value('value'));
    }

    public function test_terminal_validation_rolls_back_handler_effects_and_persists_error(): void
    {
        app(CommandRegistry::class)->register('counter.increment', PartialThenReject::class);
        $result = app(CommandProcessor::class)->process($this->command);
        self::assertSame('validation.failed', $result['error']['code']);
        self::assertSame(0, DB::table('counter')->value('value'));
        self::assertDatabaseCount('socket_bridge_command_receipts', 1);
        self::assertDatabaseCount('socket_bridge_outbox', 1);
    }

    public function test_transient_failure_rolls_back_receipt_and_mutation_for_retry(): void
    {
        app(CommandRegistry::class)->register('counter.increment', PartialThenFail::class);
        try {
            app(CommandProcessor::class)->process($this->command);
            self::fail('The transient failure must escape for retry.');
        } catch (\RuntimeException $error) {
            self::assertSame('Temporary failure', $error->getMessage());
        }
        self::assertSame(0, DB::table('counter')->value('value'));
        self::assertDatabaseCount('socket_bridge_command_receipts', 0);
        self::assertDatabaseCount('socket_bridge_outbox', 0);
        app(CommandRegistry::class)->register('counter.increment', Increment::class);
        self::assertTrue(app(CommandProcessor::class)->process($this->command)['ok']);
    }

    public function test_spoofed_identity_and_expired_commands_cannot_mutate(): void
    {
        $spoofed = $this->command;
        $spoofed['context']['user_id'] = 'other';
        self::assertSame('auth.unauthenticated', app(CommandProcessor::class)->process($spoofed)['error']['code']);
        $this->command['expires_at'] = now()->subMinute()->toISOString();
        self::assertSame('command.expired', app(CommandProcessor::class)->process($this->command)['error']['code']);
        self::assertSame(0, DB::table('counter')->value('value'));
    }

    public function test_unregistered_command_is_a_terminal_result(): void
    {
        $this->command['command'] = 'not.registered';
        self::assertSame('command.unknown', app(CommandProcessor::class)->process($this->command)['error']['code']);
    }

    public function test_channel_control_name_cannot_shadow_a_business_handler(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CommandRegistry::class)->register('room:join', Increment::class);
    }
}

class Increment implements CommandHandler
{
    public function handle(array $payload, CommandContext $context): array
    {
        DB::table('counter')->increment('value', $payload['amount']);

        return ['value' => DB::table('counter')->value('value')];
    }
}
class PartialThenReject implements CommandHandler
{
    public function handle(array $payload, CommandContext $context): array
    {
        DB::table('counter')->increment('value', 100);
        throw ValidationException::withMessages(['amount' => ['Invalid amount.']]);
    }
}
class PartialThenFail implements CommandHandler
{
    public function handle(array $payload, CommandContext $context): array
    {
        DB::table('counter')->increment('value', 100);
        throw new \RuntimeException('Temporary failure');
    }
}
