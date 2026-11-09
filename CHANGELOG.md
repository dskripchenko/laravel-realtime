# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.3.0] - 2026-11-09

### Added

- Ticket claims and expiry. `Ticket` gains `array $claims` (host-defined, JSON-safe,
  string keys) and `?int $expiresAt` (unix seconds, the end of the connection), plus
  `expired(?int $now = null): bool`. `toArray()` adds `claims` and `exp`; `fromJson()`
  still accepts tickets without them and rejects malformed ones.
- `Realtime::issueTicket()` takes `array $claims = []` and `?int $expiresAt = null` after
  `$request`. An expiry that is not in the future, or claims without string keys, throw
  `InvalidArgumentException`. Claims reach `authorizeUsing()` through the `Ticket`.
- The server refuses an expired ticket at the upgrade (401) and, in its once-a-second
  maintenance, ends an open connection whose ticket expired:
  `{"type":"error","code":"expired","message":"Ticket expired"}`, then close code 1008,
  with the usual cleanup (presence `leave` included).
- `Realtime::disconnect(array $claims, ?string $scope = null): void` closes, on every
  server, the open connections of the scope (current scope by default) whose ticket claims
  contain all the given pairs (strict comparison). Clients get
  `{"type":"error","code":"revoked","message":"Connection revoked"}` and close code 1008.
  Empty claims throw `InvalidArgumentException`. The request is a volatile message on a
  reserved broker control channel (`RealtimeManager::CONTROL_KEY`, `#realtime:control`)
  that every server subscribes to on connect and again after a Redis reconnect; it is
  never subscribable by or forwarded to clients. Servers that predate it ignore it.

## [0.2.0] - 2026-11-09

### Added

- `Realtime::reauthorize(string $channel, ?string $scope = null): int` makes the servers run
  the channel callbacks again (through `authorizeUsing()`) for existing subscriptions of the
  channel and drop the ones that no longer pass, as an unsubscribe would (presence `leave`
  included). Dropped clients get `{"type":"error","channel":…,"code":"revoked"}`. The request
  is an ordinary event with the reserved name `realtime:reauthorize`
  (`RealtimeManager::REAUTHORIZE`), consumed by the server and never forwarded, so it needs
  no broker or protocol change; older servers forward it as a regular event.
- JS client: `Channel.onRevoked()`; a revoked channel is marked as not subscribed and a
  presence channel's member list is cleared. The `realtime:reauthorize` event is hidden from
  handlers. `REAUTHORIZE_EVENT` is exported.

## [0.1.0] - 2026-10-27

### Added

- `realtime:serve`: single-threaded WebSocket server on `stream_select()` (RFC 6455
  handshake, framing, fragmentation, masking checks, close handshake, ping/pong
  keepalive, frame/message/buffer limits, per-connection rate limit, `/health` probe,
  graceful shutdown on SIGTERM/SIGINT).
- Redis/Valkey broker over phpredis: per-channel sequence numbers, capped replay log
  (Redis stream), pub/sub fan-out, one-time tickets, presence bookkeeping with dead
  server reaping. Non-blocking RESP pub/sub client for the server loop, with automatic
  reconnect and gap filling from the replay log.
- JSON protocol: `subscribe` (with `since` replay), `unsubscribe`, `event`, `presence`,
  `ack`, `error`, `ping`/`pong`, `welcome`, `resync`.
- Scopes (tenants) baked into every channel key; `Realtime::resolveScopeUsing()`.
- Channel authorization callbacks `Realtime::channel()`, wrapping hook
  `Realtime::authorizeUsing()` (returns `false` to refuse, e.g. an unknown tenant), custom user loading `Realtime::resolveUserUsing()`.
- Ticket endpoint `POST /realtime/ticket`.
- Broadcasting driver `realtime` (`ShouldBroadcast`, `toOthers()`), facade
  `Realtime::publish()`, `realtime:publish` command.
- TypeScript client `@dskripchenko/realtime` (in `js/`): ticket fetch, reconnect with
  exponential backoff and jitter, resubscribe with replay, presence, client events,
  typed handlers, keepalive, handshake timeout (`connectTimeout`; an error before the
  welcome counts as a drop, so a refused upgrade in Node does not hang). Zero runtime
  dependencies.

### Not included yet

- permessage-deflate (RFC 7692).
- An ext-event loop backend; the server always uses `stream_select()`.
- TLS termination in the server itself.
- Redis Cluster and predis.
- UI kit adapter for `@dskripchenko/ui` components.

[Unreleased]: https://github.com/dskripchenko/laravel-realtime/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/dskripchenko/laravel-realtime/releases/tag/v0.3.0
[0.2.0]: https://github.com/dskripchenko/laravel-realtime/releases/tag/v0.2.0
[0.1.0]: https://github.com/dskripchenko/laravel-realtime/releases/tag/v0.1.0
