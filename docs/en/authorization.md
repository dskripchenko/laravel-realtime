# Authorization and tenancy

Three steps decide what a connection may see.

1. **A ticket** fixes the user and the scope. It is issued over HTTP, inside your normal
   session and tenant context.
2. **Scoping**: the server keys every channel by the scope of the ticket. A connection
   cannot address another scope's channels at all.
3. **Channel callbacks** decide whether the user may subscribe to private and presence
   channels.

## Tickets

`POST /realtime/ticket` uses the `web` and `auth` middleware by default. It returns:

```json
{"ticket":"9f3c…64 hex chars…","url":"wss://app.example.com/realtime","expires_in":30}
```

- The ticket is 32 random bytes. Redis stores it under `sha256(ticket)` with a TTL of
  `ticket.ttl` (30 s by default).
- The server consumes it with `GETDEL` during the WebSocket upgrade, so each ticket opens
  exactly one connection.
- The ticket carries the user's auth identifier, the guard (`realtime.ticket.guard`, or
  `null` for the default guard) and the scope.
- The scope comes from `Realtime::resolveScopeUsing()`, evaluated while the ticket
  request runs.

You can change the route through `realtime.routes` (prefix and middleware), or turn it off
and issue tickets yourself:

```php
Route::middleware(['api', 'auth:sanctum'])->post('/api/realtime/ticket', function (Request $request) {
    return Realtime::issueTicket($request->user(), 'sanctum', scope: tenant()->id, request: $request);
});
```

### Claims and expiry

A ticket issued for an API token rather than a browser session usually has to carry more
than the user: what the token may do, and when it stops being valid. `issueTicket()` takes
both:

```php
Realtime::issueTicket(
    Authenticatable $user,
    ?string $guard = null,
    ?string $scope = null,
    ?Request $request = null,
    array $claims = [],          // array<string, mixed>, JSON-safe, yours to define
    ?int $expiresAt = null,      // unix seconds; the connection ends then
): IssuedTicket
```

```php
$token = $request->user()->currentAccessToken();

return Realtime::issueTicket($request->user(), 'sanctum', request: $request,
    claims: ['token' => $token->id, 'abilities' => $token->abilities],
    expiresAt: $token->expires_at?->getTimestamp(),
);
```

- **Claims** travel with the ticket and stay on the connection as `Ticket::$claims`. The
  package does not interpret them. Use them in `authorizeUsing()` (see below) to limit what
  the connection may subscribe to, and in `disconnect()` to find the connection again.
  Claim names must be strings; values must survive `json_encode()`/`json_decode()`
  unchanged (scalars, `null`, arrays; no objects). Integer-like names such as `"7"` are not
  allowed, because PHP turns them into integer keys.
- **Expiry** (`expiresAt`, `Ticket::$expiresAt`) is when the connection must end, not the
  ticket's own TTL (`ticket.ttl` still limits how long the ticket can be redeemed). It must
  be in the future, otherwise `issueTicket()` throws `InvalidArgumentException`. The server
  rejects an already expired ticket at the upgrade with 401, and checks open connections
  once a second: when the time has come, the client gets
  `{"type":"error","code":"expired","message":"Ticket expired"}` and a close with code 1008.
  Subscriptions end as on any close (presence `leave` included). `Ticket::expired(?int $now = null)`
  tells whether a ticket has expired.
- Tickets stored by an older version (no claims, no expiry) are still accepted.

### Disconnecting by claims (`disconnect`)

When the token behind a connection is revoked, close every connection opened with it:

```php
Realtime::disconnect(['token' => $token->id]);                  // current scope
Realtime::disconnect(['token' => $token->id], scope: 'acme');   // explicit scope
```

```php
Realtime::disconnect(array $claims, ?string $scope = null): void
```

- Every server closes its open connections in the scope (the current scope by default,
  resolved as for `publish()`) whose ticket claims contain **all** the given pairs, compared
  strictly (`===`): `['token' => 5]` does not match a ticket with `['token' => '5']`.
- The clients get `{"type":"error","code":"revoked","message":"Connection revoked"}` and a
  close with code 1008. Subscriptions end as on any close.
- An empty `$claims` throws `InvalidArgumentException`: the call can never close every
  connection by accident.
- The request travels over a reserved control channel of the broker
  (`RealtimeManager::CONTROL_KEY`) that every server subscribes to and that clients can
  neither subscribe to nor receive. It is not stored for replay: only connections open at
  that moment are closed. Stop issuing tickets for the token first, then call
  `disconnect()`.

## Scopes

```php
Realtime::resolveScopeUsing(fn (): string => (string) app(CurrentTenant::class)->id());
```

The resolver is used twice:

- when a ticket is issued, to fix the connection's scope;
- when something is published (`Realtime::publish()`, broadcasting) without an explicit
  scope.

This means a queued `ShouldBroadcast` job must run inside the tenant context, which you
need for multi-tenant queues anyway. You can also pin the scope:

- per call: `Realtime::publish($channel, $event, $data, scope: 'acme')`;
- per broadcasting connection: `['driver' => 'realtime', 'scope' => 'central']`.

A scope may contain `[A-Za-z0-9_\-.:]` and is at most 100 bytes long. The empty string is
a valid scope and is the default for single-tenant apps.

## Channel callbacks

```php
use Dskripchenko\Realtime\Facades\Realtime;

// private-board.{id}
Realtime::channel('board.{id}', function (User $user, string $id): bool {
    return $user->boards()->whereKey($id)->exists();
});

// presence-page.{id}: return the member info shown to others
Realtime::channel('page.{id}', function (User $user, string $id): array|false {
    return $user->can('view', Page::find($id)) ? ['name' => $user->name, 'avatar' => $user->avatar_url] : false;
});
```

How callbacks are matched and called:

- The pattern is matched against the channel name without its `private-` or `presence-`
  prefix. Placeholders `{name}` match one dot-separated segment and are passed in order,
  as strings. Implicit model binding is not available.
- For private channels, any truthy return value allows the subscription.
- For presence channels, an array return value becomes the member's `info`. `true` means
  empty info.
- A private or presence channel without a matching callback is **denied**.
- Public channels need no callback.
- If a callback throws, the subscription is denied with `forbidden` and the exception goes
  to your exception handler.

### Callbacks run in the realtime server

Callbacks run inside the `realtime:serve` process, not in an HTTP request. The server calls
them directly; there is no extra HTTP round trip. As a result:

- `request()`, the session, cookies and `auth()->user()` are not available, or belong to
  nothing. Use the `$user` argument.
- The user is loaded once per subscription through the guard's user provider
  (`retrieveById`). To load users differently:

  ```php
  Realtime::resolveUserUsing(fn (Ticket $ticket) => User::query()->find($ticket->userId));
  ```

- Channel callbacks are registered in service providers, so the server process must load
  the same providers as your web app. It does, as an artisan command.

### Entering the tenant (`authorizeUsing`)

In a multi-tenant app (for example one schema per tenant), user loading and callbacks have
to run inside the tenant of the ticket. Wrap them:

```php
use Dskripchenko\Realtime\Auth\Ticket;

Realtime::authorizeUsing(function (Ticket $ticket, Closure $next): mixed {
    return Tenancy::run($ticket->scope, $next);   // enter, call $next(), always leave
});
```

The hook:

- receives the `Ticket` (`userId`, `guard`, `scope`, `issuedAt`, `claims`, `expiresAt`), so
  it can refuse by claims as well, or make them available to the channel callbacks for
  the duration of `$next()`;
- must return what `$next()` returns, or `false` to refuse without calling it (for example,
  when the ticket's tenant no longer exists or is suspended);
- runs once per subscription;
- must restore the previous context, even on exceptions (`try`/`finally`), because the
  process serves every tenant.

## Revoking access (`reauthorize`)

Authorization is checked when a client subscribes. When access changes later (a role is
removed, a document is closed to someone), tell the servers to check the channel again:

```php
// after the change is committed
Realtime::reauthorize('private-board.42');                 // current scope
Realtime::reauthorize('presence-page.7', scope: 'acme');   // explicit scope
```

Every server runs the channel callback again for each of its subscriptions to that channel,
through `authorizeUsing()` like a subscribe does. Subscriptions that still pass are left
alone. The others are removed exactly as if the client had unsubscribed: on a presence
channel the other members get `leave`. The dropped client receives an error and no further
events:

```json
{"type":"error","channel":"private-board.42","code":"revoked","message":"Subscription revoked: forbidden"}
```

A callback that throws counts as denied (`Subscription revoked: error`), and the exception
goes to your exception handler.

How it works and what to expect:

- `reauthorize()` publishes an ordinary event named `realtime:reauthorize`
  (`RealtimeManager::REAUTHORIZE`) to the channel and returns its sequence number. The
  server consumes it and never forwards it, so clients see a skipped `seq`. The
  `realtime:` prefix is reserved: do not use it for your own event names.
- No Redis or protocol change is involved, so calling it is safe against any server
  version. A server that predates the feature forwards the event to clients like any other;
  the JS client ignores it.
- Only channels that need it are checked: one callback call per local subscription of the
  channel. Call it for the channels whose access actually changed.
- The client may subscribe again; that runs the callback again and fails with `forbidden`
  while access is still missing.

## Hardening checklist

- Prefer private channels. Public channels are visible to every logged-in user of the same
  scope.
- Keep `ticket.ttl` short. Tickets travel in the query string, so make sure your proxy
  does not log query strings for the realtime location, or accept that one-time tickets
  in logs are harmless once used or expired.
- Set `allowed_origins`.
- Keep presence `info` minimal. Every member of the channel sees it.
- Client events (`client-*`) come straight from other users. Treat their `data` as
  untrusted input, and disable them with `client_events => false` if you do not need them.
- Revoking access does not drop existing subscriptions by itself. Authorization is checked
  when a client subscribes. Call `Realtime::reauthorize($channel)` after the change to make
  the servers check existing subscriptions again (see above).
- For tickets issued for tokens, pass the token's expiry as `expiresAt`, and call
  `Realtime::disconnect()` with the token's claims when it is revoked.
