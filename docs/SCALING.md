# Scaling, metrics and recovery validation

Socket Bridge supports multiple gateway processes and multiple Laravel command/outbox workers for one application. They share the same Redis namespace, internal secret and database. Each application keeps a separate namespace and authorization policy.

## Scaling behavior

Gateways compete for entries in one `gateways` consumer group. The successful consumer sends one internal fanout request through the Socket.IO Redis adapter to participating gateways and waits for their acknowledgements. Each gateway groups its local recipients by their authorized intersection with the target rooms, then dispatches locally. A socket subscribed to several matching rooms receives one dispatch. Client metadata includes `channels`, containing only canonical target names that this recipient actually belongs to; other private targets are not disclosed. A gateway acknowledgement means the local dispatch was queued, not that a browser received or processed it.

Command consumers compete through the `laravel` group. A database receipt lock serializes duplicate command IDs within the same authenticated bridge session; outbox workers lock rows while publishing. Additional workers increase available concurrency, but database capacity, handler latency and Redis availability still set throughput limits. Keep pending-claim timeouts above normal handler duration. External effects need application-level idempotency.

All gateway replicas sharing a Redis namespace must run the same gateway version. Coordinate upgrades while retaining the Redis streams and database. Reconnecting applications must rejoin their channels and resynchronize their state.

WebSocket is the default transport and does not require sticky sessions. If HTTP polling is enabled, configure sticky sessions in the load balancer. Supervise all long-running processes and restart them on deployment. Use TLS at the gateway or reverse proxy, allow exact browser origins, and keep Redis private.

A Pub/Sub delivery to another gateway is not persisted independently. Adapter-connection loss triggers recoverable browser disconnects, and new handshakes wait until all Redis roles are ready, so partial Pub/Sub outages also force resynchronization. A missing peer response times out after five seconds; the stream entry remains eligible for retry and recipients may see a repeated envelope ID. Replica/network interruption can create gaps even when the event stream entry has been ACKed elsewhere. A reconnecting client must resynchronize business state. Increasing replica count does not create browser delivery guarantees, global ordering or offline replay; see [Reliability](RELIABILITY.md).

## Gateway metrics

Metrics are disabled unless `SOCKET_BRIDGE_METRICS_TOKEN` contains at least 32 characters. When enabled, `GET /metrics` requires `Authorization: Bearer <token>`. Without configuration the endpoint returns 404; missing/incorrect credentials return 401. Use a dedicated token and TLS or a trusted loopback/private scrape path. Do not put the token in a URL.

The response is Prometheus text exposition (`text/plain; version=0.0.4`) with `Cache-Control: no-store`. It contains aggregate process statistics only: no user, session, socket, channel or event-name labels. Configure the scraper's target labels to identify instances.

| Metric prefix `socket_bridge_gateway_` | Meaning |
| --- | --- |
| `commands_accepted_total` | Client commands successfully appended to Redis. Acceptance is not business success. |
| `pending_acks` | Business acknowledgements currently waiting on this gateway. |
| `command_acks_completed_total`, `command_acks_errors_total` | Successful business responses and rejected/failed commands. |
| `command_acks_timeouts_total` | Gateway response deadlines exceeded; queued work may still complete. |
| `command_acks_disconnected_total` | Pending responses discarded when the socket disconnects or the process shuts down. |
| `command_ack_seconds` | Histogram from receipt of the named Socket.IO event to dispatch of its ACK, including Redis/PHP/outbox wait. Disconnected requests without a response are excluded. |
| `events_accepted_total` | First processing attempts for physical Redis event entries. |
| `events_delivered_total` | Successful gateway dispatches before ACK. This is **not** browser receipt or rendering. |
| `events_failed_total` | Event entries transferred to dead letters. |
| `events_retries_total` | Processing attempts whose Redis attempt count is greater than one. |
| `events_duplicates_total` | Repeated envelope IDs observed by this process in a bounded 5,000-ID window. Observation does not suppress transport retries; this is not a global duplicate count. |
| `connected_total`, `disconnected_total` | Connection/disconnection lifecycle counters. |
| `refreshes_total`, `renewals_total` | Successful identity refreshes and channel grant renewals. |
| `authorization_retries_total`, `revoked_total` | Temporary grant retries and terminal socket disconnects. Channel-only denial is not a socket revocation. |
| `slow_clients_total` | Connections removed after exceeding configured outgoing-buffer limits. |
| `connections`, `subscriptions` | Current connected sockets and active channel grants. |
| `heap_bytes`, `rss_bytes` | V8 heap used and resident process memory. |
| `processing_seconds` | Histogram of event-entry processing duration, including dispatch and acknowledgement. |

Event-processing histogram bounds are 0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1 and 5 seconds, plus `+Inf`. The command ACK histogram also includes 10, 30, 60 and 300 seconds. Bucket counts are cumulative. Counters reset when a gateway restarts. Scrape every process and use rates/increases across restarts. ACK timing ends when the gateway dispatches the response; browser processing is outside this measurement.

The existing Redis gateway heartbeat remains a lightweight operational signal: updated every five seconds with a 15-second TTL. A heartbeat confirms recent activity, while stream lag, pending entries, outbox age and application-specific checks show whether useful work is progressing. PHP processing/outbox metrics are a separate producer; do not sum them with gateway dispatches as if they measure the same stage.

## Slow consumers

The gateway checks each connection's Engine.IO queue and WebSocket buffered bytes during its lifecycle tick. Defaults are:

```dotenv
SOCKET_BRIDGE_MAX_BUFFERED_BYTES=1048576
SOCKET_BRIDGE_MAX_BUFFERED_PACKETS=1000
```

Exceeding either limit triggers a recoverable `slow_client` disconnect. Applications should reconnect and resynchronize after recoverable interruptions. Queue checks normally run at most 100 ms apart; they are not a strict byte-perfect allocation cap during a burst or blocked event loop. A connection can temporarily exceed the configured threshold between checks. Payload limits, rate limits, deployment memory limits and process supervision remain necessary.

A notification sent immediately before disconnect may itself be stuck behind a slow client's queued data. Applications must also handle ordinary transport disconnects and resynchronize. Do not increase the queue limit indefinitely to conceal an overloaded browser or network.

A separate transport regression check pauses a real TCP reader, publishes bounded 60-KiB stream events to that socket and verifies eviction while an independent healthy socket continues receiving:

```bash
SOCKET_BRIDGE_TEST_REDIS_URL=redis://127.0.0.1:6379/0 \
  node scripts/scale-soak-pressure.mjs
```

Use a dedicated test Redis. This check isolates one namespace, removes only its own keys, uses a 64-KiB gateway queue threshold, and records `.test-results/scale-pressure.json`. Its authorization endpoint is a stub; the multi-process harness below supplies real Laravel authorization. A short slow-reader pause during the long run may remain below its ordinary 1-MiB threshold and does not by itself establish eviction.

## Reproducible multi-process harness

The repository provides a separate integration harness for actual Laravel handlers, database transactions, duplicate commands and outbox publication:

```bash
composer install
npm ci --prefix gateway
npm run build --prefix gateway

SOCKET_BRIDGE_E2E_APP="$PWD/.test-results/scale-app" \
  php scripts/setup-e2e.php

# Default: 500 clients, two measured hours, plus startup and drain.
node scripts/scale-soak.mjs
```

Requirements are Docker, Node 24 and PHP 8.3+ with PostgreSQL PDO. The harness creates dedicated Redis 7.4 and PostgreSQL 16 containers with unique run names and loopback ports 17389/17432. It does not flush a shared Redis instance or operate on unrelated containers. Its Laravel fixture defaults to `.test-results/scale-app`; `SOCKET_BRIDGE_E2E_APP` may select another directory inside `.test-results`. Container teardown removes only the names created by that run.

Two gateway processes serve alternating clients. Two PHP command consumers and two outbox workers share PostgreSQL. Two local Laravel HTTP server groups handle tickets and channel authorization. The harness snapshots the gateway distribution and PHP package per run so later working-tree changes do not alter a restarted process. These local development HTTP servers are a test fixture, not a production hosting recommendation.

Defaults publish five 4-KiB fanout events per second and submit two unique transactional commands per second, each concurrently duplicated. Commands insert a business row, write a receipt and persist a durable event/result. The harness repeatedly injects gateway termination, Redis restart, PHP-worker termination, mass transport reconnects and an eight-second slow-reader pause. Gateway `rate_limited` responses during reconnect bursts are counted by error code and retried with the same command ID. It disables worker age/memory recycling only inside this bounded test, supervises failed PHP workers, and reuses command IDs when the outcome is unknown.

A passing run requires every submitted operation to complete, exactly one database mutation per operation within the tested receipt scope, no unexpected business error, drained pending/outbox queues and unread stream lag, no dead letters, a final barrier received by every client, and sustained delivery to every client around the fault windows. It records the difference between theoretical always-connected fanout opportunities and received events; that gap includes deliberate outages and is not hidden or represented as lossless delivery.

Run a short smoke check before a long measurement:

```bash
SOCKET_BRIDGE_SCALE_DURATION_MS=65000 \
SOCKET_BRIDGE_SCALE_CONNECTIONS=50 \
  node scripts/scale-soak.mjs
```

Additional controls are `SOCKET_BRIDGE_SCALE_EVENTS_PER_SECOND`, `SOCKET_BRIDGE_SCALE_COMMANDS_PER_SECOND`, `SOCKET_BRIDGE_SCALE_REDIS_PORT`, `SOCKET_BRIDGE_SCALE_PG_PORT`, `SOCKET_BRIDGE_SCALE_PHP_METRICS=1`, and `PHP_BINARY`. The metrics option enables PHP instrumentation with a run-specific token and verifies both protected HTTP and CLI exports after drain. Keep only one run on a given fixture/port pair at a time. Use the pinned package Node runtime for a reproducible measurement and record its version with the report.

Each `.test-results/scale-<run-id>/` directory contains `report.json`, gateway memory/metric samples in `gateway-samples.jsonl`, bounded process logs and the tested source snapshots. `scale-current.json` identifies the latest run and harness PID. Reports distinguish attempted/Redis-acknowledged event publications and publication failures, submitted/committed operations, raw repeated event IDs, delivery gaps, pending/dead-letter counts and sampled p50/p95/p99 client latencies. Event latency is sampled from every 25th client; command latency includes retries and fault windows. Fanout ticks exercise direct Redis-to-gateway-to-client transport; transactional commands also exercise Laravel durable broadcasts. A rejected producer write has an unknown outcome and may still have reached Redis, so the fanout opportunity difference is an estimate, not a definitive loss counter.

Gateway memory is measured in separate processes; the load generator is excluded. The harness requests GC before periodic gateway samples to make retained heap growth visible; the maximum sampled RSS is also retained (not an operating-system peak measurement).

## Interpreting results

The harness is a recovery and regression check on the recorded machine and configuration. It does not establish a production capacity limit, cross-region network performance, Redis durability under all failure modes or exactly-once browser delivery. Its Redis instance uses AOF with `appendfsync always`; production configurations using a different fsync policy have different acknowledged-write durability boundaries.

Read latency percentiles alongside fault timestamps and missing-delivery opportunities. Deliberate reconnect/slow-reader windows can dominate tail latency. A restarted gateway's heap cannot be treated as continuous lifetime growth; the report retains PID and gateway index so uninterrupted processes can be analyzed separately. Repeated slow-reader pauses that remain below the configured queue threshold exercise delayed delivery without necessarily causing a backpressure disconnect; the report records actual disconnect counts.

Run `node scripts/scale-soak-analyze.mjs .test-results/scale-<run-id>` after completion. It evaluates each uninterrupted gateway PID with at least one hour of samples. After excluding ten minutes of warmup, the first and final ten-minute forced-GC heap means may differ by at most the greater of 25% or 8 MiB; the final-half linear slope must not exceed 8 MiB/hour. These thresholds are a bounded regression tolerance, not proof that a process has no memory leak. The analyzer exits unsuccessfully for a completed hour-plus run with no qualifying process or a failed memory assessment. Short-lived/restarted PIDs remain separately reported.

For runs explicitly targeting 40 to less than 60 minutes, a separate `duration_limited_assessment` requires at least 35 minutes of samples from one continuous PID. It excludes ten minutes of warmup and compares the first and final five-minute windows, with at least 30 samples in each window and no sampling gap above 30 seconds. The growth limit remains `max(25%, 8 MiB)` and the final-half slope limit remains 8 MiB/hour. A passing result is named `passed-duration-limited`; the hour-plus assessment remains separate and normally reports `insufficient-duration`. Missing duration or sampling coverage does not pass and causes a completed short run's analyzer check to fail. Gateway 0 is deliberately restarted by the harness; gateway 1 is left running to provide a continuous observation. This short assessment only checks the observed interval and does not establish the absence of a memory leak. The analyzer writes `analysis.json` and never rewrites the raw run's duration or outcome.

See [Validation](VALIDATION.md) for completed release results. A running measurement is not a passed test; only a report with `final:true` and `passed:true` establishes successful completion of its stated duration.
