# Protokoll

Jede Nachricht ist ein JSON-Objekt, das als WebSocket-**Text**nachricht gesendet wird.
Eine Binärnachricht schließt die Verbindung mit Code 1003. Jede Nachricht hat ein Feld
`type`.

Eine Client-Nachricht kann eine `id` tragen, eine Ganzzahl oder einen String mit bis zu 64
Zeichen. Der Server kopiert die `id` in die Antwort `ack`, `error`, `pong` oder
`presence`, damit der Client Antworten den Anfragen zuordnen kann.

## Verbindungsaufbau

```text
GET /?ticket=<ticket> HTTP/1.1
Upgrade: websocket
Connection: Upgrade
Sec-WebSocket-Version: 13
Sec-WebSocket-Key: ...
Origin: https://app.example.com
```

Der Server kann das Upgrade mit einem dieser HTTP-Status ablehnen:

| Status | Grund |
|--------|--------|
| 400 | Fehlerhafte Upgrade-Anfrage |
| 401 | Ticket fehlt, ist unbekannt, abgelaufen oder bereits verwendet |
| 403 | `Origin` nicht in `realtime.allowed_origins` |
| 426 | `Sec-WebSocket-Version` ist nicht 13 |
| 431 | Anfragekopf größer als 8 KiB |
| 503 | Verbindungslimit erreicht oder Ticket-Speicher nicht verfügbar |

Ein Browser sieht diese Status nicht, weil die `WebSocket`-API nur den Close-Code 1006
meldet. Clients sollten vor jedem Versuch ein neues Ticket holen – genau das tut der
JS-Client.

Nach erfolgreichem Upgrade sendet der Server eine Begrüßung:

```json
{"type":"welcome","socket_id":"4161587040080015970.4500350542000801717","heartbeat":25}
```

- `socket_id` ist der Wert, der bei HTTP-Anfragen als `X-Socket-ID` gesendet wird, damit
  `broadcast(...)->toOthers()` diese Verbindung überspringt.
- `heartbeat` ist das Keepalive-Intervall des Servers in Sekunden.

## Channels

| Name | Typ | Wer abonnieren darf |
|------|------|-------------------|
| `news`, `board.42.public` | public | jede Verbindung im selben Scope |
| `private-board.42` | private | der Callback für `board.{id}` liefert einen wahren Wert |
| `presence-page.7` | presence | der Callback für `page.{id}` liefert einen wahren Wert; ein Array als Rückgabe wird zur `info` des Mitglieds |

Ein Channel-Name entspricht `[A-Za-z0-9_\-=@,.;:]+` und ist höchstens 200 Bytes lang. Im
Server und in Redis wird jeder Channel als `<scope>/<name>` geführt, wobei der Scope aus
dem Ticket stammt. Zwei Mandanten, die beide `private-board.1` abonnieren, nutzen zwei
verschiedene Channels.

## Client → Server

### subscribe

```json
{"type":"subscribe","id":1,"channel":"private-board.42"}
{"type":"subscribe","id":2,"channel":"private-board.42","since":17}
```

`since` ist die letzte Sequenznummer, die der Client angewendet hat. Beim ersten
Abonnement entfällt sie.

Der Server antwortet mit einem `ack`. Presence-Channels erhalten im `ack` zusätzlich eine
`members`-Liste:

```json
{"type":"ack","id":2,"channel":"private-board.42","seq":19,"gap":false}
{"type":"ack","id":3,"channel":"presence-page.7","seq":0,"gap":false,
 "members":[{"id":7,"info":{"name":"Ann"}}]}
```

Nach dem `ack` sendet der Server die Ereignisse von `since + 1` bis zum aktuellen Kopf,
älteste zuerst. Danach folgen Live-Ereignisse. Die Felder des `ack`:

- `seq` ist der aktuelle Kopf des Channels.
- `gap: true` bedeutet, dass die verpassten Ereignisse nicht mehr nachgespielt werden
  können: Es wurden mehr als `realtime.replay.size` Ereignisse verpasst, das Log ist
  abgelaufen oder der Channel wurde zurückgesetzt (`since` > Kopf). Dann wird nichts
  nachgespielt. Der Client muss seinen Zustand neu laden (etwa per HTTP) und bei `seq`
  weitermachen.

Ein erneutes Abonnieren eines bereits abonnierten Channels liefert ein weiteres `ack` und
ändert nichts.

Fehler verwenden den `code` `forbidden` (der Callback hat abgelehnt oder für einen
Private- oder Presence-Channel ist kein Callback registriert), `bad_channel`, `limit` (mehr
als `limits.max_subscriptions`) oder `unavailable` (Redis ist nicht erreichbar):

```json
{"type":"error","id":1,"channel":"private-board.13","code":"forbidden","message":"Subscription denied: forbidden"}
```

### unsubscribe

```json
{"type":"unsubscribe","id":4,"channel":"private-board.42"}
```

Die Antwort ist `{"type":"ack","id":4,"channel":"private-board.42"}`. In einem
Presence-Channel erhalten die anderen Mitglieder `leave`, wenn dies die letzte Verbindung
des Benutzers war.

### presence (list)

```json
{"type":"presence","id":5,"channel":"presence-page.7","action":"list"}
```

Die Antwort ist `{"type":"presence","id":5,"channel":"presence-page.7","action":"list","members":[...]}`.
Clients können kein `join` oder `leave` senden; diese ergeben sich aus dem Abonnieren.

### event (Client-Ereignisse)

```json
{"type":"event","id":6,"channel":"presence-page.7","event":"client-typing","data":{"on":true}}
```

Client-Ereignisse werden den anderen Abonnenten des Channels auf allen Servern
zugestellt. Sie sind für Tipp-Indikatoren, Cursor und ähnliche kurzlebige Signale gedacht.
Die Regeln:

- Die Verbindung muss den Channel bereits abonniert haben.
- Der Channel muss private oder presence sein.
- Der Ereignisname muss `client-<name>` entsprechen.
- `realtime.client_events` muss aktiviert sein.

Client-Ereignisse haben keine Sequenznummer und werden nicht nachgespielt. Ist eine `id`
angegeben, erhält der Absender ein `ack`. Empfänger sehen die Benutzer-ID des Absenders:

```json
{"seq":null,"type":"event","channel":"presence-page.7","event":"client-typing","data":{"on":true},"user_id":2,"except":"..."}
```

### ping

```json
{"type":"ping","id":"p1"}
```

Die Antwort ist `{"type":"pong","id":"p1"}`. Browser können keine WebSocket-Ping-Frames
senden; mit dieser Nachricht prüft ein Client daher, ob die Verbindung lebt. Der Server
sendet auch selbst Ping-Frames (siehe Keepalive unten).

## Server → Client

### event

```json
{"seq":21,"type":"event","channel":"private-board.42","event":"CardMoved","data":{"card":5}}
```

- `seq` steigt mit jedem im Channel veröffentlichten Ereignis um eins. Clients müssen ein
  Ereignis ignorieren, dessen `seq` ≤ der zuletzt angewendeten ist, weil sich Replay und
  Live-Zustellung kurz überschneiden können.
- `seq: null` kennzeichnet nicht nachspielbare Nachrichten: Presence-Hinweise und
  Client-Ereignisse.
- `except` ist intern: die Socket-ID, die der Server übersprungen hat.

### Presence-Hinweise

```json
{"seq":null,"type":"presence","channel":"presence-page.7","action":"join","member":{"id":2,"info":{"name":"Bob"}}}
{"seq":null,"type":"presence","channel":"presence-page.7","action":"leave","member":{"id":2,"info":{"name":"Bob"}}}
```

`join` wird gesendet, wenn ein Benutzer seine erste Verbindung zum Channel erhält, `leave`,
wenn seine letzte Verbindung wegfällt. Mehrere Tabs eines Benutzers zählen als ein
Mitglied. Die beitretende Verbindung erhält ihr eigenes `join` ebenfalls.

### resync

```json
{"type":"resync","channel":"private-board.42","seq":250}
```

Der Server sendet `resync`, wenn seine eigene Pub/Sub-Verbindung zu Redis länger
unterbrochen war, als der Replay-Puffer abdeckt. Der Client sollte seinen Zustand neu
laden und bei `seq` weitermachen.

### error

```json
{"type":"error","id":7,"code":"bad_request","message":"Field \"channel\" is required"}
```

| Code | Bedeutung |
|------|---------|
| `bad_request` | Ungültiges JSON oder ungültige Felder |
| `unknown_type` | Nicht unterstützter `type` |
| `bad_channel` | Ungültiger Channel-Name |
| `forbidden` | Autorisierung verweigert oder Client-Ereignis nicht erlaubt |
| `not_subscribed` | `presence`/`event` auf einem Channel, den die Verbindung nicht abonniert hat |
| `limit` | Zu viele Abonnements |
| `unavailable` | Redis vorübergehend nicht verfügbar |
| `internal` | Unerwarteter Serverfehler (wird geloggt) |

Fehler schließen die Verbindung nie.

## Keepalive

- Der Server sendet nach `heartbeat.interval` Sekunden Stille (standardmäßig 25) einen
  Ping-Frame. Er schließt die Verbindung mit Code 4000, wenn innerhalb von
  `heartbeat.timeout` Sekunden (standardmäßig 20) nichts ankommt.
- Clients senden nach einem Heartbeat-Intervall Stille `{"type":"ping"}`. Nach zwei
  Intervallen trennen sie die Verbindung und verbinden sich neu.

## Close-Codes

| Code | Wann |
|------|------|
| 1000 | Normales Schließen (der Server spiegelt den Code des Clients) |
| 1001 | Server fährt herunter |
| 1002 | Protokollfehler (unmaskierter Frame, falscher Opcode, RSV-Bits, falsche Fortsetzung, falscher Close-Payload) |
| 1003 | Binärnachricht |
| 1007 | Textnachricht ist kein gültiges UTF-8 |
| 1008 | Ratenlimit (`limits.messages_per_second`) überschritten |
| 1009 | Frame oder Nachricht größer als die Limits |
| 4000 | Keepalive-Timeout |
| 4008 | Langsamer Konsument: mehr als `limits.max_buffer` Bytes warten auf Versand |

## Nicht unterstützt

- Erweiterungen, einschließlich permessage-deflate. RSV-Bits müssen null sein.
- Subprotokolle. `Sec-WebSocket-Protocol` wird ignoriert.
