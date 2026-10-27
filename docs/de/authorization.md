# Autorisierung und Mandantenfähigkeit

Drei Schritte entscheiden, was eine Verbindung sehen darf.

1. **Ein Ticket** legt Benutzer und Scope fest. Es wird per HTTP innerhalb Ihrer normalen
   Session und Ihres Mandantenkontexts ausgestellt.
2. **Scoping**: Der Server schlüsselt jeden Channel nach dem Scope des Tickets. Eine
   Verbindung kann Channels eines anderen Scopes überhaupt nicht ansprechen.
3. **Channel-Callbacks** entscheiden, ob der Benutzer Private- und Presence-Channels
   abonnieren darf.

## Tickets

`POST /realtime/ticket` verwendet standardmäßig die Middleware `web` und `auth`. Antwort:

```json
{"ticket":"9f3c…64 hex chars…","url":"wss://app.example.com/realtime","expires_in":30}
```

- Das Ticket besteht aus 32 Zufallsbytes. Redis speichert es unter `sha256(ticket)` mit
  einer TTL von `ticket.ttl` (standardmäßig 30 s).
- Der Server verbraucht es beim WebSocket-Upgrade mit `GETDEL`, sodass jedes Ticket genau
  eine Verbindung öffnet.
- Das Ticket enthält die Auth-ID des Benutzers, den Guard (`realtime.ticket.guard` oder
  `null` für den Standard-Guard) und den Scope.
- Der Scope stammt aus `Realtime::resolveScopeUsing()`, ausgewertet während der
  Ticket-Anfrage.

Die Route lässt sich über `realtime.routes` (Präfix und Middleware) ändern oder
abschalten, um Tickets selbst auszustellen:

```php
Route::middleware(['api', 'auth:sanctum'])->post('/api/realtime/ticket', function (Request $request) {
    return Realtime::issueTicket($request->user(), 'sanctum', scope: tenant()->id, request: $request);
});
```

## Scopes

```php
Realtime::resolveScopeUsing(fn (): string => (string) app(CurrentTenant::class)->id());
```

Der Resolver wird zweimal verwendet:

- beim Ausstellen eines Tickets, um den Scope der Verbindung festzulegen;
- beim Veröffentlichen (`Realtime::publish()`, Broadcasting) ohne expliziten Scope.

Ein gequeueter `ShouldBroadcast`-Job muss also im Mandantenkontext laufen, was Sie für
mandantenfähige Queues ohnehin brauchen. Der Scope lässt sich auch festlegen:

- pro Aufruf: `Realtime::publish($channel, $event, $data, scope: 'acme')`;
- pro Broadcasting-Verbindung: `['driver' => 'realtime', 'scope' => 'central']`.

Ein Scope darf `[A-Za-z0-9_\-.:]` enthalten und ist höchstens 100 Bytes lang. Der leere
String ist ein gültiger Scope und der Standard für Single-Tenant-Anwendungen.

## Channel-Callbacks

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

Wie Callbacks zugeordnet und aufgerufen werden:

- Das Muster wird gegen den Channel-Namen ohne das Präfix `private-` bzw. `presence-`
  geprüft. Platzhalter `{name}` passen auf ein durch Punkte getrenntes Segment und werden
  der Reihe nach als Strings übergeben. Implizites Model-Binding gibt es nicht.
- Bei Private-Channels erlaubt jeder wahre Rückgabewert das Abonnement.
- Bei Presence-Channels wird ein zurückgegebenes Array zur `info` des Mitglieds. `true`
  bedeutet leere `info`.
- Ein Private- oder Presence-Channel ohne passenden Callback wird **abgelehnt**.
- Public-Channels brauchen keinen Callback.
- Wirft ein Callback eine Exception, wird das Abonnement mit `forbidden` abgelehnt und die
  Exception an Ihren Exception-Handler übergeben.

### Callbacks laufen im Realtime-Server

Callbacks laufen im Prozess `realtime:serve`, nicht in einem HTTP-Request. Der Server ruft
sie direkt auf, ohne zusätzlichen HTTP-Roundtrip. Daraus folgt:

- `request()`, die Session, Cookies und `auth()->user()` sind nicht verfügbar oder gehören
  zu nichts. Verwenden Sie das Argument `$user`.
- Der Benutzer wird einmal pro Abonnement über den User-Provider des Guards geladen
  (`retrieveById`). Um Benutzer anders zu laden:

  ```php
  Realtime::resolveUserUsing(fn (Ticket $ticket) => User::query()->find($ticket->userId));
  ```

- Channel-Callbacks werden in Service-Providern registriert, daher muss der
  Server-Prozess dieselben Provider laden wie Ihre Web-App. Als Artisan-Befehl tut er das.

### Den Mandanten betreten (`authorizeUsing`)

In einer mandantenfähigen App (etwa ein Schema pro Mandant) müssen das Laden des Benutzers
und die Callbacks im Mandanten des Tickets laufen. Umschließen Sie sie:

```php
use Dskripchenko\Realtime\Auth\Ticket;

Realtime::authorizeUsing(function (Ticket $ticket, Closure $next): mixed {
    return Tenancy::run($ticket->scope, $next);   // enter, call $next(), always leave
});
```

Der Hook:

- erhält das `Ticket` (`userId`, `guard`, `scope`, `issuedAt`);
- muss zurückgeben, was `$next()` zurückgibt, oder `false`, um ohne Aufruf abzulehnen (etwa
  wenn der Mandant des Tickets nicht mehr existiert oder gesperrt ist);
- läuft einmal pro Abonnement;
- muss den vorherigen Kontext auch bei Exceptions wiederherstellen (`try`/`finally`), weil
  der Prozess alle Mandanten bedient.

## Härtungs-Checkliste

- Bevorzugen Sie Private-Channels. Public-Channels sind für jeden angemeldeten Benutzer
  desselben Scopes sichtbar.
- Halten Sie `ticket.ttl` kurz. Tickets stehen im Query-String; sorgen Sie also dafür, dass
  Ihr Proxy für die Realtime-Location keine Query-Strings loggt, oder akzeptieren Sie, dass
  Einmal-Tickets in Logs nach Verwendung oder Ablauf harmlos sind.
- Setzen Sie `allowed_origins`.
- Halten Sie die Presence-`info` minimal. Jedes Mitglied des Channels sieht sie.
- Client-Ereignisse (`client-*`) kommen direkt von anderen Benutzern. Behandeln Sie ihre
  `data` als nicht vertrauenswürdige Eingabe und schalten Sie sie mit
  `client_events => false` ab, wenn Sie sie nicht brauchen.
- Der Entzug von Rechten beendet bestehende Abonnements nicht, denn die Autorisierung wird
  beim Abonnieren geprüft. Um einen Benutzer sofort abzuschneiden, veröffentlichen Sie ein
  Ereignis, das den Client abmelden lässt, oder starten Sie den Server neu. Ein gezieltes
  Trennen einzelner Verbindungen ist nicht Teil von 0.1.
