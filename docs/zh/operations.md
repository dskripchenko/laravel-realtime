# 运维

## 进程模型

每个 `realtime:serve` 进程彼此独立。它是单线程的，`stream_select()` 将其限制在约 1000 个
连接（`FD_SETSIZE`）。扩展方式：

- **单主机多核**：以 `REALTIME_REUSE_PORT=true` 在同一端口启动 N 个进程。这利用 Linux 的
  `SO_REUSEPORT`，由内核把新连接分配给各进程。
- **多主机**：在每台主机上运行进程，并置于负载均衡器之后。所有进程共享一个 Redis/Valkey。

不需要粘性会话。每个进程都能服务任何频道，重放可跨进程工作。

把打开文件数上限（`ulimit -n`、`LimitNOFILE`）调高到连接数之上并留出余量。

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

部署时重启这些进程（`supervisorctl restart realtime:*`）。客户端会重连并重放。

## Docker / docker compose

一个镜像可以承担所有角色，容器扮演哪个角色由命令决定：

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

镜像需要 `ext-redis` 和 `ext-pcntl`（用于优雅关闭），并且 CLI 中必须启用 `pcntl_*` 函数。

## 反向代理

服务器使用普通的 `ws://`。请在其前面终结 TLS，并将某个路径路由到它。

nginx：

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

Caddy：

```text
handle_path /realtime* {
    reverse_proxy 127.0.0.1:6001
}
```

Traefik 和其他代理默认会透传 WebSocket 升级。请确保它们的空闲超时长于心跳（默认 45 秒）。

服务器忽略升级请求的路径，因此 `/realtime` 和 `/` 都可以。除此之外它只响应 `/health`。

## 监控

- **存活 / 就绪**：`GET /health` 返回 200；当 Redis pub/sub 链路中断时返回 503。它还报告
  连接数、频道数、运行时间和内存。
- **日志**：错误输出到 stderr（Redis 故障、presence 更新失败、意外异常）。加上 `-v` 可看到
  Redis 重连等信息级日志。频道回调抛出的异常交给 Laravel 的异常处理器。
- **Redis**：每个服务器的 pub/sub 连接名为 `realtime-<server-id>`
  （`CLIENT LIST TYPE pubsub`）。存活的服务器列在集合 `<prefix>servers` 中。

## Redis 使用与内存

使用的键，均位于 `redis.prefix` 之下：

| 键 | 类型 | 生命周期 |
|-----|------|----------|
| `seq:<scope>/<channel>` | string | `replay.retention`，发布时刷新 |
| `log:<scope>/<channel>` | stream，`MAXLEN ~ replay.size` | `replay.retention`，发布时刷新 |
| `ch:<scope>/<channel>` | pub/sub 频道 | 无 |
| `ticket:<sha256>` | string | `ticket.ttl` |
| `presence:…`、`presence-info:…` | hash | 有成员在线期间 |
| `server:<id>`、`servers`、`server-presence:<id>`、`reap:<id>` | 多种 | 基于心跳 |

每个活跃频道的重放日志大约占用 `replay.size × 平均事件大小`。使用 `MAXLEN ~` 时，Redis
按整个内部节点裁剪，因此日志可能略多于 `replay.size` 条。

## 容量说明

- 每个连接占用几 KB 的 PHP 内存，外加其待发送数据。在 `limits.max_buffer = 4 MiB` 时，
  一个慢客户端在被断开前最多可占用 4 MiB。大规模部署请调低该限制。
- 发布到频道的消息在每个进程中编码为一个帧，并复制到每个本地订阅者的缓冲区。在高扇出速率
  下，单个进程会受限于 CPU。在提高限制之前先增加进程。
- 每次订阅都需要若干 Redis 往返（订阅确认、重放、presence）和一次频道回调，通常意味着一次
  数据库查询。每个连接的 `messages_per_second` 限制可防止单个客户端借此制造负载。
- 以上数字未在 0.1.0 中做过压测。在为生产环境规划容量前，请用自己的流量特征进行测量。

## Redis 升级 / 故障切换

Redis 重启期间，发布方会收到错误，直到它恢复。服务器以退避方式重连 pub/sub 链路，并用重放
日志填补空缺。如果日志丢失（未开启持久化），序号会从 1 重新开始：

- 已连接的客户端收到带有新头部的 `resync`；
- 之后以较旧的 `since` 重连的客户端收到 `gap: true`。

两种情况下，客户端都会重新加载自身状态。
