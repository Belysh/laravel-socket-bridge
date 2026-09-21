<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use SocketBridge\Runtime\GatewayEnvironment;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class ProbeCommand extends BridgeCommand
{
    protected $signature = 'socket-bridge:probe
        {--native : Run from the Laravel host using its prepared Node runtime}
        {--docker : Run from the running gateway container}
        {--headers= : Private JSON file with existing HTTP authentication headers}
        {--laravel-url= : Override the Laravel HTTP base URL for this probe}
        {--gateway-url= : Override the Socket.IO URL for this probe}
        {--timeout=30 : Total roundtrip deadline in seconds}
        {--json : Output a machine-readable result}';

    protected $description = 'Verify authenticated Socket.IO → Redis Streams → PHP → outbox → ACK';

    public function handle(): int
    {
        try {
            $path = $this->option('headers');
            if (! is_string($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 16384) {
                throw new \RuntimeException('Provide --headers with a readable private JSON file containing your existing Authorization or Cookie/CSRF headers (maximum 16 KiB).');
            }
            $headers = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($headers) || ($headers !== [] && array_is_list($headers))) {
                throw new \RuntimeException('The headers file must contain a JSON object of header names and string values.');
            }
            foreach ($headers as $name => $value) {
                if (! is_string($name) || ! preg_match('/^[A-Za-z0-9-]+$/D', $name) || ! is_string($value) || preg_match('/[\r\n\x00]/', $value)) {
                    throw new \RuntimeException('The headers file contains invalid HTTP headers.');
                }
            }
            $seconds = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
            if ($seconds === false) {
                throw new \RuntimeException('--timeout must be between 1 and 300 seconds.');
            }
            $runtime = $this->runtime();
            $configuration = [
                'headers' => (object) $headers, 'timeout_ms' => $seconds * 1000,
                'route_prefix' => config('socket-bridge.route_prefix', 'socket-bridge'),
                'origin' => config('socket-bridge.gateway.origins.0'),
            ];
            foreach (['laravel-url' => 'laravel_url', 'gateway-url' => 'gateway_url'] as $option => $key) {
                if ($this->option($option) !== null) {
                    GatewayEnvironment::validateHttpUrl((string) $this->option($option), '--'.$option);
                    $configuration[$key] = $this->option($option);
                }
            }
            $input = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($this->mode() === 'docker') {
                $binary = (new ExecutableFinder)->find('docker');
                if ($binary === null || ! is_file($this->laravel->basePath('compose.socket-bridge.yml'))) {
                    throw new \RuntimeException('Docker Compose and a running Socket Bridge gateway are required for --docker.');
                }
                $process = new Process([$binary, 'compose', '-f', $this->laravel->basePath('compose.socket-bridge.yml'), 'exec', '-T', 'socket-bridge', 'node', '/app/probe.cjs'], $this->laravel->basePath(), null, $input);
            } else {
                $binary = $runtime->node()->find();
                if ($binary === null || ! is_file($runtime->packagePath('runtime/probe.cjs'))) {
                    throw new \RuntimeException('Prepare the package Node runtime with socket-bridge:install before running --native.');
                }
                $process = new Process([$binary, $runtime->packagePath('runtime/probe.cjs')], $this->laravel->basePath(), $runtime->environment()->make(), $input);
            }
            $process->setTimeout($seconds + 5);
            try {
                $process->run();
            } catch (Throwable) {
                throw new \RuntimeException('The roundtrip probe could not finish before its deadline.');
            }
            $result = json_decode($process->getOutput(), true);
            if (! $process->isSuccessful() || ($result['ok'] ?? false) !== true) {
                $code = $result['error']['code'] ?? 'probe.process_failed';
                if (! is_string($code) || ! preg_match('/^probe\.[a-z_]+$/D', $code)) {
                    $code = 'probe.failed';
                }
                if ($this->option('json')) {
                    $this->line(json_encode(['ok' => false, 'error' => ['code' => $code]], JSON_THROW_ON_ERROR));
                } else {
                    $this->error('FAIL '.$code.'. Check HTTP authentication, gateway connectivity and command/outbox workers. A timeout does not cancel queued diagnostic work.');
                }

                return self::FAILURE;
            }
            $safe = ['ok' => true, 'duration_ms' => (int) $result['duration_ms'], 'path' => 'http-ticket/socket.io/redis-streams/php/outbox/ack'];
            if ($this->option('json')) {
                $this->line(json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info('OK authenticated Socket.IO roundtrip: '.$safe['duration_ms'].' ms. PHP handler and outbox ACK verified.');
            }

            return self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        } catch (Throwable) {
            $message = 'The probe configuration could not be read. Check the headers file and package configuration.';
        }
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => false, 'error' => ['code' => 'probe.configuration', 'message' => $message]], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
