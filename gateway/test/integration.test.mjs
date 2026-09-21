import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { createHmac, randomBytes, randomUUID } from "node:crypto";
import { setTimeout as sleep } from "node:timers/promises";
import Redis from "ioredis";
import { io } from "socket.io-client";
import app from "../dist/app.js";
import configs from "../dist/config.js";
import protocol from "../dist/protocol.js";
const REDIS = process.env.SOCKET_BRIDGE_TEST_REDIS_URL;
const integration = REDIS ? test : test.skip;
const sockets = [];
let redis, prefix, secret, authorizer, first, second;
const denied = new Set();
const unavailable = new Set();
const delayed = new Set();
let rejectedAuthorizations = 0;
const envelope = (overrides) => ({
  v: 1,
  id: randomUUID(),
  created_at: new Date().toISOString(),
  type: "socket.emit",
  event: "message.created",
  rooms: ["private-chat.1"],
  payload: { value: 1 },
  ...overrides,
});
function event(socket, name, timeout = 3000) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      socket.off(name, listener);
      reject(new Error(`Timed out: ${name}`));
    }, timeout);
    const listener = (...args) => {
      clearTimeout(timer);
      resolve(args);
    };
    socket.once(name, listener);
  });
}
async function until(predicate, timeout = 3000) {
  const start = Date.now();
  while (Date.now() - start < timeout) {
    if (await predicate()) return;
    await sleep(20);
  }
  assert.fail("Condition timeout");
}
async function issue(user = "42", sessionId = protocol.hash(randomUUID())) {
  const userVersion = Number(
    (await redis.get(`${prefix}:user-version:${protocol.hash(user)}`)) ?? 0,
  );
  const identity = {
    user_id: user,
    session_id: sessionId,
    user_version: userVersion,
    expires_at: Math.floor(Date.now() / 1000) + 3600,
  };
  await redis.set(
    `${prefix}:session:${sessionId}`,
    JSON.stringify({ ...identity, guard: "web", provider: "users" }),
    "EX",
    3600,
  );
  const token = randomBytes(32).toString("hex");
  await redis.set(
    `${prefix}:ticket:${protocol.hash(token)}`,
    JSON.stringify(identity),
    "EX",
    60,
  );
  return { token, identity };
}
async function connect(which = first, user = "42", sessionId) {
  const ticket = await issue(user, sessionId);
  const socket = io(`http://127.0.0.1:${which.address.port}`, {
    transports: ["websocket"],
    auth: { token: ticket.token },
    reconnection: false,
    autoConnect: false,
  });
  sockets.push(socket);
  const ready = event(socket, "connect");
  socket.connect();
  await ready;
  return { socket, ...ticket };
}
const request = (socket, name, input) =>
  socket.timeout(3000).emitWithAck(name, input);
async function publish(value) {
  return redis.xadd(`${prefix}:events`, "*", "envelope", JSON.stringify(value));
}
before(async () => {
  if (!REDIS) return;
  prefix = `socket-bridge:test:${randomUUID()}`;
  secret = randomBytes(32).toString("hex");
  redis = new Redis(REDIS);
  authorizer = createServer(async (req, res) => {
    let body = "";
    for await (const chunk of req) body += chunk;
    const timestamp = req.headers["x-socket-bridge-timestamp"];
    const expected = createHmac("sha256", secret)
      .update(`${timestamp}\n${body}`)
      .digest("hex");
    if (expected !== req.headers["x-socket-bridge-signature"]) {
      res.writeHead(401);
      res.end();
      return;
    }
    const input = JSON.parse(body);
    const session = JSON.parse(
      (await redis.get(`${prefix}:session:${input.session_id}`)) ?? "null",
    );
    if (!session) {
      res.writeHead(401);
      res.end();
      return;
    }
    if (delayed.has(input.channel)) await sleep(1500);
    if (unavailable.has(input.channel)) {
      rejectedAuthorizations++;
      res.writeHead(503);
      res.end();
      return;
    }
    if (denied.has(input.channel)) {
      res.writeHead(403);
      res.end();
      return;
    }
    res.setHeader("content-type", "application/json");
    res.end(
      JSON.stringify({
        allowed: true,
        expires_in: 1,
        ...(input.channel.startsWith("presence-")
          ? {
              member: {
                id: session.user_id,
                info: { name: `User ${session.user_id}` },
              },
            }
          : {}),
      }),
    );
  });
  await new Promise((resolve) => authorizer.listen(0, "127.0.0.1", resolve));
  const config = configs.loadConfig({
    SOCKET_BRIDGE_PREFIX: prefix,
    SOCKET_BRIDGE_SECRET: secret,
    SOCKET_BRIDGE_REDIS_URL: REDIS,
    SOCKET_BRIDGE_LARAVEL_URL: `http://127.0.0.1:${authorizer.address().port}`,
    SOCKET_BRIDGE_HOST: "127.0.0.1",
    SOCKET_BRIDGE_PORT: "0",
    SOCKET_BRIDGE_ORIGINS: "http://good.example",
    SOCKET_BRIDGE_AUTH_CHECK_MS: "100",
    SOCKET_BRIDGE_CLAIM_IDLE_MS: "100",
    SOCKET_BRIDGE_ROOM_LEASE_SECONDS: "1",
    SOCKET_BRIDGE_RATE_LIMIT: "1000",
  });
  first = await app.createGateway({ ...config, instanceId: "first" });
  second = await app.createGateway({ ...config, instanceId: "second" });
});
after(async () => {
  for (const socket of sockets) socket.disconnect();
  if (first) await first.close();
  if (second) await second.close();
  if (authorizer) await new Promise((resolve) => authorizer.close(resolve));
  if (redis) {
    let cursor = "0";
    do {
      const result = await redis.scan(
        cursor,
        "MATCH",
        `${prefix}:*`,
        "COUNT",
        1000,
      );
      cursor = result[0];
      if (result[1].length) await redis.del(...result[1]);
    } while (cursor !== "0");
    await redis.quit();
  }
});
integration(
  "single-use tickets, Origin restrictions and readiness",
  async () => {
    assert.equal(
      (await fetch(`http://127.0.0.1:${first.address.port}/health/ready`))
        .status,
      200,
    );
    const one = await connect();
    const replay = io(`http://127.0.0.1:${first.address.port}`, {
      transports: ["websocket"],
      auth: { token: one.token },
      autoConnect: false,
      reconnection: false,
    });
    sockets.push(replay);
    const rejected = event(replay, "connect_error");
    replay.connect();
    assert.equal((await rejected)[0].data.code, "unauthenticated");
    const fresh = await issue();
    const foreign = io(`http://127.0.0.1:${first.address.port}`, {
      transports: ["websocket"],
      auth: { token: fresh.token },
      extraHeaders: { Origin: "http://evil.example" },
      autoConnect: false,
      reconnection: false,
    });
    sockets.push(foreign);
    const blocked = event(foreign, "connect_error");
    foreign.connect();
    await blocked;
    assert.equal(foreign.connected, false);
  },
);
integration(
  "two-node fanout emits once, private canonical names, socket and user exclusions",
  async () => {
    const a = await connect(first, "fanout-user"),
      b = await connect(second, "fanout-user"),
      c = await connect(second, "other-user");
    for (const x of [a, b, c])
      assert.equal(
        (await request(x.socket, "room:join", { channel: "private-chat.1" }))
          .ok,
        true,
      );
    let counts = [0, 0, 0];
    [a, b, c].forEach((x, i) =>
      x.socket.on("message.created", () => counts[i]++),
    );
    await publish(envelope({ payload: { tag: "one" } }));
    await until(() => counts.every((n) => n === 1));
    await sleep(150);
    assert.deepEqual(counts, [1, 1, 1]);
    await publish(envelope({ except_socket: a.socket.id }));
    await until(() => counts[2] === 2);
    assert.deepEqual(counts, [1, 2, 2]);
    await publish(envelope({ except_user: "fanout-user" }));
    await until(() => counts[2] === 3);
    assert.deepEqual(counts, [1, 2, 3]);
    const deniedJoin = await request(a.socket, "room:join", {
      channel: protocol.userRoom("other-user"),
    });
    assert.equal(deniedJoin.ok, false);
    const raw = await connect(first, "raw");
    await request(raw.socket, "room:join", { channel: "chat.1" });
    let leaks = 0;
    raw.socket.on("message.created", () => leaks++);
    await publish(envelope({}));
    await sleep(150);
    assert.equal(leaks, 0);
  },
);
integration("toUser targets all sessions of only that user", async () => {
  const a = await connect(first, "target"),
    b = await connect(second, "target"),
    c = await connect(second, "unrelated");
  const received = [event(a.socket, "notice"), event(b.socket, "notice")];
  let leaked = false;
  c.socket.on("notice", () => (leaked = true));
  const e = envelope({
    event: "notice",
    rooms: [protocol.userRoom("target")],
    payload: { ok: true },
  });
  await publish(e);
  for (const response of await Promise.all(received)) {
    assert.deepEqual(response[0], { ok: true });
    assert.equal(response[1].id, e.id);
  }
  assert.equal(leaked, false);
});
integration(
  "presence deduplicates multiple tabs and changes on leave",
  async () => {
    const a = await connect(first, "presence-user"),
      b = await connect(second, "presence-user"),
      c = await connect(second, "presence-other");
    let members = [];
    a.socket.on("bridge.presence", (value) => {
      if (value.channel === "presence-chat.2") members = value.members;
    });
    for (const x of [a, b, c])
      assert.equal(
        (await request(x.socket, "room:join", { channel: "presence-chat.2" }))
          .ok,
        true,
      );
    await until(() => members.length === 2);
    assert.deepEqual(
      members.map((v) => v.id),
      ["presence-other", "presence-user"],
    );
    await request(c.socket, "room:leave", { channel: "presence-chat.2" });
    await until(() => members.length === 1);
  },
);
integration(
  "named events queue verified identity and return business ACK across replicas",
  async () => {
    const a = await connect(first, "commands"),
      b = await connect(second, "commands");
    const commandId = randomUUID();
    const forged = await a.socket
      .timeout(3000)
      .emitWithAck(
        "chat.create",
        { text: "hi" },
        { id: commandId, context: { user_id: "attacker" } },
      );
    assert.equal(forged.ok, false);
    assert.equal(forged.error.code, "invalid_command");
    let resolved = false;
    const result = a.socket
      .timeout(3000)
      .emitWithAck("chat.create", { text: "hi" }, { id: commandId })
      .then((value) => {
        resolved = true;
        return value;
      });
    await until(async () => Number(await redis.xlen(`${prefix}:commands`)) > 0);
    assert.equal(resolved, false, "Queue admission is not business completion");
    const entries = await redis.xrange(`${prefix}:commands`, "-", "+");
    const queued = JSON.parse(entries.at(-1)[1][1]);
    assert.equal(queued.command, "chat.create");
    assert.equal(queued.context.user_id, "commands");
    assert.equal(queued.context.session_id, a.identity.session_id);
    assert.ok(protocol.validId(queued.context.request_id));
    const response = envelope({
      type: "socket.command.result",
      command_id: commandId,
      request_id: queued.context.request_id,
      session_id: a.identity.session_id,
      user_id: "commands",
      socket_id: a.socket.id,
      result: { ok: true, data: { id: "message1" } },
    });
    let leaked = 0;
    b.socket.onAny(() => leaked++);
    // Hold this result on the origin consumer if it claims it; the other node
    // must consume/reclaim it through Redis and route the callback over Pub/Sub.
    const original = first.runtime.processEntry;
    let release;
    const held = new Promise((resolve) => {
      release = resolve;
    });
    first.runtime.processEntry = async function (entry) {
      if (entry[1][1]?.includes(response.id)) await held;
      return original.call(this, entry);
    };
    try {
      await publish(response);
      assert.deepEqual(await result, {
        ok: true,
        id: commandId,
        data: { id: "message1" },
      });
      assert.equal(leaked, 0);
      assert.equal(first.runtime.snapshot().pending_acks, 0);
    } finally {
      release();
      first.runtime.processEntry = original;
    }
  },
);
integration(
  "session deletion and user version invalidation disconnect live sockets",
  async () => {
    const a = await connect(first, "revoked-session");
    const disconnected = event(a.socket, "disconnect");
    await redis.del(`${prefix}:session:${a.identity.session_id}`);
    await disconnected;
    const b = await connect(second, "revoked-user");
    const invalidated = event(b.socket, "disconnect");
    await redis.incr(`${prefix}:user-version:${protocol.hash("revoked-user")}`);
    await invalidated;
  },
);
integration(
  "room authorization lease expires and rejects later broadcasts",
  async () => {
    const a = await connect(first, "room-lease");
    await request(a.socket, "room:join", { channel: "private-revoked" });
    const revoked = event(a.socket, "bridge.subscription.revoked");
    denied.add("private-revoked");
    assert.equal((await revoked)[0].channel, "private-revoked");
    let messages = 0;
    a.socket.on("revoked-event", () => messages++);
    await publish(
      envelope({ event: "revoked-event", rooms: ["private-revoked"] }),
    );
    await sleep(150);
    assert.equal(messages, 0);
  },
);
integration(
  "server leave control reaches the other node and invalid events dead-letter",
  async () => {
    const a = await connect(second, "leave-control");
    await request(a.socket, "room:join", { channel: "private-control" });
    await publish(
      envelope({
        type: "socket.leave_room",
        user_id: "leave-control",
        room: "private-control",
      }),
    );
    await sleep(200);
    let messages = 0;
    a.socket.on("control-event", () => messages++);
    await publish(
      envelope({ event: "control-event", rooms: ["private-control"] }),
    );
    await sleep(150);
    assert.equal(messages, 0);
    const malformed = await redis.xadd(
      `${prefix}:events`,
      "*",
      "envelope",
      "not-json",
    );
    await until(
      async () => Number(await redis.xlen(`${prefix}:dead:events`)) > 0,
    );
    const failures = await redis.xrange(`${prefix}:dead:events`, "-", "+");
    assert.ok(
      failures.some((row) => JSON.parse(row[1][1]).source_id === malformed),
    );
  },
);

integration(
  "pending work owned by a crashed consumer is recovered with XAUTOCLAIM",
  async () => {
    const recoveryPrefix = `${prefix}:recovery`;
    await redis.xgroup(
      "CREATE",
      `${recoveryPrefix}:events`,
      "gateways",
      "0",
      "MKSTREAM",
    );
    const entry = await redis.xadd(
      `${recoveryPrefix}:events`,
      "*",
      "envelope",
      "malformed-pending",
    );
    await redis.xreadgroup(
      "GROUP",
      "gateways",
      "crashed",
      "COUNT",
      1,
      "STREAMS",
      `${recoveryPrefix}:events`,
      ">",
    );
    const recovery = await app.createGateway({
      ...first.runtime.config,
      prefix: recoveryPrefix,
      port: 0,
      instanceId: "recovery",
    });
    try {
      await until(
        async () =>
          Number(await redis.xlen(`${recoveryPrefix}:dead:events`)) === 1,
      );
      const pending = await redis.xpending(
        `${recoveryPrefix}:events`,
        "gateways",
      );
      assert.equal(pending[0], 0);
      const failed = await redis.xrange(
        `${recoveryPrefix}:dead:events`,
        "-",
        "+",
      );
      assert.equal(JSON.parse(failed[0][1][1]).source_id, entry);
    } finally {
      await recovery.close();
    }
  },
);
integration(
  "transient room authorization failure leaves stream work pending for retry",
  async () => {
    const a = await connect(second, "retry-user");
    const rejectedBefore = rejectedAuthorizations;
    unavailable.add("private-retry");
    const entry = await publish(
      envelope({
        type: "socket.join_room",
        user_id: "retry-user",
        room: "private-retry",
      }),
    );
    await until(() => rejectedAuthorizations > rejectedBefore);
    unavailable.delete("private-retry");
    await until(
      async () => !(await redis.get(`${prefix}:event-attempts:${entry}`)),
      5000,
    );
    const received = event(a.socket, "retry-event");
    await publish(envelope({ event: "retry-event", rooms: ["private-retry"] }));
    await received;
  },
);

integration(
  "maximum-sized named payload reaches Redis while oversized payload is rejected",
  async () => {
    const a = await connect(first, "payload-boundary");
    const payload = { text: "a".repeat(65525) };
    assert.equal(Buffer.byteLength(JSON.stringify(payload)), 65536);
    const id = randomUUID();
    a.socket.emit("large.command", payload, { id });
    await until(async () => {
      const rows = await redis.xrange(`${prefix}:commands`, "-", "+");
      return rows.some((row) => JSON.parse(row[1][1]).id === id);
    });
    const rejected = await a.socket
      .timeout(3000)
      .emitWithAck("large.command", { text: "a".repeat(65526) });
    assert.equal(rejected.ok, false);
    assert.equal(rejected.error.code, "payload_too_large");
  },
);
integration(
  "slow Laravel authorization does not extend other room leases or session revocation",
  async () => {
    const clients = [];
    for (let index = 0; index < 21; index++) {
      const client = await connect(first, `slow-lease-${index}`);
      clients.push(client);
      assert.equal(
        (await request(client.socket, "room:join", { channel: "private-slow" }))
          .ok,
        true,
      );
    }
    delayed.add("private-slow");
    try {
      await sleep(1150);
      let leaks = 0;
      for (const client of clients)
        client.socket.on("slow-event", () => leaks++);
      await publish(envelope({ event: "slow-event", rooms: ["private-slow"] }));
      await sleep(100);
      assert.equal(leaks, 0);
      const last = clients.at(-1);
      const disconnected = event(last.socket, "disconnect", 750);
      await redis.del(`${prefix}:session:${last.identity.session_id}`);
      await disconnected;
    } finally {
      delayed.delete("private-slow");
      for (const client of clients) client.socket.disconnect();
    }
  },
);

integration(
  "channel metadata separates equal event names and keeps union delivery singular",
  async () => {
    const a = await connect(first, "channel-a"),
      b = await connect(second, "channel-b"),
      both = await connect(second, "channel-both");
    await request(a.socket, "room:join", { channel: "private-scope.a" });
    await request(b.socket, "room:join", { channel: "private-scope.b" });
    await request(both.socket, "room:join", { channel: "private-scope.a" });
    await request(both.socket, "room:join", { channel: "private-scope.b" });
    const received = [[], [], []];
    [a, b, both].forEach((c, index) =>
      c.socket.on("scope.updated", (_payload, meta) =>
        received[index].push(meta),
      ),
    );
    await publish(
      envelope({ event: "scope.updated", rooms: ["private-scope.a"] }),
    );
    await until(() => received[0].length === 1 && received[2].length === 1);
    assert.deepEqual(received[0][0].channels, ["private-scope.a"]);
    assert.equal(received[1].length, 0);
    await publish(
      envelope({ event: "scope.updated", rooms: ["private-scope.b"] }),
    );
    await until(() => received[1].length === 1 && received[2].length === 2);
    assert.deepEqual(received[1][0].channels, ["private-scope.b"]);
    await publish(
      envelope({
        event: "scope.updated",
        rooms: ["private-scope.a", "private-scope.b", "private-scope.a"],
      }),
    );
    await until(() => received[2].length === 3);
    await sleep(100);
    assert.equal(received[2].length, 3);
    assert.deepEqual(received[2][2].channels, [
      "private-scope.a",
      "private-scope.b",
    ]);
    assert.deepEqual(received[0][1].channels, ["private-scope.a"]);
    assert.deepEqual(received[1][1].channels, ["private-scope.b"]);
    const user = event(a.socket, "scope.user");
    await publish(
      envelope({
        event: "scope.user",
        rooms: [protocol.userRoom("channel-a")],
      }),
    );
    assert.deepEqual((await user)[1].channels, []);
  },
);
