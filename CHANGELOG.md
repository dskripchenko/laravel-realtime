# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

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
  `Realtime::authorizeUsing()`, custom user loading `Realtime::resolveUserUsing()`.
- Ticket endpoint `POST /realtime/ticket`.
- Broadcasting driver `realtime` (`ShouldBroadcast`, `toOthers()`), facade
  `Realtime::publish()`, `realtime:publish` command.
- TypeScript client `@dskripchenko/realtime` (in `js/`): ticket fetch, reconnect with
  exponential backoff and jitter, resubscribe with replay, presence, client events,
  typed handlers, keepalive. Zero runtime dependencies.

### Not included yet

- permessage-deflate (RFC 7692).
- An ext-event loop backend; the server always uses `stream_select()`.
- TLS termination in the server itself.
- Redis Cluster and predis.
- UI kit adapter for `@dskripchenko/ui` components.
