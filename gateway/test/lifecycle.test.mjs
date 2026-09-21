import { test, before, after } from "node:test";
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
let redis, gateway, authorizer, prefix;
const clients = [];
const authorizations = new Map();
async function until(predicate, ms = 5000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    if (await predicate()) return;
    await sleep(20);
  }
  assert.fail("Condition timeout");
}
async function ticket(
  user,
  session = protocol.hash(randomUUID()),
  seconds = 60,
) {
  const identity = {
    user_id: user,
    session_id: session,
    user_version: Number(
      (await redis.get(`${prefix}:user-version:${protocol.hash(user)}`)) ?? 0,
    ),
    expires_at: Math.floor(Date.now() / 1000) + seconds,
  };
  const token = randomBytes(32).toString("hex");
  await redis.set(
    `${prefix}:session:${session}`,
    JSON.stringify(identity),
    "EX",
    seconds,
  );
  await redis.set(
    `${prefix}:ticket:${protocol.hash(token)}`,
    JSON.stringify(identity),
    "EX",
    30,
  );
  return { token, identity };
}
async function connect(user, seconds = 60) {
  const issued = await ticket(user, undefined, seconds);
  const socket = io(`http://127.0.0.1:${gateway.address.port}`, {
    transports: ["websocket"],
    auth: { token: issued.token },
    reconnection: false,
  });
  clients.push(socket);
  await until(() => socket.connected);
  return { socket, ...issued };
}
const ack = (socket, name, payload) =>
  socket.timeout(3000).emitWithAck(name, payload);
before(async () => {
  if (!process.env.SOCKET_BRIDGE_TEST_REDIS_URL) return;
  prefix = `socket-bridge:lifecycle:${randomUUID()}`;
  redis = new Redis(process.env.SOCKET_BRIDGE_TEST_REDIS_URL);
  authorizer = createServer(async (req, res) => {
    let raw = "";
    for await (const chunk of req) raw += chunk;
    const { channel } = JSON.parse(raw);
    const control = authorizations.get(channel) ?? {};
    control.onRequest?.();
    if (control.delay) await sleep(control.delay);
    res.writeHead(control.status ?? 200, {
      "Content-Type": "application/json",
    });
    res.end(JSON.stringify({ allowed: true, expires_in: 2 }));
  });
  await new Promise((resolve) => authorizer.listen(0, "127.0.0.1", resolve));
  gateway = await app.createGateway(
    configs.loadConfig({
      SOCKET_BRIDGE_PREFIX: prefix,
      SOCKET_BRIDGE_SECRET: randomBytes(32).toString("hex"),
      SOCKET_BRIDGE_REDIS_URL: process.env.SOCKET_BRIDGE_TEST_REDIS_URL,
      SOCKET_BRIDGE_LARAVEL_URL: `http://127.0.0.1:${authorizer.address().port}`,
      SOCKET_BRIDGE_PORT: "0",
      SOCKET_BRIDGE_ROOM_LEASE_SECONDS: "2",
      SOCKET_BRIDGE_AUTH_CHECK_MS: "100",
      SOCKET_BRIDGE_RATE_LIMIT: "10000",
      SOCKET_BRIDGE_AUTH_TIMEOUT_MS: "500",
    }),
  );
});
after(async () => {
  for (const client of clients) client.disconnect();
  if (gateway) await gateway.close();
  if (authorizer) await new Promise((resolve) => authorizer.close(resolve));
  if (redis) {
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
});
integration(
  "proactive renewals preserve membership and event delivery without renewal gaps",
  async () => {
    const { socket } = await connect("continuous");
    await ack(socket, "room:join", { channel: "private-continuous" });
    authorizations.set("private-continuous", { delay: 150 });
    const server = gateway.runtime.io.sockets.sockets.get(socket.id);
    let deliveries = 0,
      sent = 0;
    socket.on("probe", () => deliveries++);
    const end = Date.now() + 4500;
    while (Date.now() < end) {
      assert.equal(
        server.rooms.has(protocol.channelRoom("private-continuous")),
        true,
      );
      gateway.runtime.io
        .to(protocol.channelRoom("private-continuous"))
        .emit("probe", ++sent);
      await sleep(30);
    }
    await until(() => deliveries === sent);
    assert.ok(gateway.runtime.metrics.renewals >= 2);
    socket.disconnect();
  },
);
integration(
  "transient authorization failures retain the valid grant, suspend at deadline and restore automatically",
  async () => {
    const { socket } = await connect("transient");
    await ack(socket, "room:join", { channel: "private-outage" });
    const server = gateway.runtime.io.sockets.sockets.get(socket.id);
    const notices = [];
    for (const name of [
      "bridge.subscription.revoked",
      "bridge.subscription.suspended",
      "bridge.subscription.restored",
    ])
      socket.on(name, (p) => notices.push([name, p]));
    authorizations.set("private-outage", { status: 503 });
    await sleep(1500);
    assert.equal(
      server.rooms.has(protocol.channelRoom("private-outage")),
      true,
    );
    await until(() =>
      notices.some(([name]) => name === "bridge.subscription.suspended"),
    );
    assert.equal(
      server.rooms.has(protocol.channelRoom("private-outage")),
      false,
    );
    assert.equal(socket.connected, true);
    assert.equal(
      notices.some(([name]) => name === "bridge.subscription.revoked"),
      false,
    );
    authorizations.delete("private-outage");
    await until(() =>
      notices.some(([name]) => name === "bridge.subscription.restored"),
    );
    assert.equal(
      server.rooms.has(protocol.channelRoom("private-outage")),
      true,
    );
    socket.disconnect();
  },
);
integration(
  "explicit denial evicts an unexpired grant and cancels further renewal",
  async () => {
    const { socket } = await connect("denied");
    await ack(socket, "room:join", { channel: "private-denied" });
    const start = Date.now();
    let revoked = false;
    socket.on("bridge.subscription.revoked", () => (revoked = true));
    authorizations.set("private-denied", { status: 403 });
    await until(() => revoked);
    assert.ok(Date.now() - start < 1900);
    assert.equal(
      gateway.runtime.io.sockets.sockets
        .get(socket.id)
        .rooms.has(protocol.channelRoom("private-denied")),
      false,
    );
    authorizations.delete("private-denied");
    await sleep(1000);
    assert.equal(
      gateway.runtime.io.sockets.sockets
        .get(socket.id)
        .rooms.has(protocol.channelRoom("private-denied")),
      false,
    );
    socket.disconnect();
  },
);
integration(
  "repeat join immediately revokes an existing grant on denial but retains it on transient failure",
  async () => {
    const { socket } = await connect("repeat-denied");
    const channel = "private-repeat-denied";
    const server = gateway.runtime.io.sockets.sockets.get(socket.id);
    const revoked = [];
    socket.on("bridge.subscription.revoked", (value) => revoked.push(value));
    assert.equal((await ack(socket, "room:join", { channel })).ok, true);
    authorizations.set(channel, { status: 503 });
    assert.equal((await ack(socket, "room:join", { channel })).error.code, "authorization_unavailable");
    assert.equal(server.rooms.has(protocol.channelRoom(channel)), true);
    authorizations.set(channel, { status: 403 });
    const denied = await ack(socket, "room:join", { channel });
    assert.equal(denied.error.code, "forbidden");
    assert.equal(server.rooms.has(protocol.channelRoom(channel)), false);
    await until(() => revoked.length === 1);
    assert.equal(revoked[0].channel, channel);
    assert.equal(socket.connected, true);
    socket.disconnect();
  },
);
integration(
  "stale join responses neither revoke a newer grant nor restore one after a newer denial",
  async () => {
    const { socket } = await connect("join-races");
    const channel = "private-join-races";
    const server = gateway.runtime.io.sockets.sockets.get(socket.id);
    const revoked = [];
    socket.on("bridge.subscription.revoked", (value) => revoked.push(value));
    await ack(socket, "room:join", { channel });
    let received = false;
    authorizations.set(channel, { status: 403, delay: 150, onRequest: () => { received = true; } });
    const oldDenial = ack(socket, "room:join", { channel });
    await until(() => received);
    authorizations.delete(channel);
    assert.equal((await ack(socket, "room:join", { channel })).ok, true);
    assert.equal((await oldDenial).error.code, "forbidden");
    assert.equal(server.rooms.has(protocol.channelRoom(channel)), true);
    assert.equal(revoked.length, 0);
    received = false;
    authorizations.set(channel, { delay: 150, onRequest: () => { received = true; } });
    const oldGrant = ack(socket, "room:join", { channel });
    await until(() => received);
    authorizations.set(channel, { status: 403 });
    assert.equal((await ack(socket, "room:join", { channel })).error.code, "forbidden");
    assert.equal((await oldGrant).error.code, "subscription_cancelled");
    assert.equal(server.rooms.has(protocol.channelRoom(channel)), false);
    await until(() => revoked.length === 1);
    socket.disconnect();
  },
);
integration(
  "fresh same-session ticket refreshes expiry while foreign identity and consumed tickets are rejected",
  async () => {
    const a = await connect("refresh");
    const replacement = await ticket("refresh", a.identity.session_id, 120);
    const result = await ack(a.socket, "session:refresh", {
      token: replacement.token,
    });
    assert.equal(result.ok, true);
    assert.equal(result.expires_at, replacement.identity.expires_at);
    const replay = await ack(a.socket, "session:refresh", {
      token: replacement.token,
    }).catch(() => null);
    assert.ok(replay === null || replay.ok === false);
    await until(() => !a.socket.connected);
    const b = await connect("foreign");
    const foreign = await ticket("attacker");
    await ack(b.socket, "session:refresh", { token: foreign.token }).catch(
      () => null,
    );
    await until(() => !b.socket.connected);
  },
);
integration(
  "raw Socket.IO session refresh extends evidence without reconnect and revocation is terminal",
  async () => {
    const issued = await ticket("raw-refresh", undefined, 3);
    let refreshTimer,
      refreshes = 0,
      reason;
    const socket = io(`http://127.0.0.1:${gateway.address.port}`, {
      transports: ["websocket"],
      auth: { token: issued.token },
      reconnection: false,
      autoConnect: false,
    });
    clients.push(socket);
    socket.on("bridge.session", (schedule) => {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(async () => {
        if (!socket.connected) return;
        const fresh = await ticket(
          "raw-refresh",
          issued.identity.session_id,
          3,
        );
        const response = await ack(socket, "session:refresh", {
          token: fresh.token,
        });
        assert.equal(response.ok, true);
        refreshes++;
      }, schedule.refresh_after_ms);
    });
    socket.on("bridge.disconnect", (value) => {
      reason = value;
      clearTimeout(refreshTimer);
    });
    socket.connect();
    await until(() => socket.connected);
    const original = socket.id;
    try {
      await sleep(4500);
      assert.equal(socket.connected, true);
      assert.equal(socket.id, original);
      assert.ok(refreshes >= 2);
      await redis.incr(
        `${prefix}:user-version:${protocol.hash("raw-refresh")}`,
      );
      await until(() => !socket.connected);
      assert.equal(reason.retryable, false);
      assert.equal(reason.code, "unauthenticated");
    } finally {
      clearTimeout(refreshTimer);
      socket.disconnect();
    }
  },
);
integration(
  "Redis outage sends retryable disconnect and raw Socket.IO can reconnect and reauthorize",
  async () => {
    const a = await connect("redis-recovery");
    await ack(a.socket, "room:join", { channel: "private-recovered" });
    const old = a.socket.id;
    let notice;
    a.socket.on("bridge.disconnect", (p) => {
      notice = p;
    });
    gateway.runtime.redis.disconnect();
    await until(() => !a.socket.connected);
    assert.deepEqual(notice, { code: "redis_unavailable", retryable: true });
    await gateway.runtime.redis.connect();
    await until(() => gateway.runtime.ready());
    a.socket.auth = {
      token: (await ticket("redis-recovery", a.identity.session_id)).token,
    };
    a.socket.connect();
    await until(() => a.socket.connected);
    assert.notEqual(a.socket.id, old);
    assert.equal(
      (await ack(a.socket, "room:join", { channel: "private-recovered" })).ok,
      true,
    );
    assert.equal(
      gateway.runtime.io.sockets.sockets
        .get(a.socket.id)
        .rooms.has(protocol.channelRoom("private-recovered")),
      true,
    );
    a.socket.disconnect();
  },
);
integration(
  "gateway heartbeat contains live versioned status and a finite TTL",
  async () => {
    const key = `${prefix}:health:gateway:${gateway.runtime.config.instanceId}`;
    await until(async () => !!(await redis.get(key)));
    const heartbeat = JSON.parse(await redis.get(key));
    assert.equal(heartbeat.protocol, 1);
    assert.equal(heartbeat.instance_id, gateway.runtime.config.instanceId);
    assert.equal(typeof heartbeat.connections, "number");
    assert.equal(typeof heartbeat.subscriptions, "number");
    assert.ok((await redis.ttl(key)) > 0);
    assert.ok((await redis.ttl(key)) <= 15);
  },
);

integration(
  "isolated subscriber outage blocks raw handshakes until cluster transport recovers",
  async () => {
    const a = await connect("adapter-recovery");
    await ack(a.socket, "room:join", { channel: "private-adapter-recovery" });
    const old = a.socket.id;
    let notice;
    a.socket.on("bridge.disconnect", (p) => {
      notice = p;
    });
    gateway.runtime.subscriber.disconnect();
    await until(() => !a.socket.connected);
    assert.equal(notice.retryable, true);
    assert.equal(gateway.runtime.redis.status, "ready");
    a.socket.auth = {
      token: (await ticket("adapter-recovery", a.identity.session_id)).token,
    };
    const failed = new Promise((resolve) =>
      a.socket.once("connect_error", resolve),
    );
    a.socket.connect();
    assert.equal((await failed).data.code, "redis_unavailable");
    await gateway.runtime.subscriber.connect();
    a.socket.auth = {
      token: (await ticket("adapter-recovery", a.identity.session_id)).token,
    };
    a.socket.connect();
    await until(() => a.socket.connected);
    assert.notEqual(a.socket.id, old);
    assert.equal(
      (
        await ack(a.socket, "room:join", {
          channel: "private-adapter-recovery",
        })
      ).ok,
      true,
    );
    a.socket.disconnect();
  },
);
