# laravel-realtime

Self-hosted WebSockets for Laravel, with no third-party services or runtime packages.

- **Server**: `php artisan realtime:serve` is a long-running PHP process on non-blocking
  sockets (`stream_select`). It implements RFC 6455 itself.
- **Scaling**: run as many server processes as you like. They do not know about each other.
  Publications go through Redis/Valkey pub/sub, and each server delivers to its own sockets.
- **Protocol**: a small JSON protocol. Every channel has sequence numbers and a short replay
  buffer, so a client that reconnects picks up where it stopped.
- **Auth**: a connection needs a one-time ticket issued for the logged-in user. Private and
  presence channels are checked by callbacks you register, as in `routes/channels.php`. Every
  channel is keyed by the ticket's *scope* (your tenant), so tenants cannot cross.
- **Laravel integration**: the `realtime` broadcasting driver sends `ShouldBroadcast` events
  unchanged. The `Realtime` facade publishes directly.
- **Client**: `@dskripchenko/realtime` (in [`js/`](js)) is written in TypeScript and has zero
  runtime dependencies.

Requirements: PHP 8.2–8.5, Laravel 11/12/13, ext-redis (phpredis), Redis ≥ 6.2 or Valkey.

## Install

```bash
composer require dskripchenko/laravel-realtime
php artisan vendor:publish --tag=realtime-config   # optional
```

```dotenv
BROADCAST_CONNECTION=realtime
REALTIME_PORT=6001
REALTIME_PUBLIC_URL=wss://app.example.com/realtime
REALTIME_ALLOWED_ORIGINS=https://app.example.com
```

Add the connection to `config/broadcasting.php`:

```php
'connections' => [
    'realtime' => ['driver' => 'realtime'],
],
```

## Quick start

Register the channels and the tenant hooks in a service provider:

```php
use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Facades\Realtime;

public function boot(): void
{
    // Scope for tickets and publications: the current tenant (empty string = single tenant).
    Realtime::resolveScopeUsing(fn (): string => (string) tenant()?->id);

    // Authorization runs inside the realtime server, so it has to enter the tenant itself.
    Realtime::authorizeUsing(fn (Ticket $ticket, Closure $next) => Tenant::run($ticket->scope, $next));

    Realtime::channel('board.{id}', fn ($user, string $id) => $user->can('view', Board::find($id)));
    Realtime::channel('page.{id}', fn ($user, string $id) => ['name' => $user->name]); // presence
}
```

Run the server:

```bash
php artisan realtime:serve
```

Broadcast as usual:

```php
class CardMoved implements ShouldBroadcast
{
    use InteractsWithSockets;

    public function __construct(public int $boardId, public array $card) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("board.{$this->boardId}");
    }
}

broadcast(new CardMoved($board->id, $card))->toOthers();
// or directly:
Realtime::publish("private-board.{$board->id}", 'CardMoved', ['card' => $card]);
```

Listen in the browser:

```ts
import { RealtimeClient } from '@dskripchenko/realtime';

const realtime = new RealtimeClient();          // POST /realtime/ticket with the session cookie
await realtime.connect();

realtime.private('board.42').on('App\\Events\\CardMoved', (data) => moveCard(data.card));
axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId; // for toOthers()

const page = realtime.join('page.7');
page.onMembers((members) => showEditors(members));
```

## Protocol at a glance

```text
client → {"type":"subscribe","id":1,"channel":"private-board.42","since":17}
server ← {"type":"ack","id":1,"channel":"private-board.42","seq":19,"gap":false}
server ← {"seq":18,"type":"event","channel":"private-board.42","event":"CardMoved","data":{...}}
server ← {"seq":19,"type":"event","channel":"private-board.42","event":"CardMoved","data":{...}}
```

The other messages are `unsubscribe`, `presence` (join/leave/list), `event` (client events),
`error`, `ping`/`pong`, `welcome` and `resync`. The full spec is in
[docs/en/protocol.md](docs/en/protocol.md).

## Scaling

```text
web / queue workers ──EVAL publish──▶ Redis/Valkey ──pub/sub──▶ realtime server A ─▶ its sockets
                                         │  seq + replay log    ──pub/sub──▶ realtime server B ─▶ its sockets
```

- Each server subscribes only to the channels its clients use.
- Sequence numbers and the replay log are assigned atomically in Redis, so every server sees
  the same order.
- Put any number of servers behind a TCP or HTTP load balancer. No sticky sessions are
  needed: a reconnecting client replays from its last sequence number on whichever server
  it reaches.
- Presence is counted in Redis per user. Entries left by a crashed server are removed by the
  surviving servers once its heartbeat expires.

## Security notes

- Tickets are single-use and short-lived (30 s by default). Redis stores only their SHA-256
  hash.
- The server does not terminate TLS. Put it behind nginx, Caddy or Traefik using `wss://`,
  and do not expose its port directly.
- Set `REALTIME_ALLOWED_ORIGINS` as defence in depth against cross-site WebSocket hijacking.
- Public channels are readable by every connection in the same scope. Use `private-`
  channels for anything sensitive.
- Channel callbacks run in a long-lived process. They must not use request state such as
  `request()`, the session or `auth()->user()`. They receive the user from the ticket.

## Documentation

- [Server](docs/en/server.md): the loop, limits, keepalive, health
- [Protocol](docs/en/protocol.md): every message, with examples
- [Authorization & tenancy](docs/en/authorization.md)
- [Broadcasting driver](docs/en/broadcasting.md)
- [JS client](docs/en/js-client.md)
- [Operations](docs/en/operations.md): supervisor, Docker, proxies, monitoring, capacity

## Status

The current version is 0.1.0 and has not been released yet. permessage-deflate, the
ext-event loop backend, Redis Cluster and the UI-kit adapter are not implemented yet. See
[CHANGELOG.md](CHANGELOG.md).

## License

MIT © Denis Skripchenko
