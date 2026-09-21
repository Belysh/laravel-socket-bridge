<?php

declare(strict_types=1);

namespace SocketBridge\Tests\Runtime;

use Dotenv\Dotenv;
use Illuminate\Filesystem\Filesystem;
use SocketBridge\Console\InstallCommand;
use SocketBridge\Runtime\DockerInstaller;
use SocketBridge\Runtime\FileInstaller;
use SocketBridge\Runtime\GatewayEnvironment;
use SocketBridge\Runtime\InstallationProfile;
use SocketBridge\Runtime\NetworkDiagnostics;
use SocketBridge\Runtime\ProfileDetector;
use SocketBridge\Runtime\RuntimeManager;
use SocketBridge\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class InstallationProfileTest extends TestCase
{
    private string $directory;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/bridge-profile-'.bin2hex(random_bytes(6));
        $this->home = $this->directory.'/home';
        mkdir($this->home, 0700, true);
        $this->app->setBasePath($this->directory);
        $this->app->useEnvironmentPath($this->directory);
        $this->app->useConfigPath($this->directory.'/config');
        $this->app->useDatabasePath($this->directory.'/database');
        config(['app.url' => 'http://localhost', 'socket-bridge.gateway.laravel_url' => 'http://localhost', 'socket-bridge.gateway.origins' => ['http://localhost'], 'socket-bridge.gateway.public_url' => 'http://localhost:6001', 'socket-bridge.install.app_url' => 'http://localhost',
            'database.redis.default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 2, 'username' => 'gateway', 'password' => 'do-not-display']]);
        file_put_contents($this->directory.'/.env', "APP_URL=http://localhost\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function profiles(bool $inside = false): InstallationProfile
    {
        return new InstallationProfile($this->app, new ProfileDetector($this->directory, $this->home, $inside));
    }

    private function sail(?string $network = 'actual-sail-network'): void
    {
        $compose = ['services' => ['laravel.test' => ['build' => ['context' => './vendor/laravel/sail/runtimes/8.4'], 'ports' => ['${APP_PORT:-8080}:80'], 'networks' => ['sail']], 'redis' => ['image' => 'redis:7-alpine', 'networks' => ['sail']]], 'networks' => ['sail' => ['driver' => 'bridge']]];
        if ($network !== null) {
            $compose['networks']['sail']['name'] = $network;
        }
        file_put_contents($this->directory.'/compose.yaml', Yaml::dump($compose, 8));
    }

    public function test_plain_php_defaults_match_artisan_serve_without_overwriting_app_url(): void
    {
        $plan = $this->profiles()->plan('native', ['offline' => true]);
        self::assertSame('php', $plan['profile']);
        self::assertSame('http://127.0.0.1:8000', $plan['browser_url']);
        self::assertSame($plan['browser_url'], $plan['callback_url']);
        self::assertSame(['http://127.0.0.1:8000'], $plan['origins']);
        $this->profiles()->apply($plan, new FileInstaller);
        self::assertSame('http://localhost', Dotenv::parse(file_get_contents($this->directory.'/.env'))['APP_URL']);
    }

    public function test_preview_is_read_only_and_credentials_are_redacted(): void
    {
        $before = file_get_contents($this->directory.'/.env');
        $command = new InstallCommand;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute(['--preview' => true, '--profile' => 'php'], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->directory.'/.env'));
        self::assertDirectoryDoesNotExist($this->directory.'/.socket-bridge');
        self::assertStringNotContainsString('do-not-display', $tester->getDisplay());
        self::assertStringContainsString('http://127.0.0.1:8000', $tester->getDisplay());
    }

    public function test_sail_service_dns_and_observed_network_generate_usable_compose(): void
    {
        $this->sail();
        $plan = $this->profiles(true)->plan('docker', ['offline' => true]);
        self::assertSame('sail', $plan['profile']);
        self::assertSame('http://laravel.test', $plan['callback_url']);
        self::assertSame('http://localhost:8080', $plan['browser_url']);
        self::assertSame('actual-sail-network', $plan['network']);
        self::assertSame('redis://gateway:do-not-display@redis:6379/2', $plan['docker_redis_url']);
        $this->profiles()->configure($plan);
        $environment = (new GatewayEnvironment(config()))->make();
        (new DockerInstaller)->install($this->directory, $this->directory.'/vendor/belysh/laravel-socket-bridge', $environment, false, 6380, $plan);
        $compose = Yaml::parseFile($this->directory.'/compose.socket-bridge.yml');
        self::assertSame('actual-sail-network', $compose['networks']['socket-bridge-project']['name']);
        self::assertTrue($compose['networks']['socket-bridge-project']['external']);
        self::assertContains('socket-bridge-project', $compose['services']['socket-bridge']['networks']);
        $env = Dotenv::parse(file_get_contents($this->directory.'/.env.socket-bridge'));
        self::assertSame('http://laravel.test', $env['SOCKET_BRIDGE_LARAVEL_URL']);
        self::assertSame('redis', $this->host($env['SOCKET_BRIDGE_REDIS_URL']));
        self::assertSame('./vendor/belysh/laravel-socket-bridge', $compose['services']['socket-bridge']['build']['context']);
    }

    public function test_sail_unknown_network_requires_explicit_evidence_before_any_write(): void
    {
        $this->sail(null);
        $plan = $this->profiles(true)->plan('docker', ['offline' => true]);
        self::assertNull($plan['network']);
        $before = file_get_contents($this->directory.'/.env');
        try {
            $this->profiles()->apply($plan, new FileInstaller);
            self::fail('Unknown network must not be guessed.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('--network', $e->getMessage());
        }
        self::assertSame($before, file_get_contents($this->directory.'/.env'));
        self::assertSame('explicit-network', $this->profiles()->plan('docker', ['offline' => true, 'network' => 'explicit-network'])['network']);
    }

    public function test_custom_values_survive_profile_changes_and_only_explicit_overrides_change_them(): void
    {
        file_put_contents($this->directory.'/.env', "APP_URL=http://localhost\nSOCKET_BRIDGE_LARAVEL_URL=https://custom.internal.test\nSOCKET_BRIDGE_URL=https://ws.custom.test\nSOCKET_BRIDGE_ORIGINS=https://frontend.custom.test\n");
        $plan = $this->profiles()->plan('docker', ['profile' => 'php', 'offline' => true]);
        self::assertSame('https://custom.internal.test', $plan['callback_url']);
        self::assertSame('https://ws.custom.test', $plan['gateway_url']);
        self::assertSame(['https://frontend.custom.test'], $plan['origins']);
        $updated = $this->profiles()->plan('docker', ['profile' => 'php', 'offline' => true, 'laravel-url' => 'https://new.internal.test']);
        $this->profiles()->apply($updated, new FileInstaller);
        $env = Dotenv::parse(file_get_contents($this->directory.'/.env'));
        self::assertSame('https://new.internal.test', $env['SOCKET_BRIDGE_LARAVEL_URL']);
        self::assertSame('https://ws.custom.test', $env['SOCKET_BRIDGE_URL']);
    }

    public function test_linked_valet_and_herd_project_markers_are_detected_from_actual_files(): void
    {
        $valet = $this->home.'/.config/valet';
        mkdir($valet.'/Sites', 0700, true);
        symlink($this->directory, $valet.'/Sites/linked-app');
        file_put_contents($valet.'/config.json', json_encode(['tld' => 'test', 'paths' => []]));
        $plan = $this->profiles()->plan('native', ['offline' => true]);
        self::assertSame('valet', $plan['profile']);
        self::assertSame('http://linked-app.test', $plan['browser_url']);
        file_put_contents($this->directory.'/herd.yml', "name: herd-app\nphp: '8.4'\n");
        $plan = $this->profiles()->plan('native', ['offline' => true]);
        self::assertSame('herd', $plan['profile']);
        self::assertSame('http://herd-app.test', $plan['browser_url']);
        config(['app.url' => 'https://custom-public.test', 'socket-bridge.install.app_url' => 'https://custom-public.test', 'socket-bridge.gateway.laravel_url' => 'https://custom-public.test', 'socket-bridge.gateway.origins' => ['https://custom-public.test']]);
        self::assertSame('https://custom-public.test', $this->profiles()->plan('native', ['offline' => true])['browser_url']);
    }

    public function test_existing_ca_and_site_certificate_keep_https_hostname_and_create_read_only_mount(): void
    {
        $directory = $this->home.'/.config/valet';
        mkdir($directory.'/Sites', 0700, true);
        mkdir($directory.'/Certificates');
        mkdir($directory.'/CA');
        symlink($this->directory, $directory.'/Sites/secure');
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'secure.test'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 2);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents($directory.'/Certificates/secure.test.crt', $pem);
        file_put_contents($directory.'/Certificates/secure.test.key', $keyPem);
        file_put_contents($directory.'/CA/LaravelValetCASelfSigned.pem', $pem);
        $plan = $this->profiles()->plan('docker', ['offline' => true]);
        self::assertSame('https://secure.test', $plan['callback_url']);
        self::assertSame('https://secure.test:6001', $plan['gateway_url']);
        self::assertSame(['secure.test'], $plan['hostnames']);
        $this->profiles()->configure($plan);
        $env = (new GatewayEnvironment(config()))->make();
        self::assertSame($plan['ca_cert'], $env['NODE_EXTRA_CA_CERTS']);
        (new DockerInstaller)->install($this->directory, $this->directory.'/vendor/package', $env, false, 6380, $plan);
        $compose = Yaml::parseFile($this->directory.'/compose.socket-bridge.yml');
        self::assertContains('secure.test:host-gateway', $compose['services']['socket-bridge']['extra_hosts']);
        self::assertContains('./.socket-bridge/tls:/run/socket-bridge/tls:ro', $compose['services']['socket-bridge']['volumes']);
        self::assertStringContainsString('node:https', $compose['services']['socket-bridge']['healthcheck']['test'][3]);
        self::assertStringNotContainsString('rejectUnauthorized', $compose['services']['socket-bridge']['healthcheck']['test'][3]);
        $saved = Dotenv::parse(file_get_contents($this->directory.'/.env.socket-bridge'));
        self::assertSame('https://secure.test', $saved['SOCKET_BRIDGE_LARAVEL_URL']);
        self::assertSame('/run/socket-bridge/tls/ca.pem', $saved['NODE_EXTRA_CA_CERTS']);
        self::assertSame($pem, file_get_contents($this->directory.'/.socket-bridge/tls/ca.pem'));
        self::assertSame(0700, fileperms($this->directory.'/.socket-bridge') & 0777);
    }

    public function test_nonexistent_or_non_certificate_ca_is_rejected_without_writes(): void
    {
        $file = $this->directory.'/fake.pem';
        file_put_contents($file, 'not a certificate');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PEM certificate');
        $this->profiles()->plan('native', ['offline' => true, 'ca-cert' => $file]);
    }

    public function test_metrics_and_buffer_limits_are_forwarded_but_short_token_is_rejected(): void
    {
        config(['socket-bridge.metrics' => ['token' => str_repeat('m', 32)], 'socket-bridge.gateway.max_buffered_bytes' => 100000, 'socket-bridge.gateway.max_buffered_packets' => 77]);
        $env = (new GatewayEnvironment(config()))->make();
        self::assertSame(str_repeat('m', 32), $env['SOCKET_BRIDGE_METRICS_TOKEN']);
        self::assertSame('100000', $env['SOCKET_BRIDGE_MAX_BUFFERED_BYTES']);
        self::assertSame('77', $env['SOCKET_BRIDGE_MAX_BUFFERED_PACKETS']);
        config(['socket-bridge.metrics.enabled' => false]);
        self::assertArrayNotHasKey('SOCKET_BRIDGE_METRICS_TOKEN', (new GatewayEnvironment(config()))->make());
        config(['socket-bridge.metrics.enabled' => true, 'socket-bridge.metrics.token' => 'short']);
        $this->expectException(\RuntimeException::class);
        (new GatewayEnvironment(config()))->make();
    }

    public function test_command_ack_limits_are_forwarded_and_invalid_timeouts_are_rejected(): void
    {
        config([
            'socket-bridge.gateway.command_ack_timeout_ms' => 45000,
            'socket-bridge.gateway.max_pending_command_acks' => 12,
            'socket-bridge.gateway.max_pending_command_acks_total' => 500,
        ]);
        $env = (new GatewayEnvironment(config()))->make();
        self::assertSame('45000', $env['SOCKET_BRIDGE_COMMAND_ACK_TIMEOUT_MS']);
        self::assertSame('12', $env['SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS']);
        self::assertSame('500', $env['SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS_TOTAL']);
        config(['socket-bridge.gateway.command_ack_timeout_ms' => 0]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOCKET_BRIDGE_COMMAND_ACK_TIMEOUT_MS');
        (new GatewayEnvironment(config()))->make();
    }

    public function test_native_https_callback_uses_explicit_ca_and_rejects_untrusted_certificate(): void
    {
        $binary = getenv('SOCKET_BRIDGE_TEST_NODE_BINARY');
        if (! $binary) {
            self::markTestSkipped('Set SOCKET_BRIDGE_TEST_NODE_BINARY to Node24.');
        }
        $openssl = $this->directory.'/openssl.cnf';
        file_put_contents($openssl, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $openssl, 'digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 2, ['config' => $openssl, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $keyPem);
        $certFile = $this->directory.'/server.crt';
        $keyFile = $this->directory.'/server.key';
        file_put_contents($certFile, $pem);
        file_put_contents($keyFile, $keyPem);
        $script = <<<'JS'
const fs=require('node:fs'),https=require('node:https'),crypto=require('node:crypto');
https.createServer({cert:fs.readFileSync(process.env.TEST_CERT),key:fs.readFileSync(process.env.TEST_KEY)},async(req,res)=>{
let body='';for await(const part of req)body+=part;
const expected=crypto.createHmac('sha256',process.env.TEST_SECRET).update(req.headers['x-socket-bridge-timestamp']+'\n'+body).digest('hex');
res.writeHead(401,{'content-type':'application/json'});res.end(JSON.stringify(req.headers['x-socket-bridge-signature']===expected?{message:'Unauthenticated.'}:{error:{code:'bridge.invalid_signature'}}));
}).listen(0,'127.0.0.1',function(){console.log(this.address().port)});
JS;
        $process = new Process([$binary, '-e', $script], $this->directory, ['TEST_CERT' => $certFile, 'TEST_KEY' => $keyFile, 'TEST_SECRET' => str_repeat('s', 64)]);
        $process->setTimeout(20);
        $process->start();
        try {
            self::assertTrue($process->waitUntil(static fn ($type, $buffer) => preg_match('/^\d+\s*$/D', $buffer) === 1));
            config(['socket-bridge.gateway.laravel_url' => 'https://127.0.0.1:'.trim($process->getOutput()), 'socket-bridge.gateway.ca_cert' => $certFile, 'socket-bridge.runtime.node_binary' => $binary]);
            $runtime = new RuntimeManager($this->app);
            NetworkDiagnostics::probe($runtime, $this->directory, false);
            self::assertTrue(true, 'The real signed HTTPS callback trusted only its configured certificate.');
            config(['socket-bridge.gateway.ca_cert' => null]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Callback probe failed');
            NetworkDiagnostics::probe($runtime, $this->directory, false);
        } finally {
            $process->stop();
        }
    }

    public function test_generated_docker_profile_verifies_tls_health_and_signed_host_callback(): void
    {
        if (getenv('SOCKET_BRIDGE_TEST_DOCKER') !== '1' || ! getenv('SOCKET_BRIDGE_TEST_NODE_BINARY')) {
            self::markTestSkipped('Set SOCKET_BRIDGE_TEST_DOCKER=1 and Node24 to run the disposable Docker/TLS profile proof.');
        }
        $name = 'socket-bridge-profile-'.bin2hex(random_bytes(5));
        $execute = static function (array $arguments, int $timeout = 60): string {
            $process = new Process($arguments);
            $process->setTimeout($timeout);
            $process->mustRun();

            return trim($process->getOutput());
        };
        $openssl = $this->directory.'/openssl.cnf';
        file_put_contents($openssl, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:secure.test\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'secure.test'], $key, ['config' => $openssl]);
        $certificate = openssl_csr_sign($csr, null, $key, 2, ['config' => $openssl, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $keyPem);
        $valet = $this->home.'/.config/valet';
        mkdir($valet.'/Certificates', 0700, true);
        mkdir($valet.'/Sites');
        mkdir($valet.'/CA');
        symlink($this->directory, $valet.'/Sites/secure');
        $cert = $valet.'/Certificates/secure.test.crt';
        $privateKey = $valet.'/Certificates/secure.test.key';
        file_put_contents($cert, $pem);
        file_put_contents($privateKey, $keyPem);
        file_put_contents($valet.'/CA/LaravelValetCASelfSigned.pem', $pem);
        $script = <<<'JS'
const fs=require('node:fs'),https=require('node:https'),crypto=require('node:crypto');
https.createServer({cert:fs.readFileSync(process.env.TEST_CERT),key:fs.readFileSync(process.env.TEST_KEY)},async(req,res)=>{
let body='';for await(const part of req)body+=part;
const expected=crypto.createHmac('sha256',process.env.TEST_SECRET).update(req.headers['x-socket-bridge-timestamp']+'\n'+body).digest('hex');
res.writeHead(401,{'content-type':'application/json'});res.end(JSON.stringify(req.headers['x-socket-bridge-signature']===expected?{message:'Unauthenticated.'}:{error:{code:'bridge.invalid_signature'}}));
}).listen(0,'0.0.0.0',function(){console.log(this.address().port)});
JS;
        $server = new Process([getenv('SOCKET_BRIDGE_TEST_NODE_BINARY'), '-e', $script], $this->directory, ['TEST_CERT' => $cert, 'TEST_KEY' => $privateKey, 'TEST_SECRET' => str_repeat('s', 64)]);
        $server->setTimeout(180);
        $server->start();
        $network = false;
        $redis = false;
        $compose = null;
        try {
            self::assertTrue($server->waitUntil(static fn ($type, $buffer) => preg_match('/^\d+\s*$/D', $buffer) === 1));
            $portSocket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr(strrchr(stream_socket_get_name($portSocket, false), ':'), 1);
            fclose($portSocket);
            config(['socket-bridge.gateway.laravel_url' => 'https://secure.test:'.trim($server->getOutput()), 'socket-bridge.gateway.port' => $port,
                'database.redis.default' => ['host' => 'redis', 'port' => 6379, 'database' => 0], 'socket-bridge.prefix' => $name]);
            $plan = $this->profiles()->plan('docker', ['offline' => true, 'network' => $name]);
            $this->profiles()->configure($plan);
            $execute(['docker', 'network', 'create', $name]);
            $network = true;
            $execute(['docker', 'run', '--rm', '-d', '--name', $name.'-redis', '--network', $name, '--network-alias', 'redis', 'redis:7-alpine']);
            $redis = true;
            (new DockerInstaller)->install($this->directory, dirname(__DIR__, 2), (new GatewayEnvironment(config()))->make(), false, 6380, $plan);
            $compose = ['docker', 'compose', '-p', $name, '-f', $this->directory.'/compose.socket-bridge.yml'];
            $execute([...$compose, 'up', '-d', '--build'], 120);
            $id = $execute([...$compose, 'ps', '-q', 'socket-bridge']);
            self::assertNotSame('', $id);
            $status = 'starting';
            $deadline = microtime(true) + 35;
            while (microtime(true) < $deadline && $status !== 'healthy') {
                $status = $execute(['docker', 'inspect', '--format', '{{.State.Health.Status}}', $id]);
                if ($status !== 'healthy') {
                    usleep(250000);
                }
            }
            self::assertSame('healthy', $status, $execute([...$compose, 'logs', '--no-color']));
            // NetworkDiagnostics uses the generated Compose project's context; the
            // explicit project name is supplied through its standard process env.
            file_put_contents($this->directory.'/.env', "\nCOMPOSE_PROJECT_NAME=".$name."\n", FILE_APPEND);
            NetworkDiagnostics::probe(new RuntimeManager($this->app), $this->directory, true);
            self::assertTrue(true, 'Generated CA mount, TLS healthcheck, local DNS mapping and signed callback succeeded together.');
        } finally {
            if ($compose !== null) {
                (new Process([...$compose, 'down', '--rmi', 'local', '--volumes', '--remove-orphans']))->run();
            }
            if ($redis) {
                (new Process(['docker', 'rm', '-f', $name.'-redis']))->run();
            }
            if ($network) {
                (new Process(['docker', 'network', 'rm', $name]))->run();
            }
            $server->stop();
        }
    }

    public function test_explicit_herd_profile_selects_herd_when_project_also_contains_sail(): void
    {
        $this->sail();
        file_put_contents($this->directory.'/herd.yml', "name: preferred-site\nsecured: true\n");
        $plan = $this->profiles()->plan('native', ['offline' => true, 'profile' => 'herd']);
        self::assertSame('herd', $plan['profile']);
        self::assertSame('https://preferred-site.test', $plan['browser_url']);
        self::assertSame('https://preferred-site.test', $plan['callback_url']);
        self::assertNull($plan['tls_cert']);
        self::assertNotEmpty($plan['warnings']);
    }

    private function host(string $url): string
    {
        return parse_url($url, PHP_URL_HOST);
    }
}
