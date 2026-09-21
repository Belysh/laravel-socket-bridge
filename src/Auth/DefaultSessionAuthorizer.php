<?php

namespace SocketBridge\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use SocketBridge\Contracts\SessionAuthorizer;

class DefaultSessionAuthorizer implements SessionAuthorizer
{
    public function allowed(Authenticatable $user, array $evidence): bool
    {
        return true;
    }
}
