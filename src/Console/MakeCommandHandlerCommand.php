<?php

namespace SocketBridge\Console;

use Illuminate\Console\GeneratorCommand;

final class MakeCommandHandlerCommand extends GeneratorCommand
{
    protected $signature = 'make:socket-command {name : Handler class name} {--force : Overwrite an existing handler}';

    protected $description = 'Create a Socket Bridge command handler';

    protected $type = 'Socket command handler';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/command-handler.php.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\SocketCommands';
    }
}
