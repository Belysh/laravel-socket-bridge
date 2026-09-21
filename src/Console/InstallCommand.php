<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use Dotenv\Dotenv;
use SocketBridge\Runtime\DockerInstaller;
use SocketBridge\Runtime\FileInstaller;
use SocketBridge\Runtime\GatewayEnvironment;
use SocketBridge\Runtime\InstallationProfile;
use SocketBridge\Runtime\NetworkDiagnostics;
use SocketBridge\Runtime\ProfileDetector;
use SocketBridge\Runtime\ProfileOptions;
use Throwable;

final class InstallCommand extends BridgeCommand
{
    protected $signature = 'socket-bridge:install
        {--profile= : auto, php, sail, herd or valet; auto-detected by default}
        {--app-url= : Browser-facing Laravel URL}
        {--gateway-url= : Browser-facing Socket.IO URL}
        {--origins= : Comma-separated exact browser origins}
        {--laravel-service= : Laravel service name from the project Compose file}
        {--network= : Existing external Docker network shared with Laravel/Redis}
        {--ca-cert= : Existing PEM CA trusted by the gateway only}
        {--tls-cert= : Existing PEM gateway TLS certificate}
        {--tls-key= : Existing matching gateway TLS private key}
        {--preview : Display the proposed profile without changing files}
        {--check : After configuration, probe already-running services}
        {--mode= : native or docker; prompts when interactive}
        {--redis= : Docker Redis: existing or generated}
        {--redis-port=6380 : Host port for a generated Docker Redis service}
        {--laravel-url= : Laravel callback base URL reachable by the gateway}
        {--no-download : Do not download Node if a compatible runtime is absent}
        {--skip-runtime : Publish configuration without preparing a native runtime}
        {--keep-broadcaster : Preserve the current default Laravel broadcast connection}
        {--example : Add an example broadcast event without replacing an existing file}';

    protected $aliases = ['socket:install'];

    protected $description = 'Install Socket Bridge using native Node or optional Docker';

    public function handle(): int
    {
        try {
            if ($this->laravel->configurationIsCached()) {
                throw new \RuntimeException('Run php artisan config:clear before installation; rebuild config:cache after reviewing the profile.');
            }
            $mode = $this->option('mode');
            if (! $mode) {
                $saved = is_file($this->laravel->environmentFilePath()) ? Dotenv::parse((string) file_get_contents($this->laravel->environmentFilePath())) : [];
                $selectedProfile = $this->option('profile') ?? $saved['SOCKET_BRIDGE_PROFILE'] ?? 'auto';
                $detectedProfile = $selectedProfile === 'auto' ? (new ProfileDetector($this->laravel->basePath()))->detect(false)['profile'] : $selectedProfile;
                $suggestedMode = $saved['SOCKET_BRIDGE_MODE'] ?? ($detectedProfile === 'sail' ? 'docker' : $this->laravel->make('config')->get('socket-bridge.install.mode', 'native'));
                $mode = $this->input->isInteractive() && ! $this->option('preview')
                    ? $this->choice('Run the gateway natively or with Docker?', ['native', 'docker'], $suggestedMode === 'docker' ? 1 : 0)
                    : $suggestedMode;
            }
            if (! in_array($mode, ['native', 'docker'], true)) {
                $this->error('--mode must be native or docker.');

                return self::FAILURE;
            }
            $profiles = new InstallationProfile($this->laravel);
            $plan = $profiles->plan($mode, ProfileOptions::from($this));
            InstallationProfile::show($this, $plan);
            if ($this->option('preview')) {
                return self::SUCCESS;
            }
            if ($plan['profile'] === 'sail' && $mode === 'docker' && $plan['network'] === null) {
                throw new \RuntimeException('Start Sail first so its network can be detected, or pass --network=<existing Docker network>.');
            }
            $redis = 'existing';
            if ($mode === 'docker') {
                $redis = $this->option('redis') ?: ($this->input->isInteractive()
                    ? $this->choice('Use existing Laravel Redis or generate a dedicated Redis service?', ['existing', 'generated'], 0)
                    : 'existing');
                if (! in_array($redis, ['existing', 'generated'], true)) {
                    $this->error('--redis must be existing or generated.');

                    return self::FAILURE;
                }
            }
            $runtime = $this->runtime();
            $config = $this->laravel->make('config');
            if ($mode === 'docker' && ! $plan['inside_container'] && ! is_file($this->laravel->basePath('compose.socket-bridge.yml'))) {
                NetworkDiagnostics::assertPortAvailable('127.0.0.1', (int) $config->get('socket-bridge.gateway.port', 6001));
                if ($redis === 'generated') {
                    NetworkDiagnostics::assertPortAvailable('127.0.0.1', (int) $this->option('redis-port'));
                }
            }
            $files = new FileInstaller;
            $backup = $files->backup([
                'application.env' => $this->laravel->environmentFilePath(),
                'socket-bridge.php' => $this->laravel->configPath('socket-bridge.php'),
                'docker.env' => $this->laravel->basePath('.env.socket-bridge'),
                'compose.socket-bridge.yml' => $this->laravel->basePath('compose.socket-bridge.yml'),
            ], $this->laravel->basePath('.socket-bridge/backups'));
            $files->appendIgnoreRules($this->laravel->basePath('.gitignore'), ['/.env.socket-bridge', '/.socket-bridge/']);
            $source = file_get_contents($runtime->packagePath('config/socket-bridge.php'));
            $files->writeIfAbsent($this->laravel->configPath('socket-bridge.php'), $source);
            $secret = (string) $config->get('socket-bridge.internal_secret', '');
            $generatedSecret = strlen($secret) < 32;
            if ($generatedSecret) {
                $secret = bin2hex(random_bytes(32));
            }
            $profiles->apply($plan, $files);
            $laravelUrl = $plan['callback_url'];
            GatewayEnvironment::validateHttpUrl((string) $laravelUrl, 'Laravel callback URL');
            $environmentPath = $this->laravel->environmentFilePath();
            $added = $files->appendEnvironment($environmentPath, [
                'SOCKET_BRIDGE_MODE' => $mode,
                'SOCKET_BRIDGE_SECRET' => $secret,
                'SOCKET_BRIDGE_PREFIX' => $config->get('socket-bridge.prefix'),
                'SOCKET_BRIDGE_LARAVEL_URL' => $laravelUrl,
            ]);
            if ($generatedSecret && ! in_array('SOCKET_BRIDGE_SECRET', $added, true)) {
                $this->error('Existing SOCKET_BRIDGE_SECRET was preserved but is missing/too short in loaded config. Set it to at least 32 random characters, clear the config cache, then rerun install.');

                return self::FAILURE;
            }
            $config->set('socket-bridge.internal_secret', $secret);
            $files->selectMode($environmentPath, $mode);
            if ($this->option('laravel-url')) {
                $files->setEnvironment($environmentPath, ['SOCKET_BRIDGE_LARAVEL_URL' => $laravelUrl]);
            }
            $config->set('socket-bridge.install.mode', $mode);
            if (in_array('SOCKET_BRIDGE_LARAVEL_URL', $added, true) || $this->option('laravel-url')) {
                $config->set('socket-bridge.gateway.laravel_url', $laravelUrl);
            }
            if (! $this->option('keep-broadcaster')) {
                $previous = (string) $config->get('broadcasting.default', 'none');
                $files->selectBroadcaster($environmentPath);
                $this->info('Default broadcaster: '.$previous.' → socketio.');
            } else {
                $this->line('Default broadcaster preserved. Select connection socketio explicitly in your broadcast events.');
            }
            // Match the migration suffix, so rerunning install never adds a second timestamped copy.
            foreach (glob($runtime->packagePath('database/migrations/*.php')) ?: [] as $migration) {
                $basename = basename($migration);
                $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $basename);
                if ((glob($this->laravel->databasePath('migrations/*_'.$suffix)) ?: []) === []) {
                    $files->writeIfAbsent($this->laravel->databasePath('migrations/'.$basename), file_get_contents($migration));
                }
            }
            $files->writeIfAbsent($this->laravel->basePath('routes/channels.php'), file_get_contents($runtime->packagePath('stubs/channels.php.stub')));
            if ($this->option('example')) {
                $files->writeIfAbsent($this->laravel->path('Events/SocketBridgeExample.php'), file_get_contents($runtime->packagePath('stubs/broadcast-event.php.stub')));
            }
            if ($mode === 'docker') {
                if ($redis === 'generated' && $config->get('socket-bridge.redis_url') && ! is_file($this->laravel->basePath('compose.socket-bridge.yml'))) {
                    $this->error('An existing SOCKET_BRIDGE_REDIS_URL was preserved. Choose --redis=existing, or remove that dedicated override explicitly before generating a new Redis service.');

                    return self::FAILURE;
                }
                $installed = (new DockerInstaller($files))->install(
                    $this->laravel->basePath(), $runtime->packagePath(), $runtime->environment()->make(),
                    $redis === 'generated', (int) $this->option('redis-port'), $plan,
                );
                if ($installed['redis_url']) {
                    $redisAdded = $files->appendEnvironment($environmentPath, ['SOCKET_BRIDGE_REDIS_URL' => $installed['redis_url']]);
                    if (! in_array('SOCKET_BRIDGE_REDIS_URL', $redisAdded, true)) {
                        $this->warn('Existing SOCKET_BRIDGE_REDIS_URL was preserved. Set it to the host port/credentials from .socket-bridge/redis.conf before starting PHP workers.');
                    } else {
                        $config->set('socket-bridge.redis_url', $installed['redis_url']);
                    }
                }
                $this->info($installed['created'] ? 'Created compose.socket-bridge.yml and private .env.socket-bridge.' : 'Existing Docker configuration and secrets were preserved.');
                if (! $installed['created']) {
                    $backup = (new DockerInstaller($files))->synchronize($this->laravel->basePath(), $runtime->environment()->make(), false, $plan);
                    $this->line('Docker environment synchronized; private backup: '.$backup);
                }
                $this->line('Start Compose from the Docker host: docker compose -f compose.socket-bridge.yml up -d --build socket-bridge');
                if ($plan['profile'] === 'sail') {
                    $this->line('Keep Sail running. Run Laravel command/outbox/queue workers with ./vendor/bin/sail artisan. The gateway joins the detected external network.');
                    $this->line('Inside Sail, run Docker commands only when the CLI and daemon socket are deliberately available; otherwise use a host terminal.');
                }
                $this->line('Start with: php artisan socket-bridge:start --docker (requires Docker CLI/daemon access)');
                if ($plan['profile'] !== 'sail') {
                    $this->line('For local Laravel callbacks, bind the HTTP server to a host-reachable interface; the generated callback uses host.docker.internal for HTTP loopback URLs.');
                }
                if ($redis === 'existing' && $plan['profile'] !== 'sail') {
                    $this->line('Existing Redis must also accept connections from Docker; on Linux, a Redis server bound only to 127.0.0.1 is not reachable through the Docker bridge.');
                }
                $this->line('PHP command/outbox/queue workers run in Laravel. Use socket-bridge:dev --docker to start them together.');
            } elseif (! $this->option('skip-runtime')) {
                $node = $runtime->node()->resolve(! $this->option('no-download'), fn (string $message) => $this->info($message));
                $this->info('Native Node 24 is ready: '.$node);
            }
            if ($mode === 'native' && is_file($this->laravel->basePath('.socket-bridge/redis.conf'))) {
                $this->line('The dedicated Docker Redis data and URL are retained. Keep that Redis service running, or explicitly migrate its data and change SOCKET_BRIDGE_REDIS_URL.');
            }
            $this->info('Socket Bridge configuration installed. Existing package configuration and secrets were preserved.');
            $this->line('Private configuration backup: '.$backup);
            $this->line('Run php artisan migrate; after Laravel, Redis, gateway and workers are running: php artisan socket-bridge:doctor --'.$mode.' --probe --operational.');
            $this->line('Start all local workers with php artisan socket-bridge:dev'.($mode === 'docker' ? ' --docker' : ' --native').'.');
            if ($this->laravel->configurationIsCached()) {
                $this->warn('Laravel configuration is cached. Run php artisan config:clear locally, or rebuild config:cache during deployment, before starting Socket Bridge.');
            }
            if (! in_array('SOCKET_BRIDGE_MODE', $added, true) && $config->get('socket-bridge.install.mode') !== $mode) {
                $this->warn('Existing SOCKET_BRIDGE_MODE was preserved. Use --'.$mode.' when starting, or update that setting yourself.');
            }

            if ($this->option('check')) {
                return $this->call('socket-bridge:doctor', ['--'.$mode => true, '--probe' => true]);
            }

            return self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Installation could not finish. Check application storage/config permissions and run socket-bridge:doctor. Configuration backups are in .socket-bridge/backups.');

            return self::FAILURE;
        }
    }
}
