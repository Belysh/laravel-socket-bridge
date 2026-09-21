<?php

namespace SocketBridge;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use SocketBridge\Auth\DefaultSessionAuthorizer;
use SocketBridge\Auth\RevokeLoggedOutSession;
use SocketBridge\Auth\SessionManager;
use SocketBridge\Broadcasters\SocketIoBroadcaster;
use SocketBridge\Commands\CommandConsumer;
use SocketBridge\Commands\CommandProcessor;
use SocketBridge\Commands\CommandRegistry;
use SocketBridge\Commands\ProbeHandler;
use SocketBridge\Console\ConfigureCommand;
use SocketBridge\Console\ConsumeCommand;
use SocketBridge\Console\DevCommand;
use SocketBridge\Console\DoctorCommand;
use SocketBridge\Console\FailedCommand;
use SocketBridge\Console\InstallCommand;
use SocketBridge\Console\MakeCommandHandlerCommand;
use SocketBridge\Console\MetricsCommand;
use SocketBridge\Console\OutboxCommand;
use SocketBridge\Console\PruneCommand;
use SocketBridge\Console\ProbeCommand;
use SocketBridge\Console\RestartCommand;
use SocketBridge\Console\StartCommand;
use SocketBridge\Console\StatusCommand;
use SocketBridge\Console\UpgradeCommand;
use SocketBridge\Contracts\EnvelopeTransport;
use SocketBridge\Contracts\SessionAuthorizer;
use SocketBridge\Outbox\OutboxRelay;
use SocketBridge\Outbox\OutboxStore;
use SocketBridge\Transport\RedisStreams;

class SocketBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/socket-bridge.php', 'socket-bridge');
        if (! $this->app->configurationIsCached()) {
            $defaults = require __DIR__.'/../config/socket-bridge.php';
            // Laravel's mergeConfigFrom is shallow. Published 1.0 sections must
            // receive additive defaults without merging indexes in origin lists.
            foreach (['gateway', 'install', 'metrics', 'workers', 'retention'] as $section) {
                $this->app['config']->set('socket-bridge.'.$section, array_replace($defaults[$section], (array) $this->app['config']->get('socket-bridge.'.$section, [])));
            }
        }
        foreach ([RedisStreams::class, EnvelopePublisher::class, OutboxStore::class, OutboxRelay::class, SessionManager::class, SocketManager::class, CommandRegistry::class, CommandProcessor::class, CommandConsumer::class] as $service) {
            $this->app->singleton($service);
        }
        $this->app->bind(EnvelopeTransport::class, fn ($app) => $app->make(RedisStreams::class));
        $this->app->bind(SessionAuthorizer::class, DefaultSessionAuthorizer::class);
    }

    public function boot(Dispatcher $events): void
    {
        $this->app->make(CommandRegistry::class)->register('socket-bridge.probe', ProbeHandler::class);
        $name = config('socket-bridge.broadcast_connection', 'socketio');
        if (config('broadcasting.connections.'.$name) === null) {
            config(['broadcasting.connections.'.$name => ['driver' => 'socketio']]);
        }
        $this->app->afterResolving(BroadcastManager::class, function (BroadcastManager $manager): void {
            $manager->extend('socketio', fn () => new SocketIoBroadcaster($this->app->make(EnvelopePublisher::class)));
        });
        // BroadcastManager might have been resolved by an earlier provider.
        if ($this->app->resolved(BroadcastManager::class)) {
            $this->app->make(BroadcastManager::class)->extend('socketio', fn () => new SocketIoBroadcaster($this->app->make(EnvelopePublisher::class)));
        }
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->booted(function (): void {
            $channels = base_path('routes/channels.php');
            if (is_file($channels)) {
                require_once $channels;
            }
        });
        $events->listen([Logout::class, CurrentDeviceLogout::class, OtherDeviceLogout::class], RevokeLoggedOutSession::class);
        $this->publishes([__DIR__.'/../config/socket-bridge.php' => config_path('socket-bridge.php')], 'socket-bridge-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'socket-bridge-migrations');
        $this->publishes([__DIR__.'/../stubs/deploy' => base_path('deploy/socket-bridge')], 'socket-bridge-deploy');
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('socket-bridge.retention.automatic', true)) {
                $minutes = (int) config('socket-bridge.retention.frequency_minutes', 1);
                if ($minutes < 1 || $minutes > 60) {
                    throw new \InvalidArgumentException('socket-bridge.retention.frequency_minutes must be between 1 and 60.');
                }
                $schedule->command('socket-bridge:prune --no-interaction')
                    ->cron($minutes === 60 ? '0 * * * *' : '*/'.$minutes.' * * * *')
                    ->withoutOverlapping(10);
            }
        });
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class, StartCommand::class,
                DevCommand::class, DoctorCommand::class,
                ConsumeCommand::class, OutboxCommand::class,
                MakeCommandHandlerCommand::class,
                ConfigureCommand::class,
                UpgradeCommand::class,
                PruneCommand::class,
                ProbeCommand::class,
                StatusCommand::class,
                FailedCommand::class,
                RestartCommand::class,
                MetricsCommand::class,
            ]);
        }
    }
}
