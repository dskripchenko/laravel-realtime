# Operations

## Process model

Each `realtime:serve` process is independent. It is single-threaded, and
`stream_select()` limits it to about 1000 connections (`FD_SETSIZE`). To scale:

- **One host, several cores**: start N processes on the same port with
  `REALTIME_REUSE_PORT=true`. This uses Linux `SO_REUSEPORT`, and the kernel spreads new
  connections across the processes.
- **Several hosts**: run processes on each host behind a load balancer. All processes
  share one Redis/Valkey.

Sessions do not need to be sticky. Every process can serve every channel, and replay
works across processes.

Raise the open file limit (`ulimit -n`, `LimitNOFILE`) above the number of connections
plus some headroom.

## Supervisor

```ini
[program:realtime]
command=php /var/www/app/artisan realtime:serve --port=6001
process_name=%(program_name)s_%(process_num)02d
numprocs=4
environment=REALTIME_REUSE_PORT="true"
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=10
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/realtime.log
```

Restart the processes on deploy (`supervisorctl restart realtime:*`). Clients reconnect
and replay.

## Docker / docker compose

One image can serve every role; the command decides which one a container plays:

```yaml
services:
  realtime:
    image: registry.example.com/app:latest
    command: ["php", "artisan", "realtime:serve", "--port=6001"]
    environment:
      REALTIME_REUSE_PORT: "true"
      REALTIME_PUBLIC_URL: wss://app.example.com/realtime
      REALTIME_ALLOWED_ORIGINS: https://app.example.com
    stop_signal: SIGTERM
    stop_grace_period: 10s
    healthcheck:
      test: ["CMD", "php", "-r", "exit(@file_get_contents('http://127.0.0.1:6001/health') ? 0 : 1);"]
      interval: 15s
      timeout: 3s
    deploy:
      replicas: 2
```

The image needs `ext-redis` and `ext-pcntl` (for graceful shutdown) and the
`pcntl_*` functions enabled for the CLI.

## Reverse proxy

The server speaks plain `ws://`. Terminate TLS in front of it and route a path to it.

nginx:

```nginx
map $http_upgrade $connection_upgrade { default upgrade; '' close; }

upstream realtime { server 127.0.0.1:6001; }

location /realtime {
    proxy_pass http://realtime/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_set_header Host $host;
    proxy_read_timeout 120s;      # longer than heartbeat.interval + heartbeat.timeout
    access_log off;               # the one-time ticket is in the query string
}
```

Caddy:

```text
handle_path /realtime* {
    reverse_proxy 127.0.0.1:6001
}
```

Traefik and other proxies pass WebSocket upgrades through by default. Make sure their
idle timeout is longer than the heartbeat (45 s by default).

The server ignores the request path of the upgrade, so `/realtime` and `/` both work.
`/health` is the only other path it answers.

## Monitoring

- **Liveness / readiness**: `GET /health` returns 200, or 503 when the Redis pub/sub link
  is down. It also reports connections, channels, uptime and memory.
- **Logs**: errors go to stderr (Redis outages, failed presence updates, unexpected
  exceptions). Add `-v` to see informational lines such as Redis reconnects. Exceptions
  thrown by channel callbacks go to Laravel's exception handler.
- **Redis**: the pub/sub connection of each server is named `realtime-<server-id>`
  (`CLIENT LIST TYPE pubsub`). Live servers are listed in the set `<prefix>servers`.

## Redis usage and memory

Keys used, all under `redis.prefix`:

| Key | Type | Lifetime |
|-----|------|----------|
| `seq:<scope>/<channel>` | string | `replay.retention`, refreshed on publish |
| `log:<scope>/<channel>` | stream, `MAXLEN ~ replay.size` | `replay.retention`, refreshed on publish |
| `ch:<scope>/<channel>` | pub/sub channel | none |
| `ticket:<sha256>` | string | `ticket.ttl` |
| `presence:…`, `presence-info:…` | hash | while members are present |
| `server:<id>`, `servers`, `server-presence:<id>`, `reap:<id>` | various | heartbeat-based |

The replay log costs roughly `replay.size × average event size` per active channel. With
`MAXLEN ~`, Redis trims whole internal nodes, so a log can hold somewhat more than
`replay.size` entries.

## Capacity notes

- Each connection uses a few KB of PHP memory plus its pending output. With
  `limits.max_buffer = 4 MiB`, a slow client can hold up to 4 MiB before it is dropped.
  Lower the limit for large fleets.
- A message published to a channel is encoded into one frame per process and copied to
  each local subscriber's buffer. A single process is CPU-bound at high fan-out rates.
  Add processes before you raise limits.
- Every subscribe costs Redis round trips (subscribe confirmation, replay, presence) and
  one channel callback, which usually means a database query. The per-connection
  `messages_per_second` limit keeps a single client from turning this into load.
- The numbers above have not been load-tested for 0.1.0. Measure with your own traffic
  profile before you size production.

## Upgrading Redis / failover

If Redis restarts, publishers get errors until it is back. Servers reconnect their pub/sub
link with backoff and fill gaps from the replay log. If the log was lost (no persistence),
sequence numbers start again at 1:

- connected clients receive `resync` with the new head;
- clients that reconnect later with an older `since` get `gap: true`.

In both cases the client reloads its state.
