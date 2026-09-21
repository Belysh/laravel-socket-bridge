<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use Illuminate\Console\Command;

final class ProfileOptions
{
    public static function from(Command $command): array
    {
        $options = [];
        foreach (['profile', 'app-url', 'gateway-url', 'origins', 'laravel-url', 'laravel-service', 'network', 'ca-cert', 'tls-cert', 'tls-key'] as $name) {
            if ($command->getDefinition()->hasOption($name) && $command->option($name) !== null) {
                $options[$name] = $command->option($name);
            }
        }

        return $options;
    }
}
