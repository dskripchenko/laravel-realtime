# Эксплуатация

## Модель процессов

Каждый процесс `realtime:serve` независим. Он однопоточный, и `stream_select()`
ограничивает его примерно 1000 соединений (`FD_SETSIZE`). Для масштабирования:

- **Один хост, несколько ядер**: запустите N процессов на одном порту с
  `REALTIME_REUSE_PORT=true`. Используется Linux `SO_REUSEPORT`, и ядро распределяет
  новые соединения по процессам.
- **Несколько хостов**: запустите процессы на каждом хосте за балансировщиком. Все
  процессы используют один общий Redis/Valkey.

Липкие сессии не нужны. Любой процесс может обслуживать любой канал, и replay работает
между процессами.

Поднимите лимит открытых файлов (`ulimit -n`, `LimitNOFILE`) выше числа соединений с
запасом.

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

Перезапускайте процессы при деплое (`supervisorctl restart realtime:*`). Клиенты
переподключатся и проиграют пропущенное.

## Docker / docker compose

Один образ может выполнять любую роль; какую именно играет контейнер, решает команда:

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

Образу нужны `ext-redis` и `ext-pcntl` (для корректной остановки), а функции `pcntl_*`
должны быть разрешены для CLI.

## Обратный прокси

Сервер говорит на обычном `ws://`. Терминируйте TLS перед ним и направьте на него путь.

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

Traefik и другие прокси по умолчанию пропускают WebSocket upgrade. Убедитесь, что их
тайм-аут простоя больше heartbeat (по умолчанию 45 с).

Сервер игнорирует путь запроса upgrade, поэтому работают и `/realtime`, и `/`. Кроме него
сервер отвечает только на `/health`.

## Мониторинг

- **Liveness / readiness**: `GET /health` возвращает 200 или 503, когда канал pub/sub к
  Redis недоступен. Он же сообщает число соединений, каналов, uptime и память.
- **Логи**: ошибки идут в stderr (недоступность Redis, неудачные обновления presence,
  непредвиденные исключения). Добавьте `-v`, чтобы видеть информационные строки, например
  переподключения к Redis. Исключения из колбэков каналов уходят в обработчик исключений
  Laravel.
- **Redis**: pub/sub-соединение каждого сервера называется `realtime-<server-id>`
  (`CLIENT LIST TYPE pubsub`). Живые серверы перечислены в множестве `<prefix>servers`.

## Использование Redis и память

Используемые ключи, все под `redis.prefix`:

| Ключ | Тип | Время жизни |
|-----|------|----------|
| `seq:<scope>/<channel>` | string | `replay.retention`, продлевается при публикации |
| `log:<scope>/<channel>` | stream, `MAXLEN ~ replay.size` | `replay.retention`, продлевается при публикации |
| `ch:<scope>/<channel>` | канал pub/sub | нет |
| `ticket:<sha256>` | string | `ticket.ttl` |
| `presence:…`, `presence-info:…` | hash | пока есть участники |
| `server:<id>`, `servers`, `server-presence:<id>`, `reap:<id>` | разные | по heartbeat |

Журнал replay стоит примерно `replay.size × средний размер события` на активный канал. С
`MAXLEN ~` Redis обрезает целыми внутренними узлами, поэтому журнал может хранить немного
больше `replay.size` записей.

## Заметки о ёмкости

- Каждое соединение занимает несколько КБ памяти PHP плюс неотправленный вывод. При
  `limits.max_buffer = 4 MiB` медленный клиент может удерживать до 4 МиБ, прежде чем его
  отключат. Для больших парков снижайте лимит.
- Опубликованное в канал сообщение кодируется в один фрейм на процесс и копируется в
  буфер каждого локального подписчика. При высокой интенсивности рассылки один процесс
  упирается в CPU. Добавляйте процессы, прежде чем поднимать лимиты.
- Каждая подписка стоит нескольких обращений к Redis (подтверждение подписки, replay,
  presence) и одного колбэка канала, что обычно означает запрос к базе. Лимит
  `messages_per_second` на соединение не даёт одному клиенту превратить это в нагрузку.
- Приведённые цифры для 0.1.0 не проверялись нагрузочным тестированием. Прежде чем
  рассчитывать прод, измерьте на своём профиле трафика.

## Обновление Redis / отказ

Если Redis перезапускается, публикующие получают ошибки, пока он не вернётся. Серверы
переподключают свой канал pub/sub с нарастающей задержкой и заполняют пропуски из журнала
replay. Если журнал потерян (нет персистентности), номера начинаются снова с 1:

- подключённые клиенты получают `resync` с новой головой;
- клиенты, переподключившиеся позже со старым `since`, получают `gap: true`.

В обоих случаях клиент перезагружает своё состояние.
