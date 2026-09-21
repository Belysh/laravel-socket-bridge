import { createHmac, randomUUID } from 'node:crypto';
import { AsyncLocalStorage } from 'node:async_hooks';
import type { Server as HttpServer } from 'node:http';
import { Server, Socket } from 'socket.io';
import { createAdapter } from '@socket.io/redis-adapter';
import Redis from 'ioredis';
import type { Config } from './config';
import { BridgeError, channelRoom, Envelope, failure, hash, identity, Identity, object, parseEnvelope, PresenceMember, sessionRoom, string, userRoom, validChannel, validCommand, validId } from './protocol';

type Ack = (value: unknown) => void;
type Lease = { until: number; renewAt: number; nextAttempt: number; attempts: number; renewing: boolean; suspended: boolean; member?: PresenceMember };
type PendingAck = { requestId: string; respond: Ack; timer: NodeJS.Timeout };
type LocalState = { leases: Map<string, Lease>; revisions: Map<string, number>; lastAuth: number; operations: number; pending: Map<string, PendingAck> };
const CONTROL = 'bridge.internal.control.v1';
const FANOUT = 'bridge.internal.fanout.v1';
const RESULT = 'bridge.internal.result.v1';
const CLIENT_CONTROLS = new Set(['room:join', 'room:leave', 'session:refresh']);
const now = () => Math.floor(Date.now() / 1000);
const delay = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));
const parse = (raw: string | null): unknown => { try { return raw === null ? null : JSON.parse(raw); } catch { return null; } };
const RATE = `local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('PEXPIRE',KEYS[1],ARGV[1]) end; return n`;
const ACK_FAILED = `redis.call('XADD',KEYS[2],'*','envelope',ARGV[3]); redis.call('XACK',KEYS[1],ARGV[1],ARGV[2]); redis.call('DEL',KEYS[3]); return 1`;

export class GatewayRuntime {
  readonly redis: Redis;
  readonly reader: Redis;
  readonly publisher: Redis;
  readonly subscriber: Redis;
  io!: Server;
  private state = new Map<string, LocalState>();
  private pendingAckCount = 0;
  private publications = new AsyncLocalStorage<Promise<unknown>[]>();
  private stopping = false;
  private initialized = false;
  private consuming?: Promise<void>;
  private timer?: NodeJS.Timeout;
  private renewing = 0;
  private heartbeatTimer?: NodeJS.Timeout;
  readonly metrics = { connected: 0, disconnected: 0, refreshes: 0, renewals: 0, authorization_retries: 0, revoked: 0, commands_accepted: 0, events_accepted: 0, events_delivered: 0, events_failed: 0, events_duplicates: 0, events_retries: 0, slow_clients: 0 };
  private readonly observedEvents = new Set<string>();
  private readonly latencyBounds = [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 5];
  private readonly latencyBuckets = new Array<number>(10).fill(0);
  private latencyCount = 0;
  private latencySum = 0;
  private checkingSessions = false;
  private claimCursor = '0-0';
  private revisionSequence = 0;
  private nextClaim = 0;
  readonly consumer: string;

  constructor(readonly config: Config) {
    const options = { lazyConnect: true, connectTimeout: 5000, maxRetriesPerRequest: 1, enableOfflineQueue: false };
    this.redis = new Redis(config.redisUrl, options);
    this.reader = new Redis(config.redisUrl, options);
    this.publisher = new Redis(config.redisUrl, options);
    this.subscriber = new Redis(config.redisUrl, options);
    this.consumer = `${config.instanceId}:${randomUUID()}`;
    // The Redis adapter does not await pubClient.publish(). Track its promises
    // so a stream entry is ACKed only after Redis accepts the cluster handoff.
    // Calls outside an event context still have a rejection handler, preventing
    // an unhandled rejection from terminating Node during a Redis outage.
    const publish = this.publisher.publish.bind(this.publisher);
    this.publisher.publish = ((...args: unknown[]) => {
      const pending = (publish as (...args: unknown[]) => Promise<number>)(...args);
      pending.catch(() => this.log('adapter_publish_failed', 'redis_unavailable'));
      this.publications.getStore()?.push(pending);
      return pending;
    }) as Redis['publish'];
    for (const client of this.clients()) {
      client.on('error', () => { /* readiness exposes unavailability; never log connection credentials */ });
      client.on('close', () => {
        // A partial adapter outage can miss Pub/Sub while session Redis stays
        // healthy. Reconnect/resync every browser instead of hiding that gap.
        if (!this.stopping && this.io) for (const socket of this.io.sockets.sockets.values()) this.disconnect(socket, 'redis_unavailable', true);
      });
    }
  }
  key(suffix: string): string { return `${this.config.prefix}:${suffix}`; }
  private clients(): Redis[] { return [this.redis, this.reader, this.publisher, this.subscriber]; }
  async attach(server: HttpServer): Promise<void> {
    await Promise.all(this.clients().map(client => client.connect()));
    const version = (await this.redis.info('server')).match(/redis_version:(\d+)/)?.[1];
    if (!version || Number(version) < 7) throw new Error('Socket Bridge requires Redis 7 or later');
    await this.group();
    this.io = new Server(server, {
      transports: this.config.transports,
      maxHttpBufferSize: this.config.maxPayloadBytes + 4096,
      cors: { origin: this.config.origins, credentials: true },
      allowRequest: (request, callback) => {
        const origin = request.headers.origin;
        callback(null, !this.stopping && (!origin || this.config.origins.includes(origin)));
      },
      serveClient: false,
    });
    this.io.adapter(createAdapter(this.publisher, this.subscriber, { key: this.key('adapter'), requestsTimeout: 5000 }));
    this.io.use((socket, next) => {
      void this.authenticate(socket).then(() => next(), error => {
        const result = failure(error);
        const denied = Object.assign(new Error(result.message), { data: result });
        next(denied);
      });
    });
    this.io.on('connection', socket => this.connected(socket));
    this.io.on(FANOUT, (envelope: Envelope, ack: Ack) => {
      try { this.localEmit(envelope); ack({ ok: true }); } catch { ack({ ok: false }); }
    });
    this.io.on(RESULT, (envelope: Envelope, ack: Ack) => {
      try { this.localResult(envelope); ack({ ok: true }); } catch { ack({ ok: false }); }
    });
    this.io.on(CONTROL, (envelope: Envelope, ack: Ack) => {
      void this.localControl(envelope).then(() => ack({ ok: true }), () => ack({ ok: false }));
    });
    this.consuming = this.consume();
    this.timer = setInterval(() => void this.checkLeases(), Math.min(this.config.authCheckMs, 100));
    this.timer.unref();
    await this.heartbeat();
    this.heartbeatTimer = setInterval(() => void this.heartbeat(), 5000);
    this.heartbeatTimer.unref();
  }
  private async group(): Promise<void> {
    try { await this.redis.xgroup('CREATE', this.key('events'), 'gateways', '0', 'MKSTREAM'); }
    catch (error) { if (!(error instanceof Error && error.message.includes('BUSYGROUP'))) throw error; }
    this.initialized = true;
  }
  async ready(): Promise<boolean> {
    if (this.stopping || !this.initialized || this.clients().some(client => client.status !== 'ready')) return false;
    try { return await this.redis.ping() === 'PONG'; } catch { return false; }
  }
  private async limited(bucket: string, multiplier = 1): Promise<void> {
    const count = Number(await this.redis.eval(RATE, 1, this.key(`rate:${bucket}`), this.config.rateWindowMs));
    if (count > this.config.rateLimit * multiplier) throw new BridgeError('rate_limited', 'Too many realtime requests');
  }
  private async authenticate(socket: Socket): Promise<void> {
    if (!this.initialized || this.clients().some(client => client.status !== 'ready')) throw new BridgeError('redis_unavailable', 'Realtime dependencies are recovering');
    if (this.stopping || this.io.engine.clientsCount > this.config.maxConnections) throw new BridgeError('capacity', 'Realtime connection capacity reached');
    await this.limited(`connect:${hash(socket.handshake.address)}`, 5);
    const ticket = await this.consumeTicket(socket.handshake.auth?.token);
    socket.data.identity = ticket;
    socket.data.presence = {};
  }
  private async consumeTicket(token: unknown): Promise<Identity> {
    if (typeof token !== 'string' || !/^[a-f0-9]{64}$/i.test(token)) throw new BridgeError('unauthenticated', 'A valid connection ticket is required');
    const ticket = parse(await this.redis.getdel(this.key(`ticket:${hash(token)}`)));
    if (!identity(ticket)) throw new BridgeError('unauthenticated', 'Connection ticket is invalid or has expired');
    await this.validateSession(ticket);
    return ticket;
  }
  async validateSession(expected: Identity): Promise<void> {
    if (expected.expires_at <= now()) throw new BridgeError('session_expired', 'Realtime session has expired');
    const [raw, version] = await this.redis.mget(this.key(`session:${expected.session_id}`), this.key(`user-version:${hash(expected.user_id)}`));
    const session = parse(raw);
    if (!identity(session) || session.session_id !== expected.session_id || session.user_id !== expected.user_id || session.user_version !== expected.user_version || session.expires_at <= now() || Number(version ?? 0) !== expected.user_version) {
      throw new BridgeError('unauthenticated', 'Realtime session is no longer valid');
    }
  }
  private connected(socket: Socket): void {
    const who = socket.data.identity as Identity;
    this.state.set(socket.id, { leases: new Map(), revisions: new Map(), lastAuth: Date.now(), operations: 0, pending: new Map() });
    void socket.join([userRoom(who.user_id), sessionRoom(who.session_id)]);
    const route = (event: string, callback: (input: unknown) => Promise<unknown>) => socket.on(event, (input: unknown, ack?: Ack) => {
      void this.clientRequest(socket, input, callback).then(result => {
        if (typeof ack === 'function') ack({ ok: true, ...result as Record<string, unknown> });
      }, error => {
        if (typeof ack === 'function') ack({ ok: false, error: failure(error) });
      });
    });
    route('room:join', input => this.joinRequest(socket, input));
    route('room:leave', input => this.leaveRequest(socket, input));
    route('session:refresh', input => this.refreshSession(socket, input));
    socket.onAny((event: string, ...args: unknown[]) => {
      if (!CLIENT_CONTROLS.has(event)) this.namedCommand(socket, event, args);
    });
    this.metrics.connected++;
    this.sessionSchedule(socket);
    socket.on('disconnect', () => {
      this.metrics.disconnected++;
      const local = this.state.get(socket.id);
      if (local) for (const [id, pending] of local.pending) this.removePending(local, id, pending);
      this.state.delete(socket.id);
      if (!this.stopping && local) for (const channel of local.leases.keys()) if (channel.startsWith('presence-')) void this.presence(channel).catch(() => undefined);
    });
  }
  private async clientRequest(socket: Socket, input: unknown, callback: (input: unknown) => Promise<unknown>): Promise<unknown> {
    const local = this.state.get(socket.id);
    if (!local || !socket.connected) throw new BridgeError('unauthenticated', 'Socket is not connected');
    if (local.operations >= 4) throw new BridgeError('rate_limited', 'Too many concurrent realtime requests');
    local.operations++;
    try {
      if (Buffer.byteLength(JSON.stringify(input) ?? '') > this.config.maxPayloadBytes + 4096) throw new BridgeError('payload_too_large', 'Request exceeds payload limit');
      await this.limited(`session:${hash(socket.data.identity.session_id)}`);
      await this.validateSession(socket.data.identity);
      return await callback(input);
    } catch (error) {
      if (error instanceof BridgeError && ['unauthenticated', 'session_expired'].includes(error.code)) this.disconnect(socket, error.code, error.code === 'session_expired');
      throw error;
    } finally { local.operations--; }
  }
  private async authorize(socket: Socket, channel: string): Promise<Lease> {
    const body = JSON.stringify({ session_id: socket.data.identity.session_id, channel, socket_id: socket.id });
    const timestamp = String(now());
    const signature = createHmac('sha256', this.config.secret).update(`${timestamp}\n${body}`).digest('hex');
    const response = await fetch(`${this.config.laravelUrl}${this.config.authorizePath}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Socket-Bridge-Timestamp': timestamp, 'X-Socket-Bridge-Signature': signature },
      body, signal: AbortSignal.timeout(this.config.authTimeoutMs), redirect: 'error',
    });
    if ([401, 419].includes(response.status)) throw new BridgeError('unauthenticated', 'Laravel session is no longer valid');
    if (response.status === 403) throw new BridgeError('forbidden', 'Channel subscription is not authorized');
    if (!response.ok) throw new BridgeError('authorization_unavailable', 'Channel authorization is temporarily unavailable');
    const raw = await response.text();
    if (Buffer.byteLength(raw) > this.config.maxPayloadBytes) throw new BridgeError('authorization_unavailable', 'Invalid channel authorization response');
    const grant = parse(raw);
    if (!object(grant) || grant.allowed !== true) throw new BridgeError('forbidden', 'Channel subscription is not authorized');
    const expires = Number(grant.expires_in);
    if (!Number.isFinite(expires) || expires <= 0) throw new BridgeError('forbidden', 'Channel authorization has expired');
    const duration = Math.min(expires, this.config.roomLeaseSeconds) * 1000;
    const renewAt = Date.now() + duration * (0.6 + Math.random() * 0.1);
    const lease: Lease = { until: Date.now() + duration, renewAt, nextAttempt: renewAt, attempts: 0, renewing: false, suspended: false };
    if (channel.startsWith('presence-')) {
      if (!object(grant.member) || !string(grant.member.id) || !object(grant.member.info)) throw new BridgeError('forbidden', 'Invalid presence authorization');
      lease.member = { id: grant.member.id, info: grant.member.info };
    }
    return lease;
  }
  private async joinRequest(socket: Socket, input: unknown): Promise<{ channel: string }> {
    if (!object(input) || !validChannel(input.channel)) throw new BridgeError('invalid_channel', 'Invalid channel name');
    await this.join(socket, input.channel);
    return { channel: input.channel };
  }
  private async join(socket: Socket, channel: string): Promise<void> {
    const local = this.state.get(socket.id);
    if (!local) throw new BridgeError('unauthenticated', 'Socket is not connected');
    if (!local.leases.has(channel) && local.leases.size >= this.config.maxRooms) throw new BridgeError('room_limit', 'Subscription limit reached');
    const revision = ++this.revisionSequence;
    local.revisions.set(channel, revision);
    try {
      const grant = await this.authorize(socket, channel);
      // A logout or leave during the HTTP request must invalidate its response.
      await this.validateSession(socket.data.identity);
      if (!socket.connected || local.revisions.get(channel) !== revision) throw new BridgeError('subscription_cancelled', 'Subscription was cancelled');
      if (!local.leases.has(channel) && local.leases.size >= this.config.maxRooms) throw new BridgeError('room_limit', 'Subscription limit reached');
      await socket.join(channelRoom(channel));
      local.leases.set(channel, grant);
      if (grant.member) socket.data.presence[channel] = grant.member;
      if (grant.member) await this.presence(channel);
    } finally {
      if (!local.leases.has(channel) && local.revisions.get(channel) === revision) local.revisions.delete(channel);
    }
  }
  private async leaveRequest(socket: Socket, input: unknown): Promise<{ channel: string }> {
    if (!object(input) || !validChannel(input.channel)) throw new BridgeError('invalid_channel', 'Invalid channel name');
    await this.leave(socket, input.channel);
    return { channel: input.channel };
  }
  private async leave(socket: Socket, channel: string): Promise<void> {
    const local = this.state.get(socket.id);
    if (local) {
      local.revisions.delete(channel);
      local.leases.delete(channel);
    }
    delete socket.data.presence?.[channel];
    await socket.leave(channelRoom(channel));
    if (channel.startsWith('presence-') && !this.stopping) await this.presence(channel);
  }
  private async presence(channel: string): Promise<void> {
    const sockets = await this.io.in(channelRoom(channel)).fetchSockets();
    const members = new Map<string, PresenceMember>();
    for (const socket of sockets) {
      const member = socket.data.presence?.[channel];
      if (member && socket.data.identity) members.set(socket.data.identity.user_id, member);
    }
    this.io.to(channelRoom(channel)).emit('bridge.presence', { channel, members: [...members.values()].sort((a, b) => a.id.localeCompare(b.id)) });
  }
  private namedCommand(socket: Socket, name: string, args: unknown[]): void {
    const callback = typeof args.at(-1) === 'function' ? args.pop() as Ack : undefined;
    const payload = args[0], options = args[1];
    const id = object(options) && validId(options.id) ? options.id.toLowerCase() : randomUUID();
    let responded = false;
    const respond: Ack = value => { if (!responded) { responded = true; if (socket.connected) callback?.(value); } };
    void this.clientRequest(socket, { command: name, payload, options }, async () => {
      if (!validCommand(name) || !object(payload) || args.length < 1 || args.length > 2 || options !== undefined && (!object(options) || !validId(options.id) || Object.keys(options).some(key => key !== 'id'))) throw new BridgeError('invalid_command', 'Expected a named event, object payload and optional {id: UUID}');
      if (Buffer.byteLength(JSON.stringify(payload)) > this.config.maxPayloadBytes) throw new BridgeError('payload_too_large', 'Command payload exceeds configured limit');
      const local = this.state.get(socket.id);
      if (!local || !socket.connected) throw new BridgeError('unauthenticated', 'Socket is no longer connected');
      if (local.pending.has(id)) throw new BridgeError('command.pending', 'This command already has a pending acknowledgement on this socket');
      if (callback && (local.pending.size >= this.config.maxPendingCommandAcks || this.pendingAckCount >= this.config.maxPendingCommandAcksTotal)) throw new BridgeError('rate_limited', 'Too many pending command acknowledgements');
      const requestId = randomUUID();
      let pending: PendingAck | undefined;
      if (callback) {
        const timer = setTimeout(() => {
          if (pending && this.removePending(local, id, pending)) respond({ ok: false, id, error: { code: 'command.timeout', message: 'Command outcome is unknown. Retry the same id and input; execution is not cancelled.' } });
        }, this.config.commandAckTimeoutMs);
        timer.unref();
        pending = { requestId, respond, timer };
        local.pending.set(id, pending); this.pendingAckCount++;
      }
      const who = socket.data.identity as Identity;
      const envelope = { v: 1, id, type: 'socket.command', created_at: new Date().toISOString(), command: name, payload, context: { user_id: who.user_id, session_id: who.session_id, socket_id: socket.id, request_id: requestId }, expires_at: Math.min(who.expires_at, now() + this.config.commandTtlSeconds) };
      try {
        await this.redis.xadd(this.key('commands'), '*', 'envelope', JSON.stringify(envelope));
        this.metrics.commands_accepted++;
      } catch (error) {
        if (pending) this.removePending(local, id, pending);
        throw error;
      }
    }).catch(error => respond({ ok: false, id, error: failure(error) }));
  }
  private removePending(local: LocalState, id: string, pending: PendingAck): boolean {
    if (local.pending.get(id) !== pending) return false;
    clearTimeout(pending.timer); local.pending.delete(id); this.pendingAckCount--;
    return true;
  }
  private localResult(envelope: Envelope): void {
    const socket = this.io.sockets.sockets.get(envelope.socket_id as string);
    const local = socket && this.state.get(socket.id);
    if (!socket?.connected || !local || socket.data.identity?.session_id !== envelope.session_id || socket.data.identity?.user_id !== envelope.user_id) return;
    const id = (envelope.command_id as string).toLowerCase(), pending = local.pending.get(id);
    if (!pending || pending.requestId !== envelope.request_id || !this.removePending(local, id, pending)) return;
    const result = envelope.result as Record<string, unknown>;
    pending.respond(result.ok ? { ok: true, id, data: result.data } : { ok: false, id, error: result.error });
  }
  private disconnect(socket: Socket, code: string, retryable = false): void {
    if (!socket.connected) return;
    if (!retryable) this.metrics.revoked++;
    socket.emit('bridge.disconnect', { code, retryable });
    socket.disconnect(true);
  }
  private sessionSchedule(socket: Socket): void {
    const remaining = socket.data.identity.expires_at * 1000 - Date.now();
    socket.emit('bridge.session', { expires_at: socket.data.identity.expires_at, refresh_after_ms: Math.max(100, remaining - Math.min(60_000, remaining / 2)) });
  }
  private async refreshSession(socket: Socket, input: unknown): Promise<{ expires_at: number }> {
    if (!object(input) || Object.keys(input).some(key => key !== 'token')) throw new BridgeError('invalid_ticket', 'Expected a connection ticket');
    const previous = socket.data.identity as Identity;
    const renewed = await this.consumeTicket(input.token);
    if (renewed.user_id !== previous.user_id || renewed.session_id !== previous.session_id || renewed.user_version !== previous.user_version) throw new BridgeError('unauthenticated', 'A refresh ticket must match the existing session');
    // Recheck after consuming the ticket so concurrent revocation cannot revive access.
    await this.validateSession(previous);
    if (!socket.connected) throw new BridgeError('unauthenticated', 'Socket is not connected');
    socket.data.identity = renewed;
    const local = this.state.get(socket.id); if (local) local.lastAuth = Date.now();
    this.metrics.refreshes++;
    this.sessionSchedule(socket);
    return { expires_at: renewed.expires_at };
  }
  snapshot(): Record<string, unknown> {
    let subscriptions = 0;
    for (const local of this.state.values()) for (const lease of local.leases.values()) if (!lease.suspended) subscriptions++;
    return { connections: this.state.size, subscriptions, pending_acks: this.pendingAckCount, counters: { ...this.metrics }, latency: { bounds: [...this.latencyBounds], buckets: [...this.latencyBuckets], count: this.latencyCount, sum: this.latencySum }, memory: process.memoryUsage() };
  }
  prometheus(): string {
    const snapshot = this.snapshot();
    const lines: string[] = [];
    const metric = (name: string, kind: string, help: string, value: number) => lines.push(`# HELP ${name} ${help}`, `# TYPE ${name} ${kind}`, `${name} ${value}`);
    for (const [name, value] of Object.entries(this.metrics)) metric(`socket_bridge_gateway_${name}_total`, 'counter', name === 'events_delivered' ? 'Successful gateway dispatches; not acknowledgements from browsers.' : `Process-local ${name.replaceAll('_', ' ')} count.`, value);
    metric('socket_bridge_gateway_connections', 'gauge', 'Current connected sockets.', Number(snapshot.connections));
    metric('socket_bridge_gateway_subscriptions', 'gauge', 'Current unexpired channel grants.', Number(snapshot.subscriptions));
    metric('socket_bridge_gateway_heap_bytes', 'gauge', 'Current V8 heap usage.', process.memoryUsage().heapUsed);
    metric('socket_bridge_gateway_rss_bytes', 'gauge', 'Current process resident memory.', process.memoryUsage().rss);
    const name = 'socket_bridge_gateway_processing_seconds';
    lines.push(`# HELP ${name} Stream-entry processing duration including Redis acknowledgement.`, `# TYPE ${name} histogram`);
    this.latencyBounds.forEach((bound, index) => lines.push(`${name}_bucket{le="${bound}"} ${this.latencyBuckets[index]}`));
    lines.push(`${name}_bucket{le="+Inf"} ${this.latencyCount}`, `${name}_sum ${this.latencySum}`, `${name}_count ${this.latencyCount}`);
    return lines.join('\n') + '\n';
  }
  private buffered(socket: Socket): { bytes: number; packets: number } {
    const connection = socket.conn as unknown as { writeBuffer?: { data?: unknown }[]; transport?: { socket?: { bufferedAmount?: number } } };
    const packets = connection.writeBuffer ?? [];
    let bytes = connection.transport?.socket?.bufferedAmount ?? 0;
    for (const packet of packets) {
      const data = packet.data;
      if (typeof data === 'string') bytes += Buffer.byteLength(data);
      else if (Buffer.isBuffer(data)) bytes += data.byteLength;
      else if (ArrayBuffer.isView(data)) bytes += data.byteLength;
    }
    return { bytes, packets: packets.length };
  }
  private async heartbeat(): Promise<void> {
    if (this.stopping) return;
    let subscriptions = 0;
    for (const local of this.state.values()) for (const lease of local.leases.values()) if (!lease.suspended) subscriptions++;
    try {
      await this.redis.set(this.key(`health:gateway:${this.config.instanceId}`), JSON.stringify({ updated_at: new Date().toISOString(), protocol: 1, instance_id: this.config.instanceId, connections: this.state.size, subscriptions, metrics: this.metrics }), 'EX', 15);
    } catch { /* Expiry makes an unavailable gateway visible without stale health. */ }
  }
  private async checkLeases(): Promise<void> {
    if (this.stopping) return;
    const sockets = [...this.io.sockets.sockets.values()];
    const changedPresence = new Set<string>();
    for (const socket of sockets) {
      const queued = this.buffered(socket);
      if (queued.bytes > this.config.maxBufferedBytes || queued.packets > this.config.maxBufferedPackets) {
        this.metrics.slow_clients++; this.disconnect(socket, 'slow_client', true); continue;
      }
      if (socket.data.identity.expires_at <= now()) { this.disconnect(socket, 'session_expired', true); continue; }
      const local = this.state.get(socket.id);
      if (!local) continue;
      for (const [channel, lease] of local.leases) {
        // Independent expiry enforcement never waits for an HTTP authorization.
        if (lease.until <= Date.now() && !lease.suspended) {
          lease.suspended = true;
          void socket.leave(channelRoom(channel));
          delete socket.data.presence?.[channel];
          socket.emit('bridge.subscription.suspended', { channel, code: 'authorization_unavailable' });
          if (channel.startsWith('presence-')) changedPresence.add(channel);
        }
        if (!lease.renewing && lease.nextAttempt <= Date.now() && this.renewing < 64) {
          lease.renewing = true; this.renewing++;
          void this.renew(socket, channel, lease).finally(() => { lease.renewing = false; this.renewing--; });
        }
      }
    }
    for (const channel of changedPresence) void this.presence(channel).catch(() => undefined);
    void this.checkSessions(sockets);
  }
  private async checkSessions(sockets: Socket[]): Promise<void> {
    if (this.checkingSessions || this.stopping) return;
    this.checkingSessions = true;
    const due = sockets.filter(socket => {
      const local = this.state.get(socket.id);
      return socket.connected && local && Date.now() - local.lastAuth >= this.config.authCheckMs;
    });
    try {
      for (let index = 0; index < due.length; index += 500) {
        const batch = due.slice(index, index + 500);
        const values = await this.redis.mget(...batch.flatMap(socket => [
          this.key(`session:${socket.data.identity.session_id}`),
          this.key(`user-version:${hash(socket.data.identity.user_id)}`),
        ]));
        for (let offset = 0; offset < batch.length; offset++) {
          const socket = batch[offset];
          const expected = socket.data.identity as Identity;
          const session = parse(values[offset * 2]);
          if (!identity(session) || session.session_id !== expected.session_id || session.user_id !== expected.user_id || session.user_version !== expected.user_version || session.expires_at <= now() || Number(values[offset * 2 + 1] ?? 0) !== expected.user_version) this.disconnect(socket, 'unauthenticated');
          else { const local = this.state.get(socket.id); if (local) local.lastAuth = Date.now(); }
        }
      }
    } catch { for (const socket of due) this.disconnect(socket, 'redis_unavailable', true); }
    finally { this.checkingSessions = false; }
  }
  private async renew(socket: Socket, channel: string, lease: Lease): Promise<void> {
    const local = this.state.get(socket.id);
    if (!local || !socket.connected) return;
    const revision = local.revisions.get(channel);
    const current = () => socket.connected && local.leases.get(channel) === lease && local.revisions.get(channel) === revision;
    try {
      const renewed = await this.authorize(socket, channel);
      await this.validateSession(socket.data.identity);
      if (!current()) return;
      await socket.join(channelRoom(channel));
      local.leases.set(channel, renewed);
      this.metrics.renewals++;
      if (renewed.member) {
        socket.data.presence[channel] = renewed.member;
        if (lease.suspended || JSON.stringify(lease.member) !== JSON.stringify(renewed.member)) await this.presence(channel);
      }
      if (lease.suspended) socket.emit('bridge.subscription.restored', { channel });
    } catch (error) {
      if (!current()) return;
      if (error instanceof BridgeError && ['forbidden', 'unauthenticated', 'session_expired'].includes(error.code)) {
        await this.leave(socket, channel).catch(() => undefined);
        socket.emit('bridge.subscription.revoked', { channel, error: failure(error) });
        if (error.code !== 'forbidden') this.disconnect(socket, error.code, error.code === 'session_expired');
        return;
      }
      // Retain subscription intent after a transient failure. The expiry loop
      // removes access at its original deadline; successful reauthorization restores it.
      lease.attempts++;
      this.metrics.authorization_retries++;
      const backoff = Math.min(10_000, 250 * 2 ** Math.min(lease.attempts - 1, 6)) * (0.75 + Math.random() * 0.5);
      lease.nextAttempt = Date.now() + backoff;
    }
  }
  private async consume(): Promise<void> {
    while (!this.stopping) {
      try {
        if (!this.initialized) await this.group();
        if (Date.now() >= this.nextClaim) {
          const claimed = await this.reader.xautoclaim(this.key('events'), 'gateways', this.consumer, this.config.claimIdleMs, this.claimCursor, 'COUNT', 20) as [string, [string, string[]][], string[]];
          this.claimCursor = claimed[0];
          for (const entry of claimed[1]) await this.processEntry(entry);
          this.nextClaim = Date.now() + Math.min(1000, this.config.claimIdleMs);
        }
        const rows = await this.reader.xreadgroup('GROUP', 'gateways', this.consumer, 'COUNT', 20, 'BLOCK', 500, 'STREAMS', this.key('events'), '>') as [string, [string, string[]][]][] | null;
        if (rows) for (const [, entries] of rows) for (const entry of entries) await this.processEntry(entry);
      } catch (error) {
        if (this.stopping) break;
        this.initialized = false;
        this.log('consumer_retry', failure(error).code);
        await delay(250);
      }
    }
  }
  private async processEntry([id, fields]: [string, string[]]): Promise<void> {
    if (this.stopping) return;
    const started = performance.now();
    const attemptsKey = this.key(`event-attempts:${id}`);
    const attempts = await this.redis.incr(attemptsKey);
    await this.redis.expire(attemptsKey, 604800);
    if (attempts === 1) this.metrics.events_accepted++; else this.metrics.events_retries++;
    const raw = fields.length === 2 && fields[0] === 'envelope' ? fields[1] : '';
    try {
      const envelope = parseEnvelope(raw, this.config.maxPayloadBytes);
      if (this.observedEvents.has(envelope.id)) this.metrics.events_duplicates++;
      await this.withPublications(() => this.dispatch(envelope));
      this.metrics.events_delivered++;
      this.observedEvents.add(envelope.id);
      if (this.observedEvents.size > 5000) this.observedEvents.delete(this.observedEvents.values().next().value!);
      await this.redis.xack(this.key('events'), 'gateways', id);
      await this.redis.del(attemptsKey);
    } catch (error) {
      if (error instanceof BridgeError && error.terminal || attempts >= this.config.maxAttempts) {
        const failed = JSON.stringify({ source_id: id, failed_at: new Date().toISOString(), attempts, error: failure(error), envelope: parse(raw) ?? raw });
        // Copy and ACK atomically; an outage must not lose failed work.
        await this.redis.eval(ACK_FAILED, 3, this.key('events'), this.key('dead:events'), attemptsKey, 'gateways', id, failed);
        this.metrics.events_failed++;
        this.log('event_dead_lettered', failure(error).code);
      } else this.log('event_pending_retry', failure(error).code);
    } finally {
      const elapsed = (performance.now() - started) / 1000;
      this.latencyCount++; this.latencySum += elapsed;
      this.latencyBounds.forEach((bound, index) => { if (elapsed <= bound) this.latencyBuckets[index]++; });
    }
  }
  private async withPublications(action: () => Promise<void>): Promise<void> {
    await this.publications.run([], async () => {
      await action();
      await Promise.all(this.publications.getStore()!);
    });
  }
  private async dispatch(envelope: Envelope): Promise<void> {
    if (this.publisher.status !== 'ready' || this.subscriber.status !== 'ready') throw new BridgeError('redis_unavailable', 'Cluster delivery is temporarily unavailable');
    if (envelope.type === 'socket.emit') {
      this.localEmit(envelope);
      const replies = await this.io.serverSideEmitWithAck(FANOUT, envelope);
      if (replies.some(reply => !reply?.ok)) throw new BridgeError('fanout_unavailable', 'A gateway could not dispatch the event');
      return;
    }
    if (envelope.type === 'socket.command.result') {
      this.localResult(envelope);
      const replies = await this.io.serverSideEmitWithAck(RESULT, envelope);
      if (replies.some(reply => !reply?.ok)) throw new BridgeError('result_unavailable', 'A gateway could not acknowledge the command result');
      return;
    }
    await this.localControl(envelope);
    const replies = await this.io.serverSideEmitWithAck(CONTROL, envelope);
    if (replies.some(reply => !reply?.ok)) throw new BridgeError('control_unavailable', 'A gateway could not apply the control command');
  }
  private localEmit(envelope: Envelope): void {
    const targets = [...new Set(envelope.rooms as string[])];
    const channels = targets.filter(room => !room.startsWith('__'));
    const recipients = new Set<string>();
    for (const target of targets) {
      const physical = target.startsWith('__') ? target : channelRoom(target);
      for (const id of this.io.sockets.adapter.rooms.get(physical) ?? []) recipients.add(id);
    }
    const groups = new Map<string, { channels: string[]; sockets: string[] }>();
    for (const id of recipients) {
      const socket = this.io.sockets.sockets.get(id);
      if (!socket?.connected || id === envelope.except_socket || socket.data.identity?.user_id === envelope.except_user) continue;
      // Routing metadata must not disclose other private targets in a union.
      // Each socket is assigned once, while equal authorized intersections can
      // share one encoded packet. No cross-node socket inventory is required.
      const visible = channels.filter(channel => socket.rooms.has(channelRoom(channel)));
      const key = JSON.stringify(visible);
      let group = groups.get(key);
      if (!group) { group = { channels: visible, sockets: [] }; groups.set(key, group); }
      group.sockets.push(id);
    }
    for (const group of groups.values()) this.io.local.to(group.sockets).emit(envelope.event as string, envelope.payload, { id: envelope.id, created_at: envelope.created_at, v: 1, channels: group.channels });
  }
  private async localControl(envelope: Envelope): Promise<void> {
    for (const socket of this.io.sockets.sockets.values()) {
      const who = socket.data.identity as Identity;
      if (envelope.type === 'socket.disconnect_session') {
        if (who.session_id === envelope.session_id) this.disconnect(socket, 'session_revoked');
        continue;
      }
      if (who.user_id !== envelope.user_id) continue;
      if (envelope.type === 'socket.disconnect_user' || envelope.type === 'socket.auth.invalidate') this.disconnect(socket, 'session_revoked');
      if (envelope.type === 'socket.leave_room') await this.leave(socket, envelope.room as string);
      if (envelope.type === 'socket.join_room') {
        try { await this.validateSession(who); await this.join(socket, envelope.room as string); }
        catch (error) { if (!(error instanceof BridgeError && ['forbidden', 'unauthenticated', 'session_expired'].includes(error.code))) throw error; }
      }
    }
  }
  private log(event: string, code: string): void { process.stderr.write(`${JSON.stringify({ service: 'socket-bridge', event, code, instance: this.config.instanceId })}\n`); }
  async close(): Promise<void> {
    if (this.stopping) return;
    this.stopping = true;
    this.initialized = false;
    if (this.timer) clearInterval(this.timer);
    if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
    await this.redis.del(this.key(`health:gateway:${this.config.instanceId}`)).catch(() => undefined);
    this.reader.disconnect();
    await this.consuming;
    if (this.io) await new Promise<void>(resolve => this.io.close(() => resolve()));
    await Promise.all(this.clients().filter(client => client.status === 'ready').map(client => client.quit().catch(() => undefined)));
    for (const client of this.clients()) client.disconnect();
    this.state.clear();
  }
}
