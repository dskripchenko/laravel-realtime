# Betrieb

## Prozessmodell

Jeder `realtime:serve`-Prozess ist unabhängig. Er ist single-threaded, und
`stream_select()` begrenzt ihn auf etwa 1000 Verbindungen (`FD_SETSIZE`). Zum Skalieren:

- **Ein Host, mehrere Kerne**: Starten Sie N Prozesse auf demselben Port mit
  `REALTIME_REUSE_PORT=true`. Das nutzt Linux `SO_REUSEPORT`, und der Kernel verteilt neue
  Verbindungen auf die Prozesse.
- **Mehrere Hosts**: Betreiben Sie Prozesse auf jedem Host hinter einem Load Balancer.
  Alle Prozesse teilen sich ein Redis/Valkey.

Sticky Sessions sind nicht nötig. Jeder Prozess kann jeden Channel bedienen, und Replay
funktioniert prozessübergreifend.

Erhöhen Sie das Limit offener Dateien (`ulimit -n`, `LimitNOFILE`) über die Zahl der
Verbindungen plus etwas Reserve.

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

Starten Sie die Prozesse beim Deployment neu (`supervisorctl restart realtime:*`). Clients
verbinden sich neu und spielen nach.

## Docker / docker compose

Ein Image kann jede Rolle übernehmen; welche ein Container spielt, entscheidet der Befehl:

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

Das Image braucht `ext-redis` und `ext-pcntl` (für sauberes Herunterfahren), und die
`pcntl_*`-Funktionen müssen für die CLI freigegeben sein.

## Reverse Proxy

Der Server spricht reines `ws://`. Terminieren Sie TLS davor und leiten Sie einen Pfad an
ihn weiter.

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

Traefik und andere Proxys reichen WebSocket-Upgrades standardmäßig durch. Achten Sie
darauf, dass ihr Idle-Timeout länger als der Heartbeat ist (standardmäßig 45 s).

Der Server ignoriert den Anfragepfad des Upgrades, daher funktionieren `/realtime` und `/`
gleichermaßen. `/health` ist der einzige andere Pfad, den er beantwortet.

## Monitoring

- **Liveness / Readiness**: `GET /health` liefert 200, oder 503, wenn die
  Pub/Sub-Verbindung zu Redis unterbrochen ist. Außerdem meldet er Verbindungen,
  Channels, Uptime und Speicher.
- **Logs**: Fehler gehen nach stderr (Redis-Ausfälle, fehlgeschlagene
  Presence-Aktualisierungen, unerwartete Exceptions). Mit `-v` sehen Sie informative
  Zeilen wie Redis-Neuverbindungen. Exceptions aus Channel-Callbacks gehen an den
  Exception-Handler von Laravel.
- **Redis**: Die Pub/Sub-Verbindung jedes Servers heißt `realtime-<server-id>`
  (`CLIENT LIST TYPE pubsub`). Laufende Server stehen im Set `<prefix>servers`.

## Redis-Nutzung und Speicher

Verwendete Schlüssel, alle unter `redis.prefix`:

| Schlüssel | Typ | Lebensdauer |
|-----|------|----------|
| `seq:<scope>/<channel>` | string | `replay.retention`, bei Publish erneuert |
| `log:<scope>/<channel>` | stream, `MAXLEN ~ replay.size` | `replay.retention`, bei Publish erneuert |
| `ch:<scope>/<channel>` | Pub/Sub-Channel | keine |
| `ticket:<sha256>` | string | `ticket.ttl` |
| `presence:…`, `presence-info:…` | hash | solange Mitglieder anwesend sind |
| `server:<id>`, `servers`, `server-presence:<id>`, `reap:<id>` | verschieden | Heartbeat-basiert |

Das Replay-Log kostet etwa `replay.size × durchschnittliche Ereignisgröße` pro aktivem
Channel. Mit `MAXLEN ~` kürzt Redis ganze interne Knoten, daher kann ein Log etwas mehr als
`replay.size` Einträge enthalten.

## Hinweise zur Kapazität

- Jede Verbindung belegt einige KB PHP-Speicher plus ihre anstehende Ausgabe. Mit
  `limits.max_buffer = 4 MiB` kann ein langsamer Client bis zu 4 MiB halten, bevor er
  getrennt wird. Senken Sie das Limit bei großen Flotten.
- Eine in einen Channel veröffentlichte Nachricht wird pro Prozess in einen Frame kodiert
  und in den Puffer jedes lokalen Abonnenten kopiert. Bei hohen Fan-out-Raten ist ein
  einzelner Prozess CPU-gebunden. Fügen Sie Prozesse hinzu, bevor Sie Limits anheben.
- Jedes Abonnement kostet Redis-Roundtrips (Abonnementbestätigung, Replay, Presence) und
  einen Channel-Callback, meist also eine Datenbankabfrage. Das Limit
  `messages_per_second` pro Verbindung verhindert, dass ein einzelner Client daraus Last
  macht.
- Die obigen Zahlen wurden für 0.1.0 nicht lastgetestet. Messen Sie mit Ihrem eigenen
  Traffic-Profil, bevor Sie die Produktion dimensionieren.

## Redis-Upgrade / Failover

Startet Redis neu, erhalten Publisher Fehler, bis es wieder da ist. Server verbinden ihre
Pub/Sub-Verbindung mit Backoff neu und füllen Lücken aus dem Replay-Log. Ging das Log
verloren (keine Persistenz), beginnen die Sequenznummern wieder bei 1:

- verbundene Clients erhalten `resync` mit dem neuen Kopf;
- Clients, die sich später mit einem älteren `since` neu verbinden, erhalten `gap: true`.

In beiden Fällen lädt der Client seinen Zustand neu.
