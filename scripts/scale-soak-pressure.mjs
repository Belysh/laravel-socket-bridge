/** Real TCP slow-reader check; use a dedicated test Redis, never FLUSHDB. */
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { randomBytes, randomUUID } from "node:crypto";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { setTimeout as sleep } from "node:timers/promises";
import Redis from "../gateway/node_modules/ioredis/built/index.js";
import { io } from "../gateway/node_modules/socket.io-client/build/esm-debug/index.js";
import app from "../gateway/dist/app.js";
import configs from "../gateway/dist/config.js";
import protocol from "../gateway/dist/protocol.js";
const redisUrl = process.env.SOCKET_BRIDGE_TEST_REDIS_URL;
assert.ok(redisUrl, "Provide a dedicated SOCKET_BRIDGE_TEST_REDIS_URL");
const redis = new Redis(redisUrl),
  prefix = `socket-bridge:pressure:${randomUUID()}`;
let gateway, slow, healthy, authorizer, paused;
const started = Date.now();
let sent = 0,
  healthyMessages = 0,
  resumedAt;
async function until(predicate, duration = 20000) {
  const deadline = Date.now() + duration;
  while (Date.now() < deadline) {
    if (await predicate()) return;
    await sleep(20);
  }
  throw new Error("Slow-reader check timeout");
}
async function ticket(user) {
  const token = randomBytes(32).toString("hex");
  const identity = {
    user_id: user,
    session_id: protocol.hash(user),
    user_version: 0,
    expires_at: Math.floor(Date.now() / 1000) + 60,
  };
  await redis
    .multi()
    .set(
      `${prefix}:session:${identity.session_id}`,
      JSON.stringify(identity),
      "EX",
      60,
    )
    .set(
      `${prefix}:ticket:${protocol.hash(token)}`,
      JSON.stringify(identity),
      "EX",
      30,
    )
    .exec();
  return token;
}
const envelope = (room, event, payload) =>
  JSON.stringify({
    v: 1,
    id: randomUUID(),
    created_at: new Date().toISOString(),
    type: "socket.emit",
    rooms: [room],
    event,
    payload,
  });
try {
  authorizer = createServer(async (request, response) => {
    for await (const _ of request) {
    }
    response.end(JSON.stringify({ allowed: true, expires_in: 30 }));
  });
  await new Promise((resolve) => authorizer.listen(0, "127.0.0.1", resolve));
  gateway = await app.createGateway(
    configs.loadConfig({
      SOCKET_BRIDGE_PREFIX: prefix,
      SOCKET_BRIDGE_REDIS_URL: redisUrl,
      SOCKET_BRIDGE_SECRET: randomBytes(32).toString("hex"),
      SOCKET_BRIDGE_LARAVEL_URL: `http://127.0.0.1:${authorizer.address().port}`,
      SOCKET_BRIDGE_PORT: "0",
      SOCKET_BRIDGE_MAX_BUFFERED_BYTES: "65536",
      SOCKET_BRIDGE_MAX_BUFFERED_PACKETS: "100",
    }),
  );
  const url = `http://127.0.0.1:${gateway.address.port}`;
  slow = io(url, {
    transports: ["websocket"],
    auth: { token: await ticket("slow-reader") },
    reconnection: false,
  });
  healthy = io(url, {
    transports: ["websocket"],
    auth: { token: await ticket("healthy-reader") },
    reconnection: false,
  });
  healthy.on("pressure.healthy", () => healthyMessages++);
  await until(() => slow.connected && healthy.connected);
  for (const [socket, channel] of [
    [slow, "private-pressure"],
    [healthy, "private-healthy"],
  ]) {
    const ack = await socket
      .timeout(5000)
      .emitWithAck("room:join", { channel });
    assert.equal(ack.ok, true);
  }
  paused = slow.io.engine.transport.ws._socket;
  paused.pause();
  // Fill real TCP buffers instead of modifying Engine.IO's writable flag.
  for (
    let batch = 0;
    batch < 100 && gateway.runtime.metrics.slow_clients === 0;
    batch++
  ) {
    const pipeline = redis.pipeline();
    for (let index = 0; index < 20; index++) {
      pipeline.xadd(
        `${prefix}:events`,
        "*",
        "envelope",
        envelope("private-pressure", "pressure.bulk", {
          padding: "x".repeat(60 * 1024),
        }),
      );
      sent++;
    }
    pipeline.xadd(
      `${prefix}:events`,
      "*",
      "envelope",
      envelope("private-healthy", "pressure.healthy", {}),
    );
    const responses = await pipeline.exec();
    assert.ok(responses.every(([error]) => !error));
    await sleep(25);
  }
  await until(() => gateway.runtime.metrics.slow_clients > 0);
  assert.equal(gateway.runtime.metrics.slow_clients, 1);
  assert.equal(
    healthy.connected,
    true,
    "A slow reader must not evict a healthy socket",
  );
  resumedAt = Date.now();
  paused.resume();
  await until(() => !slow.connected);
  const previous = healthyMessages;
  await redis.xadd(
    `${prefix}:events`,
    "*",
    "envelope",
    envelope("private-healthy", "pressure.healthy", {}),
  );
  await until(() => healthyMessages > previous);
  await until(
    async () => (await redis.xpending(`${prefix}:events`, "gateways"))[0] === 0,
  );
  const report = {
    passed: true,
    elapsed_ms: Date.now() - started,
    bulk_events_submitted: sent,
    payload_bytes_each: 60 * 1024,
    configured_queue_limit_bytes: 65536,
    slow_client_disconnections: gateway.runtime.metrics.slow_clients,
    healthy_messages: healthyMessages,
    healthy_connected: healthy.connected,
    client_observed_close_after_resume_ms: Date.now() - resumedAt,
    notes:
      "Actual paused TCP reader, real Redis Streams, authorization stub; threshold includes Node buffers but not kernel buffers. This is a transport backpressure check, not a Laravel application throughput measurement.",
  };
  await mkdir(resolve(import.meta.dirname, "../.test-results"), {
    recursive: true,
  });
  await writeFile(
    resolve(import.meta.dirname, "../.test-results/scale-pressure.json"),
    JSON.stringify(report, null, 2) + "\n",
  );
  console.log(JSON.stringify(report, null, 2));
} finally {
  paused?.resume();
  slow?.disconnect();
  healthy?.disconnect();
  await gateway?.close();
  if (authorizer) await new Promise((resolve) => authorizer.close(resolve));
  let cursor = "0";
  do {
    const found = await redis.scan(
      cursor,
      "MATCH",
      `${prefix}:*`,
      "COUNT",
      1000,
    );
    cursor = found[0];
    if (found[1].length) await redis.del(...found[1]);
  } while (cursor !== "0");
  await redis.quit();
}
