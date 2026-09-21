# Security and trust boundaries

Supported security fixes target the latest 1.x release. Use [GitHub private vulnerability reporting](https://github.com/Belysh/laravel-socket-bridge/security/advisories/new) for sensitive reports. Include the affected version, reproduction and impact. Do not post credentials or exploit details in a public issue.

- Redis is trusted internal infrastructure; do not expose it to browsers or the
  public internet. Use authentication/TLS and a dedicated per-project prefix.
- Laravel is authoritative for identity, channel authorization and business
  commands. A room name supplied by the client is never an authorization grant.
- The public ticket endpoint uses the application's configured auth middleware.
  Keep CSRF protection for cookie-based authentication. API token applications
  can configure `api,auth:sanctum` and their guard/session resolver.
- Gateway-to-Laravel authorization calls are signed, timestamped requests over
  an explicitly configured URL. Set HTTPS when crossing untrusted networks.
- One-use connection tickets expire quickly. Active sessions and room grants
  are periodically rechecked. Applications must call revocation hooks when
  custom login/session systems or membership changes bypass Laravel events.
- Node artifacts are pinned to a release manifest and checked before use.
  Do not place runtime cache paths under a public web directory.
- Payloads are developer-controlled exports. Explicitly select fields instead
  of broadcasting whole models or credentials.
- Stream ACKs are processing acknowledgements, not proof of browser delivery.
  Clients reconcile application state after reconnect.
