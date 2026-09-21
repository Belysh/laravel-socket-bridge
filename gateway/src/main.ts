import { loadConfig } from './config';
import { createGateway } from './app';
async function main(): Promise<void> {
  if (process.argv.includes('--version')) { process.stdout.write('socket-bridge-gateway 2.1.0 protocol/1\n'); return; }
  if (Number(process.versions.node.split('.')[0]) !== 24) throw new Error('Socket Bridge gateway requires Node.js 24 LTS');
  const gateway = await createGateway(loadConfig());
  process.stdout.write(`${JSON.stringify({ service: 'socket-bridge', status: 'ready', address: gateway.address, protocol: 1 })}\n`);
  let closing = false;
  const stop = async () => {
    if (closing) return;
    closing = true;
    const deadline = setTimeout(() => process.exit(1), 10_000);
    deadline.unref();
    try { await gateway.close(); clearTimeout(deadline); process.exitCode = 0; }
    catch { process.exitCode = 1; }
  };
  process.on('SIGTERM', () => void stop());
  process.on('SIGINT', () => void stop());
  // The Artisan launcher may use an IPC input pipe to prevent orphaned children.
  if (process.env.SOCKET_BRIDGE_WATCH_STDIN === '1') { process.stdin.resume(); process.stdin.on('end', () => void stop()); }
}
void main().catch(error => { process.stderr.write(`${JSON.stringify({ service: 'socket-bridge', status: 'failed', message: error instanceof Error ? error.message.replace(/redis[s]?:\/\/[^\s]+/g, '[redis]') : 'Startup failed' })}\n`); process.exitCode = 1; });
