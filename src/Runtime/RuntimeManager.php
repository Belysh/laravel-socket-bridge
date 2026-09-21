<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class RuntimeManager
{
    public function __construct(private readonly Application $app) {}

    public function packagePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    public function node(): NodeRuntime
    {
        return new NodeRuntime(
            new RuntimeManifest($this->packagePath('runtime/manifest.json')),
            $this->app->storagePath('app/private/socket-bridge/runtimes'),
            $this->app->make('config')->get('socket-bridge.runtime.node_binary'),
        );
    }

    public function environment(): GatewayEnvironment
    {
        return new GatewayEnvironment($this->app->make('config'));
    }

    public function gateway(bool $download = true, array $overrides = [], ?callable $progress = null): Process
    {
        $bundle = $this->packagePath('runtime/gateway.cjs');
        if (! is_file($bundle)) {
            throw new RuntimeException('The bundled runtime/gateway.cjs is missing. Install a released Socket Bridge package, or build the gateway when developing this package.');
        }
        $environment = $this->environment()->make($overrides);
        $binary = $this->node()->resolve($download && (bool) $this->app->make('config')->get('socket-bridge.runtime.download', true), $progress);

        // Keeping this pipe open lets the gateway exit if the PHP parent is killed.
        $environment['SOCKET_BRIDGE_WATCH_STDIN'] = '1';

        return new Process([$binary, $bundle], $this->app->basePath(), $environment, new InputStream, null);
    }

    public function artisan(string $command, array $arguments = []): Process
    {
        return new Process([PHP_BINARY, $this->app->basePath('artisan'), $command, ...$arguments], $this->app->basePath(), null, null, null);
    }
}
