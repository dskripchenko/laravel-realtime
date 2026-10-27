# JS-клиент: `@dskripchenko/realtime`

Клиент написан на TypeScript и поставляется как ES-модули с декларациями типов. У него нет
runtime-зависимостей, он работает в браузерах и в Node 22+, где есть глобальный
`WebSocket`. Исходники лежат в [`js/`](../../js).

```bash
pnpm add @dskripchenko/realtime
```

## Подключение

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

### Получение тикета

По умолчанию каждая попытка подключения отправляет `POST ticketUrl`. Запрос:

- использует `credentials: 'same-origin'`, чтобы ушла cookie сессии;
- отправляет `Accept: application/json` и `X-Requested-With`;
- отправляет `X-XSRF-TOKEN` из cookie `XSRF-TOKEN` Laravel.

Через `headers` можно добавить свои заголовки или полностью заменить запрос:

```ts
new RealtimeClient({
  fetchTicket: async () => (await api.post('/api/realtime/ticket')).data, // { ticket, url }
});
```

Если эндпоинт тикетов отвечает 401, 403 или 419, клиент отклоняет `connect()` с
`UnauthorizedError` и останавливается: пользователь вышел, повторы не помогут. Любая
другая ошибка повторяется с нарастающей задержкой.

### Переподключение

Когда соединение рвётся, клиент:

1. ждёт `min(maxDelay, initialDelay × factor^attempt)`, где доля `jitter` этой задержки
   случайна, чтобы тысячи вкладок не переподключались одновременно после деплоя;
2. получает новый тикет и подключается;
3. переподписывает каждый канал с `since = lastSeq`, и сервер проигрывает пропущенное.

`reconnect: false` оставляет клиент закрытым после разрыва, `maxAttempts` прекращает
попытки после заданного числа. `close()` окончателен до следующего вызова `connect()`.
Подписки сохраняются для этого следующего `connect()`.

### Keepalive

После одного интервала heartbeat тишины клиент отправляет `{"type":"ping"}`. Интервал
сообщает сервер в `welcome`, либо он задаётся опцией `heartbeat` в мс. После двух
интервалов тишины клиент считает связь мёртвой и переподключается. Так обнаруживаются
полуоткрытые TCP-соединения, которые браузер иначе заметил бы лишь через минуты.

### Рукопожатие

Сокет, не получивший `welcome` сервера за `connectTimeout` мс (по умолчанию 10 000),
закрывается с кодом 4001 и обрабатывается как разрыв. Ошибка до `welcome` — тоже разрыв:
браузеры следом присылают событие close, а встроенный `WebSocket` в Node держит сокет с
отклонённым upgrade (неверный `Origin`, истёкший тикет) в `CONNECTING`, никогда его не
закрывая.

## Каналы

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

Как ведут себя вызовы каналов:

- Вызывать `subscribe`, `private` или `join` до `connect()` можно. Подписка отправится,
  когда соединение откроется.
- Повторная подписка на то же имя возвращает тот же объект канала.
- События с `seq`, не большим последнего применённого, отбрасываются, поэтому replay и
  живая доставка никогда не дают дублей.

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

`whisper()` возвращает `false`, если клиент не подключён. Клиентские события не
буферизуются и не проигрываются повторно.

## Тестирование и другие среды

Передайте конструктор `WebSocket` (что угодно с `send`, `close`, `readyState` и
обработчиками `on*`) и реализацию `fetch`. Собственные unit-тесты пакета гоняют клиент
через in-memory сокет именно так (`js/test/fake-socket.ts`).

## Сводка API

| Член | Описание |
|--------|-------------|
| `new RealtimeClient(options)` | `ticketUrl`, `fetchTicket`, `url`, `headers`, `credentials`, `reconnect`, `heartbeat`, `connectTimeout`, `WebSocket`, `fetch`, `random` |
| `connect(): Promise<void>` | Открыть (идемпотентно) |
| `close()` | Закрыть окончательно |
| `subscribe(name)` / `private(name)` / `join(name)` | Получить `Channel` / `PresenceChannel` |
| `leave(name)` | Отписаться по имени |
| `on('state' \| 'error' \| 'message', fn)` | События клиента; возвращает функцию отписки |
| `state`, `socketId` | Текущее состояние и id сокета |
| `Channel.on/onAny/onSubscribed/onError/onResync/whisper/unsubscribe` | См. выше |
| `Channel.lastSeq`, `Channel.subscribed` | Позиция в канале и состояние подписки |
| `PresenceChannel.members/onMembers/onJoin/onLeave/refresh` | Presence |

## Пока не входит

Адаптера для компонентов `@dskripchenko/ui` (живые списки, аватары присутствия) в 0.1
нет.
