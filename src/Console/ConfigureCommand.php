<?php

declare(strict_types=1);

namespace SocketBridge\Console;

use SocketBridge\Runtime\DockerInstaller;
use SocketBridge\Runtime\FileInstaller;
use SocketBridge\Runtime\InstallationProfile;
use SocketBridge\Runtime\ProfileOptions;
use Throwable;

final class ConfigureCommand extends BridgeCommand
{
    protected $signature = 'socket-bridge:configure
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
        {--native : Select native mode}
        {--docker : Select Docker mode and sync its environment}
        {--laravel-url= : Persist the Laravel callback base URL}
        {--sync-secret : Explicitly synchronize the Laravel secret into Docker}';

    protected $description = 'Persist runtime choices and synchronize Docker settings with a private backup';

    public function handle(): int
    {
        try {
            if ($this->laravel->configurationIsCached()) {
                throw new \RuntimeException('Run php artisan config:clear before configure; rebuild config:cache after synchronization. Cached configuration may contain stale values.');
            }
            $mode = $this->mode();
            $config = $this->laravel->make('config');
            $profiles = new InstallationProfile($this->laravel);
            $plan = $profiles->plan($mode, ProfileOptions::from($this));
            InstallationProfile::show($this, $plan);
            if ($this->option('preview')) {
                return self::SUCCESS;
            }
            $profiles->configure($plan);
            $environment = $this->runtime()->environment()->make();
            $files = new FileInstaller;
            $backup = $files->backup(['application.env' => $this->laravel->environmentFilePath()], $this->laravel->basePath('.socket-bridge/backups'));
            $files->appendIgnoreRules($this->laravel->basePath('.gitignore'), ['/.env.socket-bridge', '/.socket-bridge/']);
            if ($mode === 'docker') {
                (new DockerInstaller($files))->synchronize($this->laravel->basePath(), $environment, (bool) $this->option('sync-secret'), $plan);
            }
            $files->selectMode($this->laravel->environmentFilePath(), $mode);
            $profiles->apply($plan, $files);
            $this->info('Runtime configuration synchronized. Private backup: '.$backup);
            $this->line('Restart the gateway and workers. Run socket-bridge:doctor --'.$mode.' --probe after Laravel is serving requests.');

            if ($this->option('check')) {
                return $this->call('socket-bridge:doctor', ['--'.$mode => true, '--probe' => true]);
            }

            return self::SUCCESS;
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());
        } catch (Throwable) {
            $this->error('Configuration synchronization failed. Check file permissions and configuration syntax; existing Compose was preserved.');
        }

        return self::FAILURE;
    }
}
