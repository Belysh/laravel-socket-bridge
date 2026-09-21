<?php

declare(strict_types=1);

namespace SocketBridge\Tests\Runtime;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SocketBridge\Console\ConfigureCommand;
use SocketBridge\Console\InstallCommand;
use SocketBridge\Console\UpgradeCommand;
use SocketBridge\Runtime\NetworkDiagnostics;
use SocketBridge\Runtime\RuntimeManager;
use SocketBridge\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class InstallCommandTest extends TestCase
{
    private string $applicationDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applicationDirectory = sys_get_temp_dir().'/socket-bridge-install-test-'.bin2hex(random_bytes(6));
        mkdir($this->applicationDirectory, 0700, true);
        $this->app->setBasePath($this->applicationDirectory);
        $this->app->useEnvironmentPath($this->applicationDirectory);
        $this->app->useConfigPath($this->applicationDirectory.'/config');
        $this->app->useDatabasePath($this->applicationDirectory.'/database');
        $this->app->useAppPath($this->applicationDirectory.'/app');
        config()->set('broadcasting.default', 'log');
        config()->set('socket-bridge.gateway.origins', ['http://localhost:3000']);
        config()->set('socket-bridge.gateway.laravel_url', 'http://localhost:8000');
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        config()->set('socket-bridge.gateway.port', (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1));
        fclose($listener);
        config()->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0]);
        file_put_contents($this->applicationDirectory.'/.env', "APP_NAME=ExistingApplication\nBROADCAST_CONNECTION=log\nREDIS_PASSWORD=do-not-change\n");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->applicationDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->applicationDirectory);
    }

    public function test_native_install_is_idempotent_preserves_secrets_and_existing_project_channels(): void
    {
        mkdir($this->applicationDirectory.'/routes');
        file_put_contents($this->applicationDirectory.'/routes/channels.php', '<?php // existing application permissions');
        $first = $this->install(['--mode' => 'native', '--skip-runtime' => true, '--example' => true]);
        self::assertSame(0, $first->getStatusCode(), $first->getDisplay());
        $environment = file_get_contents($this->applicationDirectory.'/.env');
        self::assertStringContainsString('BROADCAST_CONNECTION=socketio', $environment);
        self::assertStringContainsString('REDIS_PASSWORD=do-not-change', $environment);
        self::assertSame('<?php // existing application permissions', file_get_contents($this->applicationDirectory.'/routes/channels.php'));
        self::assertFileExists($this->applicationDirectory.'/app/Events/SocketBridgeExample.php');
        file_put_contents($this->applicationDirectory.'/config/socket-bridge.php', '<?php return ["custom" => true];');
        // Simulate a previous publish that used a deployment timestamp.
        $migration = glob($this->applicationDirectory.'/database/migrations/*.php')[0];
        rename($migration, dirname($migration).'/2026_01_01_000001_create_socket_bridge_tables.php');
        $again = $this->install(['--mode' => 'native', '--skip-runtime' => true]);
        self::assertSame(0, $again->getStatusCode(), $again->getDisplay());
        self::assertSame($environment, file_get_contents($this->applicationDirectory.'/.env'));
        self::assertSame('<?php return ["custom" => true];', file_get_contents($this->applicationDirectory.'/config/socket-bridge.php'));
        self::assertCount(count(glob(dirname(__DIR__, 2).'/database/migrations/*.php')), glob($this->applicationDirectory.'/database/migrations/*.php'));
    }

    public function test_keep_broadcaster_preserves_existing_driver_and_installs_channel_example(): void
    {
        $tester = $this->install(['--mode' => 'native', '--skip-runtime' => true, '--keep-broadcaster' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('BROADCAST_CONNECTION=log', file_get_contents($this->applicationDirectory.'/.env'));
        self::assertStringContainsString("Broadcast::channel('users.{id}'", file_get_contents($this->applicationDirectory.'/routes/channels.php'));
    }

    public function test_docker_install_generates_dedicated_php_redis_override_and_explicit_mode_switch(): void
    {
        $this->install(['--mode' => 'native', '--skip-runtime' => true]);
        $tester = $this->install(['--mode' => 'docker', '--redis' => 'generated', '--redis-port' => 6397]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $environment = file_get_contents($this->applicationDirectory.'/.env');
        self::assertStringContainsString('SOCKET_BRIDGE_MODE=docker', $environment);
        self::assertStringContainsString('SOCKET_BRIDGE_REDIS_URL="redis://:', $environment);
        self::assertStringContainsString('@127.0.0.1:6397/0', $environment);
        self::assertStringContainsString('REDIS_PASSWORD=do-not-change', $environment);
        self::assertFileExists($this->applicationDirectory.'/compose.socket-bridge.yml');
        self::assertStringNotContainsString(str_repeat('s', 64), $tester->getDisplay());
        $again = $this->install(['--mode' => 'docker', '--redis' => 'generated']);
        self::assertSame(0, $again->getStatusCode(), $again->getDisplay());
        self::assertSame($environment, file_get_contents($this->applicationDirectory.'/.env'));
    }

    private function install(array $options): CommandTester
    {
        $command = new InstallCommand;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute($options, ['interactive' => false]);

        return $tester;
    }

    public function test_explicit_callback_url_replaces_the_saved_value_on_reinstallation(): void
    {
        $first = $this->install(['--mode' => 'native', '--skip-runtime' => true]);
        self::assertSame(0, $first->getStatusCode(), $first->getDisplay());
        $again = $this->install(['--mode' => 'native', '--skip-runtime' => true, '--laravel-url' => 'https://app.example.test']);
        self::assertSame(0, $again->getStatusCode(), $again->getDisplay());
        $environment = Dotenv::parse(file_get_contents($this->applicationDirectory.'/.env'));
        self::assertSame('https://app.example.test', $environment['SOCKET_BRIDGE_LARAVEL_URL']);
    }

    public function test_configure_persists_mode_url_and_synchronizes_docker_without_replacing_compose(): void
    {
        $this->install(['--mode' => 'docker', '--redis' => 'existing']);
        $compose = file_get_contents($this->applicationDirectory.'/compose.socket-bridge.yml');
        $command = new ConfigureCommand;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute(['--docker' => true, '--laravel-url' => 'http://laravel.test:8000'], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        foreach (['.env', '.env.socket-bridge'] as $file) {
            self::assertSame('http://laravel.test:8000', Dotenv::parse(file_get_contents($this->applicationDirectory.'/'.$file))['SOCKET_BRIDGE_LARAVEL_URL']);
        }
        self::assertSame($compose, file_get_contents($this->applicationDirectory.'/compose.socket-bridge.yml'));
        $tester->execute(['--native' => true], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('native', Dotenv::parse(file_get_contents($this->applicationDirectory.'/.env'))['SOCKET_BRIDGE_MODE']);
    }

    public function test_upgrade_keeps_custom_config_and_creates_private_reviewable_defaults_and_backup(): void
    {
        $this->install(['--mode' => 'docker', '--redis' => 'existing']);
        $custom = '<?php return ["custom" => true];';
        file_put_contents($this->applicationDirectory.'/config/socket-bridge.php', $custom);
        $command = new UpgradeCommand;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute(['--docker' => true], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($custom, file_get_contents($this->applicationDirectory.'/config/socket-bridge.php'));
        self::assertCount(1, glob($this->applicationDirectory.'/.socket-bridge/backups/*/socket-bridge.defaults.php'));
        self::assertCount(1, glob($this->applicationDirectory.'/.socket-bridge/backups/*/socket-bridge.php'));
        self::assertStringNotContainsString(str_repeat('s', 64), $tester->getDisplay());
    }

    public function test_upgrade_publishes_only_missing_migrations_and_applies_index_to_existing_database(): void
    {
        $this->install(['--mode' => 'docker', '--redis' => 'existing']);
        $directory = $this->applicationDirectory.'/database/migrations';
        $initial = glob($directory.'/*_create_socket_bridge_tables.php')[0];
        $existing = $directory.'/2025_01_01_000001_create_socket_bridge_tables.php';
        rename($initial, $existing);
        $customMigration = file_get_contents($existing)."\n// Existing application migration customization.\n";
        file_put_contents($existing, $customMigration);
        // This deployment already applied the old release; the newly shipped
        // retention index has not been published or applied yet.
        unlink(glob($directory.'/*_index_socket_bridge_receipt_retention.php')[0]);
        $repository = app('migration.repository');
        $repository->createRepository();
        $repository->log('2025_01_01_000001_create_socket_bridge_tables', 1);
        DB::table('socket_bridge_command_receipts')->insert([
            'session_id' => str_repeat('a', 64), 'command_id' => '01996123-abcd-7123-8abc-123456789abc',
            'fingerprint' => str_repeat('b', 64), 'user_id' => '7',
            'result' => json_encode(['ok' => true, 'data' => ['saved' => true]]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customConfig = '<?php return ["custom" => true];';
        file_put_contents($this->applicationDirectory.'/config/socket-bridge.php', $customConfig);
        $environment = file_get_contents($this->applicationDirectory.'/.env');
        self::assertFalse(Schema::hasIndex('socket_bridge_command_receipts', 'socket_bridge_receipts_retention'));
        $command = new UpgradeCommand;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute(['--docker' => true], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Published 1 new package migration(s)', $tester->getDisplay());
        self::assertStringContainsString('php artisan migrate', $tester->getDisplay());
        self::assertSame($customMigration, file_get_contents($existing));
        self::assertFileDoesNotExist($initial);
        self::assertSame($customConfig, file_get_contents($this->applicationDirectory.'/config/socket-bridge.php'));
        self::assertSame($environment, file_get_contents($this->applicationDirectory.'/.env'));
        $index = glob($directory.'/*_index_socket_bridge_receipt_retention.php')[0];
        self::assertSame(file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_09_21_000002_index_socket_bridge_receipt_retention.php'), file_get_contents($index));
        self::assertCount(1, app('migrator')->run([$directory]));
        self::assertTrue(Schema::hasIndex('socket_bridge_command_receipts', 'socket_bridge_receipts_retention'));
        self::assertSame('7', DB::table('socket_bridge_command_receipts')->value('user_id'));
        self::assertSame([], app('migrator')->run([$directory]));
        $tester->execute(['--docker' => true], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Published 0 new package migration(s)', $tester->getDisplay());
        self::assertCount(2, glob($directory.'/*.php'));
        // Rollback is additive too: it removes only this index and retains receipts.
        self::assertCount(1, app('migrator')->rollback([$directory], ['step' => 1]));
        self::assertFalse(Schema::hasIndex('socket_bridge_command_receipts', 'socket_bridge_receipts_retention'));
        self::assertSame(1, DB::table('socket_bridge_command_receipts')->count());
    }

    public function test_native_callback_probe_checks_route_and_secret_without_creating_a_session(): void
    {
        if (getenv('SOCKET_BRIDGE_TEST_NODE_BINARY')) {
            config()->set('socket-bridge.runtime.node_binary', getenv('SOCKET_BRIDGE_TEST_NODE_BINARY'));
        }
        $runtime = new RuntimeManager($this->app);
        $binary = $runtime->node()->find();
        if ($binary === null) {
            self::markTestSkipped('Node 24 is required; set SOCKET_BRIDGE_TEST_NODE_BINARY when it is not on PATH.');
        }
        $script = <<<'JS'
const {createServer} = require('node:http');
const {createHmac} = require('node:crypto');
const server = createServer(async (req,res) => {
  let body=''; for await(const chunk of req) body += chunk;
  const expected=createHmac('sha256', process.env.TEST_SECRET).update(req.headers['x-socket-bridge-timestamp']+'\n'+body).digest('hex');
  res.writeHead(401, {'content-type':'application/json'});
  res.end(JSON.stringify(expected===req.headers['x-socket-bridge-signature']?{message:'Unauthenticated.'}:{error:{code:'bridge.invalid_signature'}}));
});
server.listen(0,'127.0.0.1',()=>console.log(server.address().port));
JS;
        $server = new Process([$binary, '-e', $script], $this->applicationDirectory, ['TEST_SECRET' => str_repeat('s', 64)]);
        $server->setTimeout(20);
        $server->start();
        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $buffer): bool => preg_match('/^\d+\s*$/D', $buffer) === 1));
            config()->set('socket-bridge.gateway.laravel_url', 'http://127.0.0.1:'.trim($server->getOutput()));
            NetworkDiagnostics::probe($runtime, $this->applicationDirectory, false);
            config()->set('socket-bridge.internal_secret', str_repeat('wrong', 13));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Callback probe failed');
            NetworkDiagnostics::probe($runtime, $this->applicationDirectory, false);
        } finally {
            $server->stop();
        }
    }
}
