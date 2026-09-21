<?php

namespace SocketBridge\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class AuthenticatedSession
{
    public function __construct(public Authenticatable $user, public array $evidence) {}
}
