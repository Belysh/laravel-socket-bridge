<?php

namespace SocketBridge\Tests\Operations;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\DTO\Envelope;
use SocketBridge\Operations\CleanupStatus;
use SocketBridge\Operations\HealthService;
use SocketBridge\Operations\Maintenance;
use SocketBridge\Operations\PrometheusExporter;
use SocketBridge\Outbox\OutboxStore;
use SocketBridge\SocketBridgeServiceProvider;
use SocketBridge\Tests\TestCase;
use SocketBridge\Transport\RedisStreams;

final class CleanupTest extends TestCase
{
    private ?RedisStreams $real = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('SOCKET_BRIDGE_TEST_REDIS_URL')) {
            $this->markTestSkipped('Real standalone Redis required.');
        }
        config([
            'socket-bridge.prefix' => 'socket-bridge:cleanup:'.bin2hex(random_bytes(8)),
            'socket-bridge.redis_url' => getenv('SOCKET_BRIDGE_TEST_REDIS_URL'),
            'socket-bridge.redis_connection' => 'cleanup',
            'database.redis.client' => getenv('SOCKET_BRIDGE_TEST_REDIS_CLIENT') ?: 'predis',
            'database.redis.cleanup' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
            'socket-bridge.retention.streams_seconds' => 10,
            'socket-bridge.retention.dead_letters_seconds' => 10,
            'socket-bridge.retention.receipts_seconds' => 10,
            'socket-bridge.retention.published_outbox_seconds' => 10,
        ]);
        $this->real = new RedisStreams($this->app);
        $this->app->instance(RedisStreams::class, $this->real);
        $this->app->instance(EnvelopeTransport::class, $this->real);
    }

    protected function tearDown(): void
    {
        if ($this->real) {
            $cursor = '0';
            do {
                [$cursor, $keys] = $this->real->raw('SCAN', $cursor, 'MATCH', $this->real->key('*'), 'COUNT', 100);
                foreach ($keys as $key) {
                    $this->real->raw('DEL', $key);
                }
            } while ((string) $cursor !== '0');
        }
        parent::tearDown();
    }

    private function history(string $stream, int $count): array
    {
        $this->real->raw('EVAL', "for i=1,tonumber(ARGV[1]) do redis.call('XADD',KEYS[1],i..'-0','envelope','{}') end return 1", 1, $this->real->key($stream), $count);
        $this->real->raw('XADD', $this->real->key($stream), '*', 'envelope', '{}');
        $this->real->createGroup($stream, 'fast');
        $entries = $this->real->read($stream, 'fast', 'consumer', $count + 1, 1);
        $ids = array_column($entries, 'id');
        $this->real->raw('XACK', $this->real->key($stream), 'fast', ...$ids);

        return $ids;
    }

    private function receipts(int $count, string $session = 'session'): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['session_id' => $session, 'command_id' => (string) Str::uuid(), 'user_id' => '1', 'fingerprint' => str_repeat('f', 64), 'result' => '{"ok":true,"data":{}}', 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('socket_bridge_command_receipts')->insert($batch);
        }

        return $rows;
    }

    private function published(int $count): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'envelope' => '{}', 'published_at' => now()->subMinute(), 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('socket_bridge_outbox')->insert($batch);
        }
    }

    public function test_multi_batch_catchup_still_protects_every_groups_pending_and_unread_entries(): void
    {
        $this->history('events', 2500);
        $this->real->createGroup('events', 'slow');
        $read = $this->real->read('events', 'slow', 'consumer', 2201, 1);
        $acks = array_values(array_filter(array_column($read, 'id'), static fn ($id) => $id !== '1701-0'));
        $this->real->raw('XACK', $this->real->key('events'), 'slow', ...$acks);
        $first = app(Maintenance::class)->prune(false, 10000, 71);
        self::assertSame(1700, $first['streams']['events']['deleted']);
        self::assertSame('1701-0', $this->real->range('events')[0]['id']);
        self::assertSame(1, $this->real->raw('XPENDING', $this->real->key('events'), 'slow')[0]);
        self::assertFalse($first['streams']['events']['has_more']);
        self::assertTrue($first['streams']['events']['protected']);
        self::assertGreaterThan(0, $first['streams']['events']['retention_lag_seconds']);
        $this->real->ack('events', 'slow', '1701-0');
        $remaining = $this->real->read('events', 'slow', 'consumer', 3000, 1);
        $this->real->raw('XACK', $this->real->key('events'), 'slow', ...array_column($remaining, 'id'));
        $second = app(Maintenance::class)->prune(false, 10000, 71);
        self::assertSame(800, $second['streams']['events']['deleted']);
        self::assertSame(0, $second['streams']['events']['retention_lag_seconds']);
        self::assertSame(1, $this->real->raw('XLEN', $this->real->key('events')));
    }

    public function test_record_budget_and_dry_run_visit_distinct_batches_without_mutation(): void
    {
        $this->history('events', 310);
        $this->published(310);
        $this->receipts(310);
        $maintenance = app(Maintenance::class);
        $dry = $maintenance->prune(true, 120, 17);
        foreach ([$dry['streams']['events'], $dry['receipts'], $dry['published_outbox']] as $resource) {
            self::assertSame(120, $resource['eligible']);
            self::assertSame(120, $resource['scanned']);
            self::assertSame(0, $resource['deleted']);
            self::assertTrue($resource['has_more']);
        }
        self::assertSame(311, $this->real->raw('XLEN', $this->real->key('events')));
        self::assertSame(310, DB::table('socket_bridge_command_receipts')->count());
        self::assertSame(310, DB::table('socket_bridge_outbox')->count());
        self::assertNull(app(CleanupStatus::class)->snapshot());
        $actual = $maintenance->prune(false, 120, 17);
        self::assertSame(120, $actual['streams']['events']['deleted']);
        self::assertSame(120, $actual['receipts']['deleted']);
        self::assertSame(120, $actual['published_outbox']['deleted']);
        $last = $maintenance->prune(false, 10000, 17);
        self::assertSame(190, $last['streams']['events']['deleted']);
        self::assertSame(190, $last['receipts']['deleted']);
        self::assertSame(190, $last['published_outbox']['deleted']);
        self::assertFalse($last['receipts']['has_more']);
    }

    public function test_protected_old_receipts_do_not_starve_later_batches_and_unpublished_rows_survive(): void
    {
        $protected = $this->receipts(2, 'aaa-protected');
        $this->receipts(12, 'zzz-completed');
        $incomplete = $this->receipts(1, 'incomplete')[0];
        DB::table('socket_bridge_command_receipts')->where('session_id', 'incomplete')->update(['result' => null]);
        foreach ($protected as $receipt) {
            app(OutboxStore::class)->store(Envelope::make('socket.command.result', ['command_id' => strtoupper($receipt['command_id']), 'session_id' => $receipt['session_id'], 'socket_id' => 'socket', 'user_id' => '1', 'result' => ['ok' => true, 'data' => []]]));
        }
        $this->published(15);
        $report = app(Maintenance::class)->prune(false, 100, 2);
        self::assertSame(14, $report['receipts']['scanned']);
        self::assertSame(12, $report['receipts']['deleted']);
        self::assertTrue($report['receipts']['protected']);
        self::assertGreaterThan(0, $report['receipts']['retention_lag_seconds']);
        self::assertSame(15, $report['published_outbox']['deleted']);
        self::assertSame(2, DB::table('socket_bridge_outbox')->whereNull('published_at')->count());
        self::assertSame(3, DB::table('socket_bridge_command_receipts')->count());
        self::assertNotNull(DB::table('socket_bridge_command_receipts')->where('command_id', $incomplete['command_id'])->first());
        DB::table('socket_bridge_outbox')->update(['published_at' => now()->subMinute()]);
        $after = app(Maintenance::class)->prune(false, 100, 2);
        self::assertSame(2, $after['receipts']['deleted']);
        self::assertSame(1, DB::table('socket_bridge_command_receipts')->count());
    }

    public function test_time_budget_is_checked_between_receipt_transactions(): void
    {
        $this->receipts(100);
        $this->published(10);
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_starts_with($query->sql, 'delete from "socket_bridge_command_receipts"')) {
                $delayed = true;
                usleep(300000);
            }
        });
        $report = app(Maintenance::class)->prune(false, 100, 100, 0.2);
        self::assertTrue($delayed);
        self::assertTrue($report['time_limit_reached']);
        self::assertSame(1, $report['receipts']['deleted']);
        self::assertSame(99, DB::table('socket_bridge_command_receipts')->count());
        self::assertSame(0, $report['published_outbox']['deleted']);
        self::assertNull($report['published_outbox']['has_more']);
    }

    public function test_receipt_cursor_resumes_across_run_limits_then_wraps_to_previously_protected_rows(): void
    {
        $protected = $this->receipts(6, 'aaa-protected');
        $this->receipts(2, 'zzz-completed');
        foreach ($protected as $receipt) {
            app(OutboxStore::class)->store(Envelope::make('socket.command.result', ['command_id' => $receipt['command_id'], 'session_id' => $receipt['session_id'], 'socket_id' => 'socket', 'user_id' => '1', 'result' => ['ok' => true, 'data' => []]]));
        }
        $maintenance = app(Maintenance::class);
        self::assertSame(0, $maintenance->prune(false, 3, 2)['receipts']['deleted']);
        $cursor = app(CleanupStatus::class)->receiptCursor();
        self::assertNotNull($cursor);
        $maintenance->prune(true, 100, 2);
        self::assertSame($cursor, app(CleanupStatus::class)->receiptCursor());
        self::assertSame(0, $maintenance->prune(false, 3, 2)['receipts']['deleted']);
        self::assertSame(2, $maintenance->prune(false, 3, 2)['receipts']['deleted']);
        self::assertNull(app(CleanupStatus::class)->receiptCursor());
        DB::table('socket_bridge_outbox')->update(['published_at' => now()->subMinute()]);
        self::assertSame(6, $maintenance->prune(false, 100, 2)['receipts']['deleted']);
        self::assertSame(0, DB::table('socket_bridge_command_receipts')->count());
    }

    public function test_retention_index_migration_upgrades_existing_tables_without_losing_receipts(): void
    {
        $this->receipts(1);
        $migration = require __DIR__.'/../../database/migrations/2026_09_21_000002_index_socket_bridge_receipt_retention.php';
        $migration->up();
        self::assertTrue(Schema::hasIndex('socket_bridge_command_receipts', 'socket_bridge_receipts_retention'));
        self::assertSame(1, DB::table('socket_bridge_command_receipts')->count());
        $migration->down();
        self::assertFalse(Schema::hasIndex('socket_bridge_command_receipts', 'socket_bridge_receipts_retention'));
        self::assertSame(1, DB::table('socket_bridge_command_receipts')->count());
    }

    public function test_status_and_metrics_are_bounded_aggregates_and_dry_run_preserves_last_real_run(): void
    {
        self::assertStringContainsString('socket_bridge_cleanup_last_run_timestamp_seconds 0', app(PrometheusExporter::class)->render());
        $this->history('events', 25);
        app(Maintenance::class)->prune(false, 10, 3);
        $status = app(CleanupStatus::class)->snapshot();
        self::assertSame(10, $status['resources']['events']['deleted']);
        self::assertTrue($status['resources']['events']['has_more']);
        self::assertGreaterThan(0, $this->real->raw('TTL', $this->real->key('operations:prune')));
        self::assertArrayNotHasKey('protected_from', $status['resources']['events']);
        self::assertSame($status, app(HealthService::class)->snapshot()['cleanup']);
        app(Maintenance::class)->prune(true, 10, 3);
        self::assertSame($status, app(CleanupStatus::class)->snapshot());
        $metrics = app(PrometheusExporter::class)->render();
        self::assertStringContainsString('socket_bridge_cleanup_deleted{resource="events"} 10', $metrics);
        self::assertStringContainsString('socket_bridge_cleanup_has_more{resource="events"} 1', $metrics);
        self::assertStringNotContainsString(config('socket-bridge.prefix'), $metrics);
    }

    public function test_scheduler_frequency_and_legacy_published_config_defaults(): void
    {
        config(['socket-bridge.retention' => ['automatic' => true, 'frequency_minutes' => 5, 'streams_seconds' => 90]]);
        (new SocketBridgeServiceProvider($this->app))->register();
        self::assertSame(10000, config('socket-bridge.retention.limit'));
        self::assertSame(100, config('socket-bridge.retention.batch_size'));
        self::assertSame(90, config('socket-bridge.retention.streams_seconds'));
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($event) => str_contains($event->command ?? '', 'socket-bridge:prune')));
        self::assertCount(1, $events);
        self::assertSame('*/5 * * * *', $events[0]->expression);
        self::assertTrue($events[0]->withoutOverlapping);
        self::assertSame(10, $events[0]->expiresAt);
    }

    public function test_invalid_cli_budgets_fail_before_cleanup(): void
    {
        $this->artisan('socket-bridge:prune', ['--limit' => 0])->assertFailed();
        $this->artisan('socket-bridge:prune', ['--batch-size' => 1001])->assertFailed();
        $this->artisan('socket-bridge:prune', ['--max-seconds' => 0])->assertFailed();
        self::assertNull(app(CleanupStatus::class)->snapshot());
    }
}
