<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class NetworkDiagnostics
{
    public static function assertPortAvailable(string $host, int $port): void
    {
        if ($port < 1 || $port > 65535 || $host === '' || preg_match('/[\s\/@?#]/', $host)) {
            throw new RuntimeException('The gateway bind address or port is invalid.');
        }
        $host = str_contains(trim($host, '[]'), ':') ? '['.trim($host, '[]').']' : $host;
        $socket = @stream_socket_server('tcp://'.$host.':'.$port, $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('Cannot bind '.$host.':'.$port.'. Stop the process already using this address, choose another port, or use an address assigned to this machine.');
        }
        fclose($socket);
    }

    /** Verify DNS, TLS, route and shared secret from the gateway's actual network context. */
    public static function probe(RuntimeManager $runtime, string $applicationPath, bool $docker): void
    {
        $script = <<<'JS'
const { createHmac, randomBytes } = require('node:crypto');
async function probe() {
  const env = process.env;
  const body = JSON.stringify({session_id:randomBytes(32).toString('hex'),channel:'private-socket-bridge-doctor',socket_id:'doctor'});
  const timestamp = String(Math.floor(Date.now()/1000));
  const url = env.SOCKET_BRIDGE_LARAVEL_URL.replace(/\/$/,'') + env.SOCKET_BRIDGE_AUTHORIZE_PATH;
  async function request(signature) {
    const response = await fetch(url, {method:'POST',redirect:'error',signal:AbortSignal.timeout(5000),headers:{'Content-Type':'application/json',Accept:'application/json','X-Socket-Bridge-Timestamp':timestamp,'X-Socket-Bridge-Signature':signature},body});
    const result = await response.json();
    return {status:response.status,result};
  }
  const rejected = await request('0'.repeat(64));
  if (rejected.status !== 401 || rejected.result.error?.code !== 'bridge.invalid_signature') throw new Error('route');
  const signature = createHmac('sha256',env.SOCKET_BRIDGE_SECRET).update(`${timestamp}\n${body}`).digest('hex');
  const authenticated = await request(signature);
  if (authenticated.status !== 401 || authenticated.result.error?.code === 'bridge.invalid_signature') throw new Error('signature');
  process.stdout.write('ok');
}
probe().catch(() => process.exit(1));
JS;
        if ($docker) {
            $binary = (new ExecutableFinder)->find('docker');
            if ($binary === null) {
                throw new RuntimeException('Docker is unavailable. Start the gateway before running doctor --docker --probe.');
            }
            $process = new Process([$binary, 'compose', '-f', $applicationPath.'/compose.socket-bridge.yml', 'exec', '-T', 'socket-bridge', 'node', '-e', $script], $applicationPath);
        } else {
            $binary = $runtime->node()->find();
            if ($binary === null) {
                throw new RuntimeException('Node 24 is required for the callback probe. Run socket-bridge:install first.');
            }
            $process = new Process([$binary, '-e', $script], $applicationPath, $runtime->environment()->make());
        }
        $process->setTimeout(15);
        try {
            $process->run();
        } catch (\Throwable) {
            throw new RuntimeException('Callback probe timed out. Check the gateway network route to Laravel.');
        }
        if (! $process->isSuccessful() || $process->getOutput() !== 'ok') {
            throw new RuntimeException('Callback probe failed. Ensure Laravel and the Docker gateway (if selected) are running; check the callback URL, route prefix, shared secret and trusted TLS certificate. For Sail use a shared Docker network/service name; for Herd use a container-reachable hostname with trusted TLS.');
        }
    }
}
