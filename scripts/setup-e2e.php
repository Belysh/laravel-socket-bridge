<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$app = getenv('SOCKET_BRIDGE_E2E_APP') ?: $root.'/.test-results/laravel-app';
if (! str_starts_with($app, $root.'/.test-results/') || str_contains($app, '/../')) {
    throw new RuntimeException('E2E fixtures must be inside this package\'s .test-results directory.');
}
echo 'Preparing fixture: '.$app."\n";
$composer = getenv('COMPOSER_BINARY') ?: 'composer';
$run = static function (array $command, string $cwd): void {
    $process = new Process($command, $cwd, null, null, 600);
    $process->mustRun(static fn ($type, $buffer) => print $buffer);
};
if (! is_dir(dirname($app))) {
    mkdir(dirname($app), 0755, true);
}
if (! is_file($app.'/artisan')) {
    $run([$composer, 'create-project', 'laravel/laravel', $app, '^13.0', '--no-interaction', '--no-scripts'], $root);
}
$configuration = json_decode(file_get_contents($app.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$source = getenv('SOCKET_BRIDGE_E2E_SOURCE') ?: 'path';
$configuration['repositories']['socket-bridge'] = match ($source) {
    'packagist' => null,
    'github' => ['type' => 'vcs', 'url' => 'https://github.com/Belysh/laravel-socket-bridge'],
    'archive' => json_decode(file_get_contents($root.'/.test-results/release/composer-repository.json'), true, flags: JSON_THROW_ON_ERROR),
    'path' => [
        'type' => 'path', 'url' => $root,
        'options' => ['symlink' => true, 'versions' => ['belysh/laravel-socket-bridge' => 'dev-main']],
    ],
    default => throw new RuntimeException('Unsupported fixture package source.'),
};
if ($source === 'packagist') {
    unset($configuration['repositories']['socket-bridge']);
}
$configuration['require']['belysh/laravel-socket-bridge'] = getenv('SOCKET_BRIDGE_E2E_VERSION') ?: ($source === 'path' ? '@dev' : '^1.1');
$configuration['require']['predis/predis'] = '^3.0';
file_put_contents($app.'/composer.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$run([$composer, 'update', 'belysh/laravel-socket-bridge', 'predis/predis', '--with-all-dependencies', '--no-interaction', '--no-scripts'], $app);
$run([PHP_BINARY, $root.'/scripts/prepare-e2e.php'], $root);
$run([PHP_BINARY, 'artisan', 'package:discover'], $app);
$run([PHP_BINARY, 'artisan', 'socket-bridge:install', '--mode=native', '--skip-runtime', '--no-interaction'], $app);
$run([PHP_BINARY, 'artisan', 'migrate', '--force'], $app);
$run([PHP_BINARY, $root.'/scripts/seed-e2e.php'], $root);
