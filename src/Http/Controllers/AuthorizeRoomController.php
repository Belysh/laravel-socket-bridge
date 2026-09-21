<?php

namespace SocketBridge\Http\Controllers;

use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use SocketBridge\Auth\SessionManager;
use SocketBridge\DTO\Envelope;

class AuthorizeRoomController
{
    public function __invoke(Request $request, SessionManager $sessions, Factory $broadcast): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'string', 'size:64'], 'channel' => ['required', 'string', 'max:200'], 'socket_id' => ['required', 'string', 'max:200']]);
        try {
            Envelope::room($data['channel']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['channel' => ['Invalid or reserved channel.']]);
        }
        $session = $sessions->resolve($data['session_id']);
        $request->setUserResolver(static fn ($guard = null) => $guard === null || $guard === $session->evidence['guard'] ? $session->user : null);
        $request->merge(['channel_name' => $data['channel']]);
        $response = $broadcast->connection(config('socket-bridge.broadcast_connection', 'socketio'))->auth($request);

        return response()->json($response)->header('Cache-Control', 'no-store');
    }
}
