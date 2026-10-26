# @dskripchenko/realtime

Browser client for [dskripchenko/laravel-realtime](https://github.com/dskripchenko/laravel-realtime).
TypeScript, ESM, zero runtime dependencies.

```ts
import { RealtimeClient } from '@dskripchenko/realtime';

const realtime = new RealtimeClient({ ticketUrl: '/realtime/ticket' });
realtime.connect();

const board = realtime.private<{ CardMoved: { id: number; column: string } }>('board.42');
board.on('CardMoved', (card, meta) => console.log(card.id, 'moved, seq', meta.seq));
board.onResync(() => reloadBoard());

const page = realtime.join<{}, { name: string }>('page.7');
page.onMembers((members) => renderAvatars(members));
```

Features: ticket-based connect, reconnect with exponential backoff and jitter, automatic
resubscribe with replay from the last sequence number, presence, client events
(`whisper`), keepalive, typed event handlers.

Full documentation: [docs/en/js-client.md](https://github.com/dskripchenko/laravel-realtime/blob/main/docs/en/js-client.md).

License: MIT.
