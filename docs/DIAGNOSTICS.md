# Diagnose the full command path

`socket-bridge:probe` opens a real authenticated Socket.IO connection and sends a diagnostic command through Redis Streams, a Laravel command worker and the transactional outbox. It succeeds only after receiving the matching business ACK.

Use a dedicated monitoring user with your application's existing authentication. Put the HTTP headers required by `POST /socket-bridge/token` in a private JSON file. For a session-based application, include `Cookie` and `X-CSRF-TOKEN`; for a configured token guard, include `Authorization`.

```json
{
  "Authorization": "Bearer YOUR_MONITORING_USER_TOKEN"
}
```

```bash
chmod 600 /private/path/socket-bridge-probe.json
php artisan socket-bridge:probe --headers=/private/path/socket-bridge-probe.json
```

The command uses the selected installation mode. `--native` runs the bundled diagnostic using the prepared Node runtime; `--docker` runs it inside the running gateway container. Authentication values pass through stdin and are omitted from the result. Keep the headers file outside version control and remove or rotate expired credentials.

Optional flags:

| Option | Purpose |
| --- | --- |
| `--json` | Machine-readable result; exit code `0` on success, `1` on failure. |
| `--timeout=30` | Whole roundtrip deadline in seconds, from 1 to 300. |
| `--laravel-url=https://app.example.com` | Reachable base URL for the ticket endpoint. |
| `--gateway-url=https://realtime.example.com` | Socket.IO URL reachable from the selected runtime. |

For Docker, URL overrides must be reachable **inside the gateway container**. TLS certificate verification stays enabled and uses the configured additional CA where applicable. The configured route prefix and first allowed browser origin are used automatically.

The built-in command name is `socket-bridge.probe`. It uses normal session validation, rate limits, receipts and outbox publication. It returns a nonce without changing application business data. Each run creates ordinary receipt/outbox records, handled by the configured retention policy. Keep this command name available if you use the diagnostic.

Failures distinguish ticket rejection/unavailability, Socket.IO connection failure, and ACK timeout or rejection. A timeout does not cancel work already queued. Check command/outbox workers, stream lag and dead letters when connectivity succeeds but the ACK does not arrive.

Use `socket-bridge:doctor --probe --operational` for configuration, signed HTTP callback and worker heartbeat checks; use `socket-bridge:status --json` and Prometheus for ongoing backlog and cleanup monitoring. The roundtrip probe adds direct evidence that the command pipeline can complete for the monitoring user.
