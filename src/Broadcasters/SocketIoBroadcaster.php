<?php

namespace SocketBridge\Broadcasters;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use SocketBridge\DTO\Envelope;
use SocketBridge\DTO\Json;
use SocketBridge\EnvelopePublisher;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SocketIoBroadcaster extends Broadcaster
{
    public function __construct(private readonly EnvelopePublisher $publisher) {}

    public function auth($request)
    {
        $wireChannel = (string) $request->input('channel_name');
        Envelope::room($wireChannel);
        $channel = preg_replace('/^(private-|presence-)/', '', $wireChannel);
        if (! $this->retrieveUser($request, $channel)) {
            throw new AccessDeniedHttpException;
        }
        if (! str_starts_with($wireChannel, 'private-') && ! str_starts_with($wireChannel, 'presence-')) {
            return $this->validAuthenticationResponse($request, true);
        }

        return $this->verifyUserCanAccessChannel($request, $channel);
    }

    public function validAuthenticationResponse($request, $result)
    {
        $response = ['allowed' => true, 'expires_in' => (int) config('socket-bridge.room_lease', 30)];
        if (str_starts_with((string) $request->input('channel_name'), 'presence-')) {
            $response['member'] = ['id' => (string) $request->user()->getAuthIdentifier(), 'info' => is_array($result) ? (object) $result : (object) []];
        }

        return $response;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        Envelope::event((string) $event);
        $socket = $payload['socket'] ?? null;
        unset($payload['socket']);
        $payload = Json::object($payload);
        Envelope::payload($payload);
        $rooms = array_values(array_unique(array_map(static fn ($channel) => Envelope::room((string) $channel), $channels)));
        if ($rooms === []) {
            return;
        }
        if (count($rooms) > 1000) {
            throw new \InvalidArgumentException('A broadcast may target at most 1000 channels.');
        }
        $fields = ['event' => (string) $event, 'rooms' => $rooms, 'payload' => $payload];
        if (is_string($socket) && $socket !== '') {
            $fields['except_socket'] = Envelope::identifier($socket);
        }
        $this->publisher->publish(Envelope::make('socket.emit', $fields));
    }
}
