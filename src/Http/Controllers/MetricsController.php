<?php

namespace SocketBridge\Http\Controllers;

use Illuminate\Http\Request;
use SocketBridge\Operations\PrometheusExporter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MetricsController
{
    public function __invoke(Request $request, PrometheusExporter $exporter): Response
    {
        $token = (string) config('socket-bridge.metrics.token', '');
        abort_unless(config('socket-bridge.metrics.enabled', false) && strlen($token) >= 32, 404);
        abort_unless(hash_equals($token, $request->bearerToken() ?? ''), 401);
        try {
            return response($exporter->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
        } catch (Throwable $error) {
            report($error);

            return response('Metrics unavailable.', 503, ['Cache-Control' => 'no-store']);
        }
    }
}
