<?php

// Independent Docker networking proof, matching Sail's service-DNS topology.
// Requires an already prepared disposable .test-results/laravel-app fixture.
declare(strict_types=1);

if (($argv[1] ?? '') === '--seed') {
    require '/fixture/vendor/autoload.php';
    $app = require '/fixture/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('local') || config('database.default') !== 'sqlite') {
        throw new RuntimeException('Disposable SQLite fixture only.');
    }
    foreach ([1, 2] as $id) {
        User::query()->updateOrCreate(['id' => $id], ['name' => 'Container '.$id, 'email' => 'container'.$id.'@example.test', 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
    }
    if (! Schema::hasTable('demo_notes')) {
        Schema::create('demo_notes', function ($table): void {
            $table->id();
            $table->string('user_id');
            $table->string('text');
        });
    }
    exit(0);
}
require dirname(__DIR__).'/vendor/autoload.php';
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$run = 'socket-bridge-network-'.bin2hex(random_bytes(5));
$fixture = sys_get_temp_dir().'/'.$run;
$phpImage = getenv('SOCKET_BRIDGE_NETWORK_PHP_IMAGE');
if (! $phpImage) {
    throw new RuntimeException('Set SOCKET_BRIDGE_NETWORK_PHP_IMAGE to a local PHP 8.3+ CLI image with Laravel extensions and PDO SQLite.');
}
$nodeImage = getenv('SOCKET_BRIDGE_NETWORK_NODE_IMAGE') ?: 'node:24.21.0-bookworm-slim';
$source = getenv('SOCKET_BRIDGE_E2E_APP') ?: $root.'/.test-results/laravel-app';
$secret = bin2hex(random_bytes(32));
$containers = [];
$network = false;
$execute = static function (array $args, int $timeout = 60): string {
    $process = new Process($args);
    $process->setTimeout($timeout);
    $process->mustRun();

    return trim($process->getOutput());
};
try {
    if (! is_file($source.'/artisan')) {
        throw new RuntimeException('Prepare scripts/setup-e2e.php first.');
    }
    $execute(['cp', '-R', $source, $fixture]);
    // The copied fixture receives its own SQLite database, cache, logs, and sessions.
    (new Filesystem)->delete($fixture.'/database/database.sqlite');
    foreach (['storage/logs', 'storage/framework/sessions', 'storage/framework/cache/data'] as $directory) {
        (new Filesystem)->deleteDirectory($fixture.'/'.$directory);
    }
    foreach (['storage/logs', 'storage/framework/sessions', 'storage/framework/cache/data'] as $directory) {
        (new Filesystem)->makeDirectory($fixture.'/'.$directory, 0755, true);
    }
    file_put_contents($fixture.'/database/database.sqlite', '');
    foreach (glob($root.'/database/migrations/*.php') ?: [] as $file) {
        $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
        if ((glob($fixture.'/database/migrations/*_'.$suffix) ?: []) === []) {
            copy($file, $fixture.'/database/migrations/'.basename($file));
        }
    }
    foreach (glob($fixture.'/bootstrap/cache/*.php') ?: [] as $file) {
        unlink($file);
    }
    $env = [
        'APP_NAME' => 'ContainerBridge', 'APP_ENV' => 'local', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false', 'APP_URL' => 'http://laravel.test:8000',
        'LOG_CHANNEL' => 'single', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/fixture/database/database.sqlite', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'file', 'BROADCAST_CONNECTION' => 'socketio',
        'REDIS_CLIENT' => 'predis', 'REDIS_HOST' => 'redis', 'REDIS_PORT' => '6379', 'REDIS_DB' => '0', 'SOCKET_BRIDGE_REDIS_URL' => 'redis://redis:6379/0',
        'SOCKET_BRIDGE_PREFIX' => $run, 'SOCKET_BRIDGE_SECRET' => $secret, 'SOCKET_BRIDGE_HOST' => '0.0.0.0', 'SOCKET_BRIDGE_PORT' => '6001', 'SOCKET_BRIDGE_URL' => 'http://gateway:6001',
        'SOCKET_BRIDGE_LARAVEL_URL' => 'http://laravel.test:8000', 'SOCKET_BRIDGE_ORIGINS' => 'http://laravel.test:8000',
    ];
    file_put_contents($fixture.'/.env', implode("\n", array_map(static fn ($key, $value) => $key.'='.$value, array_keys($env), $env))."\n");
    chmod($fixture.'/.env', 0600);
    $execute(['docker', 'network', 'create', $run]);
    $network = true;
    $redis = $run.'-redis';
    $containers[] = $redis;
    $execute(['docker', 'run', '--rm', '-d', '--name', $redis, '--network', $run, '--network-alias', 'redis', 'redis:7-alpine']);
    $laravel = $run.'-laravel';
    $containers[] = $laravel;
    $launch = <<<'SH'
set -e
php artisan package:discover --ansi
php artisan migrate --force --no-interaction
php /package/scripts/container-network-smoke.php --seed
php artisan socket-bridge:consume > /fixture/storage/logs/commands.log 2>&1 &
commands=$!
php artisan socket-bridge:outbox > /fixture/storage/logs/outbox.log 2>&1 &
outbox=$!
trap 'kill "$commands" "$outbox" 2>/dev/null || true' EXIT TERM INT
php -S 0.0.0.0:8000 -t public public/index.php
SH;
    $execute(['docker', 'run', '-d', '--name', $laravel, '--network', $run, '--network-alias', 'laravel.test', '--user', '0:0', '--env-file', $fixture.'/.env', '-v', $fixture.':/fixture', '-v', $root.':/package:ro', '-v', $root.':'.$root.':ro', '-w', '/fixture', '--entrypoint', 'sh', $phpImage, '-c', $launch]);
    $gateway = $run.'-gateway';
    $containers[] = $gateway;
    $execute(['docker', 'run', '--rm', '-d', '--name', $gateway, '--network', $run, '--network-alias', 'gateway', '--env-file', $fixture.'/.env', '-v', $root.':/package:ro', '--entrypoint', 'node', $nodeImage, '/package/runtime/gateway.cjs']);
    $client = $run.'-client';
    $containers[] = $client;
    echo $execute(['docker', 'run', '--rm', '--name', $client, '--network', $run, '--env-file', $fixture.'/.env', '-v', $root.':/package:ro', '--entrypoint', 'node', $nodeImage, '/package/scripts/container-network-smoke.mjs'], 90)."\n";
    $report = ['topology' => 'Four independent containers: Laravel/PHP workers, gateway, Redis, client; one dedicated bridge network; no published host ports.',
        'callback' => 'http://laravel.test:8000', 'redis' => 'redis://redis:6379/0', 'gateway' => 'http://gateway:6001', 'php_image' => $phpImage, 'node_image' => $nodeImage,
        'laravel_version' => $execute(['docker', 'exec', $laravel, 'php', 'artisan', '--version']), 'php_version' => $execute(['docker', 'exec', $laravel, 'php', '-r', 'echo PHP_VERSION;']),
        'node_version' => $execute(['docker', 'exec', $gateway, 'node', '--version']), 'status' => 'passed', 'tested_at' => gmdate('c')];
    file_put_contents($root.'/.test-results/container-network-smoke.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    foreach ($containers as $name) {
        $log = new Process(['docker', 'logs', $name]);
        $log->run();
        fwrite(STDERR, "[$name]\n".$log->getOutput().$log->getErrorOutput());
    }
    throw $error;
} finally {
    foreach (array_reverse($containers) as $name) {
        (new Process(['docker', 'rm', '-f', $name]))->run();
    }
    if ($network) {
        (new Process(['docker', 'network', 'rm', $run]))->run();
    }
    (new Filesystem)->deleteDirectory($fixture);
}
