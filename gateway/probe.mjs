// Server-side Artisan diagnostic. Credentials arrive on stdin, never in argv.
import { io } from 'socket.io-client';
import { randomUUID } from 'node:crypto';

class ProbeError extends Error {
  constructor(code) { super(code); this.code = code; }
}
const started = performance.now();
let socket;
async function main() {
  let input = '';
  for await (const chunk of process.stdin) {
    input += chunk;
    if (Buffer.byteLength(input) > 32768) throw new ProbeError('probe.invalid_input');
  }
  const config = JSON.parse(input);
  const timeout = Number(config.timeout_ms ?? 30000);
  if (!Number.isInteger(timeout) || timeout < 100 || timeout > 300000) throw new ProbeError('probe.invalid_input');
  const deadline = Date.now() + timeout;
  const remaining = () => {
    const value = deadline - Date.now();
    if (value <= 0) throw new ProbeError('probe.timeout');
    return value;
  };
  const laravel = config.laravel_url ?? process.env.SOCKET_BRIDGE_LARAVEL_URL;
  const gateway = config.gateway_url ?? process.env.SOCKET_BRIDGE_PUBLIC_URL;
  for (const value of [laravel, gateway]) {
    const url = new URL(value);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) throw new ProbeError('probe.invalid_url');
  }
  const prefix = config.route_prefix;
  if (typeof prefix !== 'string' || !/^[a-zA-Z0-9_/-]+$/.test(prefix)) throw new ProbeError('probe.invalid_input');
  let response;
  try {
    response = await fetch(`${laravel.replace(/\/$/, '')}/${prefix.replace(/^\/|\/$/g, '')}/token`, {
      method: 'POST', redirect: 'error', signal: AbortSignal.timeout(remaining()),
      headers: { ...config.headers, Accept: 'application/json' },
    });
  } catch { throw new ProbeError('probe.ticket_unreachable'); }
  if (!response.ok) throw new ProbeError('probe.ticket_rejected');
  const ticket = await response.json();
  if (typeof ticket.token !== 'string' || !/^[a-f0-9]{64}$/.test(ticket.token)) throw new ProbeError('probe.invalid_ticket');
  socket = io(gateway, {
    transports: ['websocket'], autoConnect: false, reconnection: false,
    auth: { token: ticket.token }, timeout: remaining(),
    ...(config.origin ? { extraHeaders: { Origin: config.origin } } : {}),
  });
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new ProbeError('probe.connect_timeout')), remaining());
    socket.once('connect', () => { clearTimeout(timer); resolve(); });
    socket.once('connect_error', () => { clearTimeout(timer); reject(new ProbeError('probe.connect_failed')); });
    socket.connect();
  });
  const nonce = randomUUID();
  let result;
  try { result = await socket.timeout(remaining()).emitWithAck('socket-bridge.probe', { nonce }, { id: randomUUID() }); }
  catch { throw new ProbeError('probe.ack_timeout'); }
  if (!result?.ok || result.data?.nonce !== nonce || result.data?.probe !== 'socket-bridge') throw new ProbeError('probe.ack_failed');
  return { ok: true, duration_ms: Math.round(performance.now() - started), path: 'http-ticket/socket.io/redis-streams/php/outbox/ack' };
}
main().then(result => process.stdout.write(JSON.stringify(result) + '\n'), error => {
  process.stdout.write(JSON.stringify({ ok: false, error: { code: error instanceof ProbeError ? error.code : 'probe.failed' } }) + '\n');
  process.exitCode = 1;
}).finally(() => socket?.disconnect());
