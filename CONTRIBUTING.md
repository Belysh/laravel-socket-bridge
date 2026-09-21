# Contributing

Socket Bridge is a Laravel package with a bundled NestJS gateway. Composer ZIP
and checksum release artifacts are distributed through GitHub.

Use PHP 8.3+ with Laravel 13, Node 24 LTS, Composer 2, and a dedicated Redis 7+
instance. Never run integration tests against an application's production Redis.
Tests use unique key prefixes and must never call FLUSHDB or FLUSHALL.

```sh
composer install
npm ci --prefix gateway
npm run build --prefix gateway
export SOCKET_BRIDGE_TEST_REDIS_URL=redis://127.0.0.1:16389/0
npm test --prefix gateway
composer test
# Also verify native phpredis when the extension is installed:
SOCKET_BRIDGE_TEST_REDIS_CLIENT=phpredis php vendor/bin/phpunit --filter RedisStreamsTest
node scripts/collect-licenses.mjs
```

The Composer package lives at the repository root, the NestJS source in
`gateway/`. Integration tests use the standard `socket.io-client` development
dependency from the gateway.
`runtime/gateway.cjs` is the distributable compiled runtime; rebuild it when
gateway source changes. Consumers do not run npm or compile the gateway.

Protocol changes must update `docs/PROTOCOL.md`, PHP and gateway validation,
and cross-language tests. The shared Redis stream protocol is distinct from
Laravel's native queue payload format. Changes to auth must exercise revoked
sessions, denied rooms, and reconnects. Commands must preserve idempotency and
return business results through the acknowledgement of the emitted command.

Do not add a Composer plugin or networking/process startup to the service
provider. Installation is an explicit Artisan operation. Keep Docker optional
and Redis Streams mandatory. Native Windows is outside v1 support.

## Real Laravel end-to-end test

Start disposable Redis on localhost:16389, then run:

```sh
php scripts/setup-e2e.php
node scripts/e2e.mjs
```

Setup creates a fresh Laravel 13 application under ignored `.test-results/`,
installs this package using a Composer path repository, runs the actual installer
and migrations, and seeds test-only users/routes. Never copy that fixture into a
deployed application. The test starts PHP HTTP, Artisan gateway and worker
processes, verifies session auth, denied channels, native `toOthers()`, user
targeting, command results, transactional outbox, idempotence, reconnect and
revocation, then shuts down its processes. It uses ports 18092 and 16092.

Redis integration tests skip when `SOCKET_BRIDGE_TEST_REDIS_URL` is absent. CI
sets it and runs both the real Redis suites and the full Laravel fixture.

## Database concurrency and faults

Provide isolated `SOCKET_BRIDGE_TEST_MYSQL_URL` and `SOCKET_BRIDGE_TEST_PGSQL_URL`,
for example `mysql://bridge:password@127.0.0.1:3306/bridge` and
`pgsql://bridge:password@127.0.0.1:5432/bridge`. PDO drivers must be installed.
Run `php vendor/bin/phpunit --fail-on-skipped` with both URLs, Redis and
`SOCKET_BRIDGE_TEST_NODE_BINARY` pointing to Node 24 for the complete suite.
Tests create unique table/key prefixes and clean those resources only.

```sh
php scripts/docker-e2e.php
php scripts/build-release.php
SOCKET_BRIDGE_SOAK_DURATION_MS=900000 npm run test:soak --prefix gateway
```

The soak duration defaults to 125 seconds with 300 connections. The longer
command runs 15 minutes. It injects authorization, Redis-connection and mass
transport failures, verifies recovery and records gateway memory separately
from the load generator in `.test-results/gateway-soak.json`.

See [release verification](docs/VALIDATION.md) for installed archive tests,
compatibility and the limits of the recorded validation.


For the network layout used by Sail, `scripts/container-network-smoke.php` runs
Laravel/workers, gateway, Redis and a client in four separate containers on one
private bridge network with service DNS and no published host ports. Prepare the
normal fixture first, then set `SOCKET_BRIDGE_NETWORK_PHP_IMAGE` to your Laravel
PHP 8.3+ CLI image with PDO SQLite and required extensions. Optionally override
`SOCKET_BRIDGE_NETWORK_NODE_IMAGE` (default Node 24.21.0). The script copies its
fixture to a separate temporary directory and removes only its own containers,
network and files. This validates the networking topology, not Sail's installer.
