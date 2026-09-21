<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/** Plans and persists only package-owned settings. Detection never changes global trust or host services. */
final class InstallationProfile
{
    public function __construct(private readonly Application $app, private readonly ?ProfileDetector $detector = null) {}

    public function plan(string $mode, array $options = []): array
    {
        $config = $this->app->make('config');
        $environment = is_file($this->app->environmentFilePath()) ? Dotenv::parse((string) file_get_contents($this->app->environmentFilePath())) : [];
        $requested = $options['profile'] ?? $environment['SOCKET_BRIDGE_PROFILE'] ?? $config->get('socket-bridge.install.profile', 'auto');
        if (! in_array($requested, ['auto', 'php', 'sail', 'herd', 'valet'], true)) {
            throw new RuntimeException('--profile must be auto, php, sail, herd or valet.');
        }
        $detected = ($this->detector ?? new ProfileDetector($this->app->basePath()))->detect(! ($options['offline'] ?? false), $requested === 'auto' ? null : $requested);
        $profile = $requested === 'auto' ? $detected['profile'] : $requested;
        $appUrl = (string) $config->get('app.url', 'http://localhost');
        $stockUrl = in_array(rtrim($appUrl, '/'), ['http://localhost', 'http://127.0.0.1'], true);
        $derived = $stockUrl && $detected['profile'] === $profile ? $detected['site_url'] : null;
        if ($derived === null) {
            $derived = in_array(rtrim($appUrl, '/'), ['http://localhost', 'http://127.0.0.1'], true) && $profile === 'php' ? 'http://127.0.0.1:8000' : $appUrl;
        }
        if ($profile === 'sail' && $stockUrl && $detected['browser_port'] !== 80) {
            $derived = $appUrl.':'.$detected['browser_port'];
        }
        $configuredBrowser = $config->get('socket-bridge.install.app_url', $appUrl);
        $browser = (string) ($options['app-url'] ?? $environment['SOCKET_BRIDGE_APP_URL'] ?? ($configuredBrowser !== $appUrl ? $configuredBrowser : $derived));
        GatewayEnvironment::validateHttpUrl($browser, 'Browser application URL');
        $browser = rtrim($browser, '/');
        $origin = $this->origin($browser);
        $callbackDefault = $browser;
        $service = $options['laravel-service'] ?? $detected['laravel_service'];
        if ($profile === 'sail' && $service && ($mode === 'docker' || $detected['inside_container']) && parse_url($browser, PHP_URL_SCHEME) !== 'https') {
            if (! in_array($service, $detected['services'], true)) {
                throw new RuntimeException('--laravel-service must name a service present in the project Compose file.');
            }
            $callbackDefault = 'http://'.$service.($detected['laravel_port'] === 80 ? '' : ':'.$detected['laravel_port']);
        }
        $configuredCallback = (string) $config->get('socket-bridge.gateway.laravel_url', $appUrl);
        $callback = $options['laravel-url'] ?? $environment['SOCKET_BRIDGE_LARAVEL_URL'] ?? ($configuredCallback !== $appUrl ? $configuredCallback : $callbackDefault);
        GatewayEnvironment::validateHttpUrl($callback, 'Laravel callback URL');
        $configuredOrigins = (array) $config->get('socket-bridge.gateway.origins', [$appUrl]);
        $origins = $options['origins'] ?? $environment['SOCKET_BRIDGE_ORIGINS'] ?? ($configuredOrigins !== [$appUrl] ? implode(',', $configuredOrigins) : $origin);
        $origins = array_values(array_filter(array_map('trim', explode(',', $origins))));
        foreach ($origins as $item) {
            GatewayEnvironment::validateHttpUrl($item, 'Browser origin');
            if ($this->origin($item) !== $item) {
                throw new RuntimeException('Origins must contain scheme, hostname and optional port only.');
            }
        }
        if ($origins === []) {
            throw new RuntimeException('At least one browser origin is required.');
        }
        $port = (int) $config->get('socket-bridge.gateway.port', 6001);
        $configuredGateway = $config->get('socket-bridge.gateway.public_url', 'http://localhost:6001');
        $gateway = $options['gateway-url'] ?? $environment['SOCKET_BRIDGE_URL'] ?? ($configuredGateway !== 'http://localhost:6001' ? $configuredGateway : parse_url($browser, PHP_URL_SCHEME).'://'.parse_url($browser, PHP_URL_HOST).':'.$port);
        GatewayEnvironment::validateHttpUrl($gateway, 'Browser gateway URL');
        $network = $options['network'] ?? $environment['SOCKET_BRIDGE_DOCKER_NETWORK'] ?? $config->get('socket-bridge.install.network') ?? ($profile === 'sail' ? $detected['network'] : null);
        if ($network !== null && ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,199}$/D', $network)) {
            throw new RuntimeException('--network must be an existing Docker network name.');
        }
        $ca = $options['ca-cert'] ?? $environment['SOCKET_BRIDGE_CA_CERT'] ?? $config->get('socket-bridge.gateway.ca_cert');
        $tlsCert = $options['tls-cert'] ?? $environment['SOCKET_BRIDGE_TLS_CERT'] ?? $config->get('socket-bridge.gateway.tls_cert');
        $tlsKey = $options['tls-key'] ?? $environment['SOCKET_BRIDGE_TLS_KEY'] ?? $config->get('socket-bridge.gateway.tls_key');
        $localSite = $detected['profile'] === $profile && $detected['site_url'] && parse_url($detected['site_url'], PHP_URL_HOST) === parse_url($callback, PHP_URL_HOST);
        if ($localSite && parse_url($callback, PHP_URL_SCHEME) === 'https') {
            $ca ??= $detected['ca_cert'];
        }
        if ($localSite && parse_url($gateway, PHP_URL_HOST) === parse_url($detected['site_url'], PHP_URL_HOST) && parse_url($gateway, PHP_URL_SCHEME) === 'https') {
            $tlsCert ??= $detected['tls_cert'];
            $tlsKey ??= $detected['tls_key'];
        }
        if ($ca !== null) {
            $ca = $this->certificate($ca, 'CA certificate');
        }
        if ($tlsCert !== null || $tlsKey !== null) {
            if ($tlsCert === null || $tlsKey === null || ! is_file($tlsKey) || ! is_readable($tlsKey)) {
                throw new RuntimeException('Gateway TLS requires a readable certificate and matching private key; provide both --tls-cert and --tls-key.');
            }
            $tlsCert = $this->certificate($tlsCert, 'Gateway certificate');
            $tlsKey = realpath($tlsKey);
            if (! @openssl_x509_check_private_key((string) file_get_contents($tlsCert), (string) file_get_contents($tlsKey))) {
                throw new RuntimeException('Gateway TLS certificate and private key do not match.');
            }
        }
        $redis = (new GatewayEnvironment($config))->redisUrl();
        $dockerRedis = $redis;
        if ($mode === 'docker' && $profile === 'sail' && $detected['redis_service'] && in_array(parse_url($redis, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) && ! isset($environment['SOCKET_BRIDGE_REDIS_URL'])) {
            $dockerRedis = preg_replace('#(?<=://)([^/@]*@)?[^/:]+:\d+#', '$1'.$detected['redis_service'].':6379', $redis);
        }
        $warnings = [];
        if ($profile === 'sail' && $mode === 'docker' && $network === null) {
            $warnings[] = 'Sail network not identified. Start Sail then rerun, or pass --network=<existing network>. No network name will be guessed.';
        }
        if (parse_url($browser, PHP_URL_SCHEME) === 'https' && parse_url($gateway, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('An HTTPS application needs an HTTPS gateway URL; use --gateway-url with a secure endpoint.');
        }
        if (parse_url($gateway, PHP_URL_SCHEME) === 'https' && $tlsCert === null) {
            $warnings[] = 'The HTTPS gateway URL requires an existing TLS reverse proxy, or readable --tls-cert and --tls-key. No certificate or trust changes are performed.';
        }
        if ($profile === 'sail' && $mode === 'native') {
            $warnings[] = 'Native mode in Sail requires a published gateway port and Node inside that container; host native mode requires host-reachable Laravel and Redis URLs.';
        }
        $values = ['SOCKET_BRIDGE_PROFILE' => $profile, 'SOCKET_BRIDGE_APP_URL' => $browser, 'SOCKET_BRIDGE_URL' => $gateway, 'SOCKET_BRIDGE_LARAVEL_URL' => $callback, 'SOCKET_BRIDGE_ORIGINS' => implode(',', $origins)];
        foreach (['SOCKET_BRIDGE_DOCKER_NETWORK' => $network, 'SOCKET_BRIDGE_CA_CERT' => $ca, 'SOCKET_BRIDGE_TLS_CERT' => $tlsCert, 'SOCKET_BRIDGE_TLS_KEY' => $tlsKey] as $key => $value) {
            if ($value !== null) {
                $values[$key] = $value;
            }
        }
        $explicit = [];
        foreach (['profile' => 'SOCKET_BRIDGE_PROFILE', 'app-url' => 'SOCKET_BRIDGE_APP_URL', 'gateway-url' => 'SOCKET_BRIDGE_URL', 'laravel-url' => 'SOCKET_BRIDGE_LARAVEL_URL', 'origins' => 'SOCKET_BRIDGE_ORIGINS', 'network' => 'SOCKET_BRIDGE_DOCKER_NETWORK', 'ca-cert' => 'SOCKET_BRIDGE_CA_CERT', 'tls-cert' => 'SOCKET_BRIDGE_TLS_CERT', 'tls-key' => 'SOCKET_BRIDGE_TLS_KEY'] as $option => $key) {
            if (($options[$option] ?? null) !== null) {
                $explicit[$key] = $values[$key] ?? null;
            }
        }

        return ['profile' => $profile, 'mode' => $mode, 'evidence' => $detected['evidence'], 'browser_url' => $browser, 'gateway_url' => $gateway, 'origins' => $origins, 'callback_url' => $callback,
            'redis_url' => $redis, 'docker_redis_url' => $dockerRedis, 'network' => $network, 'inside_container' => $detected['inside_container'], 'hostnames' => $localSite ? [parse_url($callback, PHP_URL_HOST)] : [],
            'ca_cert' => $ca, 'tls_cert' => $tlsCert, 'tls_key' => $tlsKey, 'values' => $values, 'explicit' => $explicit, 'warnings' => $warnings];
    }

    public function apply(array $plan, FileInstaller $files): void
    {
        if ($plan['profile'] === 'sail' && $plan['mode'] === 'docker' && $plan['network'] === null) {
            throw new RuntimeException($plan['warnings'][0]);
        }
        $files->appendEnvironment($this->app->environmentFilePath(), $plan['values']);
        $files->setEnvironment($this->app->environmentFilePath(), array_filter($plan['explicit'], static fn ($value) => $value !== null));
        $this->configure($plan);
    }

    public function configure(array $plan): void
    {
        $config = $this->app->make('config');
        foreach (['install.profile' => 'profile', 'install.app_url' => 'browser_url', 'install.network' => 'network', 'gateway.public_url' => 'gateway_url', 'gateway.laravel_url' => 'callback_url', 'gateway.origins' => 'origins', 'gateway.ca_cert' => 'ca_cert', 'gateway.tls_cert' => 'tls_cert', 'gateway.tls_key' => 'tls_key'] as $key => $field) {
            $config->set('socket-bridge.'.$key, $plan[$field]);
        }
    }

    public static function show(Command $command, array $plan): void
    {
        $command->line('Profile: '.$plan['profile'].'; runtime: '.$plan['mode'].($plan['inside_container'] ? '; PHP is inside a container' : '; PHP is on the host'));
        $command->line('Detected: '.($plan['evidence'] === [] ? 'standard Laravel/PHP project' : implode(', ', $plan['evidence'])));
        $command->line('Browser application: '.$plan['browser_url']);
        $command->line('Browser gateway: '.$plan['gateway_url']);
        $command->line('Browser origins: '.implode(', ', $plan['origins']));
        $callback = $plan['mode'] === 'docker' ? DockerInstaller::hostReachableUrl($plan['callback_url']) : $plan['callback_url'];
        $command->line('Gateway → Laravel: '.$callback);
        $redis = $plan['mode'] === 'docker' ? DockerInstaller::hostReachableUrl($plan['docker_redis_url']) : $plan['redis_url'];
        $command->line('Gateway → Redis: '.parse_url($redis, PHP_URL_SCHEME).'://'.parse_url($redis, PHP_URL_HOST).':'.(parse_url($redis, PHP_URL_PORT) ?: 6379).(parse_url($redis, PHP_URL_PATH) ?: '/0').' (credentials hidden)');
        if ($plan['network']) {
            $command->line('External Docker network: '.$plan['network']);
        }
        if ($plan['ca_cert']) {
            $command->line('Additional Node CA: '.$plan['ca_cert'].' (file only; system trust unchanged)');
        }
        foreach ($plan['warnings'] as $warning) {
            $command->warn($warning);
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function certificate(string $path, string $label): string
    {
        if (! is_file($path) || ! is_readable($path) || @openssl_x509_read((string) file_get_contents($path)) === false) {
            throw new RuntimeException($label.' must be an existing readable PEM certificate file.');
        }

        return realpath($path);
    }
}
