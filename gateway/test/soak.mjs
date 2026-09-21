/** Bounded recovery soak, not a production capacity benchmark.
 * SOCKET_BRIDGE_TEST_REDIS_URL=redis://127.0.0.1:16389/0 node test/soak.mjs
 * Default: 300 sockets / 125 seconds; injects auth, Redis and transport faults.
 */
import assert from 'node:assert/strict';
import { fork } from 'node:child_process';
import { createServer } from 'node:http';
import { randomBytes, randomUUID } from 'node:crypto';
import { writeFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { setTimeout as sleep } from 'node:timers/promises';
import Redis from 'ioredis';
import protocol from '../dist/protocol.js';
import { io } from 'socket.io-client';
const duration=Number(process.env.SOCKET_BRIDGE_SOAK_DURATION_MS??125000);
const count=Number(process.env.SOCKET_BRIDGE_SOAK_CONNECTIONS??300);
const redisUrl=process.env.SOCKET_BRIDGE_TEST_REDIS_URL;
if(!redisUrl)throw new Error('SOCKET_BRIDGE_TEST_REDIS_URL is required; use a dedicated test Redis.');
const prefix=`socket-bridge:soak:${randomUUID()}`,redis=new Redis(redisUrl);
const clients=[],samples=[],faults=[],issues=[];
let worker,authorizer,publishing,refreshing,started=0,authUnavailable=false,sequence=0;
const messages=new Uint32Array(count),lastSequences=new Uint32Array(count);
async function until(fn,ms=20000){const end=Date.now()+ms;while(Date.now()<end){if(await fn())return;await sleep(50);}throw new Error('Soak condition timeout');}
async function issue(user,session){
  const token=randomBytes(32).toString('hex');
  const identity={user_id:user,session_id:session,user_version:0,expires_at:Math.floor(Date.now()/1000)+20};
  await redis.multi().set(`${prefix}:session:${session}`,JSON.stringify(identity),'EX',20).set(`${prefix}:ticket:${protocol.hash(token)}`,JSON.stringify(identity),'EX',30).exec();return token;
}
try {
  authorizer=createServer(async(req,res)=>{for await(const _ of req){}res.writeHead(authUnavailable?503:200,{'Content-Type':'application/json'});res.end(JSON.stringify({allowed:true,expires_in:5}));});
  await new Promise(resolve=>authorizer.listen(0,'127.0.0.1',resolve));
  worker=fork(fileURLToPath(new URL('./soak-worker.mjs',import.meta.url)),[],{execArgv:['--expose-gc'],stdio:['ignore','ignore','pipe','ipc'],env:{...process.env,SOCKET_BRIDGE_PREFIX:prefix,SOCKET_BRIDGE_SECRET:randomBytes(32).toString('hex'),SOCKET_BRIDGE_REDIS_URL:redisUrl,SOCKET_BRIDGE_LARAVEL_URL:`http://127.0.0.1:${authorizer.address().port}`,SOCKET_BRIDGE_PORT:'0',SOCKET_BRIDGE_ROOM_LEASE_SECONDS:'5',SOCKET_BRIDGE_AUTH_CHECK_MS:'500',SOCKET_BRIDGE_RATE_LIMIT:'100000'}});
  let port;worker.on('message',m=>{if(m.type==='ready')port=m.port;else if(m.type==='sample')samples.push(m);else faults.push({...m,at:Date.now()});});
  worker.stderr.on('data',chunk=>{for(const line of String(chunk).trim().split('\n'))if(line)issues.push(line);});
  await until(()=>port);
  for(let index=0;index<count;index++){
    const session=protocol.hash(`session-${index}`);
    const socket=io(`http://127.0.0.1:${port}`,{transports:['websocket'],autoConnect:false,auth:callback=>{void issue(String(index),session).then(token=>callback({token}),()=>callback({token:''}));}});
    socket.on('connect',()=>{void socket.timeout(10000).emitWithAck('room:join',{channel:'private-soak'}).catch(error=>issues.push(error.message));});
    socket.on('disconnect',reason=>{if(reason==='io server disconnect'&&publishing!==false)setTimeout(()=>{if(publishing!==false)socket.connect();},500);});
    socket.on('connect_error',()=>{setTimeout(()=>{if(publishing!==false)socket.connect();},500);});
    socket.on('soak.tick',payload=>{messages[index]++;lastSequences[index]=payload.sequence;});socket.connect();clients.push(socket);
    if(index%25===24)await sleep(20);
  }
  await until(()=>clients.every(c=>c.connected));
  refreshing=setInterval(()=>{for(let index=0;index<clients.length;index++){const socket=clients[index];if(socket.connected)void issue(String(index),protocol.hash(`session-${index}`)).then(token=>socket.timeout(5000).emitWithAck('session:refresh',{token})).catch(error=>issues.push(error.message));}},5000);
  started=Date.now();
  publishing=setInterval(()=>{
    const envelope={v:1,id:randomUUID(),created_at:new Date().toISOString(),type:'socket.emit',event:'soak.tick',rooms:['private-soak'],payload:{sequence:++sequence}};
    void redis.xadd(`${prefix}:events`,'*','envelope',JSON.stringify(envelope)).catch(e=>issues.push(e.message));
  },500);
  const schedule=[
    [0.20,()=>{authUnavailable=true;faults.push({type:'authorization-unavailable',at:Date.now()});}],
    [0.34,()=>{authUnavailable=false;faults.push({type:'authorization-restored',at:Date.now()});}],
    [0.48,()=>{worker.send({type:'redis-fault',duration:5000});faults.push({type:'redis-disconnected',at:Date.now()});}],
    [0.68,()=>{worker.send({type:'transport-fault'});faults.push({type:'transport-disconnected',at:Date.now()});}],
  ];
  let next=0;
  while(Date.now()-started<duration){const elapsed=Date.now()-started;if(next<schedule.length&&elapsed>=duration*schedule[next][0])schedule[next++][1]();await sleep(100);}
  await until(()=>clients.every(c=>c.connected));
  await until(()=>[...lastSequences].every(n=>n>=sequence-3));
  assert.ok([...messages].every(n=>n>=duration/2000),'Every client must receive sustained events around the outage windows');
  const warm=samples.filter(s=>s.at-started>=duration*0.15);
  const baseline=warm.slice(0,3).reduce((n,s)=>n+s.memory.heapUsed,0)/Math.min(3,warm.length);
  const ending=warm.slice(-3).reduce((n,s)=>n+s.memory.heapUsed,0)/Math.min(3,warm.length);
  assert.ok(ending<=Math.max(baseline*1.5,baseline+12*1024*1024),'Gateway heap after forced GC must stabilize within the bounded soak tolerance');
  const report={passed:true,kind:duration>=120000?'bounded-recovery-soak':'short-smoke',duration_ms:Date.now()-started,requested_connections:count,connected_at_end:clients.filter(c=>c.connected).length,events_published:sequence,client_deliveries:[...messages].reduce((a,b)=>a+b,0),minimum_client_deliveries:Math.min(...messages),gateway_memory:{baseline_heap_bytes:Math.round(baseline),final_heap_bytes:Math.round(ending),peak_rss_bytes:Math.max(...samples.map(s=>s.memory.rss)),forced_gc:true,includes_load_generator:false},faults,samples,diagnostic_log_count:issues.length,notes:'Single local macOS gateway, real Redis, local authorization stub, no TLS. Recovery/memory regression check; not a production capacity benchmark.'};
  const output=fileURLToPath(new URL('../../.test-results/gateway-soak.json',import.meta.url));await mkdir(fileURLToPath(new URL('../../.test-results/',import.meta.url)),{recursive:true});await writeFile(output,JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify({...report,samples:undefined},null,2));
} finally {
  clearInterval(publishing);publishing=false;clearInterval(refreshing);for(const client of clients)client.disconnect();
  if(worker?.connected){worker.send({type:'close'});await Promise.race([new Promise(resolve=>worker.once('exit',resolve)),sleep(7000)]);if(worker.exitCode===null)worker.kill('SIGKILL');}
  if(authorizer)await new Promise(resolve=>authorizer.close(resolve));
  let cursor='0';do{const found=await redis.scan(cursor,'MATCH',`${prefix}:*`,'COUNT',1000);cursor=found[0];if(found[1].length)await redis.del(...found[1]);}while(cursor!=='0');await redis.quit();
}
