# Changelog

## 2.1.0 — 2026-09-21

- Immediately remove an existing channel grant when a repeated join receives a terminal denial, with protection against stale concurrent responses.
- Preserve numeric-key and empty JSON objects across commands, stored results, outbox publication and replay.
- Reconcile presence periodically after gateway crashes, with bounded work per pass.
- Run cleanup every minute with configurable row limits, batch sizes and time budgets; expose cleanup progress and retention lag.
- Export pending ACKs, response outcomes and end-to-end command latency in Prometheus.
- Add `socket-bridge:probe` to verify authenticated Socket.IO → Redis → PHP → outbox → ACK in Native and Docker installations.
- Extend recovery validation with final ACK-drain checks and a separate duration-limited memory assessment for 40-minute runs.

Run the package upgrade command and any outstanding migrations, then restart gateway and PHP workers. Review [JSON compatibility](docs/RELIABILITY.md#json-shape-compatibility) when handlers accept empty or numeric-key objects, and [diagnostics](docs/DIAGNOSTICS.md) for the new roundtrip command.

## 2.0.0 — 2026-09-21

- Laravel 13 broadcasting, notifications, channel policies and `toOthers()` through a bundled NestJS / Socket.IO gateway.
- Named Socket.IO events routed through Redis Streams to Laravel command handlers, with business results returned as acknowledgements.
- Transactional command receipts, validation details, duplicate-command protection and durable outbox publication.
- Single-use authentication tickets, session refresh, renewable channel authorization, presence and access revocation.
- Native and Docker installation with plain PHP, Sail, Herd and Valet profiles, configuration preview and local TLS support.
- Worker restart and resource limits, production process/proxy templates, protected metrics and operational diagnostics.
- Pending recovery, dead letters, replay and safe scheduled retention for Redis and database records.
- Composer archive verification, independent Laravel integration tests and multi-gateway fault validation.

Gateway replicas sharing one Redis namespace must run the same release. See [Installation](docs/INSTALLATION.md), [Socket.IO integration](docs/SOCKET_IO.md) and [Reliability](docs/RELIABILITY.md).
