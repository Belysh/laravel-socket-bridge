<?php

namespace SocketBridge\Commands;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use SocketBridge\Auth\SessionManager;
use SocketBridge\DTO\Envelope;
use SocketBridge\Exceptions\CommandRejected;
use SocketBridge\Outbox\OutboxStore;

class CommandProcessor
{
    public function __construct(private readonly SessionManager $sessions, private readonly CommandRegistry $registry, private readonly OutboxStore $outbox) {}

    public function valid(array $envelope): bool
    {
        $context = $envelope['context'] ?? null;

        return ($envelope['v'] ?? null) === 1 && ($envelope['type'] ?? null) === 'socket.command'
            && is_string($envelope['id'] ?? null) && Envelope::validId($envelope['id'])
            && is_string($envelope['command'] ?? null) && preg_match('/^[a-zA-Z0-9_.:-]{1,200}$/D', $envelope['command'])
            && strlen(json_encode($envelope['payload'] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS)) <= (int) config('socket-bridge.max_payload_bytes', 65536)
            && is_array($envelope['payload'] ?? null) && ($envelope['payload'] === [] || ! array_is_list($envelope['payload']))
            && is_array($context) && is_string($context['user_id'] ?? null) && strlen($context['user_id']) <= 191 && $context['user_id'] !== ''
            && is_string($context['session_id'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $context['session_id'])
            && (! array_key_exists('request_id', $context) || (is_string($context['request_id']) && Envelope::validId($context['request_id'])))
            && is_string($context['socket_id'] ?? null) && strlen($context['socket_id']) <= 200 && $context['socket_id'] !== '';
    }

    /** Persist business effects, receipt, and result together; retries return the receipt. */
    public function process(array $envelope, ?CommandRejected $forcedFailure = null): array
    {
        if (! $this->valid($envelope)) {
            throw new \InvalidArgumentException('Malformed bridge command.');
        }
        Envelope::payload($envelope['payload']);
        $contextData = $envelope['context'];
        try {
            $session = $this->sessions->resolve($contextData['session_id']);
            if (! hash_equals($session->evidence['user_id'], $contextData['user_id'])) {
                throw new AuthenticationException('Command identity does not match its session.');
            }
        } catch (AuthenticationException $error) {
            $result = $this->error('auth.unauthenticated', 'The realtime session is expired or revoked.');
            $this->outbox->store($this->resultEnvelope($envelope, $result));

            return $result;
        }
        $fingerprint = hash('sha256', json_encode($this->canonical(['command' => $envelope['command'], 'payload' => $envelope['payload']]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
        $database = $this->outbox->database();

        return $database->transaction(function () use ($database, $envelope, $contextData, $session, $fingerprint, $forcedFailure): array {
            $key = ['session_id' => $contextData['session_id'], 'command_id' => strtolower($envelope['id'])];
            // A no-op conflict update obtains an exclusive row lock directly.
            // INSERT IGNORE followed by FOR UPDATE can deadlock duplicate MySQL deliveries.
            $database->table('socket_bridge_command_receipts')->upsert([$key + [
                'fingerprint' => $fingerprint, 'user_id' => $contextData['user_id'], 'created_at' => now(), 'updated_at' => now(),
            ]], ['session_id', 'command_id'], ['command_id']);
            $receipt = $database->table('socket_bridge_command_receipts')->where($key)->lockForUpdate()->first();
            if (! hash_equals($receipt->fingerprint, $fingerprint) || ! hash_equals($receipt->user_id, $contextData['user_id'])) {
                $result = $this->error('command.id_conflict', 'This command id was already used with different input.');
            } elseif ($receipt->result !== null) {
                $result = json_decode($receipt->result, true, 512, JSON_THROW_ON_ERROR);
                $database->table('socket_bridge_command_receipts')->where($key)->update(['updated_at' => now()]);
            } else {
                try {
                    // Savepoint rolls back partial handler writes on terminal rejection.
                    $result = $database->transaction(function () use ($envelope, $contextData, $session, $forcedFailure): array {
                        // A delivery older than the deduplication window must never become a
                        // new mutation after its receipt has legitimately been pruned.
                        $created = isset($envelope['created_at']) && is_string($envelope['created_at']) ? strtotime($envelope['created_at']) : false;
                        if ($created === false || $created < time() - max(1, (int) config('socket-bridge.retention.receipts_seconds', 604800)) || $created > time() + 60) {
                            throw new CommandRejected('command.expired', 'The command is outside the deduplication window.');
                        }
                        if ($forcedFailure !== null) {
                            throw $forcedFailure;
                        }
                        if (isset($envelope['expires_at'])) {
                            $expiry = is_numeric($envelope['expires_at']) ? (int) $envelope['expires_at'] : strtotime((string) $envelope['expires_at']);
                            if ($expiry === false || $expiry <= time()) {
                                throw new CommandRejected('command.expired', 'The command expired before execution.');
                            }
                        }
                        $context = new CommandContext($session->user, $contextData['user_id'], $contextData['session_id'], $contextData['socket_id'], $envelope['id']);
                        $data = $this->registry->resolve($envelope['command'])->handle($envelope['payload'], $context);
                        Envelope::payload($data);

                        return ['ok' => true, 'data' => $data];
                    });
                } catch (ValidationException $error) {
                    $result = $this->error('validation.failed', 'The command input is invalid.', ['fields' => $error->errors()]);
                } catch (AuthenticationException $error) {
                    $result = $this->error('auth.unauthenticated', 'Authentication is required.');
                } catch (AuthorizationException $error) {
                    $result = $this->error('auth.forbidden', 'This command is not permitted.');
                } catch (CommandRejected $error) {
                    $result = $this->error($error->errorCode, $error->getMessage(), $error->details);
                }
                $database->table('socket_bridge_command_receipts')->where($key)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS), 'updated_at' => now()]);
            }
            // Each retry can target a new socket, but never repeats the mutation.
            $this->outbox->store($this->resultEnvelope($envelope, $result));

            return $result;
        }, 5);
    }

    private function resultEnvelope(array $command, array $result): array
    {
        $fields = [
            'command_id' => $command['id'], 'session_id' => $command['context']['session_id'],
            'socket_id' => $command['context']['socket_id'], 'user_id' => $command['context']['user_id'], 'result' => $result,
        ];
        if (isset($command['context']['request_id'])) {
            $fields['request_id'] = $command['context']['request_id'];
        }

        return Envelope::make('socket.command.result', $fields);
    }

    private function error(string $code, string $message, array $details = []): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return ['ok' => false, 'error' => $error];
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }
}
