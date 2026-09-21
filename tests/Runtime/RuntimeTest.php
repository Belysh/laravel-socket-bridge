<?php

declare(strict_types=1);

namespace SocketBridge\Tests\Runtime;

use Dotenv\Dotenv;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SocketBridge\Runtime\DockerInstaller;
use SocketBridge\Runtime\FileInstaller;
use SocketBridge\Runtime\GatewayEnvironment;
use SocketBridge\Runtime\NetworkDiagnostics;
use SocketBridge\Runtime\NodeRuntime;
use SocketBridge\Runtime\Platform;
use SocketBridge\Runtime\ProcessSupervisor;
use SocketBridge\Runtime\RuntimeManifest;
use Symfony\Component\Process\Process;

final class RuntimeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/socket-bridge-runtime-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_installer_preserves_existing_configuration_and_secret_on_repeated_runs(): void
    {
        $files = new FileInstaller;
        $environment = $this->directory.'/.env';
        $original = "APP_NAME=Existing\nSOCKET_BRIDGE_SECRET=keep-this-secret\n# SOCKET_BRIDGE_MODE=ignored-comment\n";
        file_put_contents($environment, $original);
        $added = $files->appendEnvironment($environment, ['SOCKET_BRIDGE_SECRET' => 'replacement', 'SOCKET_BRIDGE_MODE' => 'native']);
        self::assertSame(['SOCKET_BRIDGE_MODE'], $added);
        self::assertStringStartsWith($original, file_get_contents($environment));
        $first = file_get_contents($environment);
        self::assertSame([], $files->appendEnvironment($environment, ['SOCKET_BRIDGE_SECRET' => 'replacement', 'SOCKET_BRIDGE_MODE' => 'docker']));
        self::assertSame($first, file_get_contents($environment));
        self::assertTrue($files->writeIfAbsent($this->directory.'/config.php', 'original'));
        self::assertFalse($files->writeIfAbsent($this->directory.'/config.php', 'replacement'));
        self::assertSame('original', file_get_contents($this->directory.'/config.php'));
    }

    public function test_explicit_broadcaster_selection_changes_only_its_key(): void
    {
        $environment = $this->directory.'/.env';
        file_put_contents($environment, "APP_NAME=Existing\nBROADCAST_CONNECTION=log\nREDIS_PASSWORD=keep-me\n");
        (new FileInstaller)->selectBroadcaster($environment);
        $expected = "APP_NAME=Existing\nBROADCAST_CONNECTION=socketio\nREDIS_PASSWORD=keep-me\n";
        self::assertSame($expected, file_get_contents($environment));
        (new FileInstaller)->selectBroadcaster($environment);
        self::assertSame($expected, file_get_contents($environment));
    }

    public function test_dotenv_encoding_round_trips_special_characters(): void
    {
        $value = 'prefix$NOT_AN_ENV_VARIABLE'."\\\"quoted\"\nnext";
        $parsed = Dotenv::parse('EXAMPLE='.FileInstaller::quote($value));
        self::assertSame($value, $parsed['EXAMPLE']);
    }

    public function test_redis_url_preserves_database_tls_and_encodes_credentials(): void
    {
        $config = new Repository([
            'database' => ['redis' => ['default' => ['host' => 'tls://redis.example.test', 'port' => 6380, 'database' => 4, 'username' => 'service:user', 'password' => 'p@ss$word']]],
            'socket-bridge' => ['redis_connection' => 'default'],
        ]);
        self::assertSame('rediss://service%3Auser:p%40ss%24word@redis.example.test:6380/4', (new GatewayEnvironment($config))->redisUrl());
        $config->set('socket-bridge.redis_url', 'redis://other:secret@127.0.0.1:6381/2');
        self::assertSame('redis://other:secret@127.0.0.1:6381/2', (new GatewayEnvironment($config))->redisUrl());
    }

    public function test_gateway_credentials_are_kept_in_environment_and_wildcard_origin_rejected(): void
    {
        $environment = new GatewayEnvironment($this->validConfig());
        $env = $environment->make();
        self::assertSame(str_repeat('s', 64), $env['SOCKET_BRIDGE_SECRET']);
        self::assertSame('/custom-bridge/internal/authorize', $env['SOCKET_BRIDGE_AUTHORIZE_PATH']);
        $config = $this->validConfig();
        $config->set('socket-bridge.gateway.origins', ['*']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit browser origins');
        (new GatewayEnvironment($config))->make();
    }

    public function test_redis_hostname_validation_rejects_url_delimiters_without_php_warnings(): void
    {
        $config = $this->validConfig();
        $config->set('database.redis.default.host', 'redis.example.test#fragment');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TCP Redis host');
        (new GatewayEnvironment($config))->redisUrl();
    }

    public function test_gateway_uses_the_same_normalized_namespace_and_payload_limit_as_laravel(): void
    {
        $config = $this->validConfig();
        $config->set('socket-bridge.prefix', 'socket-bridge:custom:testing:::');
        $config->set('socket-bridge.max_payload_bytes', 32768);
        $environment = (new GatewayEnvironment($config))->make();
        self::assertSame('socket-bridge:custom:testing', $environment['SOCKET_BRIDGE_PREFIX']);
        self::assertSame('32768', $environment['SOCKET_BRIDGE_MAX_PAYLOAD_BYTES']);
        $config->set('socket-bridge.prefix', ':::');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nonempty');
        (new GatewayEnvironment($config))->make();
    }

    public function test_generated_docker_has_host_and_container_routes_to_same_redis_and_preserves_secrets(): void
    {
        $environment = (new GatewayEnvironment($this->validConfig()))->make();
        $installer = new DockerInstaller;
        $result = $installer->install($this->directory, $this->directory.'/vendor/belysh/laravel-socket-bridge', $environment, true, 6387);
        self::assertTrue($result['created']);
        self::assertStringContainsString('@127.0.0.1:6387/0', $result['redis_url']);
        self::assertSame('http://host.docker.internal:8000', $result['laravel_url']);
        $compose = file_get_contents($this->directory.'/compose.socket-bridge.yml');
        self::assertStringContainsString('context: "./vendor/belysh/laravel-socket-bridge"', $compose);
        self::assertStringContainsString('127.0.0.1:6387:6379', $compose);
        self::assertStringContainsString('condition: service_healthy', $compose);
        $dotenv = file_get_contents($this->directory.'/.env.socket-bridge');
        self::assertStringContainsString('@socket-bridge-redis:6379/0', $dotenv);
        $password = rawurldecode(parse_url($result['redis_url'], PHP_URL_PASS));
        self::assertStringContainsString('requirepass '.$password, file_get_contents($this->directory.'/.socket-bridge/redis.conf'));
        self::assertStringNotContainsString($password, $compose);
        $again = $installer->install($this->directory, '/different/package/path', $environment, true, 6399);
        self::assertFalse($again['created']);
        self::assertSame($dotenv, file_get_contents($this->directory.'/.env.socket-bridge'));
        self::assertSame($compose, file_get_contents($this->directory.'/compose.socket-bridge.yml'));
        self::assertSame(0600, fileperms($this->directory.'/.env.socket-bridge') & 0777);
    }

    public function test_existing_docker_redis_uses_host_gateway_without_creating_a_second_redis(): void
    {
        $environment = (new GatewayEnvironment($this->validConfig()))->make();
        $result = (new DockerInstaller)->install($this->directory, $this->directory.'/vendor/belysh/laravel-socket-bridge', $environment, false);
        self::assertNull($result['redis_url']);
        self::assertStringNotContainsString('image: redis:', file_get_contents($this->directory.'/compose.socket-bridge.yml'));
        self::assertStringContainsString('redis://host.docker.internal:6379/0', file_get_contents($this->directory.'/.env.socket-bridge'));
        self::assertSame('redis://user:p%40ss@host.docker.internal:6380/3', DockerInstaller::hostReachableUrl('redis://user:p%40ss@[::1]:6380/3'));
        self::assertSame('rediss://redis.example.test:6380/2', DockerInstaller::hostReachableUrl('rediss://redis.example.test:6380/2'));
    }

    public function test_corrupted_node_download_is_rejected_before_execution(): void
    {
        $archive = $this->directory.'/node.tar.gz';
        file_put_contents($archive, 'untrusted archive');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 verification failed');
        NodeRuntime::verifyChecksum($archive, str_repeat('0', 64));
    }

    public function test_docker_sync_updates_configuration_preserves_custom_compose_and_backups_credentials(): void
    {
        $environment = (new GatewayEnvironment($this->validConfig()))->make();
        $installer = new DockerInstaller;
        $installed = $installer->install($this->directory, $this->directory.'/vendor/package', $environment, true, 6398);
        $environment['SOCKET_BRIDGE_REDIS_URL'] = $installed['redis_url'];
        $environment['SOCKET_BRIDGE_ORIGINS'] = 'https://new.example.test';
        $compose = file_get_contents($this->directory.'/compose.socket-bridge.yml')."\n# My custom networking\n";
        file_put_contents($this->directory.'/compose.socket-bridge.yml', $compose);
        file_put_contents($this->directory.'/.env.socket-bridge', "CUSTOM_OPTION=preserved\nSOCKET_BRIDGE_TLS_CERT=/old/certificate.pem\nSOCKET_BRIDGE_TLS_KEY=/old/key.pem\n", FILE_APPEND);
        $before = file_get_contents($this->directory.'/.env.socket-bridge');
        $backup = $installer->synchronize($this->directory, $environment);
        $values = Dotenv::parse(file_get_contents($this->directory.'/.env.socket-bridge'));
        self::assertSame('https://new.example.test', $values['SOCKET_BRIDGE_ORIGINS']);
        self::assertSame('preserved', $values['CUSTOM_OPTION']);
        self::assertArrayNotHasKey('SOCKET_BRIDGE_TLS_CERT', $values);
        self::assertArrayNotHasKey('SOCKET_BRIDGE_TLS_KEY', $values);
        self::assertSame('socket-bridge-redis', parse_url($values['SOCKET_BRIDGE_REDIS_URL'], PHP_URL_HOST));
        self::assertSame($compose, file_get_contents($this->directory.'/compose.socket-bridge.yml'));
        self::assertSame($before, file_get_contents($backup.'/docker.env'));
        self::assertSame(0600, fileperms($backup.'/docker.env') & 0777);
        self::assertSame(0700, fileperms($backup) & 0777);
    }

    public function test_docker_sync_rejects_unrequested_secret_rotation_without_changing_environment(): void
    {
        $environment = (new GatewayEnvironment($this->validConfig()))->make();
        $installer = new DockerInstaller;
        $installer->install($this->directory, $this->directory.'/vendor/package', $environment, false);
        $before = file_get_contents($this->directory.'/.env.socket-bridge');
        $environment['SOCKET_BRIDGE_SECRET'] = str_repeat('n', 64);
        try {
            $installer->synchronize($this->directory, $environment);
            self::fail('Secret rotation requires explicit selection.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('--sync-secret', $error->getMessage());
            self::assertSame($before, file_get_contents($this->directory.'/.env.socket-bridge'));
        }
        $installer->synchronize($this->directory, $environment, true);
        self::assertSame(str_repeat('n', 64), Dotenv::parse(file_get_contents($this->directory.'/.env.socket-bridge'))['SOCKET_BRIDGE_SECRET']);
    }

    public function test_tls_loopback_url_requires_explicit_hostname_instead_of_breaking_certificate_identity(): void
    {
        self::assertSame('https://my-app.test', DockerInstaller::hostReachableUrl('https://my-app.test'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('certificate hostname');
        DockerInstaller::hostReachableUrl('https://localhost:8443');
    }

    public function test_port_collision_is_detected_before_a_child_process_is_started(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        try {
            NetworkDiagnostics::assertPortAvailable('127.0.0.1', $port);
            self::fail('An occupied port must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('already using this address', $error->getMessage());
        } finally {
            fclose($socket);
        }
        NetworkDiagnostics::assertPortAvailable('127.0.0.1', $port);
    }

    public function test_manifest_rejects_a_future_protocol_before_downloading_or_starting_node(): void
    {
        $path = $this->directory.'/manifest.json';
        file_put_contents($path, json_encode(['schema' => 1, 'protocol' => 2, 'compatible_major' => 24, 'node_version' => '24.21.0']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema, protocol');
        new RuntimeManifest($path);
    }

    public function test_manifest_selects_pinned_platform_artifacts_and_rejects_unsupported_musl_architecture(): void
    {
        $manifest = new RuntimeManifest(dirname(__DIR__, 2).'/runtime/manifest.json');
        foreach ([new Platform('darwin', 'arm64'), new Platform('darwin', 'x64'), new Platform('linux', 'arm64'), new Platform('linux', 'x64'), new Platform('linux', 'x64', true)] as $platform) {
            $artifact = $manifest->artifact($platform);
            self::assertSame(64, strlen($artifact['sha256']));
            self::assertStringContainsString('/v'.$manifest->version().'/', $artifact['url']);
            self::assertStringEndsWith($platform->key().'.tar.gz', $artifact['url']);
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('musl-compatible Node 24');
        $manifest->artifact(new Platform('linux', 'arm64', true));
    }

    public function test_configured_runtime_requires_absolute_executable_path(): void
    {
        $runtime = new NodeRuntime(new RuntimeManifest(dirname(__DIR__, 2).'/runtime/manifest.json'), $this->directory.'/cache', 'node');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('absolute path');
        $runtime->find();
    }

    public function test_supervisor_streams_child_output_and_preserves_failure_exit_code(): void
    {
        $output = '';
        $process = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "gateway-started"); exit(17);']);
        $code = (new ProcessSupervisor)->run(['gateway' => $process], static function (string $name, string $type, string $buffer) use (&$output): void {
            $output .= $name.':'.$buffer;
        });
        self::assertSame(17, $code);
        self::assertStringContainsString('gateway:gateway-started', $output);
    }

    public function test_supervisor_stops_sibling_process_when_one_child_fails(): void
    {
        $pidFile = $this->directory.'/child.pid';
        $sibling = new Process([PHP_BINARY, '-r', 'file_put_contents($argv[1], (string) getmypid()); sleep(30);', $pidFile]);
        $failing = new Process([PHP_BINARY, '-r', 'usleep(250000); exit(9);']);
        $code = (new ProcessSupervisor)->run(['sibling' => $sibling, 'failing' => $failing], static function (): void {});
        self::assertSame(9, $code);
        self::assertFalse($sibling->isRunning());
        if (function_exists('posix_kill')) {
            self::assertFalse(@posix_kill((int) file_get_contents($pidFile), 0));
        }
    }

    private function validConfig(): Repository
    {
        return new Repository([
            'app' => ['env' => 'testing', 'url' => 'http://localhost:8000'],
            'database' => ['redis' => ['default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0]]],
            'socket-bridge' => ['internal_secret' => str_repeat('s', 64), 'prefix' => 'socket-bridge:test', 'route_prefix' => 'custom-bridge', 'gateway' => ['origins' => ['http://localhost:3000'], 'laravel_url' => 'http://localhost:8000']],
        ]);
    }
}
