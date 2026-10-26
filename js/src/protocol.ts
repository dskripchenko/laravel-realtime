/** Wire messages of the laravel-realtime protocol (see docs/en/protocol.md). */

export type RequestId = number | string;

export interface Member<Info = Record<string, unknown>> {
  id: number | string;
  info: Info;
}

export interface WelcomeMessage {
  type: 'welcome';
  socket_id: string;
  heartbeat: number;
}

export interface AckMessage {
  type: 'ack';
  id?: RequestId;
  channel?: string;
  seq?: number;
  gap?: boolean;
  members?: Member[];
}

export interface EventMessage {
  type: 'event';
  seq: number | null;
  channel: string;
  event: string;
  data: unknown;
  user_id?: number | string;
  except?: string;
}

export interface PresenceMessage {
  type: 'presence';
  seq?: null;
  id?: RequestId;
  channel: string;
  action: 'join' | 'leave' | 'list';
  member?: Member;
  members?: Member[];
}

export interface ErrorMessage {
  type: 'error';
  id?: RequestId;
  channel?: string;
  code: string;
  message: string;
}

export interface PongMessage {
  type: 'pong';
  id?: RequestId;
}

export interface ResyncMessage {
  type: 'resync';
  channel: string;
  seq: number;
}

export type ServerMessage =
  | WelcomeMessage
  | AckMessage
  | EventMessage
  | PresenceMessage
  | ErrorMessage
  | PongMessage
  | ResyncMessage;

export type ClientMessage =
  | { type: 'subscribe'; id: RequestId; channel: string; since?: number }
  | { type: 'unsubscribe'; id: RequestId; channel: string }
  | { type: 'presence'; id: RequestId; channel: string; action: 'list' }
  | { type: 'event'; id?: RequestId; channel: string; event: string; data?: unknown }
  | { type: 'ping'; id?: RequestId };

const TYPES = new Set(['welcome', 'ack', 'event', 'presence', 'error', 'pong', 'resync']);

/** Parses a server frame; returns null for anything that is not a known protocol message. */
export function parseServerMessage(raw: unknown): ServerMessage | null {
  if (typeof raw !== 'string') return null;
  let value: unknown;
  try {
    value = JSON.parse(raw);
  } catch {
    return null;
  }
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return null;
  const type = (value as { type?: unknown }).type;
  if (typeof type !== 'string' || !TYPES.has(type)) return null;
  return value as ServerMessage;
}
