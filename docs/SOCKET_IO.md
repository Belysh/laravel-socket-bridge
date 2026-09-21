# Socket.IO integration

Install the standard client in your application:

```bash
npm install socket.io-client
```

The gateway accepts Socket.IO connections from any frontend. Laravel owns authentication, channel policies and command handlers.

## Connect with a Laravel session

Add the CSRF token and gateway URL to your Blade layout:

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="socket-bridge-url" content="{{ config('socket-bridge.gateway.public_url') }}">
```

In the application's JavaScript:

```js
import { io } from 'socket.io-client';

async function requestTicket() {
  const response = await fetch('/socket-bridge/token', {
    method: 'POST',
    signal: AbortSignal.timeout(10000),
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    },
  });
  if (!response.ok) throw new Error(`Authentication failed (${response.status})`);
  return (await response.json()).token;
}

const socket = io(document.querySelector('meta[name="socket-bridge-url"]').content, {
  transports: ['websocket'],
  auth: async done => {
    try {
      done({ token: await requestTicket() });
    } catch (error) {
      console.error(error);
      done({ token: '' });
    }
  },
});

socket.on('connect_error', error => console.error(error.message));
```

Tickets are single-use, so request a fresh one on every connection attempt. An authentication rejection is reported through `connect_error`; the example does not retry it. After restoring authentication, call `socket.connect()` explicitly. An HTTPS page needs an HTTPS gateway URL. For bearer authentication, send an `Authorization` header in the ticket request; see [Sanctum setup](EXAMPLES.md#sanctum-bearer-authentication).

## Subscribe and receive events

Join in `connect` so reconnecting restores the subscription. Use the canonical Laravel channel name:

```js
socket.on('connect', () => {
  socket.emit('room:join', { channel: 'private-orders.42' }, reply => {
    if (!reply.ok) console.error(reply.error);
  });
});

socket.on('order.updated', (order, metadata) => {
  console.log(order.status);
});
```

Laravel authorizes `private-orders.42` through `Broadcast::channel('orders.{id}', ...)`. `order.updated` is the event's `broadcastAs()` value. The gateway renews channel authorization while connected.

Register event listeners outside `connect` to avoid duplicates after reconnect. To stop listening, use `socket.off(event, listener)`; to leave a room, emit `room:leave` with `{channel}`. If multiple parts of your application share a room, coordinate when to leave it.

An event's optional second argument is `{id, created_at, v, channels}`. `channels` contains only matching target channels the recipient belongs to. An event targeting several subscribed channels is dispatched once to that socket. Transport retries can repeat an ID; use stable IDs or business versions if applying an event twice is unsafe.

## Send an event to Laravel

Register a Laravel command handler under the event name, then emit a plain payload:

```js
socket.emit('order.pay', { order_id: 42 }, reply => {
  if (reply.ok) console.log(reply.data);
  else console.error(reply.error);
});
```

The gateway writes the command to Redis Streams. A PHP worker verifies the authenticated session, executes the handler, commits its result, and returns it through the Socket.IO acknowledgement. Validate input and authorize business actions inside the handler:

- Success: `{ok: true, id, data}`.
- Failure: `{ok: false, id, error: {code, message, details?}}`.

Laravel validation errors use `error.code === 'validation.failed'` and `error.details.fields`. See the [PHP handler example](EXAMPLES.md#handle-an-event-in-laravel).

For an operation you may retry, generate its ID before sending and keep it with the submitted payload:

```js
const id = crypto.randomUUID();
const payload = { order_id: 42 };

socket.timeout(35000).emit('order.pay', payload, { id }, (timeout, reply) => {
  if (timeout || ['command.timeout', 'temporarily_unavailable'].includes(reply?.error?.code)) {
    console.error('Outcome unknown; retain this ID and payload:', id);
    return;
  }
  if (reply.ok) console.log(reply.data);
  else console.error(reply.error);
});
```

Retry the same event, payload and ID within the same authenticated session. Without an explicit ID, the gateway generates a new one for each emission. The server waits 30 seconds by default; timeout, disconnection or a Redis publication error can leave the outcome unknown and do not cancel work. A known validation failure needs a new ID after correcting the input. Receipt retention and external side effects are described in [Reliability](RELIABILITY.md#command-idempotency).

## Keep a session alive

The gateway emits `bridge.session` on connection and after refresh, with `{expires_at, refresh_after_ms}`. Before that deadline, obtain a new ticket through Laravel and refresh the connected session:

```js
let refreshTimer;
socket.on('bridge.session', ({ refresh_after_ms }) => {
  clearTimeout(refreshTimer);
  const connectionId = socket.id;
  refreshTimer = setTimeout(async () => {
    try {
      const token = await requestTicket();
      if (!socket.connected || socket.id !== connectionId) return;
      socket.timeout(10000).emit('session:refresh', { token }, (timeout, reply) => {
        if (timeout || !reply.ok) console.error(timeout ?? reply.error);
      });
    } catch (error) {
      console.error(error);
    }
  }, refresh_after_ms);
});
socket.on('disconnect', () => clearTimeout(refreshTimer));
```

The example replaces its refresh timer, clears it on disconnect, and discards a ticket fetched for an older connection. It logs refresh failures; the application should retry temporary ticket/refresh failures with bounded backoff before expiry; stop after an authentication rejection. A successful refresh produces the next `bridge.session` schedule. Refresh requires the same Laravel user and session; it cannot revive revoked access. Without refresh the gateway disconnects at expiry.

## Recover application state

Socket.IO reconnects after ordinary transport failures. The gateway may also disconnect deliberately and first emit `bridge.disconnect` with `{code, retryable}`. For a retryable server disconnect, call `socket.connect()` with bounded backoff and a fresh ticket. For revoked access, authenticate again first. A server-initiated disconnect does not trigger Socket.IO's automatic reconnect by itself.

Rejoin channels and reload authoritative application data after reconnect. Also reload after `bridge.subscription.restored`; `bridge.subscription.suspended` means delivery is temporarily paused, and `bridge.subscription.revoked` means channel access was denied. Missed events are not replayed.

## Presence and notifications

Join an authorized `presence-team.7` channel and listen for snapshots:

```js
socket.on('bridge.presence', ({ channel, members }) => {
  if (channel === 'presence-team.7') console.log(members);
});
```

Members are `{id, info}` values returned by Laravel's presence policy. Multiple tabs for the same user appear once. Compare successive snapshots if your UI needs join/leave changes; clear stale members after disconnect or suspended access.

Laravel broadcast notifications use the event `Illuminate\Notifications\Events\BroadcastNotificationCreated` on the notifiable's private channel, unless customized by the application. Subscribe through `room:join` and receive them with `socket.on()` just like any other event.

For the complete event and authentication contract, see [Protocol](PROTOCOL.md).
