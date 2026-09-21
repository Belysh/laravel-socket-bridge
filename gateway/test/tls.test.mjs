import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { request } from 'node:https';
import { randomUUID } from 'node:crypto';
import Redis from 'ioredis';
import app from '../dist/app.js';
import configs from '../dist/config.js';
const redisUrl=process.env.SOCKET_BRIDGE_TEST_REDIS_URL;
test('native TLS validates file configuration and serves readiness over HTTPS', {skip:!redisUrl}, async context=>{
  try {execFileSync('openssl',['version'],{stdio:'ignore'});} catch {context.skip('OpenSSL is needed to generate a temporary test certificate');return;}
  const directory=await mkdtemp(join(tmpdir(),'socket-bridge-tls-'));
  const cert=join(directory,'cert.pem'),key=join(directory,'key.pem');
  const prefix=`socket-bridge:test-tls:${randomUUID()}`;
  let gateway;
  try {
    const config=configs.loadConfig({SOCKET_BRIDGE_PREFIX:prefix,SOCKET_BRIDGE_SECRET:'c'.repeat(64),SOCKET_BRIDGE_REDIS_URL:redisUrl,SOCKET_BRIDGE_LARAVEL_URL:'http://127.0.0.1:9',SOCKET_BRIDGE_HOST:'127.0.0.1',SOCKET_BRIDGE_PORT:'0',SOCKET_BRIDGE_TLS_CERT:cert,SOCKET_BRIDGE_TLS_KEY:key});
    await assert.rejects(()=>app.createGateway(config),{code:'ENOENT'});
    execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',key,'-out',cert,'-subj','/CN=localhost','-days','1'],{stdio:'ignore'});
    gateway=await app.createGateway(config);
    const status=await new Promise((resolve,reject)=>{const req=request({host:'127.0.0.1',port:gateway.address.port,path:'/health/ready',rejectUnauthorized:false},res=>{res.resume();resolve(res.statusCode);});req.on('error',reject);req.end();});
    assert.equal(status,200);
  } finally {
    if(gateway)await gateway.close();
    const redis=new Redis(redisUrl);let cursor='0';do{const result=await redis.scan(cursor,'MATCH',`${prefix}:*`,'COUNT',1000);cursor=result[0];if(result[1].length)await redis.del(...result[1]);}while(cursor!=='0');await redis.quit();
    await rm(directory,{recursive:true,force:true});
  }
});
