<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final class GatewayEnvironment
{
    public function __construct(private readonly Repository $config) {}

    /** Credentials belong only in the child's environment, never its argv or console output. */
    public function make(array $overrides = []): array
    {
        $secret = (string) $this->config->get('socket-bridge.internal_secret', '');
        if (strlen($secret) < 32) {
            throw new RuntimeException('Socket Bridge needs an internal secret of at least 32 characters. Run socket-bridge:install and clear the Laravel configuration cache.');
        }
        $prefix = rtrim((string) $this->config->get('socket-bridge.prefix', ''), ':');
        if ($prefix === '' || preg_match('/[\s\x00-\x1f]/', $prefix)) {
            throw new RuntimeException('Set socket-bridge.prefix to a nonempty application/environment-specific Redis key prefix.');
        }
        $origins = $this->config->get('socket-bridge.gateway.origins', []);
        $origins = is_array($origins) ? $origins : explode(',', (string) $origins);
        $origins = array_values(array_filter(array_map('trim', $origins)));
        foreach ($origins as $origin) {
            if (! preg_match('#^https?://[^/\s]+$#', $origin) || str_contains($origin, '*')) {
                throw new RuntimeException('Set SOCKET_BRIDGE_ORIGINS to explicit browser origins, for example https://app.example.com. Wildcards and URL paths are not accepted.');
            }
        }
        if ($origins === []) {
            throw new RuntimeException('Configure at least one browser origin in SOCKET_BRIDGE_ORIGINS.');
        }
        $transports = $this->config->get('socket-bridge.gateway.transports', ['websocket']);
        $transports = is_array($transports) ? $transports : explode(',', (string) $transports);
        $transports = array_values(array_filter(array_map('trim', $transports)));
        if ($transports === [] || array_diff($transports, ['websocket', 'polling']) !== []) {
            throw new RuntimeException('socket-bridge.gateway.transports accepts websocket and optional polling only.');
        }
        $laravelUrl = rtrim((string) $this->config->get('socket-bridge.gateway.laravel_url', $this->config->get('app.url', '')), '/');
        self::validateHttpUrl($laravelUrl, 'SOCKET_BRIDGE_LARAVEL_URL');
        $port = filter_var($overrides['SOCKET_BRIDGE_PORT'] ?? $this->config->get('socket-bridge.gateway.port', 6001), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new RuntimeException('SOCKET_BRIDGE_PORT must be between 1 and 65535.');
        }
        $environment = [
            'NODE_ENV' => $this->config->get('app.env') === 'production' ? 'production' : 'development',
            'SOCKET_BRIDGE_PREFIX' => $prefix,
            'SOCKET_BRIDGE_SECRET' => $secret,
            'SOCKET_BRIDGE_REDIS_URL' => $this->redisUrl(),
            'SOCKET_BRIDGE_LARAVEL_URL' => $laravelUrl,
            'SOCKET_BRIDGE_AUTHORIZE_PATH' => '/'.trim((string) $this->config->get('socket-bridge.route_prefix', 'socket-bridge'), '/').'/internal/authorize',
            'SOCKET_BRIDGE_HOST' => (string) $this->config->get('socket-bridge.gateway.host', '127.0.0.1'),
            'SOCKET_BRIDGE_PORT' => (string) $port,
            'SOCKET_BRIDGE_ORIGINS' => implode(',', $origins),
            'SOCKET_BRIDGE_TRANSPORTS' => implode(',', $transports),
            'SOCKET_BRIDGE_AUTH_CHECK_MS' => (string) $this->config->get('socket-bridge.gateway.auth_check_ms', 15000),
            'SOCKET_BRIDGE_CLAIM_IDLE_MS' => (string) $this->config->get('socket-bridge.command_claim_idle_ms', 30000),
            'SOCKET_BRIDGE_ROOM_LEASE_SECONDS' => (string) $this->config->get('socket-bridge.room_lease', 30),
            'SOCKET_BRIDGE_MAX_PAYLOAD_BYTES' => (string) $this->config->get('socket-bridge.max_payload_bytes', 65536),
        ];
        $environment['SOCKET_BRIDGE_PUBLIC_URL'] = (string) $this->config->get('socket-bridge.gateway.public_url', 'http://localhost:'.$port);
        foreach ([
            'max_buffered_bytes' => ['SOCKET_BRIDGE_MAX_BUFFERED_BYTES', 1048576, 65536, 67108864],
            'max_buffered_packets' => ['SOCKET_BRIDGE_MAX_BUFFERED_PACKETS', 1000, 10, 100000],
            'command_ack_timeout_ms' => ['SOCKET_BRIDGE_COMMAND_ACK_TIMEOUT_MS', 30000, 100, 300000],
            'max_pending_command_acks' => ['SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS', 32, 1, 1000],
            'max_pending_command_acks_total' => ['SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS_TOTAL', 10000, 1, 1000000],
        ] as $key => [$variable, $default, $minimum, $maximum]) {
            $value = filter_var($this->config->get('socket-bridge.gateway.'.$key, $default), FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
            if ($value === false) {
                throw new RuntimeException($variable.' must be between '.$minimum.' and '.$maximum.'.');
            }
            $environment[$variable] = (string) $value;
        }
        $metricsToken = $this->config->get('socket-bridge.metrics.token');
        if ((bool) $this->config->get('socket-bridge.metrics.enabled', (bool) $metricsToken) && $metricsToken !== null && $metricsToken !== '') {
            if (! is_string($metricsToken) || strlen($metricsToken) < 32) {
                throw new RuntimeException('SOCKET_BRIDGE_METRICS_TOKEN must contain at least 32 characters.');
            }
            $environment['SOCKET_BRIDGE_METRICS_TOKEN'] = $metricsToken;
        }
        $ca = $this->config->get('socket-bridge.gateway.ca_cert');
        if ($ca) {
            if (! is_file($ca) || ! is_readable($ca) || @openssl_x509_read((string) file_get_contents($ca)) === false) {
                throw new RuntimeException('SOCKET_BRIDGE_CA_CERT must be an existing readable PEM certificate file.');
            }
            $environment['NODE_EXTRA_CA_CERTS'] = (string) realpath($ca);
        }
        $tlsCert = $this->config->get('socket-bridge.gateway.tls_cert');
        $tlsKey = $this->config->get('socket-bridge.gateway.tls_key');
        if ($tlsCert || $tlsKey) {
            if (! $tlsCert || ! $tlsKey) {
                throw new RuntimeException('Configure both SOCKET_BRIDGE_TLS_CERT and SOCKET_BRIDGE_TLS_KEY for native TLS.');
            }
            $environment['SOCKET_BRIDGE_TLS_CERT'] = (string) $tlsCert;
            $environment['SOCKET_BRIDGE_TLS_KEY'] = (string) $tlsKey;
        }

        return array_replace($environment, $overrides);
    }

    public function redisUrl(): string
    {
        $connection = (string) $this->config->get('socket-bridge.redis_connection', 'default');
        $settings = $this->config->get('database.redis.'.$connection);
        if (! is_array($settings) || isset($settings[0]) || $this->config->get('database.redis.clusters.'.$connection) !== null) {
            throw new RuntimeException('Configure a standalone Laravel Redis connection in database.redis.'.$connection.'. Redis Cluster is not supported by Socket Bridge.');
        }
        if ($this->config->get('socket-bridge.redis_url')) {
            $settings['url'] = $this->config->get('socket-bridge.redis_url');
        }
        if (! empty($settings['url'])) {
            $url = parse_url((string) $settings['url']);
            if (! is_array($url) || ! isset($url['host']) || ! in_array($url['scheme'] ?? '', ['redis', 'rediss', 'tcp', 'tls'], true)) {
                throw new RuntimeException('The Laravel Redis URL must be a valid redis:// or rediss:// URL.');
            }
            $settings['scheme'] = in_array($url['scheme'], ['tls', 'rediss'], true) ? 'rediss' : 'redis';
            $settings['host'] = $url['host'];
            $settings['port'] = $url['port'] ?? 6379;
            $settings['username'] = isset($url['user']) ? rawurldecode($url['user']) : ($settings['username'] ?? null);
            $settings['password'] = isset($url['pass']) ? rawurldecode($url['pass']) : ($settings['password'] ?? null);
            if (isset($url['path']) && trim($url['path'], '/') !== '') {
                $settings['database'] = trim($url['path'], '/');
            }
            parse_str($url['query'] ?? '', $query);
            if (isset($query['database'])) {
                $settings['database'] = $query['database'];
            }
        }
        $host = (string) ($settings['host'] ?? '127.0.0.1');
        $scheme = in_array($settings['scheme'] ?? '', ['tls', 'rediss'], true) ? 'rediss' : 'redis';
        if (str_starts_with($host, 'tls://')) {
            $scheme = 'rediss';
            $host = substr($host, 6);
        }
        $host = trim($host, '[]');
        if ($host === '' || preg_match('/[\s\/@?#]/', $host)) {
            throw new RuntimeException('Socket Bridge requires a TCP Redis host; Unix sockets are not supported by its Node gateway.');
        }
        $host = str_contains($host, ':') ? '['.$host.']' : $host;
        $port = filter_var($settings['port'] ?? 6379, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $database = filter_var($settings['database'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($port === false || $database === false) {
            throw new RuntimeException('Laravel Redis port/database configuration is invalid.');
        }
        $username = $settings['username'] ?? null;
        $password = $settings['password'] ?? null;
        $credentials = ($username !== null && $username !== '') || ($password !== null && $password !== '')
            ? rawurlencode((string) $username).':'.rawurlencode((string) $password).'@'
            : '';

        return $scheme.'://'.$credentials.$host.':'.$port.'/'.$database;
    }

    public static function validateHttpUrl(string $url, string $name): void
    {
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20*]/', $url) || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException($name.' must be an HTTP(S) base URL without credentials, query or fragment.');
        }
    }
}
