# JS-Client: `@dskripchenko/realtime`

Der Client ist in TypeScript geschrieben und wird als ES-Module mit Typdeklarationen
ausgeliefert. Er hat keine Laufzeitabhängigkeiten und funktioniert in Browsern und in
Node 22+, das ein globales `WebSocket` besitzt. Der Quellcode liegt in [`js/`](../../js).

```bash
pnpm add @dskripchenko/realtime
```

## Verbinden

```ts
import { RealtimeClient } from '@dskripchenko/realtime';

const realtime = new RealtimeClient({
  ticketUrl: '/realtime/ticket',            // default
  // url: 'wss://app.example.com/realtime', // overrides the URL returned with the ticket
  reconnect: { initialDelay: 500, maxDelay: 30_000, factor: 2, jitter: 0.5 },
});

await realtime.connect();      // resolves when the server has accepted the ticket
realtime.state;                // 'idle' | 'connecting' | 'open' | 'reconnecting' | 'closed'
realtime.socketId;             // send as X-Socket-ID for toOthers()
realtime.on('state', (state) => console.log(state));
realtime.on('error', (error) => console.warn(error));
```

### Ticket abrufen

Standardmäßig sendet jeder Verbindungsversuch `POST ticketUrl`. Die Anfrage:

- verwendet `credentials: 'same-origin'`, damit das Session-Cookie mitgeht;
- sendet `Accept: application/json` und `X-Requested-With`;
- sendet `X-XSRF-TOKEN` aus dem `XSRF-TOKEN`-Cookie von Laravel.

Mit `headers` fügen Sie eigene Header hinzu, oder Sie ersetzen die Anfrage vollständig:

```ts
new RealtimeClient({
  fetchTicket: async () => (await api.post('/api/realtime/ticket')).data, // { ticket, url }
});
```

Antwortet der Ticket-Endpunkt mit 401, 403 oder 419, lehnt der Client `connect()` mit
`UnauthorizedError` ab und hört auf: Der Benutzer ist abgemeldet, ein erneuter Versuch hilft
nicht. Jeder andere Fehler wird mit Backoff wiederholt.

### Neuverbindung

Bricht die Verbindung ab, geht der Client so vor:

1. Er wartet `min(maxDelay, initialDelay × factor^attempt)`, wobei der Anteil `jitter`
   dieser Verzögerung zufällig ist, damit sich nach einem Deployment nicht Tausende Tabs
   gleichzeitig neu verbinden;
2. holt ein neues Ticket und verbindet sich;
3. abonniert jeden Channel erneut mit `since = lastSeq`, sodass der Server das Verpasste
   nachspielt.

Mit `reconnect: false` bleibt der Client nach einem Abbruch geschlossen, mit `maxAttempts`
gibt er nach einer Anzahl von Versuchen auf. `close()` ist endgültig, bis Sie erneut
`connect()` aufrufen. Abonnements bleiben für dieses nächste `connect()` erhalten.

### Keepalive

Nach einem Heartbeat Stille sendet der Client `{"type":"ping"}`. Den Heartbeat kündigt der
Server in `welcome` an, oder er wird mit `heartbeat` in ms gesetzt. Nach zwei Heartbeats
Stille betrachtet der Client die Verbindung als tot und verbindet sich neu. So werden
halboffene TCP-Verbindungen erkannt, die der Browser sonst erst nach Minuten bemerken
würde.

### Handshake

Ein Socket, der das `welcome` des Servers nicht innerhalb von `connectTimeout` ms
(standardmäßig 10 000) erhalten hat, wird mit Code 4001 geschlossen und als Abbruch
behandelt. Ein Fehler vor dem `welcome` ist ebenfalls ein Abbruch: Browser lassen ein
Close-Ereignis folgen, aber das eingebaute `WebSocket` von Node hält einen Socket mit
abgelehntem Upgrade (falscher `Origin`, abgelaufenes Ticket) in `CONNECTING`, ohne ihn je
zu schließen.

## Channels

```ts
type BoardEvents = {
  'card.moved': { id: number; column: string };
  'card.deleted': { id: number };
};

const board = realtime.private<BoardEvents>('board.42');   // "private-board.42"
const off = board.on('card.moved', (card, meta) => {
  // card is typed { id: number; column: string }; meta = { channel, event, seq, userId? }
});
board.onAny((event, data, meta) => {});
board.onSubscribed(() => {});                 // after every (re)subscribe
board.onError((e) => e.code === 'forbidden' && showNoAccess());
board.onResync((seq) => reloadBoard());       // missed events are gone: reload state over HTTP
off();                                        // remove one handler
board.unsubscribe();

realtime.subscribe('news');                   // public channel, or any full channel name
realtime.leave('board.42');                   // unsubscribe by name
```

Wie sich Channel-Aufrufe verhalten:

- `subscribe`, `private` oder `join` vor `connect()` aufzurufen ist in Ordnung. Das
  Abonnement wird gesendet, sobald die Verbindung offen ist.
- Ein erneutes Abonnieren desselben Namens liefert dasselbe Channel-Objekt.
- Ereignisse mit einer `seq` kleiner oder gleich der zuletzt angewendeten werden
  verworfen, sodass Replay und Live-Zustellung nie Duplikate erzeugen.

## Presence

```ts
const page = realtime.join<{}, { name: string }>('page.7');   // "presence-page.7"

page.members();                          // [{ id: 7, info: { name: 'Ann' } }, …]
page.onMembers((members) => render(members));
page.onJoin((member) => toast(`${member.info.name} opened the page`));
page.onLeave((member) => {});
page.refresh();                          // ask the server for the full list again

// Client events: typing indicators, cursors (private and presence channels only)
page.whisper('typing', { on: true });    // sent as "client-typing"
page.on('client-typing', (data, meta) => showTyping(meta.userId, data));
```

`whisper()` gibt `false` zurück, wenn der Client nicht verbunden ist. Client-Ereignisse
werden weder gepuffert noch nachgespielt.

## Tests und andere Laufzeitumgebungen

Übergeben Sie einen `WebSocket`-Konstruktor (alles mit `send`, `close`, `readyState` und
den `on*`-Handlern) und eine `fetch`-Implementierung. Die eigenen Unit-Tests des Pakets
steuern den Client genau so über einen In-Memory-Socket (`js/test/fake-socket.ts`).

## API-Übersicht

| Mitglied | Beschreibung |
|--------|-------------|
| `new RealtimeClient(options)` | `ticketUrl`, `fetchTicket`, `url`, `headers`, `credentials`, `reconnect`, `heartbeat`, `connectTimeout`, `WebSocket`, `fetch`, `random` |
| `connect(): Promise<void>` | Öffnen (idempotent) |
| `close()` | Endgültig schließen |
| `subscribe(name)` / `private(name)` / `join(name)` | `Channel` / `PresenceChannel` holen |
| `leave(name)` | Nach Namen abbestellen |
| `on('state' \| 'error' \| 'message', fn)` | Client-Ereignisse; gibt eine Abmeldefunktion zurück |
| `state`, `socketId` | Aktueller Zustand und Socket-ID |
| `Channel.on/onAny/onSubscribed/onError/onResync/whisper/unsubscribe` | Siehe oben |
| `Channel.lastSeq`, `Channel.subscribed` | Position im Channel und Abonnementstatus |
| `PresenceChannel.members/onMembers/onJoin/onLeave/refresh` | Presence |

## Noch nicht enthalten

Einen Adapter für `@dskripchenko/ui`-Komponenten (Live-Listen, Presence-Avatare) gibt es in
0.1 nicht.
