<?php

namespace SocketBridge\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Bind your own implementation to enforce account activity/tenant revocation. */
interface SessionAuthorizer
{
    public function allowed(Authenticatable $user, array $evidence): bool;
}
