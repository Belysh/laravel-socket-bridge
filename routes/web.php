<?php

use Illuminate\Support\Facades\Route;
use SocketBridge\Http\Controllers\AuthorizeRoomController;
use SocketBridge\Http\Controllers\MetricsController;
use SocketBridge\Http\Controllers\TicketController;
use SocketBridge\Http\Middleware\VerifyBridgeSignature;

Route::prefix(config('socket-bridge.route_prefix', 'socket-bridge'))->group(function (): void {
    Route::get('metrics', MetricsController::class)->name('socket-bridge.metrics');
    Route::post('token', TicketController::class)->middleware(config('socket-bridge.middleware', ['web', 'auth']))->name('socket-bridge.token');
    Route::post('internal/authorize', AuthorizeRoomController::class)->middleware(VerifyBridgeSignature::class)->name('socket-bridge.authorize');
});
