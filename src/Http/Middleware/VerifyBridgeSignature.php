<?php

namespace SocketBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyBridgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (string) $request->header('X-Socket-Bridge-Timestamp', '');
        $signature = (string) $request->header('X-Socket-Bridge-Signature', '');
        $secret = (string) config('socket-bridge.internal_secret');
        if (strlen($secret) < 32 || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) config('socket-bridge.clock_skew', 30)
            || strlen($request->getContent()) > (int) config('socket-bridge.max_payload_bytes', 65536)
            || ! hash_equals(hash_hmac('sha256', $timestamp."\n".$request->getContent(), $secret), $signature)) {
            return response()->json(['allowed' => false, 'error' => ['code' => 'bridge.invalid_signature', 'message' => 'Invalid bridge signature.']], 401);
        }

        return $next($request);
    }
}
