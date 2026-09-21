# Verification and compatibility

## Supported environment

| Component | Contract |
| --- | --- |
| Laravel package | `belysh/laravel-socket-bridge`, PHP 8.3+, Laravel 13 |
| Gateway | Bundled NestJS / Socket.IO, Node 24 |
| Frontend transport | Standard `socket.io-client` 4.x |
| Redis | Standalone Redis 7+, Predis or PhpRedis |
| Runtime | Native macOS/Linux arm64/x64; Docker; Windows through WSL2/Docker |
| Transactional storage | MySQL, PostgreSQL or SQLite |

Deploy the gateway bundled with the Composer package. Gateway replicas sharing a Redis namespace must use the same release. Public contracts include the facade, command handlers/context, channel policies, configuration and documented Socket.IO events. The internal Redis envelope has its own version and is not the browser event payload.

## Required release checks

Run checks against the exact release commit. Results from an earlier build do not validate a changed command or authentication contract.

The [CI workflow](https://github.com/Belysh/laravel-socket-bridge/actions/workflows/ci.yml) covers PHP 8.3/8.4, real Redis, MySQL and PostgreSQL, both Laravel Redis drivers, gateway tests, native and Docker installation, and deployment templates. The integration suite uses an independent Laravel application with real cookie sessions, database queues, workers and Socket.IO connections.

Release verification must cover:

1. Fresh-ticket authentication, authorized channels and forbidden/internal room rejection.
2. Immediate and queued Laravel broadcasts, notifications, user targeting and `toOthers()`.
3. Named Socket.IO events reaching Laravel handlers and returning business acknowledgements.
4. Validation, authorization, command-ID conflicts, duplicate attempts and recovery after an unknown outcome.
5. Reconnect, channel reauthorization, session refresh, presence and revocation.
6. Signed callback probes, authenticated Socket.IO roundtrip probes, protected metrics and live gateway/worker diagnostics.
7. Installation of the actual Composer archive and the published Packagist release.

Separate database tests exercise concurrent consumers, receipt locks, outbox contention and process death at commit/publication boundaries. Transport duplicates after publication recovery are expected; repeated committed business mutations for the same retained command ID are not.

## Build and install the release archive

```bash
# Development dependencies and gateway build: see CONTRIBUTING.md.
php scripts/build-release.php
export SOCKET_BRIDGE_E2E_SOURCE=archive
export SOCKET_BRIDGE_E2E_APP="$PWD/.test-results/archive-app"
export SOCKET_BRIDGE_E2E_PREFIX=socket-bridge:e2e:archive
php scripts/setup-e2e.php
SOCKET_BRIDGE_E2E_DOCTOR=1 node scripts/e2e.mjs
```

`build-release.php` checks the manifest, bundled gateway and required archive files. It writes the Composer ZIP and checksums under ignored `.test-results/release/`. Local Composer repository metadata is a test fixture, not a release asset.

To verify public installation, use `SOCKET_BRIDGE_E2E_SOURCE=packagist`, `SOCKET_BRIDGE_E2E_VERSION=2.1.0` and a fresh fixture path. Inspect `composer.lock` to confirm the published version and GitHub source. A successful source checkout test cannot substitute for installing the release artifact.

## Release automation

A stable `vX.Y.Z` tag runs the reusable CI suite and creates a GitHub release containing the Composer ZIP and checksums. Packagist follows repository updates independently, so check the release commit before tagging it.

The optional extended scaling workflow records a bounded multi-process PostgreSQL/Redis run. See [Scaling](SCALING.md) for the load, faults, measurements and required assertions. Only a completed report with `final: true` and `passed: true` counts as a passing run; quote measurements together with the tested commit and environment.

## Limits of verification

A recovery soak is not a production capacity guarantee. Actual limits depend on handler latency, fanout, payloads, database/Redis configuration and hardware. Gateway dispatch does not acknowledge browser processing, and retained-heap measurements do not prove the absence of every leak.

Custom Sail/Herd/Valet networks and certificates need their own `doctor --probe` check. Redis Cluster, SQL Server, direct native Windows execution, durable browser replay and exactly-once external effects are outside this release. Read [Reliability](RELIABILITY.md) before deployment.
