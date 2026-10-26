import { Emitter } from './emitter.js';
import type { EventMessage, Member, PresenceMessage } from './protocol.js';

export type EventMap = Record<string, unknown>;

export interface EventMeta {
  channel: string;
  event: string;
  /** Sequence number in the channel; null for presence notices and client events (not replayable). */
  seq: number | null;
  /** Sender of a client event. */
  userId?: number | string;
}

export interface ChannelError {
  code: string;
  message: string;
}

type ChannelEvents = {
  subscribed: [];
  error: [ChannelError];
  resync: [number];
  event: [string, unknown, EventMeta];
};

/** Hooks the client gives a channel to talk to the socket. */
export interface ChannelHost {
  sendSubscribe(channel: Channel<EventMap>): void;
  sendUnsubscribe(channel: Channel<EventMap>): void;
  sendClientEvent(channel: string, event: string, data: unknown): boolean;
  sendPresenceList(channel: string): void;
  forget(channel: Channel<EventMap>): void;
}

export class Channel<E extends EventMap = EventMap> {
  /** Last sequence number applied; sent as `since` on resubscribe. */
  lastSeq: number | null = null;
  subscribed = false;
  protected readonly emitter = new Emitter<ChannelEvents>();
  private closed = false;

  constructor(
    readonly name: string,
    protected readonly host: ChannelHost,
  ) {}

  /** Handles one named event; returns a function that removes the handler. */
  on<K extends keyof E & string>(event: K, handler: (data: E[K], meta: EventMeta) => void): () => void {
    return this.emitter.on('event', (name, data, meta) => {
      if (name === event) handler(data as E[K], meta);
    });
  }

  /** Handles every event on the channel. */
  onAny(handler: (event: string, data: unknown, meta: EventMeta) => void): () => void {
    return this.emitter.on('event', handler);
  }

  /** Fires after each successful (re)subscription. */
  onSubscribed(handler: () => void): () => void {
    return this.emitter.on('subscribed', handler);
  }

  onError(handler: (error: ChannelError) => void): () => void {
    return this.emitter.on('error', handler);
  }

  /**
   * Fires when missed events could not be replayed (buffer overrun, channel reset).
   * Refetch the state over HTTP; delivery continues from `seq`.
   */
  onResync(handler: (seq: number) => void): () => void {
    return this.emitter.on('resync', handler);
  }

  /** Sends a "client-<event>" to the other subscribers (private and presence channels only). */
  whisper(event: string, data?: unknown): boolean {
    return this.host.sendClientEvent(this.name, event.startsWith('client-') ? event : `client-${event}`, data);
  }

  unsubscribe(): void {
    if (this.closed) return;
    this.closed = true;
    if (this.subscribed) this.host.sendUnsubscribe(this as unknown as Channel<EventMap>);
    this.subscribed = false;
    this.host.forget(this as unknown as Channel<EventMap>);
    this.emitter.clear();
  }

  /** @internal */
  handleAck(seq: number, gap: boolean, members: Member[] | undefined): void {
    const fresh = this.lastSeq === null;
    this.subscribed = true;
    // Without "since" or after a gap the server's head becomes our position; otherwise replayed events advance it.
    if (fresh || gap) this.lastSeq = seq;
    this.applyMembers(members);
    this.emitter.emit('subscribed');
    if (gap && !fresh) this.emitter.emit('resync', seq);
  }

  /** @internal */
  handleResync(seq: number): void {
    this.lastSeq = seq;
    this.emitter.emit('resync', seq);
  }

  /** @internal */
  handleEvent(message: EventMessage): void {
    if (message.seq !== null) {
      if (this.lastSeq !== null && message.seq <= this.lastSeq) return;
      this.lastSeq = message.seq;
    }
    const meta: EventMeta = { channel: this.name, event: message.event, seq: message.seq };
    if (message.user_id !== undefined) meta.userId = message.user_id;
    this.emitter.emit('event', message.event, message.data, meta);
  }

  /** @internal */
  handlePresence(_message: PresenceMessage): void {}

  /** @internal */
  handleError(error: ChannelError): void {
    this.emitter.emit('error', error);
  }

  /** @internal */
  handleDisconnect(): void {
    this.subscribed = false;
  }

  protected applyMembers(_members: Member[] | undefined): void {}
}

type PresenceEvents<Info> = {
  join: [Member<Info>];
  leave: [Member<Info>];
  members: [Member<Info>[]];
};

export class PresenceChannel<E extends EventMap = EventMap, Info = Record<string, unknown>> extends Channel<E> {
  private readonly memberMap = new Map<string, Member<Info>>();
  private readonly presence = new Emitter<PresenceEvents<Info>>();

  /** Current members, one entry per user (several tabs of one user count once). */
  members(): Member<Info>[] {
    return [...this.memberMap.values()];
  }

  onJoin(handler: (member: Member<Info>) => void): () => void {
    return this.presence.on('join', handler);
  }

  onLeave(handler: (member: Member<Info>) => void): () => void {
    return this.presence.on('leave', handler);
  }

  /** Fires with the full list whenever it changes. */
  onMembers(handler: (members: Member<Info>[]) => void): () => void {
    return this.presence.on('members', handler);
  }

  /** Asks the server for a fresh member list. */
  refresh(): void {
    this.host.sendPresenceList(this.name);
  }

  override unsubscribe(): void {
    super.unsubscribe();
    this.presence.clear();
    this.memberMap.clear();
  }

  /** @internal */
  override handlePresence(message: PresenceMessage): void {
    if (message.action === 'list') {
      this.applyMembers(message.members);
      return;
    }
    const member = message.member as Member<Info> | undefined;
    if (!member) return;
    const key = String(member.id);
    if (message.action === 'join') {
      if (this.memberMap.has(key)) return;
      this.memberMap.set(key, member);
      this.presence.emit('join', member);
    } else {
      const known = this.memberMap.get(key);
      if (!known) return;
      this.memberMap.delete(key);
      this.presence.emit('leave', known);
    }
    this.presence.emit('members', this.members());
  }

  protected override applyMembers(members: Member[] | undefined): void {
    if (!members) return;
    this.memberMap.clear();
    for (const member of members) this.memberMap.set(String(member.id), member as Member<Info>);
    this.presence.emit('members', this.members());
  }
}
