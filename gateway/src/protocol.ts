import { createHash } from 'node:crypto';
export class BridgeError extends Error {
  constructor(public readonly code: string, message: string, public readonly terminal = false) { super(message); }
}
export function object(value: unknown): value is Record<string, unknown> { return value !== null && typeof value === 'object' && !Array.isArray(value); }
export function string(value: unknown, max = 200): value is string { return typeof value === 'string' && value.length > 0 && value.length <= max; }
export const hash = (value: string): string => createHash('sha256').update(value).digest('hex');
export const userRoom = (id: string): string => `__user:${hash(id)}`;
export const sessionRoom = (id: string): string => `__session:${hash(id)}`;
// Physical client rooms cannot overlap Socket.IO's implicit per-socket rooms.
export const channelRoom = (channel: string): string => `__channel:${channel}`;
export const validChannel = (value: unknown): value is string => typeof value === 'string' && /^[a-zA-Z0-9_.:-]{1,200}$/.test(value) && !value.startsWith('__');
export const validId = (value: unknown): value is string => typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
const reserved = new Set(['connect', 'connect_error', 'disconnect', 'disconnecting', 'newListener', 'removeListener']);
export const validCommand = (value: unknown): value is string => string(value) && /^[a-zA-Z0-9_.:-]+$/.test(value) && !reserved.has(value) && !value.startsWith('bridge.') && !['room:join', 'room:leave', 'session:refresh', 'command'].includes(value);
export const validEvent = (value: unknown): value is string => string(value) && !reserved.has(value) && !value.startsWith('bridge.');
export interface Identity { user_id: string; session_id: string; user_version: number; expires_at: number; }
export interface PresenceMember { id: string; info: Record<string, unknown>; }
export interface Envelope extends Record<string, unknown> { v: 1; id: string; type: string; created_at: string; }
export function identity(value: unknown): value is Identity {
  return object(value) && string(value.user_id) && string(value.session_id) && Number.isSafeInteger(value.user_version) && Number(value.user_version) >= 0 && Number.isSafeInteger(value.expires_at);
}
export function parseEnvelope(raw: string, maxBytes: number): Envelope {
  // Room routing metadata is bounded separately from application payload bytes.
  if (Buffer.byteLength(raw) > maxBytes + 262_144) throw new BridgeError('invalid_envelope', 'Envelope exceeds configured limit', true);
  let value: unknown;
  try { value = JSON.parse(raw); } catch { throw new BridgeError('invalid_envelope', 'Malformed envelope JSON', true); }
  if (!object(value) || value.v !== 1 || !validId(value.id) || !string(value.type) || !string(value.created_at) || Number.isNaN(Date.parse(value.created_at))) throw new BridgeError('invalid_envelope', 'Invalid protocol envelope', true);
  const invalid = () => { throw new BridgeError('invalid_envelope', 'Invalid envelope fields', true); };
  switch (value.type) {
    case 'socket.emit':
      if (!validEvent(value.event) || !Array.isArray(value.rooms) || !value.rooms.length || value.rooms.length > 1000 || !value.rooms.every(room => validChannel(room) || typeof room === 'string' && /^__(user|session):[a-f0-9]{64}$/.test(room)) || !object(value.payload)) invalid();
      if (Buffer.byteLength(JSON.stringify(value.payload)) > maxBytes) invalid();
      if (value.except_socket !== undefined && !string(value.except_socket)) invalid();
      if (value.except_user !== undefined && !string(value.except_user)) invalid();
      break;
    case 'socket.command.result':
      if (!validId(value.command_id) || (value.request_id !== undefined && !validId(value.request_id)) || !string(value.socket_id) || !string(value.session_id) || !string(value.user_id) || !object(value.result) || typeof value.result.ok !== 'boolean') invalid();
      break;
    case 'socket.disconnect_user': case 'socket.auth.invalidate': if (!string(value.user_id)) invalid(); break;
    case 'socket.disconnect_session': if (!string(value.session_id)) invalid(); break;
    case 'socket.leave_room': case 'socket.join_room': if (!string(value.user_id) || !validChannel(value.room)) invalid(); break;
    default: throw new BridgeError('unknown_type', 'Unsupported envelope type', true);
  }
  return value as Envelope;
}
export function failure(error: unknown): { code: string; message: string } {
  return error instanceof BridgeError ? { code: error.code, message: error.message } : { code: 'temporarily_unavailable', message: 'Realtime service is temporarily unavailable' };
}
