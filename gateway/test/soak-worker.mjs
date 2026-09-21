// Child process isolates gateway memory from the load generator.
import app from '../dist/app.js';
import configs from '../dist/config.js';
const gateway=await app.createGateway(configs.loadConfig());
process.send({type:'ready',port:gateway.address.port});
const timer=setInterval(()=>{
  global.gc?.();
  process.send({type:'sample',at:Date.now(),memory:process.memoryUsage(),connections:gateway.runtime.io.sockets.sockets.size,metrics:gateway.runtime.metrics});
},5000);
let closing=false;
process.on('message',async message=>{
  if(message.type==='redis-fault'){
    const clients=[gateway.runtime.redis,gateway.runtime.reader,gateway.runtime.publisher,gateway.runtime.subscriber];
    for(const client of clients)client.disconnect();
    setTimeout(()=>void Promise.all(clients.map(client=>client.connect())).then(()=>process.send({type:'redis-restored'})),message.duration);
  }
  if(message.type==='transport-fault'){
    for(const socket of gateway.runtime.io.sockets.sockets.values())socket.conn.close();
    process.send({type:'transport-closed'});
  }
  if(message.type==='close'&&!closing){closing=true;clearInterval(timer);await gateway.close();process.disconnect();}
});
