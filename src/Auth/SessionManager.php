<?php

namespace SocketBridge\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Http\Request;
use SocketBridge\Contracts\SessionAuthorizer;
use SocketBridge\Transport\RedisStreams;

class SessionManager
{
    public function __construct(private readonly RedisStreams $redis, private readonly Factory $auth, private readonly SessionAuthorizer $authorizer) {}

    public function issueTicket(Request $request): array
    {
        $guard = (string) (config('socket-bridge.guard') ?: $this->auth->getDefaultDriver());
        $user = $request->user($guard);
        if (! $user instanceof Authenticatable) {
            throw new AuthenticationException;
        }
        if ((string) $user->getAuthIdentifier() === '' || strlen((string) $user->getAuthIdentifier()) > 191) {
            throw new AuthenticationException('Realtime user identifiers must be 1 to 191 bytes.');
        }
        $evidence = $this->evidence($request, $guard, $user);
        if (! $this->authorizer->allowed($user, $evidence)) {
            throw new AuthenticationException('This account cannot open a realtime connection.');
        }
        $ttl = $evidence['expires_at'] - time();
        if ($ttl < 1) {
            throw new AuthenticationException('The authentication session has expired.');
        }
        $ticket = bin2hex(random_bytes(32));
        $ticketTtl = max(1, min(300, (int) config('socket-bridge.ticket_ttl', 60), $ttl));
        $this->redis->raw('SET', $this->redis->key('session:'.$evidence['session_id']), json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS), 'EX', $ttl);
        $ticketData = array_intersect_key($evidence, array_flip(['user_id', 'session_id', 'user_version', 'expires_at']));
        $this->redis->raw('SET', $this->redis->key('ticket:'.hash('sha256', $ticket)), json_encode($ticketData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS), 'EX', $ticketTtl);

        return ['token' => $ticket, 'expires_in' => $ticketTtl, 'session_expires_at' => $evidence['expires_at'], 'url' => config('socket-bridge.gateway.public_url')];
    }

    public function resolve(string $sessionId): AuthenticatedSession
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $sessionId)) {
            throw new AuthenticationException('Invalid realtime session.');
        }
        $raw = $this->redis->raw('GET', $this->redis->key('session:'.$sessionId));
        $evidence = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($evidence) || ($evidence['session_id'] ?? '') !== $sessionId || (int) ($evidence['expires_at'] ?? 0) <= time()
            || (int) ($evidence['user_version'] ?? -1) !== $this->userVersion((string) ($evidence['user_id'] ?? ''))) {
            throw new AuthenticationException('Realtime session expired or revoked.');
        }
        $provider = $this->auth->createUserProvider($evidence['provider'] ?? null);
        $user = $provider?->retrieveById($evidence['user_id']);
        if (! $user instanceof Authenticatable || ! $this->authorizer->allowed($user, $evidence)) {
            throw new AuthenticationException('Realtime identity is unavailable.');
        }
        if (isset($evidence['password_fingerprint']) && ! hash_equals($evidence['password_fingerprint'], $this->passwordFingerprint($user))) {
            $this->disconnectSession($sessionId);
            throw new AuthenticationException('The source credentials have changed.');
        }
        if (isset($evidence['source_session_id'], $evidence['source_login_key'])) {
            $source = clone app('session.store');
            $source->setHandler(clone $source->getHandler());
            $source->flush();
            $source->setId($evidence['source_session_id']);
            $source->start();
            if (! hash_equals((string) $user->getAuthIdentifier(), (string) $source->get($evidence['source_login_key'], ''))) {
                $this->disconnectSession($sessionId);
                throw new AuthenticationException('The source Laravel session expired or was revoked.');
            }
        }
        if (isset($evidence['token_id'])) {
            if (! method_exists($user, 'tokens')) {
                throw new AuthenticationException('Token authentication is unavailable.');
            }
            $token = $user->tokens()->find($evidence['token_id']);
            $sanctumMinutes = config('sanctum.expiration');
            if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())
                || ($sanctumMinutes && $token->created_at->lte(now()->subMinutes($sanctumMinutes)))) {
                $this->disconnectSession($sessionId);
                throw new AuthenticationException('Source access token expired or revoked.');
            }
            if (method_exists($user, 'withAccessToken')) {
                $user->withAccessToken($token);
            }
        }

        return new AuthenticatedSession($user, $evidence);
    }

    public function userVersion(string $userId): int
    {
        return (int) $this->redis->raw('GET', $this->redis->key('user-version:'.hash('sha256', $userId)));
    }

    public function invalidateUser(string $userId): void
    {
        $this->redis->raw('INCR', $this->redis->key('user-version:'.hash('sha256', $userId)));
    }

    public function disconnectSession(string $sessionId): void
    {
        $this->redis->raw('DEL', $this->redis->key('session:'.$sessionId));
    }

    public function sessionIdFor(Request $request, string $guard, Authenticatable $user): string
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $tokenId = is_object($token) && method_exists($token, 'getKey') ? $token->getKey() : null;
        $identity = $tokenId !== null ? 'token:'.$tokenId : ($request->hasSession() && $request->session()->getId() !== '' ? 'session:'.$request->session()->getId() : null);
        if ($identity === null) {
            throw new AuthenticationException('Socket Bridge needs a session or a persisted access token.');
        }
        $secret = (string) config('socket-bridge.internal_secret');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('SOCKET_BRIDGE_SECRET must contain at least 32 characters.');
        }

        return hash_hmac('sha256', $guard.'|'.(string) $user->getAuthIdentifier().'|'.$identity, $secret);
    }

    private function passwordFingerprint(Authenticatable $user): string
    {
        return hash_hmac('sha256', (string) $user->getAuthPassword(), (string) config('socket-bridge.internal_secret'));
    }

    private function evidence(Request $request, string $guard, Authenticatable $user): array
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $tokenId = is_object($token) && method_exists($token, 'getKey') ? $token->getKey() : null;
        $ttl = max(1, (int) config('socket-bridge.session_ttl', 3600));
        if ($tokenId === null) {
            $ttl = min($ttl, (int) config('session.lifetime', 120) * 60);
        }
        $expiry = time() + $ttl;
        if ($tokenId !== null && $token->expires_at !== null) {
            $expiry = min($expiry, $token->expires_at->getTimestamp());
        }
        if ($tokenId !== null && config('sanctum.expiration')) {
            $expiry = min($expiry, $token->created_at->copy()->addMinutes(config('sanctum.expiration'))->getTimestamp());
        }
        $provider = config('socket-bridge.provider') ?: config('auth.guards.'.$guard.'.provider') ?: config('auth.defaults.provider', 'users');
        $evidence = [
            'user_id' => (string) $user->getAuthIdentifier(),
            'session_id' => $this->sessionIdFor($request, $guard, $user),
            'guard' => $guard,
            'provider' => $provider,
            'user_version' => $this->userVersion((string) $user->getAuthIdentifier()),
            'expires_at' => $expiry,
        ];
        if ($tokenId !== null) {
            $evidence['token_id'] = (string) $tokenId;
        } else {
            if ($user->getAuthPassword()) {
                $evidence['password_fingerprint'] = $this->passwordFingerprint($user);
            }
            // Server-side stores let later authorization reject deleted/expired
            // source sessions. Cookie stores instead use expiry + logout hooks.
            if (! $request->session()->handlerNeedsRequest()) {
                foreach (array_unique([$guard, ...(array) config('sanctum.guard', [])]) as $sourceGuard) {
                    $candidate = $this->auth->guard($sourceGuard);
                    if (method_exists($candidate, 'getName') && (string) $request->session()->get($candidate->getName()) === (string) $user->getAuthIdentifier()) {
                        $evidence['source_session_id'] = $request->session()->getId();
                        $evidence['source_login_key'] = $candidate->getName();
                        break;
                    }
                }
            }
        }

        return $evidence;
    }
}
