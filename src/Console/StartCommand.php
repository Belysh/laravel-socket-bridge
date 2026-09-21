<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use SocketBridge\Runtime\NetworkDiagnostics;
use Throwable;

final class StartCommand extends BridgeCommand
{
    protected $aliases = ['socket:start'];

    protected $signature = 'socket-bridge:start
        {--native : Run the private/existing Node runtime}
        {--docker : Run the generated Docker Compose service}
        {--host= : Override the gateway bind address for this process}
        {--port= : Override the gateway port for this process}
        {--no-download : Require an already installed Node 24}';

    protected $description = 'Run the Socket Bridge gateway in the foreground';

    public function handle(): int
    {
        try {
            if ($this->mode() === 'docker') {
                if ($this->option('host') || $this->option('port')) {
                    $this->error('For Docker, configure ports in compose.socket-bridge.yml and .env.socket-bridge.');

                    return self::FAILURE;
                }

                return $this->runProcesses(['gateway' => $this->dockerProcess()]);
            }
            $overrides = [];
            if ($this->option('host')) {
                $overrides['SOCKET_BRIDGE_HOST'] = (string) $this->option('host');
            }
            if ($this->option('port')) {
                $port = filter_var($this->option('port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
                if ($port === false) {
                    $this->error('The gateway port must be between 1 and 65535.');

                    return self::FAILURE;
                }
                $overrides['SOCKET_BRIDGE_PORT'] = (string) $port;
            }
            $environment = $this->runtime()->environment()->make($overrides);
            NetworkDiagnostics::assertPortAvailable($environment['SOCKET_BRIDGE_HOST'], (int) $environment['SOCKET_BRIDGE_PORT']);
            $process = $this->runtime()->gateway(! $this->option('no-download'), $overrides, fn (string $message) => $this->info($message));
            $this->info('Starting Socket Bridge. Press Ctrl+C to stop.');

            return $this->runProcesses(['gateway' => $process]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('The gateway could not start. Run socket-bridge:doctor to inspect its configuration.');

            return self::FAILURE;
        }
    }
}
