import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { randomBytes, randomUUID } from "node:crypto";
import { setTimeout as sleep } from "node:timers/promises";
import Redis from "ioredis";
import { io } from "socket.io-client";
import app from "../dist/app.js";
import configs from "../dist/config.js";
import protocol from "../dist/protocol.js";
const integration = process.env.SOCKET_BRIDGE_TEST_REDIS_URL ? test : test.skip;
let redis, gateway, prefix;
const sockets = [];
async function until(check, ms = 3000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    if (await check()) return;
    await sleep(10);
  }
  assert.fail("Condition timeout");
}
async function connect(
  user = "command-user",
  session = protocol.hash(randomUUID()),
) {
  const token = randomBytes(32).toString("hex"),
    identity = {
      user_id: user,
      session_id: session,
      user_version: 0,
      expires_at: Math.floor(Date.now() / 1000) + 60,
    };
  await redis
    .multi()
    .set(`${prefix}:session:${session}`, JSON.stringify(identity), "EX", 60)
    .set(
      `${prefix}:ticket:${protocol.hash(token)}`,
      JSON.stringify(identity),
      "EX",
      30,
    )
    .exec();
  const socket = io(`http://127.0.0.1:${gateway.address.port}`, {
    transports: ["websocket"],
    auth: { token },
    reconnection: false,
  });
  sockets.push(socket);
  await until(() => socket.connected);
  return { socket, identity };
}
async function queued(id) {
  let value;
  await until(async () => {
    const entries = await redis.xrange(`${prefix}:commands`, "-", "+");
    value = entries
      .map((row) => JSON.parse(row[1][1]))
      .findLast((e) => e.id === id);
    return !!value;
  });
  return value;
}
async function result(
  command,
  value = { ok: true, data: { paid: true } },
  overrides = {},
) {
  await redis.xadd(
    `${prefix}:events`,
    "*",
    "envelope",
    JSON.stringify({
      v: 1,
      id: randomUUID(),
      created_at: new Date().toISOString(),
      type: "socket.command.result",
      command_id: command.id,
      request_id: command.context.request_id,
      socket_id: command.context.socket_id,
      session_id: command.context.session_id,
      user_id: command.context.user_id,
      result: value,
      ...overrides,
    }),
  );
}
before(async () => {
  if (!process.env.SOCKET_BRIDGE_TEST_REDIS_URL) return;
  prefix = `socket-bridge:named:${randomUUID()}`;
  redis = new Redis(process.env.SOCKET_BRIDGE_TEST_REDIS_URL);
  gateway = await app.createGateway(
    configs.loadConfig({
      SOCKET_BRIDGE_PREFIX: prefix,
      SOCKET_BRIDGE_SECRET: randomBytes(32).toString("hex"),
      SOCKET_BRIDGE_REDIS_URL: process.env.SOCKET_BRIDGE_TEST_REDIS_URL,
      SOCKET_BRIDGE_LARAVEL_URL: "http://127.0.0.1:1",
      SOCKET_BRIDGE_PORT: "0",
      SOCKET_BRIDGE_COMMAND_ACK_TIMEOUT_MS: "250",
      SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS: "2",
      SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS_TOTAL: "3",
      SOCKET_BRIDGE_RATE_LIMIT: "10000",
    }),
  );
});
after(async () => {
  for (const socket of sockets) socket.disconnect();
  await gateway?.close();
  if (redis) {
    let cursor = "0";
    do {
      const found = await redis.scan(cursor, "MATCH", `${prefix}:*`);
      cursor = found[0];
      if (found[1].length) await redis.del(...found[1]);
    } while (cursor !== "0");
    await redis.quit();
  }
});
integration(
  "plain named event generates a server UUID and waits for the business result",
  async () => {
    const { socket } = await connect();
    let finished = false;
    const before = await redis.xlen(`${prefix}:commands`);
    const response = socket
      .timeout(1500)
      .emitWithAck("order.pay", { order_id: 7 })
      .then((value) => {
        finished = true;
        return value;
      });
    await until(async () => (await redis.xlen(`${prefix}:commands`)) > before);
    const entries = await redis.xrange(`${prefix}:commands`, "-", "+");
    const command = JSON.parse(entries.at(-1)[1][1]);
    assert.equal(command.command, "order.pay");
    assert.ok(protocol.validId(command.id));
    assert.deepEqual(command.payload, { order_id: 7 });
    assert.equal(finished, false);
    await result(command);
    assert.deepEqual(await response, {
      ok: true,
      id: command.id,
      data: { paid: true },
    });
    assert.equal(gateway.runtime.snapshot().pending_acks, 0);
    socket.disconnect();
  },
);
integration(
  "ACK preserves Laravel validation details and rejects forged result identity/correlation",
  async () => {
    const { socket } = await connect();
    const id = randomUUID();
    let finished = false;
    const response = socket
      .timeout(1500)
      .emitWithAck("order.pay", { order_id: 0 }, { id })
      .then((value) => {
        finished = true;
        return value;
      });
    const command = await queued(id);
    await result(command, undefined, { user_id: "another-user" });
    await result(command, undefined, {
      session_id: protocol.hash("another-session"),
    });
    await result(command, undefined, { request_id: randomUUID() });
    await sleep(30);
    assert.equal(finished, false);
    const error = {
      code: "validation.failed",
      message: "Invalid order.",
      details: { fields: { order_id: ["The order is required."] } },
    };
    await result(command, { ok: false, error });
    assert.deepEqual(await response, { ok: false, id, error });
    socket.disconnect();
  },
);
integration(
  "same-ID pending emission is rejected without replacing its ACK or queueing another command",
  async () => {
    const { socket } = await connect();
    const id = randomUUID();
    const first = socket
      .timeout(1500)
      .emitWithAck("order.pay", { order_id: 8 }, { id });
    const command = await queued(id),
      length = await redis.xlen(`${prefix}:commands`);
    const duplicate = await socket
      .timeout(1500)
      .emitWithAck("order.pay", { order_id: 8 }, { id });
    assert.equal(duplicate.error.code, "command.pending");
    assert.equal(await redis.xlen(`${prefix}:commands`), length);
    await result(command);
    assert.equal((await first).ok, true);
    socket.disconnect();
  },
);
integration(
  "timeout is an unknown outcome and late results cannot ACK a newer same-ID attempt",
  async () => {
    const { socket } = await connect();
    const id = randomUUID();
    const first = socket
      .timeout(1500)
      .emitWithAck("order.pay", { order_id: 9 }, { id });
    const old = await queued(id);
    const expired = await first;
    assert.equal(expired.ok, false);
    assert.equal(expired.id, id);
    assert.equal(expired.error.code, "command.timeout");
    assert.equal(gateway.runtime.snapshot().pending_acks, 0);
    let finished = false;
    const second = socket
      .timeout(1500)
      .emitWithAck("order.pay", { order_id: 10 }, { id })
      .then((value) => {
        finished = true;
        return value;
      });
    let next;
    await until(async () => {
      next = await queued(id);
      return next.context.request_id !== old.context.request_id;
    });
    await result(old);
    await sleep(25);
    assert.equal(finished, false);
    const error = {
      code: "command.id_conflict",
      message: "Different input for retained id.",
    };
    await result(next, { ok: false, error });
    assert.deepEqual(await second, { ok: false, id, error });
    assert.equal(gateway.runtime.snapshot().pending_acks, 0);
    socket.disconnect();
  },
);
integration(
  "disconnect clears pending callbacks while the queued command can be retried from a new socket",
  async () => {
    const a = await connect();
    const id = randomUUID();
    const abandoned = a.socket
      .timeout(600)
      .emitWithAck("order.pay", { order_id: 11 }, { id })
      .catch((error) => error);
    const old = await queued(id);
    a.socket.disconnect();
    await until(() => gateway.runtime.snapshot().pending_acks === 0);
    const b = await connect(a.identity.user_id, a.identity.session_id);
    let finished = false;
    const response = b.socket
      .timeout(1500)
      .emitWithAck("order.pay", { order_id: 11 }, { id })
      .then((value) => {
        finished = true;
        return value;
      });
    let current;
    await until(async () => {
      current = await queued(id);
      return current.context.socket_id === b.socket.id;
    });
    await result(old);
    await sleep(25);
    assert.equal(finished, false);
    await result(current);
    assert.deepEqual(await response, { ok: true, id, data: { paid: true } });
    assert.ok((await abandoned) instanceof Error);
    b.socket.disconnect();
  },
);
integration(
  "pending ACK limits apply per socket and globally and release their capacity on timeout",
  async () => {
    const a = await connect(),
      b = await connect(),
      c = await connect();
    const waiting = [];
    for (let i = 0; i < 2; i++) {
      const id = randomUUID();
      waiting.push(
        a.socket
          .timeout(1500)
          .emitWithAck("order.pay", { order_id: i }, { id }),
      );
      await queued(id);
    }
    const perSocket = await a.socket.timeout(1500).emitWithAck("order.pay", {});
    assert.equal(perSocket.error.code, "rate_limited");
    const third = randomUUID();
    waiting.push(
      b.socket.timeout(1500).emitWithAck("order.pay", {}, { id: third }),
    );
    await queued(third);
    assert.equal(gateway.runtime.snapshot().pending_acks, 3);
    const global = await c.socket.timeout(1500).emitWithAck("order.pay", {});
    assert.equal(global.error.code, "rate_limited");
    for (const response of await Promise.all(waiting))
      assert.equal(response.error.code, "command.timeout");
    assert.equal(gateway.runtime.snapshot().pending_acks, 0);
    for (const value of [a, b, c]) value.socket.disconnect();
  },
);
integration(
  "ACK metrics measure callback outcomes and end-to-end latency without counting abandoned or duplicate results",
  async () => {
    const { socket } = await connect("metrics-command-user");
    const before = gateway.runtime.snapshot();
    const successfulId = randomUUID();
    const successful = socket.timeout(1500).emitWithAck("order.pay", {}, { id: successfulId });
    const successCommand = await queued(successfulId);
    assert.match(gateway.runtime.prometheus(), /socket_bridge_gateway_pending_acks 1\n/);
    await sleep(40);
    await result(successCommand);
    assert.equal((await successful).ok, true);
    await result(successCommand);
    const failedId = randomUUID();
    const failed = socket.timeout(1500).emitWithAck("order.pay", {}, { id: failedId });
    await result(await queued(failedId), { ok: false, error: { code: "payment.declined", message: "Declined" } });
    assert.equal((await failed).ok, false);
    assert.equal((await socket.timeout(1500).emitWithAck("order.pay", [])).error.code, "invalid_command");
    const originalXadd = gateway.runtime.redis.xadd;
    try {
      gateway.runtime.redis.xadd = async function (...args) {
        if (args[0] === `${prefix}:commands`) throw new Error("Injected Redis write failure");
        return originalXadd.apply(this, args);
      };
      assert.equal((await socket.timeout(1500).emitWithAck("order.pay", {})).error.code, "temporarily_unavailable");
    } finally { gateway.runtime.redis.xadd = originalXadd; }
    assert.equal(gateway.runtime.snapshot().pending_acks, 0);
    const timedId = randomUUID();
    const timed = await socket.timeout(1500).emitWithAck("order.pay", {}, { id: timedId });
    assert.equal(timed.error.code, "command.timeout");
    await result(await queued(timedId));
    const abandonedId = randomUUID();
    let unexpected = 0;
    socket.emit("order.pay", {}, { id: abandonedId }, () => { unexpected++; });
    const abandoned = await queued(abandonedId);
    socket.disconnect();
    await until(() => gateway.runtime.snapshot().pending_acks === 0);
    await result(abandoned);
    await sleep(300);
    const after = gateway.runtime.snapshot();
    assert.equal(after.counters.command_acks_completed - before.counters.command_acks_completed, 1);
    assert.equal(after.counters.command_acks_errors - before.counters.command_acks_errors, 3);
    assert.equal(after.counters.command_acks_timeouts - before.counters.command_acks_timeouts, 1);
    assert.equal(after.counters.command_acks_disconnected - before.counters.command_acks_disconnected, 1);
    assert.equal(after.command_ack_latency.count - before.command_ack_latency.count, 5);
    assert.ok(after.command_ack_latency.sum - before.command_ack_latency.sum >= 0.29);
    assert.equal(unexpected, 0);
    const text = gateway.runtime.prometheus();
    assert.match(text, /socket_bridge_gateway_pending_acks 0\n/);
    assert.match(text, new RegExp(`socket_bridge_gateway_command_ack_seconds_count ${after.command_ack_latency.count}\\n`));
    assert.doesNotMatch(text, /metrics-command-user|payment\.declined/);
    assert.ok(!text.includes(successfulId));
  },
);
integration(
  "reserved names and forged command options never reach the command stream",
  async () => {
    const { socket } = await connect();
    const before = await redis.xlen(`${prefix}:commands`);
    for (const [name, payload, options] of [
      ["bridge.internal.control.v1", {}, {}],
      ["bad name", {}, {}],
      ["command", { command: "order.pay", payload: {} }, {}],
      ["order.pay", {}, { id: randomUUID(), user_id: "forged" }],
      ["order.pay", [], { id: randomUUID() }],
    ]) {
      const response = await socket
        .timeout(1500)
        .emitWithAck(name, payload, options);
      assert.equal(response.ok, false);
      assert.equal(response.error.code, "invalid_command");
    }
    assert.equal(await redis.xlen(`${prefix}:commands`), before);
    socket.disconnect();
  },
);
