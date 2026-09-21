<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use SocketBridge\Runtime\DockerInstaller;
use SocketBridge\Runtime\FileInstaller;
use SocketBridge\Runtime\InstallationProfile;
use SocketBridge\Runtime\ProfileOptions;
use SocketBridge\Runtime\RuntimeManifest;
use Throwable;

final class UpgradeCommand extends BridgeCommand
{
    protected $signature = 'socket-bridge:upgrade
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
        {--laravel-url= : Persist the Laravel callback URL}
        {--native : Prepare the native runtime}
        {--docker : Synchronize the Docker runtime configuration}
        {--no-download : Require an existing Node 24 runtime}
        {--sync-secret : Explicitly synchronize the Laravel secret into Docker}';

    protected $description = 'Back up configuration and prepare the installed package version for deployment';

    public function handle(): int
    {
        try {
            if ($this->laravel->configurationIsCached()) {
                throw new \RuntimeException('Run php artisan config:clear before upgrade; rebuild config:cache after reviewing the new configuration defaults.');
            }
            $profiles = new InstallationProfile($this->laravel);
            $plan = $profiles->plan($this->mode(), ProfileOptions::from($this));
            InstallationProfile::show($this, $plan);
            if ($this->option('preview')) {
                return self::SUCCESS;
            }
            $profiles->configure($plan);
            $runtime = $this->runtime();
            new RuntimeManifest($runtime->packagePath('runtime/manifest.json'));
            $files = new FileInstaller;
            $backup = $files->backup([
                'application.env' => $this->laravel->environmentFilePath(),
                'socket-bridge.php' => $this->laravel->configPath('socket-bridge.php'),
                'docker.env' => $this->laravel->basePath('.env.socket-bridge'),
                'compose.socket-bridge.yml' => $this->laravel->basePath('compose.socket-bridge.yml'),
                'composer.lock' => $this->laravel->basePath('composer.lock'),
            ], $this->laravel->basePath('.socket-bridge/backups'));
            $files->appendIgnoreRules($this->laravel->basePath('.gitignore'), ['/.env.socket-bridge', '/.socket-bridge/']);
            $defaults = $backup.'/socket-bridge.defaults.php';
            $files->writeIfAbsent($defaults, (string) file_get_contents($runtime->packagePath('config/socket-bridge.php')), 0600);
            if ($this->mode() === 'docker') {
                (new DockerInstaller($files))->synchronize($this->laravel->basePath(), $runtime->environment()->make(), (bool) $this->option('sync-secret'), $plan);
            } else {
                $runtime->node()->resolve(! $this->option('no-download'), fn (string $message) => $this->info($message));
            }
            $profiles->apply($plan, $files);
            $published = [];
            // Publishing migrations may use the application's deployment timestamp.
            // Match by suffix before writing so upgrades preserve those files too.
            foreach (glob($runtime->packagePath('database/migrations/*.php')) ?: [] as $migration) {
                $basename = basename($migration);
                $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $basename);
                if ((glob($this->laravel->databasePath('migrations/*_'.$suffix)) ?: []) !== []) {
                    continue;
                }
                $contents = file_get_contents($migration);
                if ($contents === false) {
                    throw new \RuntimeException('Cannot read package migration: '.$basename);
                }
                if ($files->writeIfAbsent($this->laravel->databasePath('migrations/'.$basename), $contents)) {
                    $published[] = $basename;
                }
            }
            $this->info('Published '.count($published).' new package migration(s); existing migration files were preserved.');
            foreach ($published as $migration) {
                $this->line('  database/migrations/'.$migration);
            }
            $this->info('Installed package runtime prepared. Private configuration backup: '.$backup);
            $this->line('Compare socket-bridge.defaults.php in that backup with your published config; application config was preserved.');
            $this->line('Review the published migrations, run php artisan migrate, rebuild config:cache, restart gateway/workers, then doctor --probe. Docker start rebuilds the bundled image.');
            $this->line('Rollback requires the previous release/composer.lock plus its configuration backup. This command does not reverse database migrations or Redis changes.');

            if ($this->option('check')) {
                return $this->call('socket-bridge:doctor', ['--'.$this->mode() => true, '--probe' => true]);
            }

            return self::SUCCESS;
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());
        } catch (Throwable) {
            $this->error('Upgrade preparation failed. Check runtime/configuration permissions. No application configuration or migrations were replaced.');
        }

        return self::FAILURE;
    }
}
