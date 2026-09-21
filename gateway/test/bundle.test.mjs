import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, copyFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import Redis from 'ioredis';
const redisUrl=process.env.SOCKET_BRIDGE_TEST_REDIS_URL;
test('distribution bundle boots without node_modules and terminates on stdin EOF', {skip: !redisUrl}, async()=>{
  const directory=await mkdtemp(join(tmpdir(),'socket-bridge-bundle-'));
  const file=join(directory,'gateway.cjs');
  await copyFile(new URL('../../runtime/gateway.cjs',import.meta.url),file);
  const prefix=`socket-bridge:test-bundle:${randomUUID()}`;
  const child=spawn(process.execPath,[file],{cwd:directory,env:{PATH:process.env.PATH,SOCKET_BRIDGE_PREFIX:prefix,SOCKET_BRIDGE_REDIS_URL:redisUrl,SOCKET_BRIDGE_SECRET:'b'.repeat(64),SOCKET_BRIDGE_LARAVEL_URL:'http://127.0.0.1:9',SOCKET_BRIDGE_HOST:'127.0.0.1',SOCKET_BRIDGE_PORT:'0',SOCKET_BRIDGE_WATCH_STDIN:'1'},stdio:['pipe','pipe','pipe']});
  const exited=new Promise(resolve=>child.once('exit',(code,signal)=>resolve({code,signal})));
  let errorOutput='';child.stderr.on('data',chunk=>errorOutput+=chunk);
  try {
    const started=await new Promise((resolve,reject)=>{
      let output='';const timeout=setTimeout(()=>reject(new Error(`Bundle startup timeout: ${errorOutput}`)),5000);
      child.stdout.on('data',chunk=>{output+=chunk;for(const line of output.split('\n')){try{const message=JSON.parse(line);if(message.status==='ready'){clearTimeout(timeout);resolve(message);return;}}catch{}}});
      child.once('exit',code=>{clearTimeout(timeout);reject(new Error(`Bundle exited ${code}: ${errorOutput}`));});
    });
    const response=await fetch(`http://127.0.0.1:${started.address.port}/health/ready`);assert.equal(response.status,200);
    child.stdin.end();
    const result=await Promise.race([exited,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Bundle shutdown timeout')),5000))]);
    assert.equal(result.code,0);assert.equal(result.signal,null);
  } finally {
    if(child.exitCode===null)child.kill('SIGKILL');await exited;
    const redis=new Redis(redisUrl);let cursor='0';do{const result=await redis.scan(cursor,'MATCH',`${prefix}:*`,'COUNT',1000);cursor=result[0];if(result[1].length)await redis.del(...result[1]);}while(cursor!=='0');await redis.quit();
    await rm(directory,{recursive:true,force:true});
  }
});
