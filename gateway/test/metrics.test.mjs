import { test } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { randomBytes, randomUUID } from "node:crypto";
import { setTimeout as sleep } from "node:timers/promises";
import Redis from "ioredis";
import { io } from "socket.io-client";
import app from "../dist/app.js";
import configs from "../dist/config.js";
import protocol from "../dist/protocol.js";
const integration = process.env.SOCKET_BRIDGE_TEST_REDIS_URL ? test : test.skip;
async function until(fn) {
  for (let i = 0; i < 100; i++) {
    if (await fn()) return;
    await sleep(25);
  }
  throw new Error("Condition timeout");
}
integration(
  "opt-in metrics require a bearer token and report aggregate counters/histograms without identity labels",
  async () => {
    const prefix = `socket-bridge:metrics:${randomUUID()}`,
      redis = new Redis(process.env.SOCKET_BRIDGE_TEST_REDIS_URL),
      token = randomBytes(32).toString("hex");
    let gateway, disabled;
    const authorizer = createServer((_req, res) =>
      res.end(JSON.stringify({ allowed: true, expires_in: 30 })),
    );
    await new Promise((resolve) => authorizer.listen(0, "127.0.0.1", resolve));
    try {
      const config = configs.loadConfig({
        SOCKET_BRIDGE_PREFIX: prefix,
        SOCKET_BRIDGE_REDIS_URL: process.env.SOCKET_BRIDGE_TEST_REDIS_URL,
        SOCKET_BRIDGE_SECRET: randomBytes(32).toString("hex"),
        SOCKET_BRIDGE_LARAVEL_URL: `http://127.0.0.1:${authorizer.address().port}`,
        SOCKET_BRIDGE_PORT: "0",
        SOCKET_BRIDGE_METRICS_TOKEN: token,
      });
      gateway = await app.createGateway(config);
      const url = `http://127.0.0.1:${gateway.address.port}/metrics`;
      assert.equal((await fetch(url)).status, 401);
      assert.equal(
        (await fetch(url, { headers: { Authorization: "Bearer incorrect" } }))
          .status,
        401,
      );
      const envelope = {
        v: 1,
        id: randomUUID(),
        created_at: new Date().toISOString(),
        type: "socket.emit",
        event: "metric.event",
        rooms: ["private-secret-channel"],
        payload: {},
      };
      for (let i = 0; i < 2; i++)
        await redis.xadd(
          `${prefix}:events`,
          "*",
          "envelope",
          JSON.stringify(envelope),
        );
      await redis.xadd(`${prefix}:events`, "*", "envelope", "malformed");
      await until(() => gateway.runtime.metrics.events_failed === 1);
      const response = await fetch(url, {
          headers: { Authorization: `Bearer ${token}` },
        }),
        text = await response.text();
      assert.equal(response.status, 200);
      assert.match(response.headers.get("content-type"), /text\/plain/);
      assert.match(text, /socket_bridge_gateway_events_accepted_total 3/);
      assert.match(text, /socket_bridge_gateway_events_delivered_total 2/);
      assert.match(text, /socket_bridge_gateway_events_duplicates_total 1/);
      assert.match(text, /socket_bridge_gateway_processing_seconds_count 3/);
      assert.doesNotMatch(text, /private-secret-channel|user_id|session_id/);
      assert.ok(!text.includes(token));
      disabled = await app.createGateway({
        ...config,
        prefix: `${prefix}:disabled`,
        instanceId: randomUUID(),
        metricsToken: undefined,
      });
      assert.equal(
        (await fetch(`http://127.0.0.1:${disabled.address.port}/metrics`))
          .status,
        404,
      );
    } finally {
      await gateway?.close();
      await disabled?.close();
      await new Promise((resolve) => authorizer.close(resolve));
      let cursor = "0";
      do {
        const values = await redis.scan(cursor, "MATCH", `${prefix}:*`);
        cursor = values[0];
        if (values[1].length) await redis.del(...values[1]);
      } while (cursor !== "0");
      await redis.quit();
    }
  },
);
integration(
  "blocked Socket.IO transport is disconnected once its bounded outgoing queue is exceeded",
  async () => {
    const prefix = `socket-bridge:backpressure:${randomUUID()}`,
      redis = new Redis(process.env.SOCKET_BRIDGE_TEST_REDIS_URL);
    let gateway, socket;
    try {
      gateway = await app.createGateway(
        configs.loadConfig({
          SOCKET_BRIDGE_PREFIX: prefix,
          SOCKET_BRIDGE_REDIS_URL: process.env.SOCKET_BRIDGE_TEST_REDIS_URL,
          SOCKET_BRIDGE_SECRET: randomBytes(32).toString("hex"),
          SOCKET_BRIDGE_LARAVEL_URL: "http://127.0.0.1:1",
          SOCKET_BRIDGE_PORT: "0",
          SOCKET_BRIDGE_MAX_BUFFERED_BYTES: "65536",
          SOCKET_BRIDGE_MAX_BUFFERED_PACKETS: "10",
        }),
      );
      const token = randomBytes(32).toString("hex"),
        identity = {
          user_id: "slow",
          session_id: protocol.hash("slow"),
          user_version: 0,
          expires_at: Math.floor(Date.now() / 1000) + 60,
        };
      await redis.set(
        `${prefix}:session:${identity.session_id}`,
        JSON.stringify(identity),
      );
      await redis.set(
        `${prefix}:ticket:${protocol.hash(token)}`,
        JSON.stringify(identity),
      );
      socket = io(`http://127.0.0.1:${gateway.address.port}`, {
        auth: { token },
        transports: ["websocket"],
        reconnection: false,
      });
      await until(() => socket.connected);
      const server = gateway.runtime.io.sockets.sockets.get(socket.id);
      server.conn.transport.writable = false;
      for (let i = 0; i < 20; i++)
        server.emit("pressure", { data: "x".repeat(8192) });
      await until(() => gateway.runtime.metrics.slow_clients === 1);
      assert.equal(gateway.runtime.io.sockets.sockets.has(server.id), false);
    } finally {
      socket?.disconnect();
      await gateway?.close();
      let cursor = "0";
      do {
        const values = await redis.scan(cursor, "MATCH", `${prefix}:*`);
        cursor = values[0];
        if (values[1].length) await redis.del(...values[1]);
      } while (cursor !== "0");
      await redis.quit();
    }
  },
);
