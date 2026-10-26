# Broadcasting driver

The package registers a `realtime` broadcasting driver. `ShouldBroadcast` and
`ShouldBroadcastNow` events, `broadcast()` and anonymous broadcasts work without changes.

```php
// config/broadcasting.php
'default' => env('BROADCAST_CONNECTION', 'realtime'),

'connections' => [
    'realtime' => [
        'driver' => 'realtime',
        // optional: a fixed scope for this connection instead of Realtime::resolveScopeUsing()
        // 'scope' => 'central',
    ],
],
```

## What reaches the client

```php
class CardMoved implements ShouldBroadcast
{
    use InteractsWithSockets;

    public function __construct(public Card $card) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("board.{$this->card->board_id}")];
    }

    public function broadcastAs(): string
    {
        return 'card.moved';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->card->id, 'column' => $this->card->column];
    }
}
```

The client receives:

```json
{"seq":42,"type":"event","channel":"private-board.7","event":"card.moved","data":{"id":5,"column":"done"}}
```

How Laravel's event settings map to the message:

- `event` is `broadcastAs()` when it is defined, otherwise the fully qualified class name,
  as with any Laravel broadcaster.
- `data` is `broadcastWith()`, or the event's public properties.
- Channel names keep Laravel's prefixes: `PrivateChannel('x')` becomes `private-x` and
  `PresenceChannel('x')` becomes `presence-x`.
- Each channel in `broadcastOn()` gets its own sequence number.

## toOthers()

Laravel puts the `X-Socket-ID` header of the current request into the payload as `socket`.
The driver removes it from the payload and the server skips that connection. Send the
header from the browser:

```ts
axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId ?? '';
realtime.on('state', () => {
  axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId ?? '';
});
```

## Scope

When an event is broadcast, the scope is resolved like this:

1. the connection's `scope` option, if set;
2. otherwise `Realtime::resolveScopeUsing()`;
3. otherwise `config('realtime.scope')`.

Queued broadcasts (`ShouldBroadcast`) resolve the scope inside the queue worker. Make sure
your tenant context is restored for queued jobs.

## Publishing without events

```php
use Dskripchenko\Realtime\Facades\Realtime;

$seq = Realtime::publish('private-board.7', 'card.moved', ['id' => 5]);              // current scope
Realtime::publish('news', 'Released', ['version' => '2.0'], scope: 'acme');           // explicit scope
Realtime::publish('private-job.91', 'progress', ['done' => 40, 'total' => 100], except: $socketId);
```

`publish()` returns the event's sequence number. It throws on invalid channel names or
scopes, and when Redis is unavailable. Inside the broadcaster, these errors become
`BroadcastException`.

The same is available from the shell:

```bash
php artisan realtime:publish news Released '{"version":"2.0"}' --scope=acme
```

## Channel authorization

The driver does **not** use `/broadcasting/auth` or `Broadcast::channel()`. Authorization
happens inside the realtime server with `Realtime::channel()` (see
[authorization](authorization.md)). Calling `Broadcast::auth()` with this driver throws
`AccessDeniedHttpException`.

## Typical uses

| Use case | Channel | Notes |
|----------|---------|-------|
| Live board | `private-board.{id}` | Events with `seq`; on `resync`, reload the board |
| Notifications | `private-App.Models.User.{id}` | Like Laravel's notification channel |
| Who is editing | `presence-page.{id}` | Members plus `client-typing` / `client-cursor` |
| Job progress | `private-job.{id}` | Publish from the job with `Realtime::publish()` |
