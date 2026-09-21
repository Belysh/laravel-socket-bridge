<?php

namespace SocketBridge\Tests\Concurrency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DatabaseConcurrencyTest extends TestCase
{
    private ?array $settings = null;

    public static function databases(): array
    {
        return [['mysql'], ['pgsql']];
    }

    private function prepare(string $driver): void
    {
        $url = getenv('SOCKET_BRIDGE_TEST_'.strtoupper($driver).'_URL');
        $redis = getenv('SOCKET_BRIDGE_TEST_REDIS_URL');
        if (! $url || ! $redis) {
            $this->markTestSkipped('Configure isolated MySQL/PostgreSQL and Redis test URLs.');
        }
        $this->settings = ['driver' => $driver, 'url' => $url, 'redis_url' => $redis, 'prefix' => 'sb_'.bin2hex(random_bytes(6)).'_',
            'command_id' => '01996123-abcd-7123-8abc-'.bin2hex(random_bytes(6)), 'event_id' => '01996123-abcd-7123-9abc-'.bin2hex(random_bytes(6)), 'created_at' => gmdate('c')];
        $this->execute('prepare');
    }

    private function start(string $action, array $override = []): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', base64_encode(json_encode(array_replace($this->settings, $override), JSON_THROW_ON_ERROR)), $action]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function finish(Process $process, int $expected = 0): array
    {
        $process->wait();
        self::assertSame($expected, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        return $expected === 0 ? json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function execute(string $action, array $override = []): array
    {
        return $this->finish($this->start($action, $override));
    }

    protected function tearDown(): void
    {
        if ($this->settings !== null) {
            $this->execute('cleanup');
        } parent::tearDown();
    }

    #[DataProvider('databases')]
    public function test_concurrent_duplicate_commands_commit_one_mutation_and_each_durable_reply(string $driver): void
    {
        $this->prepare($driver);
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $workers[] = $this->start('process', ['socket' => 'socket-'.$i]);
        }
        foreach ($workers as $worker) {
            self::assertTrue($this->finish($worker)['ok']);
        }
        $status = $this->execute('status');
        self::assertSame(1, $status['counter']);
        self::assertSame(1, $status['receipts']);
        self::assertSame(4, $status['outbox']);
    }

    #[DataProvider('databases')]
    public function test_concurrent_relays_publish_one_row_once(string $driver): void
    {
        $this->prepare($driver);
        $this->execute('store');
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $workers[] = $this->start('relay');
        }
        $published = 0;
        foreach ($workers as $worker) {
            $published += $this->finish($worker)['published'];
        }
        self::assertSame(1, $published);
        $status = $this->execute('status');
        self::assertSame(0, $status['pending_outbox']);
        self::assertCount(1, $status['events']);
    }

    #[DataProvider('databases')]
    public function test_process_death_after_commit_recovers_without_repeating_effect(string $driver): void
    {
        $this->prepare($driver);
        $this->finish($this->start('commit-crash'), 86);
        self::assertTrue($this->execute('process', ['socket' => 'reconnected'])['ok']);
        $status = $this->execute('status');
        self::assertSame(1, $status['counter']);
        self::assertSame(1, $status['receipts']);
        self::assertSame(2, $status['outbox']);
        $this->execute('relay');
        $events = $this->execute('status')['events'];
        self::assertContains('reconnected', array_column($events, 'socket_id'));
    }

    #[DataProvider('databases')]
    public function test_process_death_after_publish_retries_same_envelope_identity(string $driver): void
    {
        $this->prepare($driver);
        $this->execute('store');
        $this->finish($this->start('publish-crash'), 87);
        $status = $this->execute('status');
        self::assertSame(1, $status['pending_outbox']);
        self::assertCount(1, $status['events']);
        self::assertSame(1, $this->execute('relay')['published']);
        $status = $this->execute('status');
        self::assertSame(0, $status['pending_outbox']);
        self::assertCount(2, $status['events']);
        self::assertSame($status['events'][0]['id'], $status['events'][1]['id']);
    }

    #[DataProvider('databases')]
    public function test_retention_queries_preserve_unpublished_results_and_bound_reexecution(string $driver): void
    {
        $this->prepare($driver);
        $this->execute('process');
        $result = $this->execute('prune');
        self::assertSame(0, $result['before']['receipts']['deleted']);
        self::assertSame(1, $result['after']['receipts']['deleted']);
        self::assertSame('command.expired', $result['old_result']['error']['code']);
        self::assertSame(1, $this->execute('status')['counter']);
    }
}
