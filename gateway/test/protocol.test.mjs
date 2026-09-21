import test from 'node:test';
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import protocol from '../dist/protocol.js';
import config from '../dist/config.js';
const envelope = overrides => JSON.stringify({v:1,id:randomUUID(),type:'socket.emit',created_at:new Date().toISOString(),event:'message.created',rooms:['private-chat.1'],payload:{},...overrides});
test('trusted targets support user routing but clients cannot request internal or invalid rooms', () => {
  assert.equal(protocol.validChannel('__user:'+'a'.repeat(64)), false);
  assert.equal(protocol.validChannel('chat.1'), true);
  assert.equal(protocol.channelRoom('chat.1'), '__channel:chat.1');
  assert.doesNotThrow(() => protocol.parseEnvelope(envelope({rooms:[protocol.userRoom('42')]}),65536));
  assert.throws(() => protocol.parseEnvelope(envelope({rooms:['__arbitrary']}),65536));
  assert.throws(() => protocol.parseEnvelope(envelope({event:'bridge.session'}),65536));
  assert.throws(() => protocol.parseEnvelope(envelope({event:'disconnect'}),65536));
  assert.throws(() => protocol.parseEnvelope(envelope({payload:[]}),65536));
  assert.throws(() => protocol.parseEnvelope(envelope({v:2}),65536));
});
test('configuration fails closed and supports explicit paths without accepting external URLs', () => {
  const env = {SOCKET_BRIDGE_PREFIX:'test',SOCKET_BRIDGE_SECRET:'a'.repeat(64),SOCKET_BRIDGE_REDIS_URL:'redis://127.0.0.1:6379',SOCKET_BRIDGE_LARAVEL_URL:'http://localhost',SOCKET_BRIDGE_ORIGINS:'http://localhost'};
  assert.equal(config.loadConfig(env).authorizePath,'/socket-bridge/internal/authorize');
  assert.throws(()=>config.loadConfig({...env,SOCKET_BRIDGE_SECRET:'short'}));
  assert.throws(()=>config.loadConfig({...env,SOCKET_BRIDGE_ORIGINS:'*'}));
  assert.throws(()=>config.loadConfig({...env,SOCKET_BRIDGE_AUTHORIZE_PATH:'https://other.test'}));
  assert.throws(()=>config.loadConfig({...env,SOCKET_BRIDGE_PORT:'no'}));
  assert.throws(()=>config.loadConfig({...env,SOCKET_BRIDGE_TLS_CERT:'/tmp/test.pem'}));
  assert.equal(config.loadConfig({...env,SOCKET_BRIDGE_TLS_CERT:'/tmp/test.pem',SOCKET_BRIDGE_TLS_KEY:'/tmp/test.key'}).tlsKey,'/tmp/test.key');
});

test('payload limit excludes envelope metadata while rejecting oversized payloads', () => {
  const exact = {x:'a'.repeat(1016)};
  assert.equal(Buffer.byteLength(JSON.stringify(exact)),1024);
  assert.doesNotThrow(()=>protocol.parseEnvelope(envelope({payload:exact}),1024));
  assert.throws(()=>protocol.parseEnvelope(envelope({payload:{x:'a'.repeat(1017)}}),1024));
});
