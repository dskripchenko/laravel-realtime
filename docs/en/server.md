# Server

```bash
php artisan realtime:serve [--host=0.0.0.0] [--port=6001] [--server-id=rt-1] [-v]
```

The server is an artisan command, so the application is fully booted. It loads your
service providers and runs your channel callbacks, and the same container provides the
user providers and the database connections.

When it starts it prints one line, `Realtime server listening on <host>:<port> (id <id>)`.
With `--port=0` the OS picks a free port. Use `-v` to see informational log lines as well.

## How it works

The server is a single-threaded loop around `stream_select()`. The loop watches:

- the listening socket;
- every client socket (for reading, and for writing while output is pending);
- one raw RESP connection to Redis that carries the pub/sub messages.

phpredis' `subscribe()` blocks the process, so it cannot share a loop with the client
sockets. That is why the pub/sub link is a small built-in RESP client. All other Redis
calls (publish, replay, tickets, presence) use your Laravel phpredis connection. Each of
these takes one round trip.

What one iteration does:

1. Accepts new connections, up to 100 per iteration.
2. Reads from ready sockets:
   - During the handshake, it collects the HTTP head (at most 8 KiB). It validates the
     upgrade, consumes the ticket, sends `101` and then `welcome`.
   - Once a connection is open, it decodes frames incrementally, reassembles fragmented
     messages, answers pings and runs the protocol handler.
3. Reads pub/sub messages and delivers each one to the local subscribers. The frame is
   encoded once per message, not once per connection.
4. Writes pending output without blocking. Partial writes keep the rest of the buffer.
5. Runs timers once per second: handshake timeouts, keepalive pings, close timeouts,
   the server heartbeat in Redis (every 10 s), reaping of dead servers (every 15 s) and a
   pub/sub link check.

An ext-event backend is **not implemented** in 0.1. `stream_select()` is limited by
`FD_SETSIZE`, which is typically 1024 descriptors per process on PHP builds. For more
connections, run several processes (see [operations](operations.md)).

## Ordering and delivery guarantees

- Events on one channel reach every subscriber in sequence order. The sequence number is
  assigned in Redis by one Lua script, which also appends the event to the replay log and
  publishes it.
- Delivery is at most once per connection. A client that reconnects with `since` gets the
  missed events back if they are still in the replay buffer. Otherwise it gets `gap: true`
  and has to reload its state.
- When a server subscribes a channel for the first time, it waits for Redis to confirm the
  subscription and only then reads the replay log. Nothing published in between is lost,
  and anything delivered twice is dropped by its sequence number.
- If the server's pub/sub link to Redis drops, the server reconnects with backoff
  (0.5 s, then doubling up to 30 s). It then replays every channel it holds from the last
  sequence number it saw. Channels that fell out of the buffer get a `resync` message.

## Configuration

`config/realtime.php` (publish it with `php artisan vendor:publish --tag=realtime-config`):

| Key | Default | Meaning |
|-----|---------|---------|
| `server.host` / `server.port` | `0.0.0.0` / `6001` | Bind address |
| `server.reuse_port` | `false` | `SO_REUSEPORT`: several processes on one port (Linux) |
| `public_url` | `null` | URL returned with tickets, e.g. `wss://app.example.com/realtime` |
| `allowed_origins` | `[]` | Origin allow-list (`https://a.example.com`, `*.example.com`); empty allows any |
| `redis.connection` | `default` | phpredis connection from `database.redis` |
| `redis.prefix` | `realtime:` | Prefix of every key and pub/sub channel |
| `ticket.ttl` | `30` | Ticket lifetime, seconds |
| `ticket.guard` | `null` | Guard for tickets and user loading (`null` = default guard) |
| `routes.*` | enabled, `realtime`, `['web','auth']` | Ticket route `POST /realtime/ticket` |
| `scope` | `''` | Fallback scope when no resolver is registered |
| `replay.size` | `100` | Events kept per channel for replay |
| `replay.retention` | `86400` | Seconds an idle channel keeps its counter and log |
| `heartbeat.interval` / `heartbeat.timeout` | `25` / `20` | Keepalive, seconds |
| `limits.max_connections` | `1000` | Per process; extra connections get HTTP 503 (see the `FD_SETSIZE` note above) |
| `limits.max_frame_size` / `limits.max_message_size` | `65536` | Bytes; larger closes with 1009 |
| `limits.max_subscriptions` | `100` | Channels per connection |
| `limits.max_buffer` | `4194304` | Pending output bytes per connection before closing with 4008 |
| `limits.messages_per_second` | `50` | Token bucket per connection; exceeding it closes with 1008 |
| `limits.handshake_timeout` | `10` | Seconds to finish the HTTP upgrade |
| `client_events` | `true` | Allow `client-*` events on private and presence channels |

Redis connection notes:

- The connection's own `prefix` option and serializer are **not** used. Every command goes
  through `rawCommand()`, so web processes and servers agree on key names whatever the
  connection options are.
- The pub/sub socket reads `host`, `port`, `username`, `password`, `scheme` (`tls`) and
  `url` from the same connection config.
- Redis Cluster and predis are not supported.

## Health

`GET /health` on the server port returns JSON:

```json
{"status":"ok","server_id":"rt-1","connections":12,"channels":5,"redis":true,"uptime":3600,"memory":31457280}
```

The status is 200, or 503 with `"status":"degraded"` while the pub/sub link to Redis is
down. Every other non-upgrade request gets 404.

## Shutdown

On SIGTERM or SIGINT (this needs ext-pcntl) the server:

1. sends close code 1001 to every client;
2. removes the clients' presence entries;
3. flushes output for up to one second;
4. unregisters itself from Redis and exits.

Clients reconnect to another instance and replay from their last sequence number.

## Long-running process caveats

- Channel callbacks and user providers run in the same process for its whole lifetime.
  Avoid static caches that grow without bound, and do not keep request state.
- A database connection can go stale between subscriptions. Laravel reconnects on "lost
  connection" errors. If your database closes idle connections aggressively, call
  `DB::reconnect()` in your `authorizeUsing()` hook, or restart the server periodically
  (supervisor handles restarts).
- Deploys: restart the server after each deploy so that it loads the new code, just as
  you would with queue workers.
