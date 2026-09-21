# Reliability and operations

This document describes the guarantees of **2.0.0**. The package connects Laravel's event and authorization APIs to a dedicated NestJS/Socket.IO runtime through Redis Streams. Recovery protects server-side processing; applications still own their durable business history and user-facing resynchronization.

## What a successful operation means

| Operation | Confirmed boundary | What it does not confirm |
| --- | --- | --- |
| Immediate facade/broadcaster emission returns | Redis accepted the event stream entry. | Browser receipt, rendering or database transaction commit. |
| `Socket::durable()->…->emit()` returns inside a transaction | The outbox row was written on the configured database connection. | Commit of the surrounding transaction or later Redis availability. |
| Outbox row has `published_at` | Redis accepted the entry and the publisher recorded success. | Every gateway/browser received it. |
| Socket.IO business event receives successful ACK | Handler result, receipt and result outbox were committed together, provided handler writes use the same database connection. | External API effects outside that transaction or future browser retention. |
| Gateway stream entry is ACKed | Dispatch completed and Redis accepted adapter publications; ordinary events and controls also received dispatch/handling acknowledgements from participating peers. | Browser receipt or durable delivery to a replica absent from Pub/Sub. |

Redis persistence and database durability remain infrastructure requirements. Use a private Redis service with suitable AOF/backup settings, appropriate memory capacity and monitoring. If Redis loses its stored streams, the package cannot reconstruct events that were only persisted there. An already published outbox row is not automatically republished after Redis data loss.

## Command idempotency

Every command has a UUID. Pass it as the optional `{id: UUID}` argument after the business payload to retain it for retries; otherwise the gateway generates one. The database receipt key is **bridge session ID + case-normalized command UUID**. The stored fingerprint includes command name and canonical payload; changing input under an existing ID returns `command.id_conflict`.

The worker resolves current Laravel identity, locks the receipt, runs the handler in a database transaction/savepoint, and persists the result plus a result outbox entry. Concurrent consumers serialize through that receipt lock. A retry with unchanged input returns the stored result without repeating the committed handler mutation. Validation/authorization rejections roll back partial handler writes before recording a terminal result. Unexpected exceptions roll back and are retried through the stream.

The transaction covers only the connection selected by `socket-bridge.database_connection` (the default Laravel connection unless configured). Eloquent models or queries using a different connection, email, payment APIs, files and other external systems are outside it. Give external effects their own idempotency keys or persist an application outbox on the same connection.

Keep the same command ID, command name and payload when retrying an unknown outcome. A timeout, dropped ACK or disconnect does not prove failure. After reconnect, resubmitting the same command in the same authenticated bridge session routes its stored result to the new socket. A fresh Laravel login/session rotation can produce a different receipt namespace. Cross-session business deduplication requires an application-level operation ID.

Default receipt retention is **seven days since the receipt's last update**, including a repeated request for its result. An unpublished durable result prevents pruning that receipt. Old stream deliveries outside the configured receipt-age window are rejected before execution if no stored result exists. However, a *newly submitted* request with a previously pruned ID has a new timestamp and can execute again. Do not treat deduplication as permanent or blindly retry old operations after the retention window; query business state instead.

Command expiry defaults to the earlier of five minutes after gateway acceptance and socket-session expiry. An existing receipt may still return its result, subject to current authentication, without rerunning an expired handler. Unknown command names and terminal business rejections are normal results. Exhausted transient failures produce `command.failed` and enter the dead-letter stream.

## Transactional outbox and event duplicates

For an atomic database mutation and emission, write both within one transaction on the same connection:

```php
use Illuminate\Support\Facades\DB;
use SocketBridge\Facades\Socket;

DB::transaction(function () use ($message) {
    $message->save();

    Socket::durable()
        ->toRoom('private-chat.'.$message->chat_id)
        ->emit('chat.message.created', ['message_id' => $message->id]);
});
```

If you configure another package database connection, use that same connection for the business transaction and model. `durable()` outside a transaction still stores an outbox row, but cannot make an earlier independent business commit atomic.

The outbox worker locks each eligible row while publishing. Failed publication records an error and retries with exponential delays starting at five seconds and capped at one hour. Unpublished rows are retained regardless of age. A crash after Redis accepted an entry but before `published_at` committed may publish it again; the envelope ID remains the same.

Socket.IO listeners can receive repeated events after transport recovery. Applications should deduplicate by the event metadata ID or a stable business version when duplicate application is unsafe. Laravel `ShouldBroadcast` jobs can rerun broadcaster code and generate a fresh envelope ID; the client cannot infer that these separate IDs describe one logical event. An explicitly retried facade emission can use `withId($uuid)` to preserve its ID. Consumers should also use stable business IDs or versions when applying events.

`ShouldBroadcast` uses Laravel's queue. `ShouldBroadcastNow` publishes immediately. Use Laravel's after-commit contract/configuration when an event must wait for transaction commit. This avoids premature publication but does not replace an outbox for atomic persistence.

## Connections, access and recovery

Channel renewal begins before expiry and retains valid membership while Laravel responds. Temporary errors trigger bounded retries. At the old grant's deadline, delivery is suspended until a new grant succeeds. A definitive denial immediately removes the subscription when processed. A delayed authorization response cannot undo an explicit leave or disconnection.

The application rejoins its channels in Socket.IO’s `connect` handler and reloads authoritative state after interruptions. For a long-lived connection, handle `bridge.session` by obtaining a fresh Laravel ticket and sending `session:refresh`. Refresh must match the current user, session and access version; it cannot revive revoked evidence. See [Socket.IO integration](SOCKET_IO.md).

Gateway Redis-verification or adapter-connection outages fail closed with a recoverable disconnect. Handshakes wait for all Redis roles and event consumption to be ready. After genuine revocation, establish valid authentication before explicitly calling `socket.connect()`. Socket.IO applications should implement the lifecycle in [Protocol](PROTOCOL.md#connected-session-refresh), including the distinction between retryable and terminal server disconnects.

The gateway rechecks bridge Redis evidence periodically. PHP checks source sessions/tokens during authorization, command processing and fresh-ticket requests. For immediate account disablement, token deletion or custom logout flows, update application policy and call the corresponding facade invalidation hook. A user-level room with no channel callbacks does not continuously consult the original authentication store. Invalidation revokes existing bridge evidence; it does not itself prevent a user with still-valid source credentials from obtaining a new authenticated session.

Socket.IO Pub/Sub fanout is not a durable per-gateway queue. A disconnected replica/browser can miss an event even when stream processing succeeds elsewhere. There is no built-in browser history, offline replay or global event ordering across producers/replicas. Applications requiring complete history should store it in the business database and fetch by cursor/version after interruption.

## Retention and pruning

The package registers hourly `socket-bridge:prune` when `retention.automatic` is enabled, which is the default. **Laravel's scheduler must be running**; installing the package does not create an operating-system scheduler service.

```bash
php artisan socket-bridge:prune --dry-run
php artisan socket-bridge:prune --limit=1000
```

| Configuration under `socket-bridge.retention` | Default | Eligibility |
| --- | --- | --- |
| `streams_seconds` | 86,400 / one day | Entries older than the retention cutoff and safe for every existing consumer group. |
| `dead_letters_seconds` | 604,800 / seven days | Dead-letter entries older than the cutoff; group protection applies if groups exist. |
| `receipts_seconds` | 604,800 / seven days | Completed, old receipts with no unpublished result outbox entry. |
| `published_outbox_seconds` | 86,400 / one day | Published rows older than the cutoff. |

Stream pruning atomically examines all group delivery cursors and oldest pending IDs, then trims only entries below the resulting safe boundary. Main streams with no consumer group are preserved. A stopped or forgotten consumer group can deliberately hold back cleanup; inspect it before making a manual administrative decision. Never run blanket `XTRIM MAXLEN`, delete pending entries or flush a shared Redis database as a maintenance shortcut.

Each run is bounded to 1,000 eligible entries per stream/table by default, configurable with `--limit` up to 10,000. High-volume applications must increase the limit and/or schedule more frequent runs so cleanup capacity exceeds production rate. An age setting is not a hard storage cap: pending work, inactive groups, unpublished results and bounded batches can retain records longer. Monitor Redis memory and database growth.

Ticket/session/rate-limit keys use TTLs. User access-version keys are retained because removing them could make revoked evidence valid again. Maintenance does not erase user versions. Expiring worker/gateway heartbeats disappear automatically after crashes.

## Dead letters and replay

Inspect failures before replaying them:

```bash
php artisan socket-bridge:failed commands --limit=20
php artisan socket-bridge:failed events --limit=20
php artisan socket-bridge:failed commands --replay=1900000000000-0
```

The replay argument is a **dead-letter Redis entry ID**, not the command UUID. Replay preserves the original envelope UUID and authorization evidence. Commands require a current matching session, valid original shape and an unexpired deadline within the receipt retention window. Malformed or expired work is rejected. Successful command receipts and terminal business rejections remain intact; only an exhausted transient `command.failed` receipt can be released for another execution attempt.

A replay marker suppresses repeated operator replay while it is retained, and a short lock prevents concurrent replay operations. The stream append and marker write are separate, so a crash between them can enqueue a duplicate. Normal command receipt/event-ID semantics still apply. Keep dead letters long enough to investigate, and retain application-level audit records when longer history is needed.

Replaying an event emits its historical payload to currently subscribed recipients. It is not a reconstruction of who was entitled to receive it originally. Validate that replay still makes sense for the application's policy and data before using it. Server-requested room joins are authorized again; command result replays require current session evidence and still target the original socket identity.

## Operational checks

Run the gateway, command consumer, outbox worker and any Laravel queue workers under supervision. Restart long-running processes when deploying changes. Keep command execution time below `command_claim_idle_ms`, or raise that setting; otherwise another consumer can reclaim an in-flight entry. Database locks protect committed command work, but overlapping attempts still consume resources.

```bash
php artisan socket-bridge:status --json
php artisan socket-bridge:doctor --operational
```

Operational status combines gateway and PHP-worker heartbeats, stream lengths, pending counts, group lag, dead-letter counts and unpublished outbox age. A healthy heartbeat proves recent process activity, not that every command will succeed. Nonzero/unknown lag and increasing old outbox age need inspection even if all processes are present. Without `pcntl`, long blocking PHP handlers can outlive the normal heartbeat TTL and appear unavailable; the next worker-loop update refreshes it.

`/health/live` indicates that the gateway HTTP process is running. `/health/ready` additionally checks Redis readiness and initialized event consumption. It does not call Laravel or prove PHP-worker availability. Use operational checks for the full delivery path and a business-specific synthetic transaction where stronger service-level monitoring is required.

## Validation scope

The repository includes real Redis lifecycle tests, PHP transaction/concurrency tests, release-installation checks and a bounded recovery soak. The soak command is:

```bash
SOCKET_BRIDGE_TEST_REDIS_URL=redis://127.0.0.1:16389/0 \
  npm run test:soak --prefix gateway
```

Build the gateway before running it. It creates an isolated Redis prefix, starts 300 clients for 125 seconds by default, injects authorization unavailability, gateway Redis-connection loss and mass transport reconnects, then checks recovery and sampled gateway memory in a separate process. It cleans only its own Redis keys. This is a regression check, not a production capacity guarantee. See [Validation](VALIDATION.md) for the environment and actual release results.
