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

### Claims und Ablauf

Ein Ticket, das für ein API-Token statt für eine Browser-Session ausgestellt wird, muss
meist mehr tragen als den Benutzer: was das Token darf und wann es ungültig wird.
`issueTicket()` nimmt beides an:

```php
Realtime::issueTicket(
    Authenticatable $user,
    ?string $guard = null,
    ?string $scope = null,
    ?Request $request = null,
    array $claims = [],          // array<string, mixed>, JSON-tauglich, von der App definiert
    ?int $expiresAt = null,      // Unix-Sekunden; dann endet die Verbindung
): IssuedTicket
```

```php
$token = $request->user()->currentAccessToken();

return Realtime::issueTicket($request->user(), 'sanctum', request: $request,
    claims: ['token' => $token->id, 'abilities' => $token->abilities],
    expiresAt: $token->expires_at?->getTimestamp(),
);
```

- **Claims** reisen mit dem Ticket und bleiben als `Ticket::$claims` an der Verbindung.
  Das Paket interpretiert sie nicht. Nutzen Sie sie in `authorizeUsing()` (siehe unten),
  um einzuschränken, was die Verbindung abonnieren darf, und in `disconnect()`, um die
  Verbindung wiederzufinden. Claim-Namen sind Strings; Werte müssen
  `json_encode()`/`json_decode()` unverändert überstehen (Skalare, `null`, Arrays; keine
  Objekte). Namen wie `"7"` sind nicht erlaubt, weil PHP sie zu Integer-Schlüsseln macht.
- **Ablauf** (`expiresAt`, `Ticket::$expiresAt`) ist der Zeitpunkt, an dem die Verbindung
  enden muss, nicht die TTL des Tickets selbst (`ticket.ttl` begrenzt weiterhin, wie lange
  das Ticket eingelöst werden kann). Er muss in der Zukunft liegen, sonst wirft
  `issueTicket()` eine `InvalidArgumentException`. Ein bereits abgelaufenes Ticket lehnt
  der Server beim Upgrade mit 401 ab, offene Verbindungen prüft er einmal pro Sekunde: Ist
  die Zeit gekommen, erhält der Client
  `{"type":"error","code":"expired","message":"Ticket expired"}` und einen Close mit Code
  1008. Abonnements enden wie bei jedem Close (Presence-`leave` eingeschlossen).
  `Ticket::expired(?int $now = null)` sagt, ob ein Ticket abgelaufen ist.
- Von einer älteren Version gespeicherte Tickets (ohne Claims und Ablauf) werden weiterhin
  akzeptiert.

### Trennen nach Claims (`disconnect`)

Wird das Token hinter einer Verbindung widerrufen, schließen Sie alle damit geöffneten
Verbindungen:

```php
Realtime::disconnect(['token' => $token->id]);                  // aktueller Scope
Realtime::disconnect(['token' => $token->id], scope: 'acme');   // expliziter Scope
```

```php
Realtime::disconnect(array $claims, ?string $scope = null): void
```

- Jeder Server schließt seine offenen Verbindungen im Scope (standardmäßig der aktuelle,
  aufgelöst wie bei `publish()`), deren Ticket-Claims **alle** angegebenen Paare enthalten,
  strikt verglichen (`===`): `['token' => 5]` passt nicht zu einem Ticket mit
  `['token' => '5']`.
- Die Clients erhalten `{"type":"error","code":"revoked","message":"Connection revoked"}`
  und einen Close mit Code 1008. Abonnements enden wie bei jedem Close.
- Leere `$claims` werfen eine `InvalidArgumentException`: Der Aufruf kann nie versehentlich
  alle Verbindungen schließen.
- Die Anfrage läuft über einen reservierten Steuerkanal des Brokers
  (`RealtimeManager::CONTROL_KEY`), den jeder Server abonniert und den Clients weder
  abonnieren noch empfangen können. Sie wird nicht für Replay gespeichert: Nur die in
  diesem Moment offenen Verbindungen werden geschlossen. Stellen Sie zuerst keine Tickets
  mehr für das Token aus und rufen Sie dann `disconnect()` auf.

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

- erhält das `Ticket` (`userId`, `guard`, `scope`, `issuedAt`, `claims`, `expiresAt`) und
  kann daher auch nach Claims ablehnen oder sie den Channel-Callbacks für die Dauer von
  `$next()` bereitstellen;
- muss zurückgeben, was `$next()` zurückgibt, oder `false`, um ohne Aufruf abzulehnen (etwa
  wenn der Mandant des Tickets nicht mehr existiert oder gesperrt ist);
- läuft einmal pro Abonnement;
- muss den vorherigen Kontext auch bei Exceptions wiederherstellen (`try`/`finally`), weil
  der Prozess alle Mandanten bedient.

## Rechte entziehen (`reauthorize`)

Die Autorisierung wird beim Abonnieren geprüft. Ändern sich die Rechte später (eine Rolle
wird entzogen, ein Dokument für jemanden gesperrt), lassen Sie die Server den Channel erneut
prüfen:

```php
// nachdem die Änderung gespeichert ist
Realtime::reauthorize('private-board.42');                 // aktueller Scope
Realtime::reauthorize('presence-page.7', scope: 'acme');   // expliziter Scope
```

Jeder Server ruft den Channel-Callback für jedes seiner Abonnements dieses Channels erneut
auf, wie beim Abonnieren über `authorizeUsing()`. Abonnements, die weiterhin erlaubt sind,
bleiben unverändert. Die übrigen werden so entfernt, als hätte der Client sich abgemeldet:
In einem Presence-Channel erhalten die anderen Mitglieder `leave`. Der betroffene Client
erhält einen Fehler und keine weiteren Ereignisse:

```json
{"type":"error","channel":"private-board.42","code":"revoked","message":"Subscription revoked: forbidden"}
```

Ein Callback, der eine Exception wirft, gilt als Ablehnung (`Subscription revoked: error`).

- `reauthorize()` veröffentlicht ein gewöhnliches Ereignis namens `realtime:reauthorize`
  (`RealtimeManager::REAUTHORIZE`) und gibt dessen Sequenznummer zurück. Der Server
  verarbeitet es selbst und leitet es nie weiter, daher sehen Clients eine übersprungene
  `seq`. Das Präfix `realtime:` ist reserviert.
- Weder Redis noch das Protokoll ändern sich, der Aufruf ist also mit jeder Serverversion
  sicher. Ein älterer Server leitet das Ereignis wie jedes andere weiter; der JS-Client
  ignoriert es.

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
- Der Entzug von Rechten beendet bestehende Abonnements nicht von selbst, denn die
  Autorisierung wird beim Abonnieren geprüft. Rufen Sie nach der Änderung
  `Realtime::reauthorize($channel)` auf, damit die Server bestehende Abonnements erneut
  prüfen (siehe oben).
