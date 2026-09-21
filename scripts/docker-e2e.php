<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$app = $root.'/.test-results/docker-app';
$project = 'socket-bridge-docker-e2e';
$compose = $app.'/compose.socket-bridge.yml';
$docker = (new ExecutableFinder)->find('docker');
$node = getenv('SOCKET_BRIDGE_TEST_NODE_BINARY') ?: (new ExecutableFinder)->find('node');
if ($docker === null || $node === null) {
    throw new RuntimeException('Docker Compose and Node 24 are required. Set SOCKET_BRIDGE_TEST_NODE_BINARY when Node is not on PATH.');
}
// Distinct fixture, namespace, ports and Compose project allow Native E2E to run alongside this test.
$environment = [
    'SOCKET_BRIDGE_E2E_APP' => $app,
    'SOCKET_BRIDGE_E2E_HTTP_PORT' => '18093',
    'SOCKET_BRIDGE_E2E_WS_PORT' => '16093',
    'SOCKET_BRIDGE_E2E_PREFIX' => 'socket-bridge:e2e:docker',
    'SOCKET_BRIDGE_E2E_DOCKER' => '1',
    'SOCKET_BRIDGE_E2E_DOCTOR' => '1',
    'SOCKET_BRIDGE_NODE_BINARY' => $node,
    'PHP_BINARY' => PHP_BINARY,
    'COMPOSE_PROJECT_NAME' => $project,
    'PATH' => dirname(PHP_BINARY).PATH_SEPARATOR.(getenv('PATH') ?: ''),
];
$run = static function (array $command, string $cwd, int $timeout = 600) use ($environment): void {
    (new Process($command, $cwd, $environment, null, $timeout))->mustRun(static fn ($type, $buffer) => print $buffer);
};
$down = static function () use ($docker, $project, $compose, $app, $environment): void {
    if (is_file($compose)) {
        (new Process([$docker, 'compose', '-p', $project, '-f', $compose, 'down', '--volumes', '--remove-orphans'], $app, $environment, null, 60))
            ->run(static fn ($type, $buffer) => print $buffer);
    }
};

try {
    $down();
    // These paths belong exclusively to this disposable fixture, never a consuming application.
    foreach ([$compose, $app.'/.env.socket-bridge', $app.'/.socket-bridge/redis.conf'] as $generated) {
        if (is_file($generated)) {
            unlink($generated);
        }
    }
    $run([PHP_BINARY, $root.'/scripts/setup-e2e.php'], $root);
    $run([PHP_BINARY, 'artisan', 'socket-bridge:install', '--mode=docker', '--redis=generated', '--redis-port=16390', '--laravel-url=http://127.0.0.1:18093', '--no-interaction'], $app);
    $run([$docker, 'compose', '-p', $project, '-f', $compose, 'build', 'socket-bridge'], $app);
    $run([$docker, 'compose', '-p', $project, '-f', $compose, 'up', '-d', '--wait', 'socket-bridge-redis'], $app);
    $run([$node, $root.'/scripts/e2e.mjs'], $root, 180);
    echo "PASS full Docker gateway + generated persistent Redis + host Laravel end-to-end suite.\n";
} finally {
    $down();
}
