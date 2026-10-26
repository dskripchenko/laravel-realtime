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

- receives the `Ticket` (`userId`, `guard`, `scope`, `issuedAt`);
- must return what `$next()` returns, or `false` to refuse without calling it (for example,
  when the ticket's tenant no longer exists or is suspended);
- runs once per subscription;
- must restore the previous context, even on exceptions (`try`/`finally`), because the
  process serves every tenant.

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
- Revoking access does not drop existing subscriptions. Authorization is checked when a
  client subscribes. To cut off a user immediately, publish an event that makes the client
  unsubscribe, or restart the server. Per-connection kick is not part of 0.1.
