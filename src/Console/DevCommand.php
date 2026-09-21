<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use SocketBridge\Runtime\NetworkDiagnostics;
use Throwable;

final class DevCommand extends BridgeCommand
{
    protected $aliases = ['socket:dev'];

    protected $signature = 'socket-bridge:dev
        {--native : Use Node directly}
        {--docker : Use the generated Docker Compose service}
        {--no-download : Require an existing Node 24}
        {--serve : Also start the Laravel development HTTP server}
        {--http-host=127.0.0.1 : Laravel development server bind address}
        {--http-port=8000 : Laravel development server port}
        {--no-queue : Do not start a standard Laravel queue worker}';

    protected $description = 'Run the gateway, command consumer, outbox relay and Laravel queue worker together';

    public function handle(): int
    {
        try {
            $runtime = $this->runtime();
            $mode = $this->mode();
            if ($mode === 'native') {
                $environment = $runtime->environment()->make();
                NetworkDiagnostics::assertPortAvailable($environment['SOCKET_BRIDGE_HOST'], (int) $environment['SOCKET_BRIDGE_PORT']);
            }
            if ($this->option('serve')) {
                $httpPort = filter_var($this->option('http-port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
                if ($httpPort === false) {
                    throw new \RuntimeException('The Laravel HTTP port must be between 1 and 65535.');
                }
                NetworkDiagnostics::assertPortAvailable((string) $this->option('http-host'), $httpPort);
            }
            $processes = [
                'gateway' => $mode === 'docker' ? $this->dockerProcess() : $runtime->gateway(! $this->option('no-download'), [], fn (string $message) => $this->info($message)),
                'commands' => $runtime->artisan('socket-bridge:consume', ['--no-interaction', '--max-time=0', '--memory=0']),
                'outbox' => $runtime->artisan('socket-bridge:outbox', ['--no-interaction', '--max-time=0', '--memory=0']),
            ];
            if (! $this->option('no-queue')) {
                $processes['queue'] = $runtime->artisan('queue:work', ['--sleep=1', '--tries=3', '--no-interaction']);
            }
            if ($this->option('serve')) {
                $processes['http'] = $runtime->artisan('serve', ['--host='.$this->option('http-host'), '--port='.$this->option('http-port'), '--no-reload', '--no-interaction']);
                $this->warn('Set SOCKET_BRIDGE_LARAVEL_URL to this HTTP server. Docker callbacks require a host-reachable address and --http-host=0.0.0.0.');
            }
            $this->info('Starting Socket Bridge development processes. Any child exit stops the group; Ctrl+C stops all processes.');

            return $this->runProcesses($processes, true);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Development processes could not start. Run socket-bridge:doctor.');

            return self::FAILURE;
        }
    }
}
