import { randomUUID } from 'node:crypto';
export interface Config {
  metricsToken?: string; maxBufferedBytes: number; maxBufferedPackets: number;
  commandAckTimeoutMs: number; maxPendingCommandAcks: number; maxPendingCommandAcksTotal: number;
  prefix: string; secret: string; redisUrl: string; laravelUrl: string; authorizePath: string;
  host: string; port: number; tlsCert?: string; tlsKey?: string; origins: string[]; transports: ('websocket' | 'polling')[];
  instanceId: string; claimIdleMs: number; authCheckMs: number; roomLeaseSeconds: number; presenceReconcileMs: number;
  authTimeoutMs: number; maxPayloadBytes: number; maxRooms: number; maxConnections: number;
  rateLimit: number; rateWindowMs: number; maxAttempts: number; commandTtlSeconds: number;
}
function integer(env: NodeJS.ProcessEnv, key: string, fallback: number, min: number, max: number): number {
  const value = env[key] === undefined ? fallback : Number(env[key]);
  if (!Number.isSafeInteger(value) || value < min || value > max) throw new Error(`Invalid ${key}`);
  return value;
}
export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const metricsToken = env.SOCKET_BRIDGE_METRICS_TOKEN || undefined;
  if (metricsToken && metricsToken.length < 32) throw new Error('SOCKET_BRIDGE_METRICS_TOKEN must contain at least 32 characters');
  const prefix = env.SOCKET_BRIDGE_PREFIX ?? '';
  const secret = env.SOCKET_BRIDGE_SECRET ?? '';
  const redisUrl = env.SOCKET_BRIDGE_REDIS_URL ?? '';
  const laravelUrl = env.SOCKET_BRIDGE_LARAVEL_URL ?? '';
  if (!prefix || !/^[a-zA-Z0-9_.:-]{1,180}$/.test(prefix)) throw new Error('SOCKET_BRIDGE_PREFIX is required and must be a safe Redis namespace');
  if (secret.length < 32) throw new Error('SOCKET_BRIDGE_SECRET must contain at least 32 characters');
  if (!['redis:', 'rediss:'].includes(new URL(redisUrl).protocol)) throw new Error('SOCKET_BRIDGE_REDIS_URL must use redis:// or rediss://');
  const laravel = new URL(laravelUrl);
  if (!['http:', 'https:'].includes(laravel.protocol) || laravel.username || laravel.password || laravel.search || laravel.hash) throw new Error('Invalid SOCKET_BRIDGE_LARAVEL_URL');
  const authorizePath = env.SOCKET_BRIDGE_AUTHORIZE_PATH ?? '/socket-bridge/internal/authorize';
  if (!/^\/[a-zA-Z0-9_./-]+$/.test(authorizePath) || authorizePath.includes('..')) throw new Error('Invalid SOCKET_BRIDGE_AUTHORIZE_PATH');
  const tlsCert = env.SOCKET_BRIDGE_TLS_CERT || undefined;
  const tlsKey = env.SOCKET_BRIDGE_TLS_KEY || undefined;
  if (Boolean(tlsCert) !== Boolean(tlsKey)) throw new Error('SOCKET_BRIDGE_TLS_CERT and SOCKET_BRIDGE_TLS_KEY must be configured together');
  const origins = (env.SOCKET_BRIDGE_ORIGINS ?? '').split(',').map(v => v.trim()).filter(Boolean);
  for (const origin of origins) {
    const url = new URL(origin);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== origin) throw new Error('SOCKET_BRIDGE_ORIGINS must contain exact origins');
  }
  const transports = (env.SOCKET_BRIDGE_TRANSPORTS ?? 'websocket').split(',').map(v => v.trim());
  if (!transports.length || transports.some(v => !['websocket', 'polling'].includes(v))) throw new Error('Invalid SOCKET_BRIDGE_TRANSPORTS');
  return {
    commandAckTimeoutMs: integer(env, 'SOCKET_BRIDGE_COMMAND_ACK_TIMEOUT_MS', 30_000, 100, 300_000),
    maxPendingCommandAcks: integer(env, 'SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS', 32, 1, 1000),
    maxPendingCommandAcksTotal: integer(env, 'SOCKET_BRIDGE_MAX_PENDING_COMMAND_ACKS_TOTAL', 10_000, 1, 1_000_000),
    metricsToken, maxBufferedBytes: integer(env, 'SOCKET_BRIDGE_MAX_BUFFERED_BYTES', 1_048_576, 65_536, 67_108_864),
    maxBufferedPackets: integer(env, 'SOCKET_BRIDGE_MAX_BUFFERED_PACKETS', 1000, 10, 100_000),
    prefix, secret, redisUrl, authorizePath, tlsCert, tlsKey, laravelUrl: laravelUrl.replace(/\/$/, ''),
    host: env.SOCKET_BRIDGE_HOST ?? '127.0.0.1', port: integer(env, 'SOCKET_BRIDGE_PORT', 6001, 0, 65535),
    origins, transports: transports as Config['transports'], instanceId: env.SOCKET_BRIDGE_INSTANCE_ID ?? randomUUID(),
    claimIdleMs: integer(env, 'SOCKET_BRIDGE_CLAIM_IDLE_MS', 30_000, 100, 3_600_000),
    authCheckMs: integer(env, 'SOCKET_BRIDGE_AUTH_CHECK_MS', 15_000, 100, 60_000),
    presenceReconcileMs: integer(env, 'SOCKET_BRIDGE_PRESENCE_RECONCILE_MS', 30_000, 100, 300_000),
    roomLeaseSeconds: integer(env, 'SOCKET_BRIDGE_ROOM_LEASE_SECONDS', 30, 1, 300),
    authTimeoutMs: integer(env, 'SOCKET_BRIDGE_AUTH_TIMEOUT_MS', 5000, 100, 30_000),
    maxPayloadBytes: integer(env, 'SOCKET_BRIDGE_MAX_PAYLOAD_BYTES', 65_536, 1024, 1_048_576),
    maxRooms: integer(env, 'SOCKET_BRIDGE_MAX_ROOMS', 100, 1, 1000),
    maxConnections: integer(env, 'SOCKET_BRIDGE_MAX_CONNECTIONS', 10_000, 1, 1_000_000),
    rateLimit: integer(env, 'SOCKET_BRIDGE_RATE_LIMIT', 60, 1, 100_000),
    rateWindowMs: integer(env, 'SOCKET_BRIDGE_RATE_WINDOW_MS', 10_000, 100, 3_600_000),
    maxAttempts: integer(env, 'SOCKET_BRIDGE_MAX_ATTEMPTS', 5, 1, 100),
    commandTtlSeconds: integer(env, 'SOCKET_BRIDGE_COMMAND_TTL_SECONDS', 300, 1, 86_400),
  };
}
