<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use Dotenv\Dotenv;
use RuntimeException;

final class DockerInstaller
{
    public function __construct(private readonly FileInstaller $files = new FileInstaller) {}

    /** Returns the host-side Redis URL only when a dedicated Redis service was requested. */
    public function install(string $applicationPath, string $packagePath, array $environment, bool $generateRedis, int $redisPort = 6380, array $profile = []): array
    {
        $compose = $applicationPath.'/compose.socket-bridge.yml';
        $environmentPath = $applicationPath.'/.env.socket-bridge';
        // Treat the generated pair as one configuration: never regenerate its secrets on a rerun.
        if (is_file($compose) || is_file($environmentPath)) {
            if (! is_file($compose) || ! is_file($environmentPath)) {
                throw new RuntimeException('An incomplete Docker installation exists. Review compose.socket-bridge.yml and .env.socket-bridge; restore the missing file before retrying. Existing files were preserved.');
            }

            return ['created' => false, 'redis_url' => null, 'laravel_url' => null];
        }
        if ($redisPort < 1 || $redisPort > 65535) {
            throw new RuntimeException('The Docker Redis host port must be between 1 and 65535.');
        }
        $environment['SOCKET_BRIDGE_HOST'] = '0.0.0.0';
        $environment['SOCKET_BRIDGE_LARAVEL_URL'] = self::hostReachableUrl($environment['SOCKET_BRIDGE_LARAVEL_URL']);
        $hostRedisUrl = null;
        if ($generateRedis) {
            $password = bin2hex(random_bytes(32));
            $hostRedisUrl = 'redis://:'.$password.'@127.0.0.1:'.$redisPort.'/0';
            if (($profile['profile'] ?? null) === 'sail') {
                $hostRedisUrl = 'redis://:'.$password.'@socket-bridge-redis:6379/0';
            }
            $environment['SOCKET_BRIDGE_REDIS_URL'] = 'redis://:'.$password.'@socket-bridge-redis:6379/0';
            $private = $applicationPath.'/.socket-bridge';
            if (! is_dir($private) && ! mkdir($private, 0700, true) && ! is_dir($private)) {
                throw new RuntimeException('Cannot create the private Docker Redis configuration directory.');
            }
            chmod($private, 0700);
            if (! $this->files->writeIfAbsent($private.'/redis.conf', "bind 0.0.0.0\nprotected-mode yes\nport 6379\nappendonly yes\ndir /data\nrequirepass ".$password."\n", 0644)) {
                throw new RuntimeException('An existing .socket-bridge/redis.conf was preserved. Review the previous Docker installation before retrying.');
            }
        } else {
            $environment['SOCKET_BRIDGE_REDIS_URL'] = self::hostReachableUrl($profile['docker_redis_url'] ?? $environment['SOCKET_BRIDGE_REDIS_URL']);
        }
        $environment = $this->prepareCertificates($applicationPath, $environment);
        $envContents = "# Local Docker credentials. Do not commit this file.\n";
        foreach ($environment as $key => $value) {
            $envContents .= $key.'='.FileInstaller::quote((string) $value)."\n";
        }
        $vendorPackage = $applicationPath.'/vendor/belysh/laravel-socket-bridge';
        $context = is_dir($vendorPackage) && realpath($vendorPackage) === realpath($packagePath)
            ? './vendor/belysh/laravel-socket-bridge'
            : self::relativePath($applicationPath, $packagePath);
        $port = (int) ($environment['SOCKET_BRIDGE_PORT'] ?? 6001);
        $composeContents = $this->compose($context, $port, $generateRedis, $redisPort, $profile, $environment);
        $this->files->writeIfAbsent($environmentPath, $envContents, 0600);
        $this->files->writeIfAbsent($compose, $composeContents);
        $this->files->appendIgnoreRules($applicationPath.'/.gitignore', ['/.env.socket-bridge', '/.socket-bridge/']);

        return ['created' => true, 'redis_url' => $hostRedisUrl, 'laravel_url' => $environment['SOCKET_BRIDGE_LARAVEL_URL']];
    }

    public static function hostReachableUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('A valid Redis/Laravel URL is required for Docker.');
        }
        if (! in_array(trim($parts['host'], '[]'), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return $url;
        }
        if (in_array($parts['scheme'] ?? '', ['https', 'rediss'], true)) {
            throw new RuntimeException('A TLS localhost URL cannot be rewritten safely for Docker: the certificate hostname would change. Configure a hostname reachable from the container with a trusted matching certificate, then run socket-bridge:doctor --docker --probe.');
        }

        // Replace only the authority host, preserving percent-encoded credentials and paths.
        $authorityStart = strpos($url, '://') + 3;
        $authorityEnd = strcspn($url, '/?#', $authorityStart) + $authorityStart;
        $authority = substr($url, $authorityStart, $authorityEnd - $authorityStart);
        $hostStart = strrpos($authority, '@');
        $credentials = $hostStart === false ? '' : substr($authority, 0, $hostStart + 1);
        $newAuthority = $credentials.'host.docker.internal'.(isset($parts['port']) ? ':'.$parts['port'] : '');

        return substr($url, 0, $authorityStart).$newAuthority.substr($url, $authorityEnd);
    }

    /** Sync Laravel-managed values while preserving Compose, custom env keys and generated Redis credentials. */
    public function synchronize(string $applicationPath, array $environment, bool $syncSecret = false, array $profile = []): string
    {
        $path = $applicationPath.'/.env.socket-bridge';
        $compose = $applicationPath.'/compose.socket-bridge.yml';
        if (! is_file($path) || ! is_file($compose)) {
            throw new RuntimeException('Docker configuration is missing. Run socket-bridge:install --mode=docker first.');
        }
        $existing = Dotenv::parse((string) file_get_contents($path));
        if (isset($existing['SOCKET_BRIDGE_SECRET']) && ! hash_equals($existing['SOCKET_BRIDGE_SECRET'], $environment['SOCKET_BRIDGE_SECRET']) && ! $syncSecret) {
            throw new RuntimeException('Laravel and Docker secrets differ. Verify the intended Laravel secret, then run socket-bridge:configure --docker --sync-secret to update Docker explicitly.');
        }
        if (isset($existing['SOCKET_BRIDGE_PORT']) && (string) $existing['SOCKET_BRIDGE_PORT'] !== (string) $environment['SOCKET_BRIDGE_PORT']) {
            throw new RuntimeException('The gateway port changed. Update Compose ports/healthcheck and SOCKET_BRIDGE_PORT in .env.socket-bridge together, then run configure again. Custom Compose files are never rewritten.');
        }
        $environment['SOCKET_BRIDGE_HOST'] = '0.0.0.0';
        $environment['SOCKET_BRIDGE_LARAVEL_URL'] = self::hostReachableUrl($environment['SOCKET_BRIDGE_LARAVEL_URL']);
        $redisHost = parse_url($existing['SOCKET_BRIDGE_REDIS_URL'] ?? '', PHP_URL_HOST);
        if ($redisHost === 'socket-bridge-redis' && is_file($applicationPath.'/.socket-bridge/redis.conf')) {
            $source = parse_url($environment['SOCKET_BRIDGE_REDIS_URL']);
            $generated = parse_url($existing['SOCKET_BRIDGE_REDIS_URL']);
            if (($source['pass'] ?? '') !== ($generated['pass'] ?? '') || ($source['path'] ?? '/0') !== ($generated['path'] ?? '/0')) {
                throw new RuntimeException('Laravel Redis differs from the generated Docker Redis credentials/database. Restore SOCKET_BRIDGE_REDIS_URL to that service or explicitly migrate Redis and update the Docker environment before synchronization.');
            }
            $environment['SOCKET_BRIDGE_REDIS_URL'] = $existing['SOCKET_BRIDGE_REDIS_URL'];
        } else {
            $environment['SOCKET_BRIDGE_REDIS_URL'] = self::hostReachableUrl($profile['docker_redis_url'] ?? $environment['SOCKET_BRIDGE_REDIS_URL']);
        }
        if (($profile['network'] ?? null) !== null && ! str_contains((string) file_get_contents($compose), (string) $profile['network'])) {
            throw new RuntimeException('The selected external network is absent from existing Compose. Attach socket-bridge to that external network manually; the custom Compose file was preserved.');
        }
        foreach ($profile['hostnames'] ?? [] as $hostname) {
            if (! str_contains((string) file_get_contents($compose), $hostname.':host-gateway')) {
                throw new RuntimeException('Add the detected development hostname to Compose extra_hosts with host-gateway before synchronization; keep the TLS hostname unchanged.');
            }
        }
        if (isset($environment['SOCKET_BRIDGE_TLS_CERT'], $existing['SOCKET_BRIDGE_PUBLIC_URL']) && parse_url($environment['SOCKET_BRIDGE_PUBLIC_URL'], PHP_URL_HOST) !== parse_url($existing['SOCKET_BRIDGE_PUBLIC_URL'], PHP_URL_HOST)) {
            throw new RuntimeException('The TLS gateway hostname changed. Update the Compose healthcheck servername and Docker public URL together before synchronization.');
        }
        if ((isset($environment['SOCKET_BRIDGE_TLS_CERT']) && ! isset($existing['SOCKET_BRIDGE_TLS_CERT'])) || (! isset($environment['SOCKET_BRIDGE_TLS_CERT']) && isset($existing['SOCKET_BRIDGE_TLS_CERT']) && str_contains((string) file_get_contents($compose), 'node:https'))) {
            throw new RuntimeException('Gateway TLS mode changed. Update the existing Compose healthcheck and certificate mounts plus .env.socket-bridge TLS keys before synchronization; custom Compose was preserved.');
        }
        if ((isset($environment['NODE_EXTRA_CA_CERTS']) || isset($environment['SOCKET_BRIDGE_TLS_CERT'])) && ! str_contains((string) file_get_contents($compose), '/run/socket-bridge/tls')) {
            throw new RuntimeException('Add the read-only ./.socket-bridge/tls:/run/socket-bridge/tls:ro volume to existing Compose before configuring certificates.');
        }
        $backup = $this->files->backup(['docker.env' => $path, 'compose.socket-bridge.yml' => $compose], $applicationPath.'/.socket-bridge/backups');
        $environment = $this->prepareCertificates($applicationPath, $environment);
        $this->files->setEnvironment($path, $environment, ['SOCKET_BRIDGE_TLS_CERT', 'SOCKET_BRIDGE_TLS_KEY', 'NODE_EXTRA_CA_CERTS', 'SOCKET_BRIDGE_METRICS_TOKEN']);
        $this->files->appendIgnoreRules($applicationPath.'/.gitignore', ['/.env.socket-bridge', '/.socket-bridge/']);

        return $backup;
    }

    private function prepareCertificates(string $applicationPath, array $environment): array
    {
        $private = $applicationPath.'/.socket-bridge';
        if (! is_dir($private) && ! mkdir($private, 0700, true) && ! is_dir($private)) {
            throw new RuntimeException('Cannot create private runtime directory.');
        }
        chmod($private, 0700);
        $directory = $private.'/tls';
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create private certificate mount directory.');
        }
        foreach (['NODE_EXTRA_CA_CERTS' => 'ca.pem', 'SOCKET_BRIDGE_TLS_CERT' => 'gateway.crt', 'SOCKET_BRIDGE_TLS_KEY' => 'gateway.key'] as $key => $name) {
            if (! isset($environment[$key])) {
                continue;
            }
            $source = $environment[$key];
            if (! is_readable($source) || ! is_file($source)) {
                throw new RuntimeException('TLS source file must exist in the Laravel environment before Docker synchronization.');
            }
            $contents = file_get_contents($source);
            if ($contents === false) {
                throw new RuntimeException('Cannot read a configured TLS source file.');
            }
            $destination = $directory.'/'.$name;
            if (is_file($destination) && file_get_contents($destination) !== $contents) {
                $this->files->backup([$name => $destination], $private.'/backups');
            }
            // The parent private directory is 0700; Docker mounts only this subdirectory
            // read-only so the unprivileged gateway can read its own TLS files.
            if (file_put_contents($destination, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Cannot prepare the private Docker TLS mount.');
            }
            chmod($destination, 0644);
            $environment[$key] = '/run/socket-bridge/tls/'.$name;
        }

        return $environment;
    }

    public static function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', trim($from, '/'));
        $toParts = explode('/', trim($to, '/'));
        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }
        $relative = str_repeat('../', count($fromParts)).implode('/', $toParts);

        return str_starts_with($relative, '../') ? $relative : './'.$relative;
    }

    private function compose(string $context, int $port, bool $redis, int $redisPort, array $profile, array $environment): string
    {
        $contextYaml = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $yaml = "# Generated by socket-bridge:install. Existing files are never overwritten.\nservices:\n  socket-bridge:\n    build:\n      context: {$contextYaml}\n      dockerfile: docker/Dockerfile\n    env_file:\n      - .env.socket-bridge\n    ports:\n      - \"127.0.0.1:{$port}:{$port}\"\n    extra_hosts:\n      - \"host.docker.internal:host-gateway\"\n    init: true\n    restart: unless-stopped\n    stop_grace_period: 20s\n    healthcheck:\n      test: [\"CMD\", \"node\", \"-e\", \"fetch('http://127.0.0.1:{$port}/health/ready').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))\"]\n      interval: 10s\n      timeout: 5s\n      retries: 3\n      start_period: 10s\n";
        $yaml .= "    volumes:\n      - ./.socket-bridge/tls:/run/socket-bridge/tls:ro\n";
        if (isset($environment['SOCKET_BRIDGE_TLS_CERT'])) {
            $hostname = parse_url($environment['SOCKET_BRIDGE_PUBLIC_URL'] ?? '', PHP_URL_HOST);
            if (! is_string($hostname) || $hostname === '') {
                throw new RuntimeException('A gateway hostname is required for the verified TLS healthcheck.');
            }
            $script = "require('node:https').get({host:'127.0.0.1',port:".$port.',servername:'.json_encode($hostname).",path:'/health/ready'},r=>process.exit(r.statusCode===200?0:1)).on('error',()=>process.exit(1))";
            $yaml = preg_replace('/      test: \["CMD", "node", "-e",.*\n/', '      test: '.json_encode(['CMD', 'node', '-e', $script], JSON_UNESCAPED_SLASHES)."\n", $yaml, 1);
        }
        foreach ($profile['hostnames'] ?? [] as $hostname) {
            if (! preg_match('/^[a-zA-Z0-9.-]+$/D', $hostname)) {
                throw new RuntimeException('Invalid local development hostname.');
            }
            $yaml = str_replace('      - "host.docker.internal:host-gateway"', '      - "host.docker.internal:host-gateway"'."\n".'      - '.json_encode($hostname.':host-gateway'), $yaml);
        }
        if ($profile['network'] ?? null) {
            $yaml .= "    networks: [default, socket-bridge-project]\n";
        }
        if ($redis) {
            $yaml .= "    depends_on:\n      socket-bridge-redis:\n        condition: service_healthy\n  socket-bridge-redis:\n    image: redis:7.4-alpine\n    command: [\"redis-server\", \"/usr/local/etc/redis/redis.conf\"]\n    ports:\n      - \"127.0.0.1:{$redisPort}:6379\"\n    volumes:\n      - socket-bridge-redis-data:/data\n      - ./.socket-bridge/redis.conf:/usr/local/etc/redis/redis.conf:ro\n    restart: unless-stopped\n    healthcheck:\n      test: [\"CMD-SHELL\", \"REDISCLI_AUTH=\$\$(sed -n 's/^requirepass //p' /usr/local/etc/redis/redis.conf) redis-cli ping | grep -q PONG\"]\n      interval: 5s\n      timeout: 3s\n      retries: 5\nvolumes:\n  socket-bridge-redis-data:\n";
        }

        if ($profile['network'] ?? null) {
            if ($redis) {
                $yaml = str_replace('    image: redis:7.4-alpine', "    networks: [default, socket-bridge-project]\n    image: redis:7.4-alpine", $yaml);
            }
            $yaml .= "networks:\n  socket-bridge-project:\n    external: true\n    name: ".json_encode($profile['network'])."\n";
        }

        return $yaml;
    }
}
