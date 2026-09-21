# Changelog

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
