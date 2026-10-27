# Broadcasting-драйвер

Пакет регистрирует broadcasting-драйвер `realtime`. События `ShouldBroadcast` и
`ShouldBroadcastNow`, `broadcast()` и анонимные трансляции работают без изменений.

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

## Что получает клиент

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

Клиент получает:

```json
{"seq":42,"type":"event","channel":"private-board.7","event":"card.moved","data":{"id":5,"column":"done"}}
```

Как настройки события Laravel отображаются на сообщение:

- `event` — это `broadcastAs()`, если метод определён, иначе полное имя класса, как у
  любого broadcaster'а Laravel.
- `data` — это `broadcastWith()` или публичные свойства события.
- Имена каналов сохраняют префиксы Laravel: `PrivateChannel('x')` становится `private-x`,
  а `PresenceChannel('x')` — `presence-x`.
- У каждого канала из `broadcastOn()` свой номер.

## toOthers()

Laravel кладёт заголовок `X-Socket-ID` текущего запроса в payload как `socket`. Драйвер
убирает его из payload, и сервер пропускает это соединение. Отправляйте заголовок из
браузера:

```ts
axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId ?? '';
realtime.on('state', () => {
  axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId ?? '';
});
```

## Scope

При трансляции события scope определяется так:

1. опция `scope` подключения, если задана;
2. иначе `Realtime::resolveScopeUsing()`;
3. иначе `config('realtime.scope')`.

Очередные трансляции (`ShouldBroadcast`) определяют scope внутри воркера очереди.
Убедитесь, что контекст тенанта восстанавливается для очередных задач.

## Публикация без событий

```php
use Dskripchenko\Realtime\Facades\Realtime;

$seq = Realtime::publish('private-board.7', 'card.moved', ['id' => 5]);              // current scope
Realtime::publish('news', 'Released', ['version' => '2.0'], scope: 'acme');           // explicit scope
Realtime::publish('private-job.91', 'progress', ['done' => 40, 'total' => 100], except: $socketId);
```

`publish()` возвращает номер события. Он бросает исключение при некорректных именах
каналов или scope и когда Redis недоступен. Внутри broadcaster'а эти ошибки становятся
`BroadcastException`.

То же доступно из консоли:

```bash
php artisan realtime:publish news Released '{"version":"2.0"}' --scope=acme
```

## Авторизация каналов

Драйвер **не** использует `/broadcasting/auth` и `Broadcast::channel()`. Авторизация
происходит внутри realtime-сервера через `Realtime::channel()` (см.
[авторизацию](authorization.md)). Вызов `Broadcast::auth()` с этим драйвером бросает
`AccessDeniedHttpException`.

## Типичные сценарии

| Сценарий | Канал | Заметки |
|----------|---------|-------|
| Живая доска | `private-board.{id}` | События с `seq`; при `resync` перезагрузите доску |
| Уведомления | `private-App.Models.User.{id}` | Как канал уведомлений Laravel |
| Кто редактирует | `presence-page.{id}` | Участники плюс `client-typing` / `client-cursor` |
| Прогресс задачи | `private-job.{id}` | Публикуйте из задачи через `Realtime::publish()` |
