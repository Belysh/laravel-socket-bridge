/** Real Laravel/PostgreSQL/Redis multi-process recovery run. Default duration: two hours.
 * Dedicated containers/ports only. Runs with Node24, PHP8.3+ and Docker.
 * Run `php scripts/setup-e2e.php` with SOCKET_BRIDGE_E2E_APP=$PWD/.test-results/scale-app first.
 */
import assert from "node:assert/strict";
import { spawn, spawnSync, fork } from "node:child_process";
import { randomUUID, randomBytes, createHash } from "node:crypto";
import {
  readFile,
  writeFile,
  mkdir,
  appendFile,
  cp,
  symlink,
  unlink,
  readdir,
} from "node:fs/promises";
import { resolve, dirname, delimiter } from "node:path";
import { setTimeout as sleep } from "node:timers/promises";
import Redis from "../gateway/node_modules/ioredis/built/index.js";

const root = resolve(import.meta.dirname, ".."),
  app = resolve(
    process.env.SOCKET_BRIDGE_E2E_APP ??
      resolve(root, ".test-results/scale-app"),
  );
assert.ok(
  app.startsWith(resolve(root, ".test-results") + "/"),
  "Scale fixture must be inside .test-results",
);
const duration = Number(
    process.env.SOCKET_BRIDGE_SCALE_DURATION_MS ?? 7_200_000,
  ),
  count = Number(process.env.SOCKET_BRIDGE_SCALE_CONNECTIONS ?? 500);
const eventRate = Number(
    process.env.SOCKET_BRIDGE_SCALE_EVENTS_PER_SECOND ?? 5,
  ),
  commandRate = Number(
    process.env.SOCKET_BRIDGE_SCALE_COMMANDS_PER_SECOND ?? 2,
  );
const runId =
  new Date()
    .toISOString()
    .replace(/[^0-9]/g, "")
    .slice(0, 14) +
  "-" +
  randomBytes(3).toString("hex");
const directory = resolve(root, ".test-results", `scale-${runId}`),
  prefix = `socket-bridge:scale:${runId}`;
const redisName = `socket-bridge-scale-redis-${runId}`,
  pgName = `socket-bridge-scale-pg-${runId}`;
const redisPort = Number(process.env.SOCKET_BRIDGE_SCALE_REDIS_PORT ?? 17389),
  pgPort = Number(process.env.SOCKET_BRIDGE_SCALE_PG_PORT ?? 17432);
for (const [name, value, minimum, maximum] of [
  ["duration", duration, 1000, 86400000],
  ["connections", count, 1, 10000],
  ["event rate", eventRate, 0.1, 1000],
  ["command rate", commandRate, 0.1, 1000],
  ["Redis port", redisPort, 1024, 65535],
  ["PostgreSQL port", pgPort, 1024, 65535],
])
  assert.ok(
    Number.isFinite(value) && value >= minimum && value <= maximum,
    `Invalid ${name}`,
  );
assert.ok(Number.isInteger(count), "Connection count must be an integer");
const redisUrl = `redis://127.0.0.1:${redisPort}/0`,
  php = process.env.PHP_BINARY ?? "php";
const environment = {
  ...process.env,
  PHP_BINARY: php,
  SOCKET_BRIDGE_E2E_APP: app,
  SOCKET_BRIDGE_SCALE_RUN: runId,
  SOCKET_BRIDGE_SCALE_REDIS_PORT: String(redisPort),
  SOCKET_BRIDGE_SCALE_PG_PORT: String(pgPort),
  PATH: `${dirname(process.execPath)}${delimiter}${process.env.PATH ?? ""}`,
};
const children = [],
  clients = [],
  gateways = [null, null],
  samples = [],
  faults = [],
  logs = new Map(),
  commandErrorCodes = new Map(),
  commands = new Map();
const latency = { events: [], commands: [] },
  seen = Array.from({ length: count }, () => new Set()),
  deliveries = new Uint32Array(count),
  barrierReceived = new Set();
let io,
  redis,
  publishing,
  refreshing,
  commanding,
  started = 0,
  ending = false,
  sequence = 0,
  publishedEvents = 0,
  publishFailures = 0,
  outstandingPublications = 0,
  eventDeliveries = 0,
  eventDuplicates = 0,
  barrierId,
  acceptedOperations = 0,
  completedOperations = 0,
  commandErrors = 0,
  unexpectedBusinessErrors = 0,
  slowDisconnected = 0;
const output = (message) => {
  console.log(message);
};
async function recordSnapshot() {
  const checksums = {};
  async function scan(path) {
    for (const entry of (
      await readdir(resolve(directory, path), { withFileTypes: true })
    ).sort((a, b) => a.name.localeCompare(b.name))) {
      const name = `${path}/${entry.name}`;
      if (entry.isDirectory()) await scan(name);
      else if (entry.isFile())
        checksums[name] = createHash("sha256")
          .update(await readFile(resolve(directory, name)))
          .digest("hex");
    }
  }
  for (const path of [
    "gateway/dist",
    "package/src",
    "package/config",
  ])
    await scan(path);
  await writeFile(
    resolve(directory, "snapshot-manifest.json"),
    JSON.stringify(
      {
        node_version: process.version,
        platform: process.platform,
        architecture: process.arch,
        file_checksums: checksums,
        aggregate_sha256: createHash("sha256")
          .update(JSON.stringify(checksums))
          .digest("hex"),
        note: "Exact gateway/PHP source snapshot taken before measured run. Dependencies copied independently.",
      },
      null,
      2,
    ) + "\n",
  );
}
function run(command, args, cwd = root) {
  const result = spawnSync(command, args, {
    cwd,
    env: environment,
    encoding: "utf8",
    timeout: 600000,
    maxBuffer: 20 * 1024 * 1024,
  });
  if (result.error) throw result.error;
  if (result.status !== 0)
    throw new Error(`${command} failed: ${result.stderr}\n${result.stdout}`);
  return result.stdout.trim();
}
async function until(predicate, ms = 30000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    if (await predicate()) return;
    await sleep(100);
  }
  throw new Error("Scale harness readiness timeout");
}
function processStart(name, args, cwd = app, extra = {}) {
  if (["socket-bridge:consume", "socket-bridge:outbox"].includes(args[1]))
    args = [...args, "--max-time=0", "--memory=0"];
  const child = spawn(php, args, {
    cwd,
    env: { ...environment, ...extra },
    detached: true,
    stdio: ["ignore", "pipe", "pipe"],
  });
  logs.set(name, "");
  for (const stream of [child.stdout, child.stderr])
    stream.on("data", (c) => {
      let text = (logs.get(name) ?? "") + String(c);
      if (text.length > 500000) text = text.slice(-500000);
      logs.set(name, text);
    });
  children.push(child);
  return child;
}
function startGateway(index) {
  const child = fork(resolve(root, "scripts/scale-soak-gateway.mjs"), [], {
    execArgv: ["--expose-gc"],
    cwd: root,
    env: {
      ...environment,
      SOCKET_BRIDGE_SCALE_SNAPSHOT: resolve(directory, "gateway/dist"),
      SOCKET_BRIDGE_PREFIX: prefix,
      SOCKET_BRIDGE_SECRET: secret,
      SOCKET_BRIDGE_REDIS_URL: redisUrl,
      SOCKET_BRIDGE_LARAVEL_URL: `http://127.0.0.1:${18094 + index}`,
      SOCKET_BRIDGE_PORT: String(16094 + index),
      SOCKET_BRIDGE_INSTANCE_ID: `scale-${index}`,
      SOCKET_BRIDGE_ORIGINS: "http://127.0.0.1:18094",
      SOCKET_BRIDGE_ROOM_LEASE_SECONDS: "30",
      SOCKET_BRIDGE_AUTH_CHECK_MS: "1000",
      SOCKET_BRIDGE_CLAIM_IDLE_MS: "2000",
      SOCKET_BRIDGE_RATE_LIMIT: "100000",
      SOCKET_BRIDGE_METRICS_TOKEN: metricsToken,
    },
    stdio: ["ignore", "ignore", "pipe", "ipc"],
  });
  child.on("message", (sample) => {
    if (sample.type === "sample") {
      const record = { ...sample, gateway: index, pid: child.pid };
      samples.push(record);
      void appendFile(
        resolve(directory, "gateway-samples.jsonl"),
        JSON.stringify(record) + "\n",
      );
    }
  });
  child.stderr.on("data", (c) => {
    const key = `gateway-${index}`;
    logs.set(key, ((logs.get(key) ?? "") + String(c)).slice(-500000));
  });
  gateways[index] = child;
  children.push(child);
  return child;
}
let ticketActive = 0;
const ticketWaiters = [];
async function limitTicket(callback) {
  if (ticketActive >= 8)
    await new Promise((resolve) => ticketWaiters.push(resolve));
  ticketActive++;
  try {
    return await callback();
  } finally {
    ticketActive--;
    ticketWaiters.shift()?.();
  }
}
async function login(id) {
  const cookies = new Map();
  let csrf;
  async function request(path, method = "GET", body) {
    const response = await fetch(`http://127.0.0.1:18094${path}`, {
      method,
      headers: {
        Accept: "application/json",
        Cookie: [...cookies].map(([k, v]) => `${k}=${v}`).join("; "),
        ...(csrf ? { "X-CSRF-TOKEN": csrf } : {}),
        ...(body ? { "Content-Type": "application/json" } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(20000),
    });
    for (const cookie of response.headers.getSetCookie()) {
      const first = cookie.split(";")[0],
        at = first.indexOf("=");
      cookies.set(first.slice(0, at), first.slice(at + 1));
    }
    if (!response.ok)
      throw new Error(`Laravel ${path} returned ${response.status}`);
    return response.json();
  }
  csrf = (await request(`/demo/login/${id}`)).csrf;
  return {
    request,
    token: () =>
      limitTicket(
        async () => (await request("/socket-bridge/token", "POST")).token,
      ),
  };
}
function keepLatency(list, value) {
  if (!Number.isFinite(value) || value < 0) return;
  list.push(value);
  if (list.length > 2000000) list.splice(0, 100000);
}
function percentile(list, p) {
  if (!list.length) return null;
  const sorted = [...list].sort((a, b) => a - b);
  return (
    Math.round(
      sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))] * 100,
    ) / 100
  );
}
function chooseClient() {
  const connected = clients.filter((c) => c.connected);
  return connected[Math.floor(Math.random() * connected.length)];
}
async function submit(operation) {
  const client = chooseClient();
  if (!client) return;
  operation.inflight++;
  try {
    const acknowledgement = await client.timeout(35000).emitWithAck(
      "scale.mutate", { operation_id: operation.id, sent_at: operation.at }, { id: operation.id },
    ).catch(error => { error.code = 'socket.ack_timeout'; throw error; });
    if (!acknowledgement.ok) throw Object.assign(new Error(acknowledgement.error?.message ?? 'Command failed'), acknowledgement.error);
    assert.equal(acknowledgement.id, operation.id);
    const result = acknowledgement.data;
    assert.equal(result.operation_id, operation.id);
    if (!operation.done) {
      operation.done = true;
      completedOperations++;
      keepLatency(latency.commands, Date.now() - operation.at);
    } else operation.duplicateResults++;
  } catch (error) {
    commandErrors++;
    commandErrorCodes.set(
      error.code ?? "unknown",
      (commandErrorCodes.get(error.code ?? "unknown") ?? 0) + 1,
    );
    if (
      ![
        "command.timeout",
        "socket.ack_timeout",
        "socket.disconnected",
        "command.pending",
        "rate_limited",
        "temporarily_unavailable",
        "redis_unavailable",
        "session_expired",
        "unauthenticated",
      ].includes(error.code)
    ) {
      unexpectedBusinessErrors++;
      operation.error = { code: error.code, message: error.message };
    }
  } finally {
    operation.inflight--;
  }
}
process.on("uncaughtException", (error) => {
  output(`UNCAUGHT ${error.stack}`);
  ending = true;
  process.exitCode = 1;
});
process.on("SIGTERM", () => {
  ending = true;
});
process.on("SIGINT", () => {
  ending = true;
});
let secret,
  metricsToken = randomBytes(32).toString("hex"),
  sessions,
  workerPairs = [];
const phpMetricsEnabled = process.env.SOCKET_BRIDGE_SCALE_PHP_METRICS === "1";
if (phpMetricsEnabled) {
  environment.SOCKET_BRIDGE_METRICS_ENABLED = "true";
  environment.SOCKET_BRIDGE_METRICS_TOKEN = metricsToken;
}
async function groupLag(name) {
  const target = name === "events" ? "gateways" : "laravel";
  const groups = await redis.xinfo("GROUPS", `${prefix}:${name}`);
  const group = groups
    .map((values) =>
      Object.fromEntries(
        Array.from({ length: values.length / 2 }, (_, index) => [
          values[index * 2],
          values[index * 2 + 1],
        ]),
      ),
    )
    .find((value) => value.name === target);
  assert.ok(
    group && group.lag !== null,
    `Stream ${name} must expose known consumer-group lag`,
  );
  return Number(group.lag);
}
async function snapshot(final = false) {
  const connected = clients.filter((c) => c.connected).length;
  let db,
    stream = {};
  try {
    db = await sessions[0].request("/scale/state");
    for (const name of ["events", "commands"])
      stream[name] = {
        length: await redis.xlen(`${prefix}:${name}`),
        lag: await groupLag(name),
        pending: (
          await redis.xpending(
            `${prefix}:${name}`,
            name === "events" ? "gateways" : "laravel",
          )
        )[0],
        dead: await redis.xlen(`${prefix}:dead:${name}`),
      };
  } catch (error) {
    stream.error = error.message;
  }
  const report = {
    run_id: runId,
    started_at: started ? new Date(started).toISOString() : null,
    updated_at: new Date().toISOString(),
    elapsed_ms: started ? Date.now() - started : 0,
    target_duration_ms: duration,
    connections: count,
    connected,
    gateway_processes: gateways.map((g) => ({
      pid: g?.pid,
      exited: g?.exitCode,
    })),
    throughput_per_second: {
      published_events: started
        ? publishedEvents / ((Date.now() - started) / 1000)
        : 0,
      client_deliveries: started
        ? eventDeliveries / ((Date.now() - started) / 1000)
        : 0,
      committed_operations: started
        ? completedOperations / ((Date.now() - started) / 1000)
        : 0,
    },
    events_attempted: sequence,
    events_published: publishedEvents,
    event_publication_failures: publishFailures,
    event_deliveries: eventDeliveries,
    theoretical_delivery_opportunities: publishedEvents * count,
    delivery_gap_including_fault_windows:
      publishedEvents * count - eventDeliveries,
    event_duplicates_observed: eventDuplicates,
    final_barrier_received: barrierReceived.size,
    command_error_details: [...commands.values()]
      .filter((operation) => operation.error)
      .map((operation) => ({ operation_id: operation.id, ...operation.error })),
    minimum_client_deliveries: Math.min(...deliveries),
    operations_submitted: acceptedOperations,
    operations_completed: completedOperations,
    transient_command_errors: commandErrors,
    command_error_codes: Object.fromEntries(commandErrorCodes),
    php_metrics_enabled: phpMetricsEnabled,
    unexpected_business_errors: unexpectedBusinessErrors,
    latency_ms: {
      events: {
        samples: latency.events.length,
        p50: percentile(latency.events, 0.5),
        p95: percentile(latency.events, 0.95),
        p99: percentile(latency.events, 0.99),
      },
      commands: {
        samples: latency.commands.length,
        p50: percentile(latency.commands, 0.5),
        p95: percentile(latency.commands, 0.95),
        p99: percentile(latency.commands, 0.99),
      },
    },
    database: db,
    streams: stream,
    faults,
    slow_client_disconnections: slowDisconnected,
    process_samples: samples.length,
    final,
  };
  await writeFile(
    resolve(directory, "report.json"),
    JSON.stringify(report, null, 2) + "\n",
  );
  return report;
}
async function fault(type, action) {
  faults.push({
    type,
    at: new Date().toISOString(),
    elapsed_ms: Date.now() - started,
  });
  output(`FAULT ${type} at ${Math.round((Date.now() - started) / 1000)}s`);
  await action();
}
try {
  await mkdir(directory, { recursive: true });
  await cp(resolve(root, "gateway/dist"), resolve(directory, "gateway/dist"), {
    recursive: true,
  });
  await cp(
    resolve(root, "gateway/node_modules"),
    resolve(directory, "gateway/node_modules"),
    { recursive: true },
  );
  ({ io } = await import(resolve(directory, "gateway/node_modules/socket.io-client/build/esm-debug/index.js")));
  await mkdir(resolve(directory, "package"), { recursive: true });
  for (const path of [
    "src",
    "config",
    "routes",
    "database",
    "runtime",
    "stubs",
    "docker",
    "composer.json",
  ])
    await cp(resolve(root, path), resolve(directory, "package", path), {
      recursive: true,
    });
  await recordSnapshot();
  const linked = resolve(app, "vendor/belysh/laravel-socket-bridge");
  await unlink(linked);
  await symlink(resolve(directory, "package"), linked, "dir");
  await writeFile(
    resolve(root, ".test-results/scale-current.json"),
    JSON.stringify(
      { run_id: runId, pid: process.pid, directory, duration_ms: duration },
      null,
      2,
    ),
  );
  output(`Preparing isolated run ${runId}, ${count} clients, ${duration}ms.`);
  run("docker", [
    "run",
    "-d",
    "--name",
    redisName,
    "-p",
    `127.0.0.1:${redisPort}:6379`,
    "redis:7.4-alpine",
    "redis-server",
    "--appendonly",
    "yes",
    "--appendfsync",
    "always",
    "--save",
    "",
  ]);
  run("docker", [
    "run",
    "-d",
    "--name",
    pgName,
    "-p",
    `127.0.0.1:${pgPort}:5432`,
    "-e",
    "POSTGRES_DB=bridge",
    "-e",
    "POSTGRES_USER=bridge",
    "-e",
    "POSTGRES_PASSWORD=bridge-scale-test",
    "postgres:16-alpine",
  ]);
  redis = new Redis(redisUrl, {
    maxRetriesPerRequest: 1,
    retryStrategy: (times) => Math.min(times * 100, 2000),
  });
  redis.on("error", () => {});
  await until(async () => {
    try {
      return (await redis.ping()) === "PONG";
    } catch {
      return false;
    }
  });
  await until(() => {
    const r = spawnSync(
      "docker",
      [
        "exec",
        pgName,
        "pg_isready",
        "-h",
        "127.0.0.1",
        "-U",
        "bridge",
        "-d",
        "bridge",
      ],
      { encoding: "utf8" },
    );
    return r.status === 0;
  });
  // Rebuild fixture-only sources before each run so repeated smoke/full runs are independent.
  run(php, ["scripts/prepare-e2e.php"]);
  run(php, ["scripts/scale-soak-setup.php"]);
  run(php, ["artisan", "config:clear"], app);
  run(php, ["artisan", "migrate", "--force"], app);
  run(php, ["scripts/scale-soak-seed.php"]);
  secret = (await readFile(resolve(app, ".env"), "utf8")).match(
    /^SOCKET_BRIDGE_SECRET=(.+)$/m,
  )[1];
  for (let i = 0; i < 2; i++)
    processStart(
      `http-${i}`,
      ["-S", `127.0.0.1:${18094 + i}`, "-t", "public", "public/index.php"],
      app,
      { PHP_CLI_SERVER_WORKERS: "2" },
    );
  await until(async () => {
    try {
      return (await fetch("http://127.0.0.1:18094/up")).ok;
    } catch {
      return false;
    }
  });
  for (let i = 0; i < 2; i++) {
    startGateway(i);
    workerPairs.push([
      processStart(`commands-${i}`, [
        "artisan",
        "socket-bridge:consume",
        "--consumer",
        `scale-${i}`,
      ]),
      processStart(`outbox-${i}`, [
        "artisan",
        "socket-bridge:outbox",
        "--sleep=0.1",
      ]),
    ]);
  }
  for (let i = 0; i < 2; i++)
    await until(async () => {
      try {
        return (await fetch(`http://127.0.0.1:${16094 + i}/health/ready`)).ok;
      } catch {
        return false;
      }
    });
  if (phpMetricsEnabled) {
    for (const authorization of [undefined, "Bearer invalid"]) {
      const response = await fetch(
        "http://127.0.0.1:18094/socket-bridge/metrics",
        { headers: authorization ? { Authorization: authorization } : {} },
      );
      assert.equal(
        response.status,
        401,
        "PHP metrics require the configured bearer token",
      );
    }
  }
  sessions = [await login(1), await login(2)];
  // All tabs share one authenticated user/session: duplicates can race on different gateways/workers.
  for (let index = 0; index < count; index++) {
    const client = io(`http://127.0.0.1:${16094 + (index % 2)}`, {
      transports: ['websocket'], autoConnect: false,
      auth: callback => { void sessions[0].token().then(token => callback({ token }), () => callback({ token: '' })); },
    });
    client.on('connect', () => { void client.timeout(10000).emitWithAck('room:join', {channel:'private-scale'}).catch(() => {}); });
    client.on('connect_error', () => { setTimeout(() => { if (!ending) client.connect(); }, 500); });
    client.on("scale.tick", (payload, metadata) => {
      if (metadata?.id === barrierId) { barrierReceived.add(index); return; }
      if (seen[index].has(metadata?.id)) { eventDuplicates++; return; }
      seen[index].add(metadata?.id);
      if (seen[index].size > 2000) seen[index].delete(seen[index].values().next().value);
      deliveries[index]++; eventDeliveries++;
      if (index % 25 === 0) keepLatency(latency.events, Date.now() - payload.sent_at);
    });
    client.on("bridge.disconnect", data => {
      if (data.code === "slow_client") slowDisconnected++;
      if (data.retryable) setTimeout(() => { if (!ending) client.connect(); }, 500);
    });
    client.connect();
    clients.push(client);
    if (index % 25 === 24) await sleep(20);
  }
  await until(() => clients.every((c) => c.connected), 60000);
  refreshing = setInterval(() => {
    for (const socket of clients) if (socket.connected) {
      void sessions[0].token().then(token => socket.timeout(10000).emitWithAck('session:refresh', {token})).catch(() => {});
    }
  }, 30000);
  await sleep(2000);
  started = Date.now();
  output(
    `STARTED ${new Date(started).toISOString()} pid=${process.pid} report=${directory}/report.json`,
  );
  publishing = setInterval(() => {
    const envelope = {
      v: 1,
      id: randomUUID(),
      created_at: new Date().toISOString(),
      type: "socket.emit",
      event: "scale.tick",
      rooms: ["private-scale"],
      payload: {
        sequence: ++sequence,
        sent_at: Date.now(),
        padding: "x".repeat(4096),
      },
    };
    outstandingPublications++;
    void redis
      .xadd(`${prefix}:events`, "*", "envelope", JSON.stringify(envelope))
      .then(
        () => publishedEvents++,
        () => publishFailures++,
      )
      .finally(() => outstandingPublications--);
  }, 1000 / eventRate);
  commanding = setInterval(() => {
    const operation = {
      id: randomUUID(),
      at: Date.now(),
      done: false,
      inflight: 0,
      duplicateResults: 0,
    };
    commands.set(operation.id, operation);
    acceptedOperations++;
    void submit(operation);
    void submit(operation);
  }, 1000 / commandRate);
  const cycle = Math.min(300000, Math.max(30000, duration / 2));
  let lastReport = 0,
    nextCycle = 0,
    phase = 0;
  while (Date.now() - started < duration && !ending) {
    const elapsed = Date.now() - started;
    for (let i = 0; i < workerPairs.length; i++)
      for (let role = 0; role < 2; role++) {
        const child = workerPairs[i][role];
        if (child.exitCode !== null || child.signalCode !== null)
          workerPairs[i][role] = processStart(
            `${role ? "outbox" : "commands"}-${i}-supervised`,
            role
              ? ["artisan", "socket-bridge:outbox", "--sleep=0.1"]
              : [
                  "artisan",
                  "socket-bridge:consume",
                  "--consumer",
                  `scale-${i}-supervised`,
                ],
          );
      }
    for (const operation of commands.values())
      if (
        !operation.done &&
        !operation.inflight &&
        Date.now() - operation.at > 1000
      )
        void submit(operation);
    if (elapsed - lastReport >= 30000) {
      lastReport = elapsed;
      const report = await snapshot();
      output(
        `PROGRESS ${Math.round(elapsed / 1000)}s connected=${report.connected}/${count} operations=${completedOperations}/${acceptedOperations} outbox=${report.database?.outbox_pending} p99=${report.latency_ms.commands.p99}ms`,
      );
    }
    const points = [0.2, 0.4, 0.6, 0.8];
    if (phase < points.length && elapsed >= nextCycle + cycle * points[phase]) {
      if (phase === 0)
        await fault("gateway-kill-restart", async () => {
          gateways[0].kill("SIGKILL");
          await sleep(1000);
          startGateway(0);
        });
      if (phase === 1)
        await fault("redis-restart", async () => {
          run("docker", ["restart", "--time", "0", redisName]);
        });
      if (phase === 2)
        await fault("php-workers-restart", async () => {
          for (const child of workerPairs[0]) child.kill("SIGKILL");
          workerPairs[0] = [
            processStart("commands-0-restarted", [
              "artisan",
              "socket-bridge:consume",
              "--consumer",
              "scale-0-restarted",
            ]),
            processStart("outbox-0-restarted", [
              "artisan",
              "socket-bridge:outbox",
              "--sleep=0.1",
            ]),
          ];
        });
      if (phase === 3)
        await fault("mass-reconnect-and-slow-reader", async () => {
          for (const g of gateways)
            if (g?.connected) g.send({ type: "transport-fault" });
          await sleep(2000);
          const slow = clients.find((c) => c.connected);
          const raw = slow?.io.engine.transport.ws?._socket;
          if (raw) {
            raw.pause();
            setTimeout(() => {
              try {
                raw.resume();
              } catch {}
            }, 8000);
          }
        });
      phase++;
      if (phase === 4) {
        phase = 0;
        nextCycle += cycle;
      }
    }
    await sleep(100);
  }
  clearInterval(publishing);
  clearInterval(commanding);
  publishing = commanding = undefined;
  await until(() => outstandingPublications === 0, 30000);
  await until(async () => {
    for (const operation of commands.values())
      if (!operation.done && !operation.inflight) void submit(operation);
    return completedOperations === acceptedOperations;
  }, 120000);
  await until(
    async () =>
      (await sessions[0].request("/scale/state")).outbox_pending === 0,
    120000,
  );
  await until(() => clients.every((c) => c.connected), 30000);
  barrierId = randomUUID();
  await redis.xadd(
    `${prefix}:events`,
    "*",
    "envelope",
    JSON.stringify({
      v: 1,
      id: barrierId,
      created_at: new Date().toISOString(),
      type: "socket.emit",
      event: "scale.tick",
      rooms: ["private-scale"],
      payload: { barrier: true, sent_at: Date.now() },
    }),
  );
  await until(() => barrierReceived.size === count, 30000);
  await until(
    async () =>
      (await redis.xpending(`${prefix}:commands`, "laravel"))[0] === 0 &&
      (await redis.xpending(`${prefix}:events`, "gateways"))[0] === 0 &&
      (await groupLag("commands")) === 0 &&
      (await groupLag("events")) === 0,
    30000,
  );
  const report = await snapshot(true);
  if (phpMetricsEnabled) {
    const response = await fetch(
      "http://127.0.0.1:18094/socket-bridge/metrics",
      { headers: { Authorization: `Bearer ${metricsToken}` } },
    );
    assert.equal(response.status, 200);
    const endpoint = await response.text();
    const cli = run(php, ["artisan", "socket-bridge:metrics"], app);
    for (const text of [endpoint, cli]) {
      for (const name of [
        "commands_processed_total",
        "outbox_published_total",
        "command_duration_seconds_count",
        "outbox_lag_seconds_count",
      ]) {
        assert.ok(
          Number(
            text.match(
              new RegExp(`^socket_bridge_${name} ([0-9.e+-]+)$`, "m"),
            )?.[1],
          ) > 0,
          `Positive PHP metric ${name}`,
        );
      }
      assert.ok(
        !text.includes(metricsToken),
        "Metric output must not contain its bearer token",
      );
    }
    await writeFile(resolve(directory, "php-metrics.prom"), endpoint);
    report.php_metrics_validated = true;
  }
  assert.equal(
    report.database.mutations,
    acceptedOperations,
    "Each unique submitted operation must commit exactly once inside the DB receipt scope",
  );
  assert.equal(
    report.database.distinct_operations,
    acceptedOperations,
    "Duplicate command submission must not duplicate mutations",
  );
  assert.equal(
    unexpectedBusinessErrors,
    0,
    "Unexpected command business failures",
  );
  assert.equal(
    report.streams.commands.pending,
    0,
    "No command backlog after drain",
  );
  assert.equal(
    report.streams.events.pending,
    0,
    "No event backlog after drain",
  );
  assert.equal(
    report.streams.commands.lag,
    0,
    "No unread commands after drain",
  );
  assert.equal(report.streams.events.lag, 0, "No unread events after drain");
  assert.equal(
    barrierReceived.size,
    count,
    "Every client receives the final subscription barrier",
  );
  assert.equal(report.streams.commands.dead, 0, "No command dead letters");
  assert.equal(report.streams.events.dead, 0, "No event dead letters");
  assert.ok(
    [...deliveries].every((n) => n > publishedEvents * 0.4),
    "Every client must receive sustained delivery outside fault windows",
  );
  assert.ok(
    Date.now() - started >= duration,
    "An interrupted run must not count as the requested-duration pass",
  );
  report.passed = true;
  report.gateway_memory_summary = [0, 1].map((index) => {
    const values = samples.filter(
      (s) =>
        s.gateway === index &&
        s.at - started > Math.min(duration * 0.2, 300000),
    );
    return {
      gateway: index,
      samples: values.length,
      min_heap: Math.min(...values.map((s) => s.memory.heapUsed)),
      max_heap: Math.max(...values.map((s) => s.memory.heapUsed)),
      last_heap: values.at(-1)?.memory.heapUsed,
      peak_rss: Math.max(...values.map((s) => s.memory.rss)),
    };
  });
  await writeFile(
    resolve(directory, "report.json"),
    JSON.stringify(report, null, 2) + "\n",
  );
  output(`PASSED ${JSON.stringify(report)}`);
} catch (error) {
  output(`FAILED ${error.stack}`);
  if (started) await snapshot(true).catch(() => {});
  process.exitCode = 1;
} finally {
  ending = true;
  clearInterval(publishing);
  clearInterval(commanding);
  clearInterval(refreshing);
  for (const client of clients) client.disconnect();
  for (const child of children)
    if (child.exitCode === null && child.signalCode === null) {
      if (child.connected) child.send({ type: "close" });
      else {
        try {
          process.kill(-child.pid, "SIGTERM");
        } catch {
          child.kill("SIGTERM");
        }
      }
    }
  await sleep(1500);
  for (const child of children)
    if (child.exitCode === null && child.signalCode === null) {
      try {
        process.kill(-child.pid, "SIGKILL");
      } catch {
        child.kill("SIGKILL");
      }
    }
  for (const [name, text] of logs)
    await writeFile(resolve(directory, `${name}.log`), text).catch(() => {});
  redis?.disconnect();
  for (const name of [redisName, pgName])
    spawnSync("docker", ["rm", "-f", "-v", name], {
      encoding: "utf8",
      timeout: 30000,
    });
}
