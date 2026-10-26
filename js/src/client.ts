import { backoffDelay, type BackoffOptions } from './backoff.js';
import { Channel, PresenceChannel, type ChannelHost, type EventMap } from './channel.js';
import { Emitter } from './emitter.js';
import { parseServerMessage, type ClientMessage, type RequestId, type ServerMessage } from './protocol.js';

export type ConnectionState = 'idle' | 'connecting' | 'open' | 'reconnecting' | 'closed';

export interface TicketResponse {
  ticket: string;
  url?: string | null;
  expires_in?: number;
}

/** The part of the WebSocket API the client uses; lets tests and non-browser runtimes plug in. */
export interface WebSocketLike {
  readonly readyState: number;
  send(data: string): void;
  close(code?: number, reason?: string): void;
  onopen: ((event: unknown) => void) | null;
  onmessage: ((event: { data: unknown }) => void) | null;
  onclose: ((event: { code: number; reason: string }) => void) | null;
  onerror: ((event: unknown) => void) | null;
}

export type WebSocketConstructor = new (url: string) => WebSocketLike;

export interface ReconnectOptions extends BackoffOptions {
  /** Give up after this many consecutive failed attempts (default: never). */
  maxAttempts?: number;
}

export interface RealtimeOptions {
  /** Endpoint that issues tickets (POST, session cookie). Default "/realtime/ticket". */
  ticketUrl?: string;
  /** Replaces the built-in ticket request entirely (e.g. token auth). */
  fetchTicket?: () => Promise<TicketResponse>;
  /** WebSocket URL; overrides the one returned with the ticket. */
  url?: string;
  /** Extra headers for the ticket request. */
  headers?: Record<string, string> | (() => Record<string, string>);
  credentials?: RequestCredentials;
  /** Reconnect policy, or false to stay closed after a drop. */
  reconnect?: ReconnectOptions | false;
  /** Client keepalive interval in ms; defaults to the server's advertised heartbeat. */
  heartbeat?: number;
  WebSocket?: WebSocketConstructor;
  fetch?: typeof fetch;
  /** Random source for jitter (tests). */
  random?: () => number;
}

/** Ticket endpoint said the user is not logged in: reconnecting will not help. */
export class UnauthorizedError extends Error {
  constructor(readonly status: number) {
    super(`Ticket request rejected with HTTP ${status}`);
    this.name = 'UnauthorizedError';
  }
}

type ClientEvents = {
  state: [ConnectionState];
  error: [Error];
  message: [ServerMessage];
};

const OPEN = 1;

export class RealtimeClient {
  private stateValue: ConnectionState = 'idle';
  private socketIdValue: string | null = null;
  private ws: WebSocketLike | null = null;
  private readonly channels = new Map<string, Channel<EventMap>>();
  private readonly pending = new Map<RequestId, string>();
  private readonly emitter = new Emitter<ClientEvents>();
  private nextId = 1;
  private attempts = 0;
  private manualClose = false;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private lastMessageAt = 0;
  private waiters: Array<{ resolve: () => void; reject: (error: Error) => void }> = [];
  private readonly host: ChannelHost;

  constructor(private readonly options: RealtimeOptions = {}) {
    this.host = {
      sendSubscribe: (channel) => this.sendSubscribe(channel),
      sendUnsubscribe: (channel) => {
        this.send({ type: 'unsubscribe', id: this.nextId++, channel: channel.name });
      },
      sendClientEvent: (channel, event, data) => this.send({ type: 'event', channel, event, data }),
      sendPresenceList: (channel) => {
        this.send({ type: 'presence', id: this.nextId++, channel, action: 'list' });
      },
      forget: (channel) => {
        if (this.channels.get(channel.name) === channel) this.channels.delete(channel.name);
      },
    };
  }

  get state(): ConnectionState {
    return this.stateValue;
  }

  /** Send as X-Socket-ID with HTTP requests so `broadcast(...)->toOthers()` skips this connection. */
  get socketId(): string | null {
    return this.socketIdValue;
  }

  /** Opens the connection; resolves once the server accepted the ticket. */
  connect(): Promise<void> {
    if (this.stateValue === 'open') return Promise.resolve();
    const promise = new Promise<void>((resolve, reject) => this.waiters.push({ resolve, reject }));
    if (this.stateValue === 'idle' || this.stateValue === 'closed') {
      this.manualClose = false;
      this.attempts = 0;
      void this.open();
    }
    return promise;
  }

  /** Closes for good (until connect() is called again). Subscriptions are kept for the next connect(). */
  close(): void {
    this.manualClose = true;
    this.clearTimers();
    const ws = this.ws;
    this.ws = null;
    if (ws) {
      ws.onclose = null;
      ws.onmessage = null;
      ws.close(1000, 'client closed');
    }
    this.handleDisconnect();
    this.setState('closed');
    this.settle(new Error('Connection closed'));
  }

  /** Public channel (or any full channel name, e.g. "private-board.1"). */
  subscribe<E extends EventMap = EventMap>(name: string): Channel<E> {
    return this.channel(name, () => new Channel<E>(name, this.host));
  }

  /** Private channel: private("board.1") subscribes to "private-board.1". */
  private<E extends EventMap = EventMap>(name: string): Channel<E> {
    return this.subscribe<E>(name.startsWith('private-') ? name : `private-${name}`);
  }

  /** Presence channel: join("page.5") subscribes to "presence-page.5". */
  join<E extends EventMap = EventMap, Info = Record<string, unknown>>(name: string): PresenceChannel<E, Info> {
    const full = name.startsWith('presence-') ? name : `presence-${name}`;
    return this.channel(full, () => new PresenceChannel<E, Info>(full, this.host)) as PresenceChannel<E, Info>;
  }

  /** Unsubscribes by name. */
  leave(name: string): void {
    for (const candidate of [name, `private-${name}`, `presence-${name}`]) {
      this.channels.get(candidate)?.unsubscribe();
    }
  }

  on<K extends keyof ClientEvents>(event: K, handler: (...args: ClientEvents[K]) => void): () => void {
    return this.emitter.on(event, handler);
  }

  private channel<C extends Channel<EventMap>>(name: string, create: () => C): C {
    const existing = this.channels.get(name);
    if (existing) return existing as C;
    const channel = create();
    this.channels.set(name, channel);
    if (this.stateValue === 'open') this.sendSubscribe(channel);
    return channel;
  }

  private async open(): Promise<void> {
    this.setState(this.attempts === 0 ? 'connecting' : 'reconnecting');
    let ticket: TicketResponse;
    try {
      ticket = await this.fetchTicket();
    } catch (error) {
      const err = error instanceof Error ? error : new Error(String(error));
      this.emitter.emit('error', err);
      if (this.manualClose) return;
      if (err instanceof UnauthorizedError) {
        this.setState('closed');
        this.settle(err);
        return;
      }
      this.scheduleReconnect();
      return;
    }
    if (this.manualClose) return;

    const url = this.options.url ?? ticket.url;
    if (!url) {
      const err = new Error('No WebSocket URL: set options.url or realtime.public_url');
      this.emitter.emit('error', err);
      this.setState('closed');
      this.settle(err);
      return;
    }

    const Ctor = this.options.WebSocket ?? (globalThis as { WebSocket?: WebSocketConstructor }).WebSocket;
    if (!Ctor) throw new Error('No WebSocket implementation available');
    const ws = new Ctor(`${url}${url.includes('?') ? '&' : '?'}ticket=${encodeURIComponent(ticket.ticket)}`);
    this.ws = ws;
    ws.onmessage = (event) => this.onMessage(event.data);
    ws.onclose = (event) => {
      if (this.ws === ws) this.onClose(event.code);
    };
    ws.onerror = () => {
      // A close event always follows; reconnect logic lives there.
    };
  }

  private async fetchTicket(): Promise<TicketResponse> {
    if (this.options.fetchTicket) return this.options.fetchTicket();
    const doFetch = this.options.fetch ?? globalThis.fetch;
    const custom = typeof this.options.headers === 'function' ? this.options.headers() : this.options.headers;
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...xsrfHeader(),
      ...custom,
    };
    const response = await doFetch(this.options.ticketUrl ?? '/realtime/ticket', {
      method: 'POST',
      credentials: this.options.credentials ?? 'same-origin',
      headers,
    });
    if (response.status === 401 || response.status === 403 || response.status === 419) {
      throw new UnauthorizedError(response.status);
    }
    if (!response.ok) throw new Error(`Ticket request failed with HTTP ${response.status}`);
    const body = (await response.json()) as TicketResponse;
    if (typeof body?.ticket !== 'string') throw new Error('Ticket response has no "ticket"');
    return body;
  }

  private onMessage(raw: unknown): void {
    const message = parseServerMessage(raw);
    if (!message) return;
    this.lastMessageAt = Date.now();
    this.emitter.emit('message', message);

    switch (message.type) {
      case 'welcome':
        this.socketIdValue = message.socket_id;
        this.attempts = 0;
        this.setState('open');
        this.startHeartbeat(this.options.heartbeat ?? message.heartbeat * 1000);
        for (const channel of this.channels.values()) this.sendSubscribe(channel);
        this.settle();
        return;
      case 'ack': {
        const name = message.id !== undefined ? this.pending.get(message.id) : undefined;
        if (message.id !== undefined) this.pending.delete(message.id);
        if (name !== undefined && typeof message.seq === 'number') {
          this.channels.get(name)?.handleAck(message.seq, message.gap === true, message.members);
        }
        return;
      }
      case 'error': {
        const name = message.channel ?? (message.id !== undefined ? this.pending.get(message.id) : undefined);
        if (message.id !== undefined) this.pending.delete(message.id);
        const channel = name !== undefined ? this.channels.get(name) : undefined;
        if (channel) channel.handleError({ code: message.code, message: message.message });
        else this.emitter.emit('error', new Error(`${message.code}: ${message.message}`));
        return;
      }
      case 'event':
        this.channels.get(message.channel)?.handleEvent(message);
        return;
      case 'presence':
        this.channels.get(message.channel)?.handlePresence(message);
        return;
      case 'resync':
        this.channels.get(message.channel)?.handleResync(message.seq);
        return;
      case 'pong':
        return;
    }
  }

  private onClose(_code: number): void {
    this.ws = null;
    this.clearTimers();
    this.handleDisconnect();
    if (this.manualClose) {
      this.setState('closed');
      return;
    }
    this.scheduleReconnect();
  }

  private handleDisconnect(): void {
    this.socketIdValue = null;
    this.pending.clear();
    for (const channel of this.channels.values()) channel.handleDisconnect();
  }

  private scheduleReconnect(): void {
    const policy = this.options.reconnect;
    if (policy === false || (policy?.maxAttempts !== undefined && this.attempts >= policy.maxAttempts)) {
      this.setState('closed');
      this.settle(new Error('Connection lost'));
      return;
    }
    const delay = backoffDelay(this.attempts, policy ?? {}, this.options.random);
    this.attempts++;
    this.setState('reconnecting');
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      void this.open();
    }, delay);
  }

  private startHeartbeat(interval: number): void {
    if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
    if (!(interval > 0)) return;
    // Browsers answer server pings invisibly, so the client needs its own probe to notice a dead link.
    this.heartbeatTimer = setInterval(() => {
      const idle = Date.now() - this.lastMessageAt;
      if (idle >= interval * 2) {
        const ws = this.ws;
        if (ws) {
          ws.onclose = null;
          ws.close(4000, 'keepalive timeout');
        }
        this.onClose(4000);
      } else if (idle >= interval) {
        this.send({ type: 'ping' });
      }
    }, Math.max(1000, interval / 2));
  }

  private sendSubscribe(channel: Channel<EventMap>): void {
    const id = this.nextId++;
    const message: ClientMessage =
      channel.lastSeq === null
        ? { type: 'subscribe', id, channel: channel.name }
        : { type: 'subscribe', id, channel: channel.name, since: channel.lastSeq };
    if (this.send(message)) this.pending.set(id, channel.name);
  }

  private send(message: ClientMessage): boolean {
    const ws = this.ws;
    if (!ws || ws.readyState !== OPEN || this.stateValue !== 'open') return false;
    ws.send(JSON.stringify(message));
    return true;
  }

  private setState(state: ConnectionState): void {
    if (this.stateValue === state) return;
    this.stateValue = state;
    this.emitter.emit('state', state);
  }

  private settle(error?: Error): void {
    const waiters = this.waiters;
    this.waiters = [];
    for (const waiter of waiters) {
      if (error) waiter.reject(error);
      else waiter.resolve();
    }
  }

  private clearTimers(): void {
    if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
    if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
    this.reconnectTimer = null;
    this.heartbeatTimer = null;
  }
}

function xsrfHeader(): Record<string, string> {
  if (typeof document === 'undefined' || typeof document.cookie !== 'string') return {};
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return match?.[1] ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}
