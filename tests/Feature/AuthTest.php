<?php

namespace SocketBridge\Tests\Feature;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use SocketBridge\Auth\SessionManager;
use SocketBridge\Tests\TestCase;
use SocketBridge\Tests\TestUser;

class AuthTest extends TestCase
{
    public function test_ticket_is_opaque_short_lived_and_bound_to_source_session(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        $request = Request::create('/socket-bridge/token', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $request->session()->start();
        $request->setUserResolver(fn () => $user);
        $ticket = app(SessionManager::class)->issueTicket($request);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ticket['token']);
        self::assertSame(60, $ticket['expires_in']);
        $key = $this->redis->key('ticket:'.hash('sha256', $ticket['token']));
        $evidence = json_decode($this->redis->raw('GETDEL', $key), true);
        self::assertFalse($this->redis->raw('GETDEL', $key));
        self::assertSame((string) $user->id, app(SessionManager::class)->resolve($evidence['session_id'])->evidence['user_id']);
    }

    public function test_invalidated_user_session_cannot_be_resolved(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        $evidence = $this->sessionFor($user);
        app(SessionManager::class)->invalidateUser((string) $user->id);
        $this->expectException(AuthenticationException::class);
        app(SessionManager::class)->resolve($evidence['session_id']);
    }

    public function test_authorize_uses_native_callback_and_rejects_cross_user_channel(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        $session = $this->sessionFor($user);
        Broadcast::channel('user.{id}', fn ($actor, $id) => (string) $actor->id === $id);
        $this->signedRequest(['session_id' => $session['session_id'], 'channel' => 'private-user.'.$user->id, 'socket_id' => 'one'])->assertOk()->assertJsonPath('allowed', true);
        $this->signedRequest(['session_id' => $session['session_id'], 'channel' => 'private-user.999', 'socket_id' => 'one'])->assertForbidden();
    }

    public function test_presence_authorization_returns_member_and_info(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        $session = $this->sessionFor($user);
        Broadcast::channel('team.{id}', fn ($actor, $id) => ['name' => $actor->name]);
        $this->signedRequest(['session_id' => $session['session_id'], 'channel' => 'presence-team.1', 'socket_id' => 'one'])
            ->assertOk()->assertJsonPath('member.id', (string) $user->id)->assertJsonPath('member.info.name', 'Ada');
    }

    public function test_unsigned_and_old_signed_room_requests_are_rejected(): void
    {
        $this->postJson('/socket-bridge/internal/authorize', [])->assertUnauthorized();
        $this->signedRequest([], time() - 300)->assertUnauthorized();
    }

    public function test_internal_rooms_cannot_be_requested(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        $session = $this->sessionFor($user);
        $this->signedRequest(['session_id' => $session['session_id'], 'channel' => '__user:private', 'socket_id' => 'one'])->assertStatus(422);
    }

    public function test_deleted_source_session_is_rejected_and_bridge_session_is_removed(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        [$request, $evidence] = $this->sourceSessionTicket($user);
        self::assertSame((string) $user->id, app(SessionManager::class)->resolve($evidence['session_id'])->evidence['user_id']);
        $request->session()->getHandler()->destroy($request->session()->getId());
        try {
            app(SessionManager::class)->resolve($evidence['session_id']);
            self::fail('A deleted source session must not authorize realtime.');
        } catch (AuthenticationException) {
            self::assertFalse($this->redis->raw('GET', $this->redis->key('session:'.$evidence['session_id'])));
        }
    }

    public function test_source_password_change_invalidates_existing_realtime_evidence(): void
    {
        $user = TestUser::create(['name' => 'Ada', 'password' => 'original-hash']);
        [$request, $evidence] = $this->sourceSessionTicket($user);
        $user->update(['password' => 'changed-hash']);
        $this->expectException(AuthenticationException::class);
        app(SessionManager::class)->resolve($evidence['session_id']);
    }

    public function test_current_device_logout_revokes_the_matching_socket_session(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        [$request, $evidence] = $this->sourceSessionTicket($user);
        $this->app->instance('request', $request);
        event(new CurrentDeviceLogout('web', $user));
        self::assertFalse($this->redis->raw('GET', $this->redis->key('session:'.$evidence['session_id'])));
        self::assertSame('socket.disconnect_session', $this->redis->envelopes[0]['envelope']['type']);
    }

    public function test_logout_other_devices_invalidates_all_realtime_tickets(): void
    {
        $user = TestUser::create(['name' => 'Ada']);
        event(new OtherDeviceLogout('web', $user));
        self::assertSame(1, app(SessionManager::class)->userVersion((string) $user->id));
        self::assertSame('socket.auth.invalidate', $this->redis->envelopes[0]['envelope']['type']);
    }

    private function sourceSessionTicket(TestUser $user): array
    {
        $request = Request::create('/socket-bridge/token', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $request->session()->start();
        $request->session()->put($this->app['auth']->guard('web')->getName(), $user->id);
        $request->session()->save();
        $request->setUserResolver(fn () => $user);
        $ticket = app(SessionManager::class)->issueTicket($request);
        $evidence = json_decode($this->redis->raw('GET', $this->redis->key('ticket:'.hash('sha256', $ticket['token']))), true);

        return [$request, $evidence];
    }

    private function signedRequest(array $body, ?int $timestamp = null)
    {
        $timestamp ??= time();
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp."\n".$raw, config('socket-bridge.internal_secret'));

        return $this->call('POST', '/socket-bridge/internal/authorize', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SOCKET_BRIDGE_TIMESTAMP' => (string) $timestamp, 'HTTP_X_SOCKET_BRIDGE_SIGNATURE' => $signature,
        ], $raw);
    }
}
