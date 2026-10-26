# JS client: `@dskripchenko/realtime`

The client is written in TypeScript and ships as ES modules with type declarations. It has
no runtime dependencies and works in browsers and in Node 22+, which has a global
`WebSocket`. Its source lives in [`js/`](../../js).

```bash
pnpm add @dskripchenko/realtime
```

## Connecting

```ts
import { RealtimeClient } from '@dskripchenko/realtime';

const realtime = new RealtimeClient({
  ticketUrl: '/realtime/ticket',            // default
  // url: 'wss://app.example.com/realtime', // overrides the URL returned with the ticket
  reconnect: { initialDelay: 500, maxDelay: 30_000, factor: 2, jitter: 0.5 },
});

await realtime.connect();      // resolves when the server has accepted the ticket
realtime.state;                // 'idle' | 'connecting' | 'open' | 'reconnecting' | 'closed'
realtime.socketId;             // send as X-Socket-ID for toOthers()
realtime.on('state', (state) => console.log(state));
realtime.on('error', (error) => console.warn(error));
```

### Fetching the ticket

By default, every connection attempt sends `POST ticketUrl`. The request:

- uses `credentials: 'same-origin'` so the session cookie goes along;
- sends `Accept: application/json` and `X-Requested-With`;
- sends `X-XSRF-TOKEN` taken from Laravel's `XSRF-TOKEN` cookie.

Use `headers` to add your own headers, or replace the request entirely:

```ts
new RealtimeClient({
  fetchTicket: async () => (await api.post('/api/realtime/ticket')).data, // { ticket, url }
});
```

If the ticket endpoint answers 401, 403 or 419, the client rejects `connect()` with
`UnauthorizedError` and stops: the user is logged out, so retrying will not help. Any other
failure is retried with backoff.

### Reconnect

When the connection drops, the client:

1. waits `min(maxDelay, initialDelay × factor^attempt)`, where the `jitter` share of that
   delay is randomized so that thousands of tabs do not reconnect at once after a deploy;
2. fetches a new ticket and connects;
3. resubscribes every channel with `since = lastSeq`, so the server replays what was
   missed.

Set `reconnect: false` to stay closed after a drop, or `maxAttempts` to give up after a
number of tries. `close()` is final until you call `connect()` again. Subscriptions are
kept for that next `connect()`.

### Keepalive

After one heartbeat of silence the client sends `{"type":"ping"}`. The heartbeat is
announced by the server in `welcome`, or set with `heartbeat` in ms. After two heartbeats
of silence the client treats the link as dead and reconnects. This catches half-open TCP
connections that the browser would otherwise notice only after minutes.

## Channels

```ts
type BoardEvents = {
  'card.moved': { id: number; column: string };
  'card.deleted': { id: number };
};

const board = realtime.private<BoardEvents>('board.42');   // "private-board.42"
const off = board.on('card.moved', (card, meta) => {
  // card is typed { id: number; column: string }; meta = { channel, event, seq, userId? }
});
board.onAny((event, data, meta) => {});
board.onSubscribed(() => {});                 // after every (re)subscribe
board.onError((e) => e.code === 'forbidden' && showNoAccess());
board.onResync((seq) => reloadBoard());       // missed events are gone: reload state over HTTP
off();                                        // remove one handler
board.unsubscribe();

realtime.subscribe('news');                   // public channel, or any full channel name
realtime.leave('board.42');                   // unsubscribe by name
```

How channel calls behave:

- Calling `subscribe`, `private` or `join` before `connect()` is fine. The subscription is
  sent once the connection is open.
- Subscribing to the same name again returns the same channel object.
- Events with a `seq` at or below the last one applied are dropped, so replay and live
  delivery never produce duplicates.

## Presence

```ts
const page = realtime.join<{}, { name: string }>('page.7');   // "presence-page.7"

page.members();                          // [{ id: 7, info: { name: 'Ann' } }, …]
page.onMembers((members) => render(members));
page.onJoin((member) => toast(`${member.info.name} opened the page`));
page.onLeave((member) => {});
page.refresh();                          // ask the server for the full list again

// Client events: typing indicators, cursors (private and presence channels only)
page.whisper('typing', { on: true });    // sent as "client-typing"
page.on('client-typing', (data, meta) => showTyping(meta.userId, data));
```

`whisper()` returns `false` when the client is not connected. Client events are not
buffered or replayed.

## Testing and other runtimes

Pass a `WebSocket` constructor (anything with `send`, `close`, `readyState` and the `on*`
handlers) and a `fetch` implementation. The package's own unit tests drive the client
through an in-memory socket in exactly this way (`js/test/fake-socket.ts`).

## API summary

| Member | Description |
|--------|-------------|
| `new RealtimeClient(options)` | `ticketUrl`, `fetchTicket`, `url`, `headers`, `credentials`, `reconnect`, `heartbeat`, `WebSocket`, `fetch`, `random` |
| `connect(): Promise<void>` | Open (idempotent) |
| `close()` | Close for good |
| `subscribe(name)` / `private(name)` / `join(name)` | Get a `Channel` / `PresenceChannel` |
| `leave(name)` | Unsubscribe by name |
| `on('state' \| 'error' \| 'message', fn)` | Client events; returns an unsubscribe function |
| `state`, `socketId` | Current state and socket id |
| `Channel.on/onAny/onSubscribed/onError/onResync/whisper/unsubscribe` | See above |
| `Channel.lastSeq`, `Channel.subscribed` | Position in the channel and subscription state |
| `PresenceChannel.members/onMembers/onJoin/onLeave/refresh` | Presence |

## Not included yet

There is no adapter for `@dskripchenko/ui` components (live lists, presence avatars) in
0.1.
