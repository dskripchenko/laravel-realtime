import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RealtimeClient, UnauthorizedError, backoffDelay, parseServerMessage } from '../src/index.js';
import type { RealtimeOptions } from '../src/index.js';
import { FakeSocket } from './fake-socket.js';

const flush = () => new Promise<void>((resolve) => setTimeout(resolve, 0));

function makeClient(options: Partial<RealtimeOptions> = {}) {
  let n = 0;
  const fetchTicket = vi.fn(async () => ({ ticket: `t${++n}`, url: 'wss://rt.test/ws' }));
  const client = new RealtimeClient({ fetchTicket, WebSocket: FakeSocket, random: () => 0.5, ...options });
  return { client, fetchTicket };
}

async function connected(options: Partial<RealtimeOptions> = {}) {
  const made = makeClient(options);
  const ready = made.client.connect();
  await vi.advanceTimersByTimeAsync(0);
  FakeSocket.last().accept();
  await ready;
  return made;
}

beforeEach(() => {
  FakeSocket.instances = [];
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('protocol parsing', () => {
  it('accepts known messages and rejects junk', () => {
    expect(parseServerMessage('{"type":"pong","id":1}')).toEqual({ type: 'pong', id: 1 });
    expect(parseServerMessage('{"type":"nope"}')).toBeNull();
    expect(parseServerMessage('[1]')).toBeNull();
    expect(parseServerMessage('{')).toBeNull();
    expect(parseServerMessage(new ArrayBuffer(2))).toBeNull();
  });
});

describe('backoff', () => {
  it('grows exponentially, caps and jitters', () => {
    const opts = { initialDelay: 100, maxDelay: 1000, factor: 2, jitter: 0.5 };
    expect(backoffDelay(0, opts, () => 0)).toBe(50);
    expect(backoffDelay(0, opts, () => 1)).toBe(100);
    expect(backoffDelay(3, opts, () => 1)).toBe(800);
    expect(backoffDelay(10, opts, () => 1)).toBe(1000);
    expect(backoffDelay(10, { ...opts, jitter: 0 }, () => 0)).toBe(1000);
  });
});

describe('connection', () => {
  it('connects with the ticket in the URL and exposes the socket id', async () => {
    const states: string[] = [];
    const { client } = makeClient();
    client.on('state', (s) => states.push(s));
    const ready = client.connect();
    await vi.advanceTimersByTimeAsync(0);

    expect(FakeSocket.last().url).toBe('wss://rt.test/ws?ticket=t1');
    expect(client.state).toBe('connecting');
    FakeSocket.last().accept('42.7');
    await ready;

    expect(client.state).toBe('open');
    expect(client.socketId).toBe('42.7');
    expect(states).toEqual(['connecting', 'open']);
  });

  it('requests tickets over POST with credentials, JSON and XSRF headers', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ticket: 'abc', url: 'ws://x' }), { status: 200 }));
    (globalThis as { document?: unknown }).document = { cookie: 'a=1; XSRF-TOKEN=tok%3D; b=2' };
    try {
      const client = new RealtimeClient({ fetch: fetchMock as unknown as typeof fetch, WebSocket: FakeSocket, headers: { 'X-Tenant': 'acme' } });
      void client.connect();
      await vi.advanceTimersByTimeAsync(0);
      expect(fetchMock).toHaveBeenCalledWith('/realtime/ticket', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': 'tok=', 'X-Tenant': 'acme' },
      });
      expect(FakeSocket.last().url).toBe('ws://x?ticket=abc');
    } finally {
      delete (globalThis as { document?: unknown }).document;
    }
  });

  it('stops on 401 from the ticket endpoint', async () => {
    const fetchMock = vi.fn(async () => new Response('', { status: 401 }));
    const client = new RealtimeClient({ fetch: fetchMock as unknown as typeof fetch, WebSocket: FakeSocket });
    const errors: Error[] = [];
    client.on('error', (e) => errors.push(e));
    await expect(client.connect()).rejects.toBeInstanceOf(UnauthorizedError);
    expect(client.state).toBe('closed');
    await vi.advanceTimersByTimeAsync(60_000);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(errors[0]).toBeInstanceOf(UnauthorizedError);
  });

  it('retries ticket failures with backoff', async () => {
    let calls = 0;
    const { client } = makeClient({
      fetchTicket: async () => {
        calls++;
        if (calls < 3) throw new Error('network down');
        return { ticket: 'ok', url: 'ws://x' };
      },
      reconnect: { initialDelay: 100, jitter: 0 },
    });
    void client.connect();
    await vi.advanceTimersByTimeAsync(0);
    expect(client.state).toBe('reconnecting');
    await vi.advanceTimersByTimeAsync(100);
    expect(calls).toBe(2);
    await vi.advanceTimersByTimeAsync(199);
    expect(calls).toBe(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(calls).toBe(3);
    expect(FakeSocket.last().url).toBe('ws://x?ticket=ok');
  });

  it('reconnects with a fresh ticket after a drop, with exponential backoff', async () => {
    const { client, fetchTicket } = await connected({ reconnect: { initialDelay: 1000, factor: 2, jitter: 0 } });

    FakeSocket.last().drop();
    expect(client.state).toBe('reconnecting');
    expect(client.socketId).toBeNull();
    await vi.advanceTimersByTimeAsync(999);
    expect(fetchTicket).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchTicket).toHaveBeenCalledTimes(2);
    expect(FakeSocket.last().url).toContain('ticket=t2');

    FakeSocket.last().drop();
    await vi.advanceTimersByTimeAsync(1999);
    expect(fetchTicket).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchTicket).toHaveBeenCalledTimes(3);

    FakeSocket.last().accept('9.9');
    expect(client.state).toBe('open');
    FakeSocket.last().drop();
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetchTicket).toHaveBeenCalledTimes(4);
  });

  it('gives up after maxAttempts', async () => {
    const { client } = await connected({ reconnect: { initialDelay: 10, jitter: 0, maxAttempts: 1 } });
    FakeSocket.last().drop();
    await vi.advanceTimersByTimeAsync(10);
    FakeSocket.last().drop();
    expect(client.state).toBe('closed');
  });

  it('close() is clean and final', async () => {
    const { client, fetchTicket } = await connected();
    const socket = FakeSocket.last();
    client.close();
    expect(socket.closedWith).toEqual({ code: 1000, reason: 'client closed' });
    expect(client.state).toBe('closed');
    await vi.advanceTimersByTimeAsync(120_000);
    expect(fetchTicket).toHaveBeenCalledTimes(1);
  });

  it('treats an error before the welcome as a drop (Node keeps a refused socket CONNECTING)', async () => {
    const { client, fetchTicket } = makeClient({ reconnect: { initialDelay: 10, jitter: 0 } });
    const ready = client.connect();
    await vi.advanceTimersByTimeAsync(0);
    const socket = FakeSocket.last();
    socket.onerror?.({});

    expect(client.state).toBe('reconnecting');
    await vi.advanceTimersByTimeAsync(10);
    expect(fetchTicket).toHaveBeenCalledTimes(2);
    FakeSocket.last().accept();
    await ready;
    expect(client.state).toBe('open');
    // A late close of the abandoned socket changes nothing.
    socket.drop();
    expect(client.state).toBe('open');
  });

  it('gives up on a socket that never gets the welcome', async () => {
    const { client } = makeClient({ connectTimeout: 5_000, reconnect: false });
    const failed = expect(client.connect()).rejects.toThrow('Connection lost');
    await vi.advanceTimersByTimeAsync(0);
    const socket = FakeSocket.last();

    await vi.advanceTimersByTimeAsync(5_000);
    expect(socket.closedWith).toEqual({ code: 4001, reason: 'connect timeout' });
    expect(client.state).toBe('closed');
    await failed;
  });

  it('keeps an open connection when an error comes after the welcome', async () => {
    const { client } = await connected();
    FakeSocket.last().onerror?.({});
    expect(client.state).toBe('open');
    await vi.advanceTimersByTimeAsync(30_000);
    expect(client.state).toBe('open');
  });

  it('pings when idle and drops a silent connection', async () => {
    const { client } = await connected({ heartbeat: 10_000, reconnect: { initialDelay: 50_000, jitter: 0 } });
    const socket = FakeSocket.last();

    await vi.advanceTimersByTimeAsync(10_000);
    expect(socket.sentOfType('ping')).toHaveLength(1);
    socket.push({ type: 'pong' });
    await vi.advanceTimersByTimeAsync(10_000);
    expect(client.state).toBe('open');

    await vi.advanceTimersByTimeAsync(20_000);
    expect(socket.closedWith?.code).toBe(4000);
    expect(client.state).toBe('reconnecting');
  });
});

describe('channels', () => {
  it('subscribes once open and dispatches typed events', async () => {
    const { client } = makeClient();
    const board = client.private<{ CardMoved: { card: number } }>('board.1');
    const ready = client.connect();
    await vi.advanceTimersByTimeAsync(0);
    const socket = FakeSocket.last();
    socket.accept();
    await ready;

    expect(socket.sentOfType('subscribe')).toEqual([{ type: 'subscribe', id: 1, channel: 'private-board.1' }]);
    socket.push({ type: 'ack', id: 1, channel: 'private-board.1', seq: 10, gap: false });
    expect(board.subscribed).toBe(true);
    expect(board.lastSeq).toBe(10);

    const seen: Array<[number, number | null]> = [];
    board.on('CardMoved', (data, meta) => seen.push([data.card, meta.seq]));
    socket.push({ type: 'event', seq: 11, channel: 'private-board.1', event: 'CardMoved', data: { card: 5 } });
    socket.push({ type: 'event', seq: 11, channel: 'private-board.1', event: 'CardMoved', data: { card: 5 } });
    socket.push({ type: 'event', seq: 12, channel: 'private-board.1', event: 'Other', data: {} });
    socket.push({ type: 'event', seq: 13, channel: 'news', event: 'CardMoved', data: { card: 9 } });

    expect(seen).toEqual([[5, 11]]);
    expect(board.lastSeq).toBe(12);
    expect(client.subscribe('private-board.1')).toBe(board);
  });

  it('resubscribes with since after reconnect and applies replayed events in order', async () => {
    const { client } = await connected({ reconnect: { initialDelay: 10, jitter: 0 } });
    const news = client.subscribe('news');
    const events: number[] = [];
    news.onAny((_e, _d, meta) => events.push(meta.seq ?? -1));
    let socket = FakeSocket.last();
    socket.push({ type: 'ack', id: 1, channel: 'news', seq: 0, gap: false });
    socket.push({ type: 'event', seq: 1, channel: 'news', event: 'E', data: null });
    socket.push({ type: 'event', seq: 2, channel: 'news', event: 'E', data: null });

    socket.drop();
    await vi.advanceTimersByTimeAsync(10);
    socket = FakeSocket.last();
    socket.accept();
    const resub = socket.sentOfType('subscribe');
    expect(resub).toEqual([{ type: 'subscribe', id: 2, channel: 'news', since: 2 }]);

    socket.push({ type: 'ack', id: 2, channel: 'news', seq: 4, gap: false });
    expect(news.lastSeq).toBe(2);
    socket.push({ type: 'event', seq: 3, channel: 'news', event: 'E', data: null });
    socket.push({ type: 'event', seq: 4, channel: 'news', event: 'E', data: null });
    expect(events).toEqual([1, 2, 3, 4]);
  });

  it('reports a gap as resync and continues from the server head', async () => {
    const { client } = await connected({ reconnect: { initialDelay: 10, jitter: 0 } });
    const news = client.subscribe('news');
    const resyncs: number[] = [];
    news.onResync((seq) => resyncs.push(seq));
    let socket = FakeSocket.last();
    socket.push({ type: 'ack', id: 1, channel: 'news', seq: 5, gap: false });
    expect(resyncs).toEqual([]);

    socket.drop();
    await vi.advanceTimersByTimeAsync(10);
    socket = FakeSocket.last();
    socket.accept();
    socket.push({ type: 'ack', id: 2, channel: 'news', seq: 500, gap: true });
    expect(resyncs).toEqual([500]);
    expect(news.lastSeq).toBe(500);

    socket.push({ type: 'resync', channel: 'news', seq: 900 });
    expect(resyncs).toEqual([500, 900]);
  });

  it('routes subscription errors to the channel', async () => {
    const { client } = await connected();
    const secret = client.private('board.13');
    const errors: string[] = [];
    secret.onError((e) => errors.push(e.code));
    FakeSocket.last().push({ type: 'error', id: 1, channel: 'private-board.13', code: 'forbidden', message: 'no' });
    expect(errors).toEqual(['forbidden']);
    expect(secret.subscribed).toBe(false);
  });

  it('unsubscribes and stops dispatching', async () => {
    const { client } = await connected();
    const news = client.subscribe('news');
    const socket = FakeSocket.last();
    socket.push({ type: 'ack', id: 1, channel: 'news', seq: 0, gap: false });
    const handler = vi.fn();
    news.onAny(handler);
    news.unsubscribe();
    expect(socket.sentOfType('unsubscribe')).toEqual([{ type: 'unsubscribe', id: 2, channel: 'news' }]);
    socket.push({ type: 'event', seq: 1, channel: 'news', event: 'E', data: null });
    expect(handler).not.toHaveBeenCalled();
    expect(client.subscribe('news')).not.toBe(news);
  });

  it('whispers client events only while connected', async () => {
    const { client } = makeClient();
    const page = client.join('page.1');
    expect(page.whisper('typing', { on: true })).toBe(false);
    void client.connect();
    await vi.advanceTimersByTimeAsync(0);
    FakeSocket.last().accept();
    expect(page.whisper('typing', { on: true })).toBe(true);
    expect(FakeSocket.last().sentOfType('event')).toEqual([
      { type: 'event', channel: 'presence-page.1', event: 'client-typing', data: { on: true } },
    ]);
  });

  it('isolates a throwing handler from the others', async () => {
    const { client } = await connected();
    const news = client.subscribe('news');
    const ok = vi.fn();
    news.onAny(() => {
      throw new Error('boom');
    });
    news.onAny(ok);
    const swallowed = vi.fn();
    const original = globalThis.queueMicrotask;
    globalThis.queueMicrotask = swallowed;
    try {
      FakeSocket.last().push({ type: 'event', seq: null, channel: 'news', event: 'E', data: 1 });
    } finally {
      globalThis.queueMicrotask = original;
    }
    expect(ok).toHaveBeenCalledOnce();
    expect(swallowed).toHaveBeenCalledOnce();
  });
});

describe('presence', () => {
  it('tracks members from ack, join, leave and list', async () => {
    const { client } = await connected();
    const page = client.join<Record<string, never>, { name: string }>('page.1');
    const socket = FakeSocket.last();
    const joins: string[] = [];
    const leaves: string[] = [];
    page.onJoin((m) => joins.push(m.info.name));
    page.onLeave((m) => leaves.push(m.info.name));

    socket.push({ type: 'ack', id: 1, channel: 'presence-page.1', seq: 0, gap: false, members: [{ id: 1, info: { name: 'Ann' } }] });
    expect(page.members()).toEqual([{ id: 1, info: { name: 'Ann' } }]);

    socket.push({ type: 'presence', seq: null, channel: 'presence-page.1', action: 'join', member: { id: 2, info: { name: 'Bob' } } });
    socket.push({ type: 'presence', seq: null, channel: 'presence-page.1', action: 'join', member: { id: 2, info: { name: 'Bob' } } });
    expect(joins).toEqual(['Bob']);
    expect(page.members().map((m) => m.id)).toEqual([1, 2]);

    socket.push({ type: 'presence', seq: null, channel: 'presence-page.1', action: 'leave', member: { id: 2, info: {} } });
    expect(leaves).toEqual(['Bob']);

    page.refresh();
    expect(socket.sentOfType('presence')).toEqual([{ type: 'presence', id: 2, channel: 'presence-page.1', action: 'list' }]);
    socket.push({ type: 'presence', id: 2, channel: 'presence-page.1', action: 'list', members: [{ id: 3, info: { name: 'Cy' } }] });
    expect(page.members()).toEqual([{ id: 3, info: { name: 'Cy' } }]);
  });

  it('delivers client events with the sender id', async () => {
    const { client } = await connected();
    const page = client.join<{ 'client-typing': { on: boolean } }>('page.1');
    const seen: unknown[] = [];
    page.on('client-typing', (data, meta) => seen.push([data.on, meta.userId, meta.seq]));
    FakeSocket.last().push({ type: 'event', seq: null, channel: 'presence-page.1', event: 'client-typing', data: { on: true }, user_id: 2 });
    expect(seen).toEqual([[true, 2, null]]);
  });
});
