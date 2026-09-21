// Run in a disposable client container on the same network as Laravel/gateway/Redis.
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { once } from 'node:events';
import { io } from '../gateway/node_modules/socket.io-client/build/esm-debug/index.js';
const base = 'http://laravel.test:8000';
const gateway = 'http://gateway:6001';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function ready(url) {
  for (let i = 0; i < 120; i++) {
    try { if ((await fetch(url)).ok) return; } catch {}
    await delay(250);
  }
  throw new Error(`Readiness failed: ${url}`);
}
await ready(`${base}/up`);
await ready(`${gateway}/health/ready`);
const body = JSON.stringify({ session_id: 'a'.repeat(64), channel: 'private-demo.1', socket_id: 'network-probe' });
const timestamp = String(Math.floor(Date.now() / 1000));
async function probe(signature) {
  const response = await fetch(`${base}/socket-bridge/internal/authorize`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Socket-Bridge-Timestamp': timestamp, 'X-Socket-Bridge-Signature': signature }, body });
  return { status: response.status, data: await response.json() };
}
const bad = await probe('0'.repeat(64));
assert.equal(bad.status, 401); assert.equal(bad.data.error.code, 'bridge.invalid_signature');
const signed = await probe(createHmac('sha256', process.env.SOCKET_BRIDGE_SECRET).update(`${timestamp}\n${body}`).digest('hex'));
assert.equal(signed.status, 401); assert.notEqual(signed.data.error?.code, 'bridge.invalid_signature');
console.log('PASS container DNS Laravel callback rejects bad HMAC and accepts signed transport authentication');
const cookies = new Map(); let csrf;
async function request(path, method = 'GET') {
  const response = await fetch(`${base}${path}`, { method, headers: { Accept: 'application/json', Cookie: [...cookies].map(([key,value]) => `${key}=${value}`).join('; '), ...(csrf ? {'X-CSRF-TOKEN': csrf} : {}) } });
  for (const cookie of response.headers.getSetCookie()) { const item=cookie.split(';')[0];const at=item.indexOf('=');cookies.set(item.slice(0,at),item.slice(at+1)); }
  assert.equal(response.ok,true, `${path}: ${response.status}`);return response.json();
}
csrf = (await request('/demo/login/1')).csrf;
const socket = io(gateway, { auth: { token: (await request('/socket-bridge/token', 'POST')).token }, transports: ['websocket'], autoConnect: false, reconnection: false });
async function ack(name, payload, options) {
  const result = await socket.timeout(35000).emitWithAck(name, payload, ...(options ? [options] : []));
  if (!result.ok) throw Object.assign(new Error(result.error?.message ?? 'Request rejected'), result.error);
  return result;
}
try {
  const connected = Promise.race([once(socket,'connect'),once(socket,'connect_error').then(([error])=>{throw error;})]);
  socket.connect();await connected;
  await ack('room:join', {channel:'private-demo.1'});
  await assert.rejects(ack('room:join', {channel:'private-demo.2'}));
  console.log('PASS ticket authentication and gateway→Laravel private channel authorization through service DNS');
  const before=(await request('/demo/notes')).count;
  const id=crypto.randomUUID();const payload={text:'Container network durable command'};
  const response=await ack('demo.note.create',payload,{id});assert.equal(response.id,id);const result=response.data;
  assert.equal(result.text,payload.text);
  assert.deepEqual((await ack('demo.note.create',payload,{id})).data,result);
  assert.equal((await request('/demo/notes')).count,before+1);
  console.log('PASS gateway→Redis→Laravel command worker→SQL outbox→Redis→gateway result; duplicate mutates once');
} finally { socket.disconnect(); }
