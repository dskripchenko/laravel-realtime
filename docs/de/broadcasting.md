# Broadcasting-Treiber

Das Paket registriert einen Broadcasting-Treiber `realtime`. `ShouldBroadcast`- und
`ShouldBroadcastNow`-Ereignisse, `broadcast()` und anonyme Broadcasts funktionieren ohne
Änderungen.

```php
// config/broadcasting.php
'default' => env('BROADCAST_CONNECTION', 'realtime'),

'connections' => [
    'realtime' => [
        'driver' => 'realtime',
        // optional: a fixed scope for this connection instead of Realtime::resolveScopeUsing()
        // 'scope' => 'central',
    ],
],
```

## Was beim Client ankommt

```php
class CardMoved implements ShouldBroadcast
{
    use InteractsWithSockets;

    public function __construct(public Card $card) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("board.{$this->card->board_id}")];
    }

    public function broadcastAs(): string
    {
        return 'card.moved';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->card->id, 'column' => $this->card->column];
    }
}
```

Der Client erhält:

```json
{"seq":42,"type":"event","channel":"private-board.7","event":"card.moved","data":{"id":5,"column":"done"}}
```

Wie die Ereigniseinstellungen von Laravel auf die Nachricht abgebildet werden:

- `event` ist `broadcastAs()`, falls definiert, sonst der vollqualifizierte Klassenname,
  wie bei jedem Laravel-Broadcaster.
- `data` ist `broadcastWith()` oder die öffentlichen Eigenschaften des Ereignisses.
- Channel-Namen behalten die Präfixe von Laravel: `PrivateChannel('x')` wird zu
  `private-x`, `PresenceChannel('x')` zu `presence-x`.
- Jeder Channel in `broadcastOn()` erhält seine eigene Sequenznummer.

## toOthers()

Laravel legt den Header `X-Socket-ID` der aktuellen Anfrage als `socket` in den Payload.
Der Treiber entfernt ihn aus dem Payload, und der Server überspringt diese Verbindung.
Senden Sie den Header aus dem Browser:

```ts
axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId ?? '';
realtime.on('state', () => {
  axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId ?? '';
});
```

## Scope

Beim Broadcasten eines Ereignisses wird der Scope so ermittelt:

1. die Option `scope` der Verbindung, falls gesetzt;
2. andernfalls `Realtime::resolveScopeUsing()`;
3. andernfalls `config('realtime.scope')`.

Gequeuete Broadcasts (`ShouldBroadcast`) ermitteln den Scope im Queue-Worker. Stellen Sie
sicher, dass Ihr Mandantenkontext für gequeuete Jobs wiederhergestellt wird.

## Veröffentlichen ohne Ereignisse

```php
use Dskripchenko\Realtime\Facades\Realtime;

$seq = Realtime::publish('private-board.7', 'card.moved', ['id' => 5]);              // current scope
Realtime::publish('news', 'Released', ['version' => '2.0'], scope: 'acme');           // explicit scope
Realtime::publish('private-job.91', 'progress', ['done' => 40, 'total' => 100], except: $socketId);
```

`publish()` gibt die Sequenznummer des Ereignisses zurück. Bei ungültigen Channel-Namen
oder Scopes und wenn Redis nicht verfügbar ist, wirft es eine Exception. Im Broadcaster
werden diese Fehler zu `BroadcastException`.

Dasselbe steht auf der Kommandozeile zur Verfügung:

```bash
php artisan realtime:publish news Released '{"version":"2.0"}' --scope=acme
```

## Channel-Autorisierung

Der Treiber verwendet **nicht** `/broadcasting/auth` oder `Broadcast::channel()`. Die
Autorisierung erfolgt im Realtime-Server mit `Realtime::channel()` (siehe
[Autorisierung](authorization.md)). `Broadcast::auth()` wirft mit diesem Treiber eine
`AccessDeniedHttpException`.

## Typische Anwendungsfälle

| Anwendungsfall | Channel | Hinweise |
|----------|---------|-------|
| Live-Board | `private-board.{id}` | Ereignisse mit `seq`; bei `resync` das Board neu laden |
| Benachrichtigungen | `private-App.Models.User.{id}` | Wie der Benachrichtigungs-Channel von Laravel |
| Wer bearbeitet gerade | `presence-page.{id}` | Mitglieder plus `client-typing` / `client-cursor` |
| Job-Fortschritt | `private-job.{id}` | Aus dem Job mit `Realtime::publish()` veröffentlichen |
