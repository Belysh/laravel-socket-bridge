<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use SocketBridge\Operations\HealthService;
use SocketBridge\Runtime\InstallationProfile;
use SocketBridge\Runtime\NetworkDiagnostics;
use SocketBridge\Runtime\ProfileOptions;
use SocketBridge\Runtime\RuntimeManifest;
use SocketBridge\Transport\RedisStreams;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class DoctorCommand extends BridgeCommand
{
    protected $signature = 'socket-bridge:doctor
        {--profile= : Override auto profile discovery for diagnostics only}
        {--native : Check native Node runtime}
        {--docker : Check Docker installation}
        {--probe : Verify signed Laravel callbacks from the native or running Docker gateway network}
        {--operational : Require live gateway and command/outbox workers; show lag, pending and backlog age}
        {--skip-connections : Check configuration without connecting to Redis or the database}';

    protected $aliases = ['socket:doctor'];

    protected $description = 'Check Socket Bridge prerequisites without printing credentials';

    public function handle(): int
    {
        $failures = 0;
        $check = function (string $name, callable $callback) use (&$failures): void {
            try {
                $detail = $callback();
                $this->info('OK '.$name.($detail ? ': '.$detail : ''));
            } catch (Throwable $exception) {
                $failures++;
                // Runtime-owned messages contain no secret values; connection exceptions never leave their check.
                $this->error('FAIL '.$name.': '.$exception->getMessage());
            }
        };
        $runtime = $this->runtime();
        $check('Installation profile', function (): void {
            $plan = (new InstallationProfile($this->laravel))->plan($this->mode(), ProfileOptions::from($this));
            InstallationProfile::show($this, $plan);
            if ($plan['profile'] === 'sail' && $this->mode() === 'docker' && $plan['network'] === null) {
                throw new \RuntimeException('Sail external network is unknown; configure --network after starting Sail.');
            }
        });
        $check('PHP', static function (): string {
            if (PHP_VERSION_ID < 80300) {
                throw new \RuntimeException('PHP 8.3 or newer is required.');
            }

            return PHP_VERSION;
        });
        $check('Package runtime', function () use ($runtime): void {
            new RuntimeManifest($runtime->packagePath('runtime/manifest.json'));
            if (! is_file($runtime->packagePath('runtime/gateway.cjs'))) {
                throw new \RuntimeException('runtime/gateway.cjs is missing. Reinstall a released package or build the gateway.');
            }
        });
        $check('Gateway configuration', function () use ($runtime): void {
            $runtime->environment()->make();
        });
        $check('Runtime mode', function () use ($runtime): void {
            if ($this->mode() === 'docker') {
                $docker = (new ExecutableFinder)->find('docker');
                if ($docker === null) {
                    throw new \RuntimeException('Install Docker with its Compose plugin or use --native.');
                }
                foreach ([['compose', 'version'], ['info', '--format', '{{.ServerVersion}}']] as $args) {
                    $process = new Process([$docker, ...$args]);
                    $process->setTimeout(10);
                    try {
                        $process->run();
                    } catch (Throwable) {
                        throw new \RuntimeException('Docker did not respond. Start Docker and check docker compose version.');
                    }
                    if (! $process->isSuccessful()) {
                        throw new \RuntimeException('Docker daemon or Compose is unavailable. Start Docker and check docker compose version.');
                    }
                }
                if (! is_file($this->laravel->basePath('compose.socket-bridge.yml')) || ! is_file($this->laravel->basePath('.env.socket-bridge'))) {
                    throw new \RuntimeException('Run socket-bridge:install --mode=docker to generate local Docker configuration.');
                }
            } elseif ($runtime->node()->find() === null) {
                throw new \RuntimeException('Node 24 is unavailable. Run socket-bridge:install or configure SOCKET_BRIDGE_NODE_BINARY.');
            }
        });
        if (! $this->option('skip-connections')) {
            $check('Redis 7+', function (): void {
                try {
                    $redis = $this->laravel->make(RedisStreams::class);
                    $redis->raw('PING');
                    $info = $redis->raw('INFO', 'server');
                    $version = is_array($info) ? ($info['redis_version'] ?? '') : (preg_match('/redis_version:([^\r\n]+)/', (string) $info, $match) ? $match[1] : '');
                } catch (Throwable) {
                    throw new \RuntimeException('Cannot connect using the package Laravel Redis connection. Check host, port, credentials, TLS and PHP Redis/Predis support.');
                }
                if ($version === '' || version_compare($version, '7.0.0', '<')) {
                    throw new \RuntimeException('Redis 7 or newer is required for the supported Streams implementation.');
                }
            });
            $check('Database migrations', function (): void {
                try {
                    $schema = $this->laravel->make('db')->connection($this->laravel->make('config')->get('socket-bridge.database_connection'))->getSchemaBuilder();
                    $present = $schema->hasTable('socket_bridge_outbox') && $schema->hasTable('socket_bridge_command_receipts');
                } catch (Throwable) {
                    throw new \RuntimeException('Cannot connect to the package database. Check Laravel database configuration.');
                }
                if (! $present) {
                    throw new \RuntimeException('Run socket-bridge:install and php artisan migrate to create outbox/command receipt tables.');
                }
            });
            if ($this->option('operational')) {
                $check('Operational services', function (): string {
                    try {
                        $health = $this->laravel->make(HealthService::class)->snapshot();
                    } catch (Throwable) {
                        throw new \RuntimeException('Cannot read service health. Check Redis/database connectivity and run migrations.');
                    }
                    $this->line('Gateways: '.count($health['gateways']).'; command workers: '.count($health['workers']['commands']).'; outbox workers: '.count($health['workers']['outbox']));
                    foreach ($health['streams'] as $name => $stream) {
                        $this->line($name.': entries='.$stream['length'].'; dead_letters='.$stream['dead_letters']);
                        foreach ($stream['groups'] as $group) {
                            $this->line('  '.$group['name'].': pending='.$group['pending'].'; lag='.($group['lag'] ?? 'unknown'));
                        }
                    }
                    $this->line('Outbox: pending='.$health['outbox']['pending'].'; oldest_age_seconds='.($health['outbox']['oldest_age_seconds'] ?? 0).'; failed='.$health['outbox']['failed']);
                    if (! $health['healthy']) {
                        throw new \RuntimeException('Start or restart the gateway, socket-bridge:consume and socket-bridge:outbox. Their live heartbeats are required.');
                    }

                    return 'Live heartbeats present. Inspect lag and oldest outbox age against your service targets.';
                });
            }
        } elseif ($this->option('operational') || $this->option('probe')) {
            $this->error('--skip-connections cannot be combined with --operational or --probe.');
            $failures++;
        }
        if ($this->option('probe') && ! $this->option('skip-connections')) {
            $check('Signed callback roundtrip', function () use ($runtime): string {
                NetworkDiagnostics::probe($runtime, $this->laravel->basePath(), $this->mode() === 'docker');

                return 'Route, network/TLS and shared secret verified; no application mutation was sent.';
            });
        }
        $this->line('Keep socket-bridge:start, socket-bridge:consume, socket-bridge:outbox and your Laravel queue worker supervised in production.');
        $this->line('The Laravel callback URL must be reachable from the gateway. Polling transport requires sticky sessions when multiple gateways are used.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
