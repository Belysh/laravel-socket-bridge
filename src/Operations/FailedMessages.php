<?php

namespace SocketBridge\Operations;

use Illuminate\Auth\AuthenticationException;
use SocketBridge\Auth\SessionManager;
use SocketBridge\Commands\CommandProcessor;
use SocketBridge\DTO\Envelope;
use SocketBridge\Outbox\OutboxStore;
use SocketBridge\Transport\RedisStreams;

final class FailedMessages
{
    public function __construct(private readonly RedisStreams $redis, private readonly SessionManager $sessions, private readonly CommandProcessor $processor, private readonly OutboxStore $outbox) {}

    public function inspect(string $stream, int $limit = 20): array
    {
        $this->stream($stream);

        return array_map(function (array $entry) use ($stream): array {
            $failed = $entry['envelope'] ?? [];

            return ['id' => $entry['id'], 'source_id' => $failed['source_id'] ?? null, 'reason' => $failed['reason'] ?? $failed['error'] ?? null,
                'failed_at' => $failed['failed_at'] ?? null, 'original' => $this->original($failed),
                'replayed_as' => $this->redis->raw('GET', $this->redis->key('replayed:'.$stream.':'.$entry['id'])) ?: null];
        }, $this->redis->range('dead:'.$stream, limit: $limit, reverse: true));
    }

    /** A replay retains the command/event UUID and all original authorization evidence. */
    public function replay(string $stream, string $id): array
    {
        $this->stream($stream);
        if (! preg_match('/^\d+-\d+$/D', $id)) {
            throw new \InvalidArgumentException('A Redis dead-letter entry ID is required.');
        }
        $marker = $this->redis->key('replayed:'.$stream.':'.$id);
        $lock = $marker.':lock';
        $token = bin2hex(random_bytes(16));
        if (! $this->redis->raw('SET', $lock, $token, 'NX', 'EX', 60)) {
            throw new \RuntimeException('This entry is already being replayed.');
        }
        try {
            if ($existing = $this->redis->raw('GET', $marker)) {
                return ['id' => $id, 'stream_id' => $existing, 'already_replayed' => true];
            }
            $entry = $this->redis->range('dead:'.$stream, $id, $id, 1)[0] ?? null;
            $envelope = $this->original($entry['envelope'] ?? []);
            if (! is_array($envelope) || ($envelope['v'] ?? null) !== 1 || ! Envelope::validId($envelope['id'] ?? null)) {
                throw new \InvalidArgumentException('The dead-letter entry does not contain a valid replayable envelope.');
            }
            if ($stream === 'commands') {
                if (! $this->processor->valid($envelope)) {
                    throw new \InvalidArgumentException('The original command is malformed.');
                }
                $this->session($envelope['context']['session_id'], $envelope['context']['user_id']);
                $created = is_string($envelope['created_at'] ?? null) ? strtotime($envelope['created_at']) : false;
                $expiry = isset($envelope['expires_at']) ? (is_numeric($envelope['expires_at']) ? (int) $envelope['expires_at'] : strtotime((string) $envelope['expires_at'])) : PHP_INT_MAX;
                if ($created === false || $created < time() - max(1, (int) config('socket-bridge.retention.receipts_seconds', 604800)) || $created > time() + 60 || $expiry === false || $expiry <= time()) {
                    throw new \InvalidArgumentException('The original command is expired; it cannot be replayed.');
                }
                $this->releaseTransientFailure($envelope);
            } else {
                $this->event($envelope);
            }
            $streamId = $this->redis->add($stream, $envelope);
            $this->redis->raw('SET', $marker, $streamId, 'EX', max(60, (int) config('socket-bridge.retention.dead_letters_seconds', 604800)));

            return ['id' => $id, 'envelope_id' => $envelope['id'], 'stream_id' => $streamId, 'already_replayed' => false];
        } finally {
            $this->redis->raw('EVAL', "if redis.call('GET',KEYS[1]) == ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0", 1, $lock, $token);
        }
    }

    private function releaseTransientFailure(array $envelope): void
    {
        $db = $this->outbox->database();
        $db->transaction(function () use ($db, $envelope): void {
            $query = $db->table('socket_bridge_command_receipts')->where('session_id', $envelope['context']['session_id'])->where('command_id', strtolower($envelope['id']));
            $receipt = (clone $query)->lockForUpdate()->first();
            if ($receipt === null || (json_decode($receipt->result ?? '{}', true)['error']['code'] ?? null) !== 'command.failed') {
                return;
            }
            // Only exhausted transient failures can be retried. Successful mutations
            // and terminal authorization/validation rejections retain their receipt.
            $db->table('socket_bridge_outbox')->whereNull('published_at')
                ->where('envelope->type', 'socket.command.result')->where('envelope->session_id', $envelope['context']['session_id'])
                ->whereRaw('LOWER('.$db->getQueryGrammar()->wrap('envelope->command_id').') = ?', [strtolower($envelope['id'])])
                ->where('envelope->result->error->code', 'command.failed')->delete();
            $query->update(['result' => null, 'updated_at' => now()]);
        });
    }

    private function event(array $envelope): void
    {
        switch ($envelope['type'] ?? null) {
            case 'socket.emit':
                if (! is_array($envelope['payload'] ?? null) || ! is_string($envelope['event'] ?? null) || ! is_array($envelope['rooms'] ?? null) || $envelope['rooms'] === []) {
                    throw new \InvalidArgumentException('Malformed event.');
                }
                Envelope::payload($envelope['payload']);
                Envelope::event($envelope['event']);
                foreach ($envelope['rooms'] as $room) {
                    Envelope::room($room, true);
                }
                break;
            case 'socket.command.result':
                $this->session($envelope['session_id'] ?? '', $envelope['user_id'] ?? '');
                break;
            case 'socket.join_room': case 'socket.leave_room':
                Envelope::room($envelope['room'] ?? '');
                Envelope::identifier($envelope['user_id'] ?? '', 191);
                break;
            case 'socket.disconnect_user': case 'socket.auth.invalidate':
                Envelope::identifier($envelope['user_id'] ?? '', 191);
                break;
            case 'socket.disconnect_session':
                if (! preg_match('/^[a-f0-9]{64}$/D', $envelope['session_id'] ?? '')) {
                    throw new \InvalidArgumentException('Invalid session id.');
                }
                break;
            default: throw new \InvalidArgumentException('Unsupported event type for replay.');
        }
    }

    private function original(array $failed): ?array
    {
        $raw = $failed['raw'] ?? $failed['envelope'] ?? null;
        $original = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($original) ? $original : null;
    }

    private function session(string $id, string $user): void
    {
        $session = $this->sessions->resolve($id);
        if (! hash_equals($session->evidence['user_id'], $user)) {
            throw new AuthenticationException('Original session identity is no longer valid.');
        }
    }

    private function stream(string $stream): void
    {
        if (! in_array($stream, ['commands', 'events'], true)) {
            throw new \InvalidArgumentException('Stream must be commands or events.');
        }
    }
}
