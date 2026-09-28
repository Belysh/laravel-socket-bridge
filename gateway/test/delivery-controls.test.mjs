import { test } from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes, randomUUID } from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';
import Redis from 'ioredis';
import { io } from 'socket.io-client';
import app from '../dist/app.js';
import configs from '../dist/config.js';
import protocol from '../dist/protocol.js';
const integration = process.env.SOCKET_BRIDGE_TEST_REDIS_URL ? test : test.skip;
async function until(check, ms=4000) { const end=Date.now()+ms;while(Date.now()<end){if(await check())return;await sleep(10);}assert.fail('Condition timeout'); }
async function fixture(t, options={}) {
 const prefix=`bridge:delivery:${randomUUID()}`, redis=new Redis(process.env.SOCKET_BRIDGE_TEST_REDIS_URL);
 const config=configs.loadConfig({SOCKET_BRIDGE_PREFIX:prefix,SOCKET_BRIDGE_SECRET:randomBytes(32).toString('hex'),SOCKET_BRIDGE_REDIS_URL:process.env.SOCKET_BRIDGE_TEST_REDIS_URL,SOCKET_BRIDGE_LARAVEL_URL:'http://127.0.0.1:1',SOCKET_BRIDGE_PORT:'0',SOCKET_BRIDGE_COMMAND_ACK_TIMEOUT_MS:'2000',SOCKET_BRIDGE_DRAIN_TIMEOUT_MS:'500',SOCKET_BRIDGE_CLAIM_IDLE_MS:'100',...options});
 const gateways=[await app.createGateway(config)],sockets=[];
 t.after(async()=>{for(const s of sockets)s.disconnect();for(const g of gateways)await g.close();const keys=await redis.keys(`${prefix}:*`);if(keys.length)await redis.del(...keys);await redis.quit();});
 async function connect(gateway=gateways[0],namespace=config.namespace){
  const token=randomBytes(32).toString('hex'),identity={user_id:'reader',session_id:protocol.hash(randomUUID()),user_version:0,expires_at:Math.floor(Date.now()/1000)+60};
  await redis.set(`${prefix}:session:${identity.session_id}`,JSON.stringify(identity),'EX',60);await redis.set(`${prefix}:ticket:${protocol.hash(token)}`,JSON.stringify(identity),'EX',30);
  const socket=io(`http://127.0.0.1:${gateway.address.port}${namespace==='/'?'':namespace}`,{transports:['websocket'],reconnection:false,auth:{token}});sockets.push(socket);return {socket,identity};
 }
 async function publish(fields={}){const e={v:1,id:randomUUID(),type:'socket.emit',created_at:new Date().toISOString(),rooms:[protocol.userRoom('reader')],event:'chat.notice',payload:{text:'hello'},...fields};await redis.xadd(`${prefix}:events`,'*','envelope',JSON.stringify(e));return e;}
 return {prefix,redis,config,gateways,connect,publish};
}
integration('versioned namespace accepts tickets and rejects the root namespace',async t=>{
 const f=await fixture(t,{SOCKET_BRIDGE_NAMESPACE:'/v1'}),a=await f.connect();await until(()=>a.socket.connected);
 const wrong=await f.connect(f.gateways[0],'/');const denied=await new Promise(r=>wrong.socket.once('connect_error',r));assert.match(denied.message,/namespace/);
 const received=new Promise(r=>a.socket.once('chat.notice',(data,meta)=>r(meta)));const e=await f.publish({correlation_id:'http-request-123'}),meta=await received;assert.equal(meta.id,e.id);assert.equal(meta.correlation_id,'http-request-123');
});
integration('expired ephemeral events are discarded and live duplicates reach each socket once',async t=>{
 const f=await fixture(t),a=await f.connect();await until(()=>a.socket.connected);const received=[];a.socket.on('chat.notice',(data,meta)=>received.push(meta));
 await f.publish({expires_at:Math.floor(Date.now()/1000)-1});await until(()=>f.gateways[0].runtime.metrics.events_expired>0);assert.equal(received.length,0);
 const e=await f.publish({expires_at:Math.floor(Date.now()/1000)+5});await until(()=>received.length===1);await f.publish(e);await until(()=>f.gateways[0].runtime.metrics.events_suppressed>0);assert.equal(received.length,1);
});
integration('lost cluster ACK retries contact peers without repeating successful deliveries',async t=>{
 const f=await fixture(t);f.gateways.push(await app.createGateway({...f.config,instanceId:randomUUID()}));const a=await f.connect(f.gateways[0]),b=await f.connect(f.gateways[1]);await until(()=>a.socket.connected&&b.socket.connected);
 const received=[0,0];a.socket.on('chat.notice',()=>received[0]++);b.socket.on('chat.notice',()=>received[1]++);
 for(const g of f.gateways){const ns=g.runtime.io.of('/'),emit=ns.serverSideEmitWithAck.bind(ns);let fail=true;ns.serverSideEmitWithAck=async(...args)=>{const result=await emit(...args);if(fail){fail=false;throw new Error('Simulated lost cluster ACK');}return result;};}
 await f.publish();await until(()=>f.gateways.some(g=>g.runtime.metrics.events_retries>0));await sleep(200);assert.deepEqual(received,[1,1]);assert.ok(f.gateways.reduce((n,g)=>n+g.runtime.metrics.events_suppressed,0)>0);
});
integration('drain rejects new work but keeps admitted ACKs alive',async t=>{
 const f=await fixture(t),g=f.gateways[0],a=await f.connect();await until(()=>a.socket.connected);const response=a.socket.timeout(1500).emitWithAck('message.send',{body:'hello'});
 let command;await until(async()=>{const rows=await f.redis.xrange(`${f.prefix}:commands`,'-','+');if(rows.length)command=JSON.parse(rows[0][1][1]);return !!command;});
 const disconnectHint=new Promise(r=>a.socket.once('bridge.disconnect',r));const closing=g.close();assert.equal(await g.runtime.ready(),false);const denied=await a.socket.timeout(500).emitWithAck('message.send',{body:'new'});assert.equal(denied.error.code,'server.draining');
 await f.publish({type:'socket.command.result',command_id:command.id,request_id:command.context.request_id,session_id:command.context.session_id,user_id:command.context.user_id,socket_id:command.context.socket_id,result:{ok:true,data:{saved:true}}});assert.equal((await response).data.saved,true);await closing;assert.deepEqual(await disconnectHint,{code:'server.draining',retryable:true});assert.equal(g.runtime.snapshot().pending_acks,0);
});
integration('drain deadline bounds unavailable PHP workers and clears pending callbacks',async t=>{
 const f=await fixture(t,{SOCKET_BRIDGE_DRAIN_TIMEOUT_MS:'80'}),g=f.gateways[0],a=await f.connect();await until(()=>a.socket.connected);const abandoned=a.socket.timeout(1500).emitWithAck('message.send',{body:'waiting'}).catch(e=>e);
 await until(()=>g.runtime.snapshot().pending_acks===1);const start=Date.now();await g.close();assert.ok(Date.now()-start<1200);assert.equal(g.runtime.snapshot().pending_acks,0);assert.ok((await abandoned) instanceof Error);
});
