<?php

namespace SocketBridge\Tests\Feature;

use SocketBridge\SocketBridgeServiceProvider;
use SocketBridge\Tests\TestCase;

class ConfigurationUpgradeTest extends TestCase
{
    public function test_published_old_sections_receive_new_defaults_without_overwriting_lists(): void
    {
        config(['socket-bridge.gateway' => ['port' => 6123, 'origins' => []], 'socket-bridge.install' => ['mode' => 'docker'], 'socket-bridge.metrics' => ['enabled' => false]]);
        (new SocketBridgeServiceProvider($this->app))->register();
        self::assertSame(6123, config('socket-bridge.gateway.port'));
        self::assertSame([], config('socket-bridge.gateway.origins'));
        self::assertSame(1048576, config('socket-bridge.gateway.max_buffered_bytes'));
        self::assertSame('auto', config('socket-bridge.install.profile'));
        self::assertSame('docker', config('socket-bridge.install.mode'));
        self::assertFalse(config('socket-bridge.metrics.enabled'));
        self::assertSame(86400, config('socket-bridge.metrics.ttl_seconds'));
    }
}
