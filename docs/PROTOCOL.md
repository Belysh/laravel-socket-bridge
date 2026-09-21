# Socket.IO and Redis protocol

Implementation contract for package **2.0.0** (`belysh/laravel-socket-bridge`, PHP namespace `SocketBridge`). Each Laravel project runs its own NestJS/Socket.IO gateway. Redis Streams are mandatory; Docker is optional. Requirements: Laravel 13, PHP 8.3+, standalone Redis 7+, Node 24. Native macOS/Linux; Windows through WSL2 or Docker. Redis Cluster is not supported in this release.

## Redis transport

The dedicated package connection is derived from Laravel's configured Redis connection, with client prefixing, serialization and compression disabled. `socket-bridge.prefix` is the complete namespace; its default includes the application slug, environment and application-key fingerprint. All keys below are `${prefix}:<suffix>`.

Normal stream entries contain one field, `envelope`, containing JSON. Streams `events` and `commands` use consumer groups `gateways` and `laravel`, created at `0`. Consumer names are unique per process. `XAUTOCLAIM` recovers pending entries after the configured idle period, 30 seconds by default. Set that period above the longest expected processing time to avoid overlapping active work and retries. `XACK` clears pending status; it does not delete the entry.

The gateway uses one competing events group and broadcasts through the Socket.IO Redis adapter, whose key is `${prefix}:adapter`. Stream ACK follows dispatch and Redis acceptance of adapter publications. Ordinary `socket.emit` events and controls additionally wait for participating adapter peers to acknowledge local dispatch or control handling. This does **not** confirm browser receipt or include a replica disconnected from Pub/Sub. Missing peer responses cause retries and may duplicate delivery to peers that already handled the event. Adapter-connection loss triggers recoverable browser disconnects and blocks new handshakes until Redis roles are ready. Fanout is not a durable per-replica log. All replicas sharing a namespace must use the same gateway release.

Invalid gateway envelopes go directly to `dead:events`; transient failures go there after five attempts by default. The JSON wrapper is `{source_id,failed_at,attempts,error:{code,message},envelope}`, where `envelope` is the parsed original or malformed raw string. Copy, original ACK and attempt-counter deletion share one Lua operation.

Invalid commands go to `dead:commands`. Valid authentication/validation rejections produce normal command results. Transient command failures remain pending; at the retry limit, Laravel persists a `command.failed` result before dead-lettering and ACKing. If result persistence fails, the command stays pending. The command dead-letter wrapper is `{source_id,raw,reason,failed_at}`. Its copy and ACK are separate operations, so a crash can duplicate a dead letter. Neither wrapper is itself a versioned application envelope.

Maintenance, replay and the limits of retention are described in [Reliability](RELIABILITY.md).

## Stream envelopes

Internal Redis envelopes contain `v:1`, `id` (UUID), `type`, and `created_at` (ISO 8601 UTC).

| Type | Additional fields and meaning |
| --- | --- |
| `socket.emit` | `event`, nonempty `rooms` array, object `payload`, optional `except_socket`, optional `except_user`. Client receives `emit(event, payload, {id,created_at,v:1,channels})`; `channels` contains only canonical target channels this recipient belongs to, excluding internal user/session rooms. |
| `socket.command` | `command`, object `payload`, server-derived `context:{user_id,session_id,socket_id,request_id}`, `expires_at` in Unix seconds. Envelope `id` is the command ID. |
| `socket.command.result` | `command_id`, `session_id`, `socket_id`, `user_id`, `request_id`, `result:{ok,data?,error?}`. Error is `{code,message,details?}`; successful handler data is an object. |
| `socket.disconnect_user` | `user_id`, optional `reason`. PHP increments the user's access version before publishing. |
| `socket.auth.invalidate` | `user_id`, optional `reason`. PHP increments the user's access version before publishing. |
| `socket.disconnect_session` | `session_id`, optional `reason`. PHP deletes bridge session evidence before publishing. |
| `socket.leave_room` | `user_id`, `room`. Removes current membership without changing channel policy. A later explicit join or client reconnect can authorize it again. |
| `socket.join_room` | `user_id`, `room`. Server request; Laravel channel authorization is still required. |

The result completes a pending Socket.IO acknowledgement only when socket ID, session ID, user ID and the server-generated request ID match. The request ID identifies one transport attempt; it is separate from the retained command ID and never supplied by the browser. Late results cannot acknowledge a newer attempt. Reconnecting creates a new socket ID. Resubmit a command with the same ID, name and payload in the same authenticated session to request its persisted result for the new socket. Input changes produce `command.id_conflict`. See the retention boundary in [Reliability](RELIABILITY.md#command-idempotency).

Business event names match `^[a-zA-Z0-9_.:-]{1,200}$`. They must not be Socket.IO reserved names, start with `bridge.`, or equal the control events `room:join`, `room:leave`, `session:refresh` or the reserved name `command`. Canonical client channels match `^[a-zA-Z0-9_.:-]{1,200}$` and cannot start with `__`. Laravel names are preserved: `PrivateChannel('chat.1')` becomes `private-chat.1`; `PresenceChannel('chat.1')` becomes `presence-chat.1`. Facade string arguments are exact names, with no implicit private prefix.

Trusted user/session targets are `__user:<sha256(user_id)>` and `__session:<sha256(session_id)>`. Clients cannot request these rooms. Physical client rooms use `__channel:<canonical-name>` to avoid collisions with Socket.IO's socket-ID rooms. `toTenant()` targets `private-tenant.<id>`; the application must authorize and join that channel.

Payloads are JSON objects, up to 65,536 UTF-8 JSON bytes by default, excluding routing metadata. Empty PHP payload arrays and empty result data encode as `{}`. Emissions allow at most 1,000 rooms; total event envelopes are bounded to the payload limit plus 262,144 bytes. `except_socket` excludes one Socket.IO ID; Laravel `toOthers()` needs the originating request's `X-Socket-ID`. `except_user` excludes all sessions for that user.

## Tickets and identity

`POST /socket-bridge/token` uses configurable Laravel middleware, default `web,auth`; the route prefix is configurable. Laravel returns `{token,expires_in,session_expires_at,url}`. The token is a random 32-byte hexadecimal value. Its SHA-256 key, `ticket:<hash>`, contains `{user_id,session_id,user_version,expires_at}` for at most 60 seconds by default, capped by session lifetime. `expires_at` describes the session, not ticket consumption time.

A connection sends `handshake.auth.token`. The gateway consumes the ticket with `GETDEL`, then verifies current bridge session evidence and access version. Every connection attempt needs a fresh ticket; tickets never belong in URLs. Ticket TTL governs consumption, not the socket's eventual lifetime.

`session:<session_id>` stores `{user_id,session_id,guard,provider,user_version,expires_at}` plus available source evidence. Session IDs are HMAC-SHA256 values derived from guard, user ID and underlying Laravel session or persisted-token identity using the internal secret. They are stable across transport reconnects; Laravel session rotation creates a different identity. Bridge TTL defaults to one hour, capped by Laravel session lifetime or source token expiration.

PHP reloads the user and invokes the application's `SessionAuthorizer`. For Sanctum, it rechecks token existence and expiration. Supported server-side Laravel sessions provide source session/login keys, allowing PHP to detect deletion or expiration; password authentication stores a keyed password fingerprint. Cookie-backed sessions cannot be reread independently and use expiry plus logout/invalidation hooks.

`user-version:<sha256(user_id)>` is a persistent integer, initially zero. User invalidation increments it; a session disconnect deletes bridge evidence. `Logout` and `CurrentDeviceLogout` revoke that session. `OtherDeviceLogout` invalidates the user's bridge version. These operations revoke existing bridge evidence; they do not ban future authenticated logins. Account disablement and credential revocation outside these hooks must update application policy and invoke the facade's invalidation hooks.

## Connected session refresh

On connection and after successful refresh the gateway emits:

```json
{"expires_at": 1900000000, "refresh_after_ms": 60000}
```

The event name is `bridge.session`; values above are illustrative. `expires_at` is Unix seconds. The gateway proposes refresh before the deadline: remaining lifetime minus the smaller of 60 seconds and half the remaining lifetime, with a minimum scheduling delay of 100 ms.

The client obtains a fresh ticket through the normal authenticated Laravel endpoint and sends `session:refresh` with `{token}`. The gateway consumes the ticket once and requires the **same user ID, session ID and access version** as the existing socket. Both the new evidence and existing identity must remain valid in Redis. Success updates only the connected identity's expiry, ACKs `{ok:true,expires_at}`, and emits the next `bridge.session` schedule. Writing a new Redis session record alone does not extend an existing socket.

An expired identity cannot be revived through refresh. The socket disconnects, and a new connection must authenticate again. Refresh never bypasses Laravel source evidence, access-version invalidation or channel policies.

Before an intentional live-socket disconnect, the gateway emits `bridge.disconnect` with `{code,retryable}`. `session_expired`, `redis_unavailable` and `slow_client` are retryable; revoked/deleted identities and invalid user versions are terminal (`unauthenticated` or `session_revoked`). A slow reader may not receive the notification before transport closure. The application should retry recoverable server disconnects with bounded backoff and request a fresh ticket. Ordinary transport failures use Socket.IO reconnection. After a terminal failure, establish valid authentication before calling `socket.connect()` again.

## Channel authorization and renewal

Internal `POST /socket-bridge/internal/authorize` accepts `{session_id,channel,socket_id}`. Its path follows the configured Laravel route prefix. Headers are `X-Socket-Bridge-Timestamp` (Unix seconds) and `X-Socket-Bridge-Signature` (hex HMAC-SHA256 of `timestamp + "\n" + raw body`), with default maximum skew 30 seconds. Laravel resolves authoritative identity and evaluates standard `Broadcast::channel` callbacks on the custom driver. The gateway only calls its configured URL/path, rejects redirects and uses a five-second timeout.

A successful response is `{allowed:true,expires_in:30,member?:{id,info}}`. Private/presence channels deny without a callback; public channels still require an authenticated socket. Each requested channel is authorized. HTTP 403 denies the channel; 401/419 invalidates the session. Network errors and other unsuccessful HTTP statuses are treated as temporary unavailability.

Room grants last at most 30 seconds by default. Renewal starts at a randomized 60–70% of the granted duration. A successful renewal preserves uninterrupted room membership. A temporary failure keeps the existing valid grant and retries with jittered exponential backoff, nominally 250 ms to 10 seconds. If renewal has not succeeded at expiry, the gateway removes membership and emits `bridge.subscription.suspended` with `{channel,code:"authorization_unavailable"}`. Intent is retained; a later valid grant restores membership and emits `bridge.subscription.restored` with `{channel}`.

Expiry checks run independently of outstanding HTTP authorizations, at intervals no greater than 100 ms under normal event-loop operation. Slow callbacks do not extend grants. A definite denial removes membership immediately when the response is processed and emits `bridge.subscription.revoked` with `{channel,error:{code,message}}`; automatic renewal stops. Explicit leave and disconnection invalidate outstanding renewal responses.

The gateway checks Redis identity during handshake, each client request and periodically (15 seconds by default). It fails closed with a retryable disconnect when periodic Redis verification is unavailable. Source credentials are rechecked in PHP during channel authorization, commands and ticket refresh. A socket receiving only automatic user-room events has no channel callback; applications must explicitly invalidate it when source access changes outside the provided logout hooks. The Redis polling interval is not a universal guarantee for source-credential revocation.

## Socket.IO client events

| Direction / event | Payload and response |
| --- | --- |
| Client → `room:join` | `{channel}`; ACK `{ok:true,channel}` or `{ok:false,error:{code,message}}`. |
| Client → `room:leave` | `{channel}`; same ACK shape. Cancels membership and outstanding renewal. |
| Client → `session:refresh` | `{token}`; ACK `{ok:true,expires_at}` or error ACK. |
| Client → application event, e.g. `order.pay` | Plain object payload, optional `{id:UUID}` argument, then Socket.IO ACK callback. ACK `{ok:true,id,data}` or `{ok:false,id,error:{code,message,details?}}` returns after Laravel processing. |
| Server → `bridge.session` | `{expires_at,refresh_after_ms}` as described above. |
| Server → `bridge.disconnect` | `{code,retryable}` before an intentional disconnect. |
| Server → `bridge.subscription.suspended` | `{channel,code}`; the grant expired during an outage. |
| Server → `bridge.subscription.restored` | `{channel}`; delivery may resume after reauthorization. |
| Server → `bridge.subscription.revoked` | `{channel,error}`; channel intent should be removed. |
| Server → `bridge.presence` | `{channel,members:[{id,info}]}`; snapshots deduplicate multiple tabs by authenticated user. |

Presence updates on joins, leaves, disconnects, suspension/recovery and changed member information. It reflects currently connected authorized sockets, not durable attendance history. A business-event ACK reports the Laravel result. The gateway waits up to 30 seconds by default, then returns `command.timeout` with the command ID; processing may still complete. Client timeouts and disconnects also have an unknown outcome. For retries, send an explicit `{id: UUID}` argument and retain the same ID, event name and payload. Default command expiry is the earlier of session expiry and five minutes from acceptance.

Event metadata includes `channels`, the canonical target channels this recipient belongs to. Internal user/session rooms and unrelated private targets are excluded. Use this metadata when the same event name is received from several channels. Terminal errors may include `error.details`, including Laravel validation's `details.fields`.

Only one acknowledgement may be pending for a command ID on the same socket. A concurrent emission with that ID returns `command.pending` without cancelling the original attempt. Pending-acknowledgement limits are separate from command expiry and Redis retention.

See [Socket.IO integration](SOCKET_IO.md) for authentication, direct event emission, acknowledgements and session refresh.

## Gateway environment
The Artisan launcher derives values from Laravel configuration. These are process environment names; separately deployed containers need matching settings. `SOCKET_BRIDGE_URL` is the browser-facing URL, separate from the listening address and internal Laravel URL.

| Variable | Default / requirement |
| --- | --- |
| `SOCKET_BRIDGE_PREFIX` | Required; 1–180 characters from `a-zA-Z0-9_.:-`. Use no trailing colon. |
| `SOCKET_BRIDGE_SECRET` | Required server secret, at least 32 characters. |
| `SOCKET_BRIDGE_REDIS_URL` | Required redis:// or rediss:// URL, including database and credentials when needed. |
| `SOCKET_BRIDGE_LARAVEL_URL` | Required HTTP(S) base URL reachable from the gateway. |
| `SOCKET_BRIDGE_AUTHORIZE_PATH` | `/socket-bridge/internal/authorize`; launcher derives it from Laravel's route prefix. |
| `SOCKET_BRIDGE_HOST` / `SOCKET_BRIDGE_PORT` | `127.0.0.1` / `6001`. |
| `SOCKET_BRIDGE_TLS_CERT` / `SOCKET_BRIDGE_TLS_KEY` | Optional PEM file paths; both required together. Unreadable/invalid files fail startup. |
| `SOCKET_BRIDGE_ORIGINS` | Comma-separated exact browser origins, including ports; no wildcard. Artisan requires at least one. Native clients without an Origin header still need a valid ticket. |
| `SOCKET_BRIDGE_TRANSPORTS` | `websocket`; optional polling requires sticky sessions for multiple gateways. |
| `SOCKET_BRIDGE_INSTANCE_ID` | Generated UUID; each process also has a unique consumer suffix. |
| `SOCKET_BRIDGE_CLAIM_IDLE_MS` | `30000`. |
| `SOCKET_BRIDGE_AUTH_CHECK_MS` | `15000`, for bridge Redis identity. |
| `SOCKET_BRIDGE_ROOM_LEASE_SECONDS` | `30`, maximum accepted grant duration. |
| `SOCKET_BRIDGE_AUTH_TIMEOUT_MS` | `5000`. |
| `SOCKET_BRIDGE_MAX_PAYLOAD_BYTES` | `65536`, allowed range 1,024–1,048,576. Keep PHP and gateway limits aligned. |
| `SOCKET_BRIDGE_MAX_ROOMS` / `SOCKET_BRIDGE_MAX_CONNECTIONS` | `100` per socket / `10000` per gateway. |
| `SOCKET_BRIDGE_RATE_LIMIT` / `SOCKET_BRIDGE_RATE_WINDOW_MS` | `60` requests per authenticated session / `10000`ms. Connection attempts per peer address allow five times the limit; maximum four concurrent requests per socket. |
| `SOCKET_BRIDGE_COMMAND_ACK_TIMEOUT_MS` | `30000`, range 100–300000; waiting timeout does not cancel work. |
| `SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS` | `32` per socket, range 1–1000. |
| `SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS_TOTAL` | `10000` per gateway, range 1–1000000. |
| `SOCKET_BRIDGE_MAX_ATTEMPTS` | `5` gateway attempts; PHP commands use command_max_attempts. |
| `SOCKET_BRIDGE_COMMAND_TTL_SECONDS` | `300`. |
| `SOCKET_BRIDGE_METRICS_TOKEN` | Optional bearer token, at least 32 characters; enables the protected gateway `/metrics` endpoint. The Laravel launcher omits it when package metrics are explicitly disabled. |
| `SOCKET_BRIDGE_MAX_BUFFERED_BYTES` / `SOCKET_BRIDGE_MAX_BUFFERED_PACKETS` | `1048576` / `1000`; exceeding either outgoing-buffer threshold triggers a recoverable slow-client disconnect. Checks are periodic, not a strict allocation cap. |
| `SOCKET_BRIDGE_WATCH_STDIN` | Native launcher sets `1` to stop on its input pipe's EOF. |

GET `/health/live` reports a running server. GET `/health/ready` checks ready Redis connections, Redis PING and initialized event consumption; it does not probe Laravel HTTP or PHP worker health. Missing required configuration or unavailable startup Redis fails startup. SIGTERM/SIGINT and optional stdin EOF trigger cleanup, with a ten-second shutdown deadline. Production processes still require supervision.

## Health records

Each gateway writes `${prefix}:health:gateway:<instance_id>` every five seconds with a 15-second TTL. JSON fields are `updated_at` (ISO UTC), `protocol:1`, `instance_id`, `connections`, `subscriptions`, and process-local counters under `metrics` (`connected`, `disconnected`, `refreshes`, `renewals`, `authorization_retries`, `revoked`). Counters reset on process restart. Graceful shutdown deletes the key; a crash is detected by expiry.

PHP command/outbox workers write `${prefix}:health:worker:<role>:<id>` with `protocol`, `role`, `id`, `pid`, `started_at`, `updated_at`. Defaults are the same five-second interval and 15-second TTL. The operational status command combines expiring heartbeats, stream lag/pending/dead-letter counts and unpublished outbox age. Gateway HTTP readiness alone does not prove healthy command processing.

## Delivery contract

See [Reliability](RELIABILITY.md) for transaction boundaries, command deduplication, retention, recovery and operational checks. Stream recovery does not guarantee browser delivery, global ordering, offline replay or exactly-once effects. Clients tolerate duplicates and refetch state after interruptions.
