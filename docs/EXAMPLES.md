# Application examples

These examples assume an existing Laravel 13 application with login. Install the package and run migrations first. The gateway contains no application models or handlers.

## Handle an event in Laravel

1. Use the package defaults (`middleware = ['web', 'auth']`, default guard). Add `<meta name="csrf-token" content="{{ csrf_token() }}">` to your Blade layout.
2. Copy [UpdateDisplayName.php](../examples/laravel/UpdateDisplayName.php) into `app/SocketCommands/` and register it in an application service provider:

```php
use SocketBridge\Commands\CommandRegistry;

public function boot(CommandRegistry $commands): void
{
    $commands->register('profile.rename', \App\SocketCommands\UpdateDisplayName::class);
}
```

3. Add a normal authenticated HTTP endpoint to `routes/web.php` for authoritative state:

```php
use Illuminate\Support\Facades\Route;

Route::get('/account/profile', fn () => [
    'name' => request()->user()->name,
])->middleware('auth');
```

4. Start `php artisan socket-bridge:dev` alongside Laravel, connect with the [standard Socket.IO client](SOCKET_IO.md), and emit the registered event:

```js
socket.emit('profile.rename', { name: 'Ada' }, reply => {
  if (reply.ok) console.log(reply.data);
  else console.error(reply.error);
});
```

The server derives the user from the authenticated context. It emits through the outbox on the same database connection as the user update. If your users use another database connection, configure `socket-bridge.database_connection` accordingly.

Inside a command, use `$context->user` / `$context->userId` and `Gate::forUser($context->user)`. The worker has no browser HTTP request and does not impersonate the command user through global `auth()` or `request()`. Register handlers by class name to resolve their scoped dependencies anew for each command. Scoped instances and facade roots are cleared between commands; singleton services and explicitly registered handler objects intentionally persist and must not retain user-specific state.

Pass an explicit command ID when an action may be retried; keep the same ID and payload after an unknown outcome. Query authoritative state after reconnect and beyond the receipt retention window. See [acknowledgements and retries](SOCKET_IO.md#send-an-event-to-laravel).

## Private channels and HTTP-origin exclusion

In `routes/channels.php`:

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{id}', fn ($user, $id) =>
    $user->orders()->whereKey($id)->exists()
);
```

Authorize a real relationship or policy; knowing an order ID is not permission. The standard Laravel event in the README broadcasts to `new PrivateChannel('orders.42')`. Once the Socket.IO connection is established, join the channel using its canonical name:

```js
socket.emit('room:join', { channel: 'private-orders.42' }, reply => {
  if (!reply.ok) console.error(reply.error);
});
socket.on('order.updated', payload => console.log(payload));

// In the HTTP action, include the connected socket's ID.
await fetch('/orders/42/pay', {
  method: 'POST',
  credentials: 'include',
  headers: {
    Accept: 'application/json',
    'X-Socket-ID': socket.id,
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
  },
});
```

The HTTP action uses `broadcast(new OrderUpdated(...))->toOthers()`. `X-Socket-ID` identifies the connection to exclude; keep the request's normal authentication and CSRF headers.

## Sanctum bearer authentication

Configure `config/socket-bridge.php` with `middleware => ['api', 'auth:sanctum']` and `guard => 'sanctum'`. Your application must already have Sanctum and issue bearer tokens. Override client ticket retrieval:

```js
import { io } from 'socket.io-client';

const socket = io('https://realtime.example.com', {
  transports: ['websocket'],
  auth: async done => {
    try {
      const response = await fetch('/socket-bridge/token', {
        method: 'POST',
        signal: AbortSignal.timeout(10000),
        headers: { Accept: 'application/json', Authorization: `Bearer ${accessToken}` },
      });
      if (!response.ok) throw new Error(`Ticket request failed (${response.status})`);
      done({ token: (await response.json()).token });
    } catch (error) {
      console.error(error);
      done({ token: '' });
    }
  },
});
```

Use the same bearer-authenticated ticket request for [session refresh](SOCKET_IO.md#keep-a-session-alive).

For cross-origin requests, configure Laravel CORS for your frontend. For Sanctum's stateful cookie SPA mode, retain its normal CSRF/session setup instead of inventing bearer tokens. One bridge namespace represents one identity provider; do not combine different user tables with overlapping IDs.

## Fake emissions in application tests

```php
use SocketBridge\Facades\Socket;

Socket::fake();
Socket::durable()->toUser(42)->emit('profile.updated', ['name' => 'Ada']);

Socket::assertSentToUser(42, 'profile.updated');
Socket::assertSent('profile.updated', function ($payload, $envelope, $durable) {
    return $payload['name'] === 'Ada' && $durable;
});
Socket::assertSent('profile.updated', 1);
Socket::assertSentCount(1);
Socket::assertNotSent('profile.deleted');
```

`Socket::fake()` captures facade sends and native broadcasts executed in the test process. It does not invoke Redis, write outbox rows or revoke real sessions. Use `assertControlSent('socket.disconnect_user')` for control envelopes; see the exact control names in [Protocol](PROTOCOL.md). `assertNothingSent()` counts both events and controls. `recorded()` returns a collection of `['envelope' => array, 'durable' => bool]` records.

Keep a separate integration test for database transactions, queue execution and real transport. Fakes intentionally cannot prove those boundaries.
