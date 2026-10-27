# Server

```bash
php artisan realtime:serve [--host=0.0.0.0] [--port=6001] [--server-id=rt-1] [-v]
```

Der Server ist ein Artisan-Befehl, die Anwendung ist also vollständig gebootet. Er lädt
Ihre Service-Provider und führt Ihre Channel-Callbacks aus; User-Provider und
Datenbankverbindungen kommen aus demselben Container.

Beim Start gibt er eine Zeile aus: `Realtime server listening on <host>:<port> (id <id>)`.
Mit `--port=0` wählt das Betriebssystem einen freien Port. Mit `-v` erscheinen auch
informative Logzeilen.

## Funktionsweise

Der Server ist eine Single-Thread-Schleife um `stream_select()`. Die Schleife überwacht:

- den lauschenden Socket;
- jeden Client-Socket (zum Lesen und, solange Ausgaben anstehen, zum Schreiben);
- eine rohe RESP-Verbindung zu Redis, die die Pub/Sub-Nachrichten transportiert.

`subscribe()` von phpredis blockiert den Prozess und kann deshalb keine Schleife mit den
Client-Sockets teilen. Darum bedient ein kleiner eingebauter RESP-Client die
Pub/Sub-Verbindung. Alle anderen Redis-Aufrufe (Publish, Replay, Tickets, Presence) laufen
über Ihre phpredis-Verbindung von Laravel, jeweils mit einem Roundtrip.

Was eine Iteration tut:

1. Nimmt neue Verbindungen an, bis zu 100 pro Iteration.
2. Liest von bereiten Sockets:
   - Während des Handshakes sammelt er den HTTP-Kopf (höchstens 8 KiB). Er prüft das
     Upgrade, verbraucht das Ticket, sendet `101` und danach `welcome`.
   - Sobald eine Verbindung offen ist, dekodiert er Frames inkrementell, setzt
     fragmentierte Nachrichten zusammen, beantwortet Pings und ruft den Protokoll-Handler
     auf.
3. Liest Pub/Sub-Nachrichten und stellt jede den lokalen Abonnenten zu. Der Frame wird
   einmal pro Nachricht kodiert, nicht einmal pro Verbindung.
4. Schreibt anstehende Ausgaben nicht blockierend. Bei Teilschreibvorgängen bleibt der
   Rest im Puffer.
5. Führt einmal pro Sekunde Timer aus: Handshake-Timeouts, Keepalive-Pings,
   Close-Timeouts, den Server-Heartbeat in Redis (alle 10 s), das Aufräumen toter Server
   (alle 15 s) und eine Prüfung der Pub/Sub-Verbindung.

Ein ext-event-Backend ist in 0.1 **nicht implementiert**. `stream_select()` ist durch
`FD_SETSIZE` begrenzt, in PHP-Builds typischerweise 1024 Deskriptoren pro Prozess. Für mehr
Verbindungen starten Sie mehrere Prozesse (siehe [Betrieb](operations.md)).

## Reihenfolge- und Zustellgarantien

- Ereignisse eines Channels erreichen jeden Abonnenten in Sequenzreihenfolge. Die
  Sequenznummer wird in Redis von einem einzigen Lua-Skript vergeben, das das Ereignis
  zugleich an das Replay-Log anhängt und veröffentlicht.
- Die Zustellung erfolgt höchstens einmal pro Verbindung. Ein Client, der sich mit
  `since` neu verbindet, erhält verpasste Ereignisse zurück, solange sie noch im
  Replay-Puffer liegen. Andernfalls erhält er `gap: true` und muss seinen Zustand neu
  laden.
- Abonniert ein Server einen Channel zum ersten Mal, wartet er auf die Bestätigung durch
  Redis und liest erst dann das Replay-Log. Dazwischen Veröffentlichtes geht nicht
  verloren, doppelt Zugestelltes wird anhand der Sequenznummer verworfen.
- Bricht die Pub/Sub-Verbindung des Servers zu Redis ab, verbindet er sich mit Backoff
  neu (0,5 s, dann verdoppelnd bis 30 s). Danach spielt er jeden seiner Channels ab der
  zuletzt gesehenen Sequenznummer nach. Channels, die aus dem Puffer gefallen sind,
  erhalten eine `resync`-Nachricht.

## Konfiguration

`config/realtime.php` (veröffentlichen mit `php artisan vendor:publish --tag=realtime-config`):

| Schlüssel | Standard | Bedeutung |
|-----|---------|---------|
| `server.host` / `server.port` | `0.0.0.0` / `6001` | Bind-Adresse |
| `server.reuse_port` | `false` | `SO_REUSEPORT`: mehrere Prozesse auf einem Port (Linux) |
| `public_url` | `null` | Mit dem Ticket zurückgegebene URL, z. B. `wss://app.example.com/realtime` |
| `allowed_origins` | `[]` | Origin-Allowlist (`https://a.example.com`, `*.example.com`); leer erlaubt jede |
| `redis.connection` | `default` | phpredis-Verbindung aus `database.redis` |
| `redis.prefix` | `realtime:` | Präfix aller Schlüssel und Pub/Sub-Channels |
| `ticket.ttl` | `30` | Ticket-Lebensdauer in Sekunden |
| `ticket.guard` | `null` | Guard für Tickets und das Laden von Benutzern (`null` = Standard-Guard) |
| `routes.*` | aktiv, `realtime`, `['web','auth']` | Ticket-Route `POST /realtime/ticket` |
| `scope` | `''` | Fallback-Scope, wenn kein Resolver registriert ist |
| `replay.size` | `100` | Pro Channel für Replay vorgehaltene Ereignisse |
| `replay.retention` | `86400` | Sekunden, die ein inaktiver Channel Zähler und Log behält |
| `heartbeat.interval` / `heartbeat.timeout` | `25` / `20` | Keepalive in Sekunden |
| `limits.max_connections` | `1000` | Pro Prozess; weitere Verbindungen erhalten HTTP 503 (siehe Hinweis zu `FD_SETSIZE` oben) |
| `limits.max_frame_size` / `limits.max_message_size` | `65536` | Bytes; Größeres wird mit 1009 geschlossen |
| `limits.max_subscriptions` | `100` | Channels pro Verbindung |
| `limits.max_buffer` | `4194304` | Anstehende Ausgabebytes pro Verbindung, bevor mit 4008 geschlossen wird |
| `limits.messages_per_second` | `50` | Token-Bucket pro Verbindung; Überschreitung schließt mit 1008 |
| `limits.handshake_timeout` | `10` | Sekunden bis zum Abschluss des HTTP-Upgrades |
| `client_events` | `true` | `client-*`-Ereignisse in Private- und Presence-Channels erlauben |

Hinweise zur Redis-Verbindung:

- Die eigene `prefix`-Option der Verbindung und ihr Serializer werden **nicht** verwendet.
  Jeder Befehl läuft über `rawCommand()`, sodass Webprozesse und Server sich unabhängig
  von den Verbindungsoptionen auf Schlüsselnamen einigen.
- Der Pub/Sub-Socket liest `host`, `port`, `username`, `password`, `scheme` (`tls`) und
  `url` aus derselben Verbindungskonfiguration.
- Redis Cluster und predis werden nicht unterstützt.

## Health

`GET /health` auf dem Server-Port liefert JSON:

```json
{"status":"ok","server_id":"rt-1","connections":12,"channels":5,"redis":true,"uptime":3600,"memory":31457280}
```

Der Status ist 200, oder 503 mit `"status":"degraded"`, solange die Pub/Sub-Verbindung zu
Redis unterbrochen ist. Jede andere Anfrage ohne Upgrade erhält 404.

## Herunterfahren

Bei SIGTERM oder SIGINT (erfordert ext-pcntl) führt der Server folgende Schritte aus:

1. sendet jedem Client den Close-Code 1001;
2. entfernt die Presence-Einträge der Clients;
3. leert die Ausgaben bis zu eine Sekunde lang;
4. meldet sich bei Redis ab und beendet sich.

Clients verbinden sich mit einer anderen Instanz und spielen ab ihrer letzten
Sequenznummer nach.

## Besonderheiten langlebiger Prozesse

- Channel-Callbacks und User-Provider laufen während der gesamten Lebensdauer im selben
  Prozess. Vermeiden Sie unbegrenzt wachsende statische Caches und halten Sie keinen
  Request-Zustand.
- Eine Datenbankverbindung kann zwischen Abonnements veralten. Laravel verbindet sich bei
  „lost connection“-Fehlern neu. Schließt Ihre Datenbank inaktive Verbindungen aggressiv,
  rufen Sie `DB::reconnect()` in Ihrem `authorizeUsing()`-Hook auf oder starten Sie den
  Server regelmäßig neu (Neustarts übernimmt Supervisor).
- Deployments: Starten Sie den Server nach jedem Deployment neu, damit er den neuen Code
  lädt – genau wie Queue-Worker.
