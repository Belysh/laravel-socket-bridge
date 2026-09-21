<?php

namespace App\SocketCommands;

use Illuminate\Support\Facades\Validator;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\Facades\Socket;

final class UpdateDisplayName implements CommandHandler
{
    public function handle(array $payload, CommandContext $context): array
    {
        $data = Validator::make($payload, ['name' => 'required|string|max:100'])->validate();
        // The context user comes from Laravel, never from the browser payload.
        $context->user->forceFill(['name' => $data['name']])->save();
        Socket::durable()->toUser($context->userId)->emit('profile.updated', $data);

        return $data;
    }
}
