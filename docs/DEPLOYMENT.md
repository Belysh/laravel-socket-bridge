# Running Socket Bridge

## Native runtime

The Composer package includes the bundled gateway at `runtime/gateway.cjs`; the consuming application does not run `npm install` for the server. Node 24 is selected from `SOCKET_BRIDGE_NODE_BINARY`, a compatible local installation, or the package-managed download. The installer verifies a pinned SHA-256 checksum before extraction. Private runtimes live under Laravel storage and are ignored by Git. `--no-download` requires an existing compatible runtime; `--skip-runtime` prepares configuration only.

This release supports macOS arm64/x64 and Linux arm64/x64. Use Docker for other environments. Prepare the runtime during deployment, not on the first production request. File permissions must allow the CLI user to execute the selected runtime.

## Production processes

Run migrations and rebuild Laravel configuration as part of deployment. Supervise these independent foreground processes:

```bash
php artisan socket-bridge:start --no-download
php artisan socket-bridge:consume
php artisan socket-bridge:outbox
php artisan queue:work
```

Use systemd, Supervisor or your hosting platform's process manager to restart them after failures and releases. `socket-bridge:dev` is a convenience process group for local development. `socket-bridge:doctor` validates the package configuration, Redis and database tables. Gateway liveness/readiness endpoints are `/health/live` and `/health/ready`.

Workers support `--once`, `--stop-when-empty`, `--sleep=1` and `--max-messages=1000`. The consumer also accepts `--consumer=unique-name`; use distinct names per concurrently running process. Keep command runtime below the configured pending-claim timeout, or increase `command_claim_idle_ms` to avoid simultaneous retry attempts. The database receipt lock still serializes committed command work, but retries can consume resources.

Workers recycle after `workers.max_time` seconds (default 3600) or `workers.memory_mb` MiB (default 128). Override with `--max-time=7200 --memory=256`; zero disables a limit. Memory is PHP allocated memory, not total RSS. Limits are cooperative: the current command transaction or outbox publication finishes before exit. A process manager must restart successful exits too (`Restart=always` / `autorestart=true`). `socket-bridge:dev` disables these limits for its local process group.

Publish editable production templates into the application:

```bash
php artisan vendor:publish --tag=socket-bridge-deploy
```

`deploy/socket-bridge/` contains Supervisor, systemd, Nginx, Caddy and Prometheus examples. Choose Supervisor **or** systemd, set the application directory, PHP executable and OS user, then install the chosen configuration using your normal server provisioning. Publication does not enable system services or change your server. Supervisor starts two consumers and two outbox workers; the systemd templates can be enabled as `socket-bridge-commands@1`, `socket-bridge-commands@2`, `socket-bridge-outbox@1`, `socket-bridge-outbox@2` plus `socket-bridge-gateway`. Keep stop timeouts longer than the longest handler; forced OS termination remains a crash/recovery scenario.

After deploying PHP changes, switching the application symlink and rebuilding Laravel configuration:

```bash
php artisan socket-bridge:restart
# Optional: recycle only one worker type.
php artisan socket-bridge:restart --only=commands
```

The restart generation is stored persistently in the application's Redis namespace. Workers check it at most once per second between operations and during idle waits, finish the active operation, and exit successfully. Newly started workers adopt the current generation. Claimed but unprocessed commands stay pending until the replacement reclaims them after `command_claim_idle_ms`. Keep old and new deployment namespaces/Redis settings equal while requesting restart. Restart the gateway through its process manager separately; restart normal Laravel queue workers using `queue:restart`. The package command does not launch replacement processes itself.

The namespace (`SOCKET_BRIDGE_PREFIX`), Redis connection/database and internal secret must match across replicas of one application. Use distinct namespaces/secrets for different applications. Redis must be a trusted private service; its credentials grant transport authority. Do not expose Redis to browsers or the public internet.

Run Laravel's scheduler (`php artisan schedule:work` locally, the standard scheduler cron in production). The package registers `socket-bridge:prune --no-interaction` every minute with overlap protection. Disable registration via `retention.automatic=false` if you schedule maintenance elsewhere.

| Retention setting | Default | Protects |
| --- | --- | --- |
| `streams_seconds` | 86400 | All consumer groups' unread and pending entries |
| `published_outbox_seconds` | 86400 | Unpublished rows are never pruned |
| `receipts_seconds` | 604800 | Incomplete receipts and unpublished result outbox rows |
| `dead_letters_seconds` | 604800 | Failed records remain inspectable until this cutoff |

These are age thresholds, not hard memory limits. A stalled group deliberately prevents unsafe trimming. Each run scans up to 10,000 records per resource in batches of 100, with a ten-second cooperative time budget. Configure `SOCKET_BRIDGE_PRUNE_FREQUENCY_MINUTES`, `SOCKET_BRIDGE_PRUNE_LIMIT`, `SOCKET_BRIDGE_PRUNE_BATCH_SIZE` and `SOCKET_BRIDGE_PRUNE_MAX_SECONDS` to match your volume; see [cleanup budgets and progress](RELIABILITY.md#retention-and-pruning). The receipt retention period defines the deduplication window. An old ID retried after its receipt is removed can represent new work; application idempotency is needed beyond that window.

```bash
php artisan socket-bridge:doctor --probe --operational
php artisan socket-bridge:status --json
php artisan socket-bridge:prune --dry-run
php artisan socket-bridge:failed events --limit=20
php artisan socket-bridge:failed commands --replay=REDIS_ENTRY_ID
```

Status reports gateway and both PHP worker heartbeats, each expiring after 15 seconds by default, plus stream lag/pending, dead letters and oldest outbox age. `healthy` means those processes have live heartbeats; use the reported queue metrics for your own latency/backlog alert thresholds. A busy long-running handler can exceed the heartbeat TTL; keep operations bounded or increase the health timeout with care. Readiness alone is not proof of business processing.

The `cleanup` section reports the last real cleanup run: records scanned/deleted, remaining candidates, retention lag and work protected from removal. Dry runs leave this snapshot unchanged. Alert when its completion timestamp stops advancing, its time budget is repeatedly exhausted, or retention lag keeps growing. Protected work requires recovering its consumers or outbox publication before it can be removed.

## Metrics and latency

Set a separate random `SOCKET_BRIDGE_METRICS_TOKEN` of at least 32 characters to enable protected metrics, synchronize Docker configuration when applicable, then restart processes. The Laravel endpoint is `GET /socket-bridge/metrics` (under the configured route prefix); each gateway exposes `GET /metrics`. Send `Authorization: Bearer <token>`. Without configuration these HTTP routes return 404. Use HTTPS or a trusted private monitoring network. Do not reuse the internal bridge secret. The reverse-proxy templates expose only Socket.IO; route gateway metrics privately.

`php artisan socket-bridge:metrics` prints the Laravel exporter locally. To collect counters without enabling its HTTP endpoint, set `SOCKET_BRIDGE_METRICS_ENABLED=true` and leave the token absent. PHP telemetry errors do not fail command processing or roll back outbox work.

The PHP exporter includes processed/failed/retried/dead-letter command counters, published/failed outbox counters, `command_duration_seconds` and `outbox_lag_seconds` histograms, worker/gateway counts and backlog gauges. Cleanup gauges include `socket_bridge_cleanup_last_run_timestamp_seconds`, `socket_bridge_cleanup_time_limit_reached` and per-resource `socket_bridge_cleanup_deleted`, `socket_bridge_cleanup_has_more` and `socket_bridge_cleanup_retention_lag_seconds`. These describe the last completed real run, not cumulative totals or exact backlog counts. Histograms use cumulative buckets from 5 ms to 60 seconds plus infinity. Duration covers one processing attempt including receipt lookup and acknowledgement; outbox lag is creation-to-successful-publication age. Counters describe observed attempts and may undercount during telemetry outages or count receipt replays; use business tables for accounting.

PHP counters are shared by all workers of a namespace in one Redis hash and expire after 24 hours without updates (`metrics.ttl_seconds`). Scrape the Laravel aggregate **once per namespace**; scraping every PHP replica and summing would double-count. Scrape each gateway separately for its process-local metrics. Avoid user/channel labels. Example PromQL for a five-minute command p95:

```promql
histogram_quantile(0.95, sum by (le) (rate(socket_bridge_command_duration_seconds_bucket[5m])))
```

Start with alerts for missing worker roles, growing pending/lag/dead letters and outbox age above your application's latency budget. Unknown Redis group lag is exported as `NaN`. Metrics and heartbeats complement business checks; they cannot prove every browser received a broadcast. See [scaling and fault validation](SCALING.md).

Replay preserves the original envelope identity. Command replay revalidates the session and reruns handler authorization; successful receipts are never erased. A retry-limit failure may be reset only when the rolled-back operation can be retried. Export or investigate dead letters before their configured expiry.

## Docker networking

The installer generates a Compose file that builds the bundled runtime from the installed Composer package. It does not rely on an unpublished container image. It also creates private `.env.socket-bridge`, and, when requested, Redis configuration with generated credentials and an AOF-backed volume.

```bash
php artisan socket-bridge:install --mode=docker --redis=generated
php artisan migrate
php artisan socket-bridge:dev --docker
```

Docker runs the gateway and optionally Redis. The Artisan command/outbox/queue workers run in the Laravel environment where the command is invoked. If Laravel itself runs in Sail or another container, run those workers there and configure service-to-service addresses accordingly.

There are three distinct addresses:

| Setting | Must be reachable by |
| --- | --- |
| `SOCKET_BRIDGE_URL` | The browser |
| `SOCKET_BRIDGE_LARAVEL_URL` / Docker callback setting | The NestJS gateway |
| Laravel Redis connection / `SOCKET_BRIDGE_REDIS_URL` | PHP workers; Docker env separately contains the gateway connection |

`localhost` inside a container is that container. For Laravel running on the host, the generated Compose configuration maps callbacks to `host.docker.internal`. Bind a local PHP development server to a reachable interface, for example `php artisan socket-bridge:dev --docker --serve --http-host=0.0.0.0`. Set the callback port to match that server. When both services are containerized, use their Docker network service names and ensure they share a network; the installer cannot infer arbitrary custom networks.

Generated files can be reviewed and customized before startup. Reinstalling preserves custom Compose and generated Redis credentials. After changing Laravel configuration, run `php artisan config:clear` and `php artisan socket-bridge:configure --docker` to synchronize the gateway environment. Secret rotation requires the explicit `--sync-secret` option. Review the reported private backup, rebuild configuration cache, and restart the container and workers.

Docker-generated Redis is bound to the loopback host interface by default. The PHP connection URL written into `.env` is intended for PHP on the host; for PHP in another container use the Redis service name on a shared network. Existing Redis settings are never replaced globally.

## HTTPS and origins

An HTTPS application must use a secure WebSocket URL. Terminate TLS at your existing reverse proxy and forward WebSocket upgrades to the gateway, or set `SOCKET_BRIDGE_TLS_CERT` and `SOCKET_BRIDGE_TLS_KEY` to readable PEM files for HTTPS. For Herd/Valet use certificates trusted by the browser for the gateway hostname; the package does not modify your system certificate trust. `SOCKET_BRIDGE_ORIGINS` is a comma-separated allowlist of exact browser origins, including ports. It is separate from the internal Laravel callback URL. The installer profiles can prepare TLS mounts and hostname-aware healthchecks for Docker. Review detected addresses with `socket-bridge:install --preview`; details are in [installation](INSTALLATION.md).

Set `SOCKET_BRIDGE_URL=https://your-gateway-host:6001` when using direct HTTPS. Both certificate settings are required. If Laravel itself uses a local CA, Node must trust that CA for authorization callbacks; a native process can use `NODE_EXTRA_CA_CERTS=/absolute/path/to/local-ca.pem`. In Docker, explicitly mount the required certificates read-only and configure paths inside the container.

The default transport is WebSocket. If you enable Socket.IO HTTP polling and run more than one replica, use sticky sessions in your load balancer. Gateways share a Redis adapter for broadcasts and control operations.

The internal authorization endpoint is authenticated with a timestamped HMAC over the exact request body. Keep system clocks synchronized and the secret private. Laravel still evaluates its own channel policy using the authenticated session; the gateway cannot accept a user identity supplied by the browser.

## Upgrades

Deploy the gateway bundled with the Composer package. The protocol envelope is versioned (`v: 1`). Every gateway replica sharing a Redis namespace must run the same release. Coordinate replacement of all replicas during a major upgrade; mixed gateway versions are unsupported. Restart PHP workers after upgrades; clients reconnect and resync application state. Published package config is not overwritten during reinstall, so review newly introduced settings against the package default config.


Use `php artisan socket-bridge:upgrade --native` (or `--docker`) after updating Composer. It backs up configuration and the installed lock file, prepares a compatible runtime, and saves new default configuration for review. Keep the previous deployment's lock file and configuration separately for rollback: a backup made after Composer update cannot recover the old package version. See [upgrade and rollback steps](INSTALLATION.md).
