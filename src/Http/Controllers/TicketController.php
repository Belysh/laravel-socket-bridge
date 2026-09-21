<?php

namespace SocketBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SocketBridge\Auth\SessionManager;

class TicketController
{
    public function __invoke(Request $request, SessionManager $sessions): JsonResponse
    {
        return response()->json($sessions->issueTicket($request))->header('Cache-Control', 'no-store');
    }
}
