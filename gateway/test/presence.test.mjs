import { test } from "node:test";
import assert from "node:assert/strict";
import { fork } from "node:child_process";
import { once } from "node:events";
import { createServer } from "node:http";
import { randomBytes, randomUUID } from "node:crypto";
import { setTimeout as sleep } from "node:timers/promises";
import Redis from "ioredis";
import { io } from "socket.io-client";
import app from "../dist/app.js";
import configs from "../dist/config.js";
import protocol from "../dist/protocol.js";

const integration = process.env.SOCKET_BRIDGE_TEST_REDIS_URL ? test : test.skip;
async function until(check, ms = 5000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    if (await check()) return;
    await sleep(20);
  }
  assert.fail("Condition timeout");
}

integration("presence reconciles a SIGKILLed gateway without new joins, leaves or member changes", async () => {
  const prefix = `socket-bridge:presence-crash:${randomUUID()}`;
  const redis = new Redis(process.env.SOCKET_BRIDGE_TEST_REDIS_URL);
  const sessions = new Map(), sockets = [];
  let gateway, child, childExit;
  const authorizer = createServer(async (req, res) => {
    let raw = "";
    for await (const chunk of req) raw += chunk;
    const user = sessions.get(JSON.parse(raw).session_id);
    res.end(JSON.stringify({ allowed: true, expires_in: 30, member: { id: user, info: {} } }));
  });
  await new Promise(resolve => authorizer.listen(0, "127.0.0.1", resolve));
  const env = {
    SOCKET_BRIDGE_PREFIX: prefix,
    SOCKET_BRIDGE_SECRET: randomBytes(32).toString("hex"),
    SOCKET_BRIDGE_REDIS_URL: process.env.SOCKET_BRIDGE_TEST_REDIS_URL,
    SOCKET_BRIDGE_LARAVEL_URL: `http://127.0.0.1:${authorizer.address().port}`,
    SOCKET_BRIDGE_PORT: "0",
    SOCKET_BRIDGE_PRESENCE_RECONCILE_MS: "100",
  };
  try {
    gateway = await app.createGateway(configs.loadConfig(env));
    child = fork(new URL("./presence-worker.mjs", import.meta.url), [], {
      env: { ...process.env, ...env }, stdio: ["ignore", "ignore", "pipe", "ipc"],
    });
    childExit = once(child, "exit");
    let errors = "";
    child.stderr.on("data", value => { errors += value; });
    const [ready] = await Promise.race([
      once(child, "message"),
      childExit.then(() => { throw new Error(`Peer exited before readiness: ${errors}`); }),
      sleep(10000, undefined, { ref: false }).then(() => { throw new Error("Peer startup timeout"); }),
    ]);
    for (const [user, port] of [["survivor", gateway.address.port], ["lost-peer", ready.port]]) {
      const token = randomBytes(32).toString("hex"), session = protocol.hash(randomUUID());
      const identity = { user_id: user, session_id: session, user_version: 0, expires_at: Math.floor(Date.now() / 1000) + 60 };
      sessions.set(session, user);
      await redis.multi()
        .set(`${prefix}:session:${session}`, JSON.stringify(identity), "EX", 60)
        .set(`${prefix}:ticket:${protocol.hash(token)}`, JSON.stringify(identity), "EX", 30).exec();
      const socket = io(`http://127.0.0.1:${port}`, { transports: ["websocket"], auth: { token }, reconnection: false });
      sockets.push(socket);
      await until(() => socket.connected);
    }
    const snapshots = [];
    sockets[0].on("bridge.presence", value => snapshots.push(value));
    for (const socket of sockets) assert.equal((await socket.timeout(3000).emitWithAck("room:join", { channel: "presence-crash" })).ok, true);
    await until(() => snapshots.at(-1)?.members.length === 2);
    const before = snapshots.length;
    child.kill("SIGKILL");
    const [, signal] = await childExit;
    assert.equal(signal, "SIGKILL");
    await until(() => snapshots.length > before && snapshots.at(-1)?.members.length === 1);
    assert.deepEqual(snapshots.at(-1).members.map(member => member.id), ["survivor"]);
    assert.equal(sockets[0].connected, true);
    assert.equal(gateway.runtime.metrics.renewals, 0, "repair must precede authorization renewal");
  } finally {
    for (const socket of sockets) socket.disconnect();
    if (child && child.exitCode === null && child.signalCode === null) { child.kill("SIGKILL"); await childExit; }
    await gateway?.close();
    await new Promise(resolve => authorizer.close(resolve));
    let cursor = "0";
    do {
      const found = await redis.scan(cursor, "MATCH", `${prefix}:*`);
      cursor = found[0];
      if (found[1].length) await redis.del(...found[1]);
    } while (cursor !== "0");
    await redis.quit();
  }
});
