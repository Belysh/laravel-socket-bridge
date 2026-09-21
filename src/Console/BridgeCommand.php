<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use Illuminate\Console\Command;
use RuntimeException;
use SocketBridge\Runtime\ProcessSupervisor;
use SocketBridge\Runtime\RuntimeManager;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

abstract class BridgeCommand extends Command
{
    protected function runtime(): RuntimeManager
    {
        return new RuntimeManager($this->laravel);
    }

    protected function runProcesses(array $processes, bool $labels = false): int
    {
        return (new ProcessSupervisor)->run($processes, function (string $name, string $type, string $buffer) use ($labels): void {
            if ($labels) {
                $buffer = '['.$name.'] '.$buffer;
            }
            $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });
    }

    protected function mode(): string
    {
        if ($this->option('native') && $this->option('docker')) {
            throw new RuntimeException('Choose either --native or --docker.');
        }
        $mode = $this->option('docker') ? 'docker' : ($this->option('native') ? 'native' : $this->laravel->make('config')->get('socket-bridge.install.mode', 'native'));
        if (! in_array($mode, ['native', 'docker'], true)) {
            throw new RuntimeException('SOCKET_BRIDGE_MODE must be native or docker.');
        }

        return $mode;
    }

    protected function dockerProcess(): Process
    {
        $docker = (new ExecutableFinder)->find('docker');
        if ($docker === null) {
            throw new RuntimeException('Docker was not found. Install Docker with the Compose plugin, or run socket-bridge:start --native.');
        }
        $compose = $this->laravel->basePath('compose.socket-bridge.yml');
        if (! is_file($compose)) {
            throw new RuntimeException('Docker configuration is missing. Run socket-bridge:install --mode=docker first.');
        }

        return new Process([$docker, 'compose', '-f', $compose, 'up', '--build', 'socket-bridge'], $this->laravel->basePath(), null, null, null);
    }
}
