# Install, configure and upgrade

The Composer package contains the NestJS gateway bundle. Native mode uses Node 24 installed on the machine or downloads a checksummed private runtime. Docker mode builds the same bundle. Laravel handles authentication, commands, receipts and the outbox in the application's PHP environment.

```bash
composer require belysh/laravel-socket-bridge:^2.0
php artisan socket-bridge:install --profile=auto --preview
```

The preview shows the browser application URL, browser Socket.IO URL, exact origins, gateway-to-Laravel callback, Redis hostname/database and Docker network. Credentials are hidden. `--preview` does not create files, download a runtime, start containers, or change trust settings.

Profiles are `auto`, `php`, `sail`, `herd`, and `valet`. Detection reads project Compose files, `herd.yml`, `.valetrc`, linked/parked sites, existing certificates and, when available, read-only Docker metadata. A profile is a configuration proposal; it does not establish that services are running. Projects containing both Sail and Herd files select Sail automatically; choose `--profile=herd` when that is the environment you use.

Existing `SOCKET_BRIDGE_*` values and custom published configuration take precedence over defaults. Override individual values explicitly with `--app-url`, `--gateway-url`, `--origins`, `--laravel-url`, `--network`, `--ca-cert`, `--tls-cert` and `--tls-key`. Installation never rewrites `APP_URL`, ordinary `REDIS_*` settings, application channels or existing Compose files. The default broadcaster changes to `socketio`; use `--keep-broadcaster` to preserve the current one. Private backups are created under `.socket-bridge/backups` before changes.

## Ordinary PHP / artisan serve

```bash
php artisan socket-bridge:install --profile=php --mode=native
php artisan migrate
php artisan socket-bridge:dev --native --serve --http-port=8000
```

For an unchanged `APP_URL=http://localhost`, this profile proposes browser/callback `http://127.0.0.1:8000`, origin `http://127.0.0.1:8000`, and gateway `http://127.0.0.1:6001`. Open that exact application URL. `localhost`, `127.0.0.1`, schemes and ports are distinct origins. A custom `APP_URL` is retained. To use another local server:

```bash
php artisan socket-bridge:install --profile=php --mode=native \
  --app-url=http://localhost:9000 --laravel-url=http://localhost:9000 \
  --gateway-url=http://localhost:6001 --origins=http://localhost:9000
```

Native mode requires Redis 7+. Keep your working Laravel Redis connection; if PhpRedis is unavailable, install `predis/predis:^3.0` and set `REDIS_CLIENT=predis`.

After Laravel, Redis, the gateway and workers are running, verify them:

```bash
php artisan socket-bridge:doctor --native --probe --operational
```

`--probe` checks a signed callback from the gateway's runtime context, including the authorization route and TLS. It uses a nonexistent session and does not run a command handler. `--operational` requires live gateway/command/outbox heartbeats and reports pending entries, lag, dead letters and oldest outbox age. Browser delivery still needs a client integration test. Installer/configure/upgrade `--check` opts into the connection/probe checks immediately; it can fail when services or migrations are not ready and does not claim successful installation means successful delivery.

## Docker gateway with PHP on the host

```bash
php artisan socket-bridge:install --profile=php --mode=docker --redis=existing
php artisan migrate
php artisan socket-bridge:dev --docker --serve --http-host=0.0.0.0 --http-port=8000
php artisan socket-bridge:doctor --docker --probe --operational
```

Plain HTTP and Redis loopback URLs become `host.docker.internal` in the private Docker environment. Host services must accept connections arriving from Docker; on Linux, binding only to `127.0.0.1` is insufficient. Existing Redis credentials and database numbers are retained. HTTPS and `rediss` loopback URLs are never automatically rewritten: use a reachable hostname with a matching trusted certificate.

For a separate password-protected Redis with AOF persistence, use `--redis=generated --redis-port=6380`. The installer creates its own volume and a dedicated PHP `SOCKET_BRIDGE_REDIS_URL`. Changing gateway mode does not delete or stop this Redis. Preserve it while work is pending, or deliberately migrate its data before changing that URL.

## Sail and a shared Docker network

Start the application's Sail services, then preview from its PHP environment:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan socket-bridge:install --profile=sail --mode=docker --preview
./vendor/bin/sail artisan socket-bridge:install --profile=sail --mode=docker --redis=existing \
  --network=my-project_sail
./vendor/bin/sail artisan migrate
```

Use the actual network shown by `docker network ls` or the preview. The installer recognizes Laravel services from Sail runtime/build/environment entries in `compose.yaml`, `compose.yml` or `docker-compose.*`. It reads the service's HTTP target port, browser published port, Redis service and common network. It uses an explicitly named network in Compose or `.env`'s `COMPOSE_PROJECT_NAME`, or inspects the running Laravel service's network. It does not invent a network from the directory name. If the network cannot be established, preview explains what is missing and installation requires `--network` or running Sail services. `--laravel-service` selects a service already present in that Compose file.

The generated gateway joins that external network, so callbacks can use `http://laravel.test` and Redis can use `redis:6379`. A custom HTTPS callback stays HTTPS with its original hostname; an internal service-name HTTP default never downgrades it. A default host-loopback Redis endpoint may be mapped to the observed Redis service for the gateway; PHP workers must retain a Laravel Redis connection reachable from their own environment. A generated Redis joins the shared network too, and the Sail-specific PHP override uses `socket-bridge-redis` rather than a container's own `127.0.0.1`.

From a **host terminal**, run:

```bash
docker compose -f compose.socket-bridge.yml up -d --build socket-bridge
./vendor/bin/sail artisan socket-bridge:consume
# Separate terminals or process supervision:
./vendor/bin/sail artisan socket-bridge:outbox
./vendor/bin/sail artisan queue:work
```

The normal Composer installation uses a relative `./vendor/belysh/laravel-socket-bridge` build context, so generating Compose inside Sail does not embed `/var/www/html` as a host path. Local Composer path repositories must also be accessible from the Docker host. No Docker socket is mounted into Sail by this package. Commands such as `socket-bridge:start --docker` and `doctor --docker --probe` require Docker CLI/daemon access; run them from a configured host PHP environment, or an existing deliberately configured container that already has that access. Plain operational checks can run inside Sail. Native gateway mode inside Sail is available but additionally requires Node and an exposed gateway port in the application's Sail service; existing Sail Compose is never edited automatically.

Run the signed probe and application integration checks from the environment you deploy. Custom Sail images and networks need their own verification. See the [Sail documentation](https://laravel.com/framework/docs/sail) for the application’s container lifecycle.

## Herd, Valet and local HTTPS

```bash
php artisan socket-bridge:install --profile=herd --mode=native --preview
php artisan socket-bridge:install --profile=herd --mode=native
# Or --profile=valet
```

The macOS detector checks Herd's `~/Library/Application Support/Herd/config/valet` and Valet's `~/.config/valet`: linked sites, parked paths, configured TLD and existing certificate files. `herd.yml` and `.valetrc` are project markers. A custom application URL takes precedence over a discovered name. Herd's `secured: true` keeps the proposed HTTPS URL even when certificate files are missing; the installer does not silently downgrade it. See [Herd project configuration](https://herd.mintlify.dev/docs/macos/sites/herd-yaml) and [Valet](https://laravel.com/framework/docs/valet).

A readable matching site certificate/private key can serve the gateway on the same hostname at its gateway port. An existing PEM CA file is passed to native Node through `NODE_EXTRA_CA_CERTS`. Nothing is added to system trust stores, and TLS verification remains enabled. When the conventional certificate location is unavailable, specify it:

```bash
php artisan socket-bridge:configure --native --profile=herd \
  --app-url=https://my-app.test --laravel-url=https://my-app.test \
  --gateway-url=https://my-app.test:6001 --origins=https://my-app.test \
  --ca-cert=/absolute/path/local-ca.pem \
  --tls-cert=/absolute/path/my-app.test.crt --tls-key=/absolute/path/my-app.test.key
```

An HTTPS browser requires an HTTPS gateway. If no gateway certificate/key is available, configure your existing TLS reverse proxy and its `--gateway-url`; an HTTPS URL alone does not create a proxy or certificate. CA validation is tested with a real local HTTPS callback: the configured certificate succeeds, and removing its trust fails.

Docker retains the callback's certificate hostname. For a detected local Herd/Valet site it adds a `hostname:host-gateway` mapping instead of replacing the hostname with an IP. Existing CA/certificate/key files are copied into `.socket-bridge/tls` and mounted read-only at `/run/socket-bridge/tls`. The parent `.socket-bridge` directory is private (`0700`); mounted files are readable by the unprivileged gateway process. Gateway HTTPS healthchecks verify the configured hostname and CA. Private certificate copies/backups must remain excluded from version control. Services bound only to host loopback may require local network configuration before Docker can reach them; always run the Docker callback probe after startup.

## Reconfiguration, upgrades and rollback

```bash
php artisan config:clear
php artisan socket-bridge:configure --docker --profile=auto --preview
php artisan socket-bridge:configure --docker --laravel-url=https://my-app.test
php artisan config:cache
```

Configuration synchronizes package-managed environment values and preserves custom keys/Compose. Secret rotation requires `--sync-secret`. Port changes, TLS mode/hostname changes, a new external network or new local-host mapping require reviewing the existing Compose ports, verified healthcheck, mounts or network declaration. The command stops with the needed change rather than overwriting custom infrastructure. Re-run synchronization and restart gateway/workers afterwards. Existing Compose files need `./.socket-bridge/tls:/run/socket-bridge/tls:ro` before adding CA/TLS configuration.

Before updating Composer, retain the deployed code/lockfile, configuration and normal database backup. Then:

```bash
php artisan config:clear
composer update belysh/laravel-socket-bridge --with-dependencies
php artisan socket-bridge:upgrade --native --no-download
# Or: php artisan socket-bridge:upgrade --docker
php artisan migrate --force
php artisan config:cache
```

Update the Composer version constraint first if it excludes the desired release. Upgrade validates the manifest, prepares the runtime, publishes only missing migration name suffixes and backs up configuration plus the currently installed lockfile. New defaults are supplied for review; existing published config is preserved. The package supplies missing nested gateway, install, worker, retention and metrics defaults after the configuration cache is rebuilt, while retaining explicitly configured values. Restart every supervised process and run `doctor --probe --operational` and the [authenticated roundtrip probe](DIAGNOSTICS.md). Rollback means restoring the prior release/lockfile/configuration and checking migration compatibility. Do not delete pending Redis work, receipts or volumes. A backup taken after Composer changed contains the new lockfile, so retain the pre-upgrade release separately.

For multiple gateway replicas, coordinate replacement so every replica sharing a Redis namespace runs the same version. Preserve pending Redis and database work. Review the Socket.IO event contract before a major upgrade, and expect applications to reconnect, rejoin channels and resynchronize during replacement.

## Reproduce integration checks

With development dependencies installed and the gateway built, `php scripts/docker-e2e.php` creates its own Laravel fixture and Docker gateway/Redis. It covers signed/operational doctor, auth, channels, immediate/queued broadcasts, sender exclusions, durable commands, deduplication, reconnect and revocation. It cleans only its own containers, network and Redis volume.

`scripts/container-network-smoke.php` separately tests Laravel inside a container with service-DNS callbacks and Redis, without publishing host ports. Supply a PHP CLI image with the Laravel extensions through `SOCKET_BRIDGE_NETWORK_PHP_IMAGE`; the Node image can be set with `SOCKET_BRIDGE_NETWORK_NODE_IMAGE`. Fixture preparation and output live under `.test-results`. Neither test installs Herd or Sail globally or changes system trust.
