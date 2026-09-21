<?php

namespace SocketBridge\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Http\Request;
use SocketBridge\SocketManager;

class RevokeLoggedOutSession
{
    public function __construct(private readonly SessionManager $sessions, private readonly SocketManager $socket, private readonly Request $request) {}

    public function handle(Logout|CurrentDeviceLogout|OtherDeviceLogout $event): void
    {
        if ($event->user === null) {
            return;
        }
        if ($event instanceof OtherDeviceLogout) {
            $this->socket->invalidateUser((string) $event->user->getAuthIdentifier(), 'other_devices_logged_out');

            return;
        }
        try {
            $id = $this->sessions->sessionIdFor($this->request, $event->guard, $event->user);
        } catch (AuthenticationException) {
            return;
        }
        $this->socket->disconnectSession($id);
    }
}
