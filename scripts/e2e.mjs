import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { once } from 'node:events';
import { io } from '../gateway/node_modules/socket.io-client/build/esm-debug/index.js';

const root = resolve(import.meta.dirname, '..');
const app = process.env.SOCKET_BRIDGE_E2E_APP ?? resolve(root, '.test-results/laravel-app');
const php = process.env.PHP_BINARY ?? 'php';
const docker = process.env.SOCKET_BRIDGE_E2E_DOCKER === '1';
const httpPort = Number(process.env.SOCKET_BRIDGE_E2E_HTTP_PORT ?? 18092);
const wsPort = Number(process.env.SOCKET_BRIDGE_E2E_WS_PORT ?? 16092);
const base = `http://127.0.0.1:${httpPort}`;
const gateway = `http://127.0.0.1:${wsPort}`;
const children = [];
const clients = [];
const logs = new Map();
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function eventually(predicate, label) {
  const until = Date.now() + 10000;
  while (Date.now() < until) { if (predicate()) return; await sleep(25); }
  throw new Error(`Timed out: ${label}`);
}

function start(name, args) {
  const child = spawn(php, args, {
    cwd: app,
    env: { ...process.env, SOCKET_BRIDGE_NODE_BINARY: process.execPath },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  logs.set(name, '');
  child.stdout.on('data', chunk => logs.set(name, logs.get(name) + chunk));
  child.stderr.on('data', chunk => logs.set(name, logs.get(name) + chunk));
  children.push(child);
  return child;
}
async function ready(url) {
  for (let attempt = 0; attempt < 120; attempt++) {
    if (children.some(child => child.exitCode !== null)) throw new Error('A fixture process exited before readiness');
    try { if ((await fetch(url)).ok) return; } catch {}
    await sleep(100);
  }
  throw new Error(`Readiness timeout: ${url}`);
}
async function login(id) {
  const cookies = new Map();
  let csrf;
  async function request(path, method = 'GET', headers = {}, body) {
    const response = await fetch(`${base}${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        Cookie: [...cookies].map(([key, value]) => `${key}=${value}`).join('; '),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        ...headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    for (const cookie of response.headers.getSetCookie()) {
      const [part] = cookie.split(';');
      const at = part.indexOf('=');
      cookies.set(part.slice(0, at), part.slice(at + 1));
    }
    if (!response.ok) throw new Error(`${method} ${path}: ${response.status} ${await response.text()}`);
    return response.json();
  }
  csrf = (await request(`/demo/login/${id}`)).csrf;
  return { request, token: async () => (await request('/socket-bridge/token', 'POST')).token };
}
async function connect(session) {
  const socket = io(gateway, { auth: { token: await session.token() }, transports: ['websocket'], autoConnect: false, reconnection: false });
  clients.push(socket);
  const connected = Promise.race([
    event(socket, 'connect'),
    new Promise((_, reject) => socket.once('connect_error', reject)),
  ]);
  socket.connect();
  await connected;
  return socket;
}
async function request(socket, name, payload, options) {
  const result = await socket.timeout(35_000).emitWithAck(name, payload, ...(options ? [options] : []));
  if (!result.ok) throw Object.assign(new Error(result.error?.message ?? 'Request rejected'), result.error);
  return result;
}
function event(socket, name) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { socket.off(name, listener); reject(new Error(`Event timeout: ${name}`)); }, 35_000);
    const listener = (...args) => { clearTimeout(timer); resolve(args); };
    socket.once(name, listener);
  });
}

async function doctor() {
  const child = spawn(php, ['artisan', 'socket-bridge:doctor', docker ? '--docker' : '--native', '--probe', '--operational'], {
    cwd: app, env: { ...process.env, SOCKET_BRIDGE_NODE_BINARY: process.execPath }, stdio: ['ignore', 'pipe', 'pipe'],
  });
  logs.set('doctor', '');
  child.stdout.on('data', chunk => logs.set('doctor', logs.get('doctor') + chunk));
  child.stderr.on('data', chunk => logs.set('doctor', logs.get('doctor') + chunk));
  const [code] = await once(child, 'exit');
  assert.equal(code, 0, logs.get('doctor'));
  console.log('PASS doctor signed callback probe + operational gateway/worker heartbeat and backlog checks');
}

try {
  start('http', ['-S', `${docker ? '0.0.0.0' : '127.0.0.1'}:${httpPort}`, '-t', 'public', 'public/index.php']);
  start('gateway', ['artisan', 'socket-bridge:start', ...(docker ? ['--docker'] : ['--native', '--no-download'])]);
  start('commands', ['artisan', 'socket-bridge:consume']);
  start('outbox', ['artisan', 'socket-bridge:outbox']);
  start('queue', ['artisan', 'queue:work', '--sleep=1', '--tries=1']);
  await ready(`${base}/up`);
  await ready(`${gateway}/health/ready`);
  if (process.env.SOCKET_BRIDGE_E2E_DOCTOR === '1') await doctor();

  const session = await login(1);
  const other = await login(2);
  const first = await connect(session);
  const second = await connect(session);
  const stranger = await connect(other);
  await request(first, 'room:join', { channel: 'private-demo.1' });
  await request(second, 'room:join', { channel: 'private-demo.1' });
  await assert.rejects(request(stranger, 'room:join', { channel: 'private-demo.1' }));
  await assert.rejects(request(stranger, 'room:join', { channel: '__user:forged' }));
  console.log('PASS real Laravel session ticket + private-channel authorization');

  let members = [], scopedEvents = 0;
  first.on('bridge.presence', value => { if (value.channel === 'presence-team.allowed') members = value.members; });
  await request(first, 'room:join', { channel: 'presence-team.allowed' });
  await request(second, 'room:join', { channel: 'presence-team.allowed' });
  await eventually(() => members.length === 1, 'presence deduplicates same-user tabs');
  await request(stranger, 'room:join', { channel: 'presence-team.allowed' });
  await eventually(() => members.length === 2 && members.some(member => member.id === '2'), 'presence joins');
  await request(stranger, 'room:leave', { channel: 'presence-team.allowed' });
  await eventually(() => members.length === 1, 'presence leaves');
  second.on('demo.updated', (_payload, metadata) => { scopedEvents++; assert.deepEqual(metadata.channels, ['private-demo.1']); });
  console.log('PASS raw Socket.IO presence snapshots and same-user tab deduplication');

  let senderEvents = 0;
  first.on('demo.updated', () => senderEvents++);
  const update = event(second, 'demo.updated');
  await session.request('/demo/broadcast', 'POST', { 'X-Socket-ID': first.id });
  assert.equal((await update)[0].text, 'native broadcaster');
  await sleep(100);
  assert.equal(senderEvents, 0);
  assert.equal(scopedEvents, 1);

  console.log('PASS native ShouldBroadcastNow + InteractsWithSockets + toOthers');

  const queued = event(second, 'demo.updated');
  await session.request('/demo/queued', 'POST', { 'X-Socket-ID': first.id });
  assert.equal((await queued)[0].text, 'queued broadcaster');
  await sleep(100);
  assert.equal(senderEvents, 0);
  assert.equal(scopedEvents, 2);

  console.log('PASS queued ShouldBroadcast preserves origin socket through the Laravel queue');

  const directA = event(first, 'demo.direct');
  const directB = event(second, 'demo.direct');
  await session.request('/demo/direct', 'POST');
  await Promise.all([directA, directB]);
  console.log('PASS facade toUser reaches all user devices');

  await request(second, 'room:join', { channel: 'private-App.Models.User.1' });
  const notificationDelivery = event(second, 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated');
  await session.request('/demo/notification', 'POST');
  const notice = (await notificationDelivery)[0];
  assert.equal(notice.message, 'Notification from Laravel');
  assert.equal(typeof notice.id, 'string');
  assert.equal(notice.type, 'App\\BridgeDemo\\DemoNotification');
  await request(second, 'room:leave', { channel: 'private-App.Models.User.1' });
  await request(first, 'room:leave', { channel: 'presence-team.allowed' });
  await request(second, 'room:leave', { channel: 'presence-team.allowed' });
  console.log('PASS queued native Laravel notifications through plain Socket.IO events');

  const before = (await session.request('/demo/notes')).count;
  const id = crypto.randomUUID();
  const notification = event(second, 'demo.note.created');
  const businessAck = await request(first, 'demo.note.create', { text: 'a durable note' }, { id });
  assert.equal(businessAck.id, id);
  const result = businessAck.data;
  assert.equal(result.text, 'a durable note');
  assert.equal((await notification)[0].note.id, result.id);
  assert.deepEqual((await request(first, 'demo.note.create', { text: 'a durable note' }, { id })).data, result);
  assert.equal((await session.request('/demo/notes')).count, before + 1);
  await assert.rejects(request(first, 'demo.note.create', { text: 'different' }, { id }));
  await assert.rejects(request(first, 'demo.note.create', { text: '' }), error => {
    assert.equal(error.code, 'validation.failed');
    assert.ok(Array.isArray(error.details?.fields?.text));
    assert.ok(error.details.fields.text.length > 0);
    return true;
  });
  await assert.rejects(request(first, 'unregistered.command', {}));
  console.log('PASS named command business acknowledgements, correlated results, transactional outbox, idempotence, validation');

  first.disconnect();
  first.auth = { token: await session.token() };
  const reconnected = once(first, 'connect'); first.connect(); await reconnected;
  await request(first, 'room:join', { channel: 'private-demo.1' });
  assert.deepEqual((await request(first, 'demo.note.create', { text: 'a durable note' }, { id })).data, result);
  assert.equal((await session.request('/demo/notes')).count, before + 1);
  console.log('PASS reconnect gets fresh ticket and command retry preserves outcome');

  const disconnected = [once(first, 'disconnect'), once(second, 'disconnect')];
  await session.request('/demo/revoke', 'POST');
  await Promise.all(disconnected);
  assert.equal(stranger.connected, true);
  console.log('PASS user revocation disconnects only matching user');
} catch (error) {
  for (const [name, log] of logs) console.error(`\n[${name}]\n${log}`);
  try { console.error((await readFile(resolve(app, 'storage/logs/laravel.log'), 'utf8')).slice(-16_000)); } catch {}
  throw error;
} finally {
  for (const client of clients) client.disconnect();
  for (const child of children) if (child.exitCode === null) child.kill('SIGTERM');
  await Promise.race([Promise.all(children.map(child => child.exitCode === null ? once(child, 'exit') : Promise.resolve())), sleep(4_000)]);
  for (const child of children) if (child.exitCode === null) child.kill('SIGKILL');
  await mkdir(resolve(root, '.test-results'), { recursive: true });
  for (const [name, log] of logs) await writeFile(resolve(root, `.test-results/e2e-${docker ? 'docker-' : ''}${name}.log`), log);
}
