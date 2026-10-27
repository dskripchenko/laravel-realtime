# 服务器

```bash
php artisan realtime:serve [--host=0.0.0.0] [--port=6001] [--server-id=rt-1] [-v]
```

服务器是一个 artisan 命令，因此应用会完整启动。它会加载你的服务提供者、执行你的频道回调，
用户提供者和数据库连接也来自同一个容器。

启动时它输出一行：`Realtime server listening on <host>:<port> (id <id>)`。
使用 `--port=0` 时由操作系统选择空闲端口。使用 `-v` 还会显示信息级日志。

## 工作原理

服务器是围绕 `stream_select()` 的单线程循环。循环监视：

- 监听套接字；
- 每个客户端套接字（用于读取；有待发送数据时也用于写入）；
- 一条承载 pub/sub 消息的原始 RESP Redis 连接。

phpredis 的 `subscribe()` 会阻塞进程，因此无法与客户端套接字共用一个循环。所以 pub/sub
链路由一个小型内置 RESP 客户端负责。其他所有 Redis 调用（发布、重放、票据、presence）
都走 Laravel 的 phpredis 连接，每次只需一次往返。

一次迭代做的事情：

1. 接受新连接，每次迭代最多 100 个。
2. 从就绪的套接字读取：
   - 握手阶段收集 HTTP 头（最多 8 KiB），校验升级请求、消费票据，发送 `101`，然后发送
     `welcome`。
   - 连接打开后，增量解码帧、重组分片消息、应答 ping，并运行协议处理器。
3. 读取 pub/sub 消息并投递给本地订阅者。每条消息只编码一次帧，而不是每个连接一次。
4. 以非阻塞方式写出待发送数据。部分写入时保留缓冲区剩余部分。
5. 每秒运行一次定时器：握手超时、keepalive ping、关闭超时、Redis 中的服务器心跳（每 10 秒）、
   清理死亡服务器（每 15 秒）以及 pub/sub 链路检查。

0.1 **未实现** ext-event 后端。`stream_select()` 受 `FD_SETSIZE` 限制，PHP 构建中通常为
每进程 1024 个描述符。需要更多连接时，请运行多个进程（参见[运维](operations.md)）。

## 顺序与投递保证

- 同一频道的事件按序号顺序到达每个订阅者。序号由 Redis 中的一个 Lua 脚本分配，该脚本同时
  把事件追加到重放日志并发布。
- 每个连接最多投递一次。使用 `since` 重连的客户端，如果错过的事件仍在重放缓冲区中，就能
  收回这些事件；否则会收到 `gap: true`，必须重新加载自身状态。
- 服务器首次订阅某个频道时，会等待 Redis 确认订阅后才读取重放日志。期间发布的内容不会
  丢失，重复投递的内容按序号丢弃。
- 如果服务器到 Redis 的 pub/sub 链路断开，服务器会以退避方式重连（0.5 秒，之后翻倍直到
  30 秒），然后从最后看到的序号重放它持有的每个频道。已超出缓冲区的频道会收到 `resync`
  消息。

## 配置

`config/realtime.php`（用 `php artisan vendor:publish --tag=realtime-config` 发布）：

| 键 | 默认值 | 含义 |
|-----|---------|---------|
| `server.host` / `server.port` | `0.0.0.0` / `6001` | 绑定地址 |
| `server.reuse_port` | `false` | `SO_REUSEPORT`：多个进程共用一个端口（Linux） |
| `public_url` | `null` | 随票据返回的 URL，例如 `wss://app.example.com/realtime` |
| `allowed_origins` | `[]` | Origin 白名单（`https://a.example.com`、`*.example.com`）；为空则允许任意 |
| `redis.connection` | `default` | `database.redis` 中的 phpredis 连接 |
| `redis.prefix` | `realtime:` | 所有键和 pub/sub 频道的前缀 |
| `ticket.ttl` | `30` | 票据有效期，秒 |
| `ticket.guard` | `null` | 票据和用户加载使用的 guard（`null` = 默认 guard） |
| `routes.*` | 启用、`realtime`、`['web','auth']` | 票据路由 `POST /realtime/ticket` |
| `scope` | `''` | 未注册解析器时的回退 scope |
| `replay.size` | `100` | 每个频道为重放保留的事件数 |
| `replay.retention` | `86400` | 空闲频道保留计数器和日志的秒数 |
| `heartbeat.interval` / `heartbeat.timeout` | `25` / `20` | Keepalive，秒 |
| `limits.max_connections` | `1000` | 每进程；超出的连接得到 HTTP 503（见上文关于 `FD_SETSIZE` 的说明） |
| `limits.max_frame_size` / `limits.max_message_size` | `65536` | 字节；超出则以 1009 关闭 |
| `limits.max_subscriptions` | `100` | 每个连接的频道数 |
| `limits.max_buffer` | `4194304` | 每个连接待发送字节数上限，超出则以 4008 关闭 |
| `limits.messages_per_second` | `50` | 每个连接的令牌桶；超出则以 1008 关闭 |
| `limits.handshake_timeout` | `10` | 完成 HTTP 升级的秒数 |
| `client_events` | `true` | 允许在 private 和 presence 频道中使用 `client-*` 事件 |

Redis 连接说明：

- **不**使用连接自身的 `prefix` 选项和序列化器。每条命令都通过 `rawCommand()` 发送，因此
  无论连接选项如何，Web 进程与服务器对键名的理解都一致。
- pub/sub 套接字从同一连接配置读取 `host`、`port`、`username`、`password`、`scheme`
  （`tls`）和 `url`。
- 不支持 Redis Cluster 和 predis。

## 健康检查

服务器端口上的 `GET /health` 返回 JSON：

```json
{"status":"ok","server_id":"rt-1","connections":12,"channels":5,"redis":true,"uptime":3600,"memory":31457280}
```

状态码为 200；当到 Redis 的 pub/sub 链路中断时为 503，并带有 `"status":"degraded"`。
其他所有非升级请求都返回 404。

## 关闭

收到 SIGTERM 或 SIGINT 时（需要 ext-pcntl），服务器会：

1. 向每个客户端发送关闭码 1001；
2. 删除这些客户端的 presence 记录；
3. 最多用一秒时间刷出待发送数据；
4. 在 Redis 中注销自身并退出。

客户端会重连到另一个实例，并从最后的序号开始重放。

## 长驻进程注意事项

- 频道回调和用户提供者在整个进程生命周期内运行在同一进程中。避免无限增长的静态缓存，
  也不要保存请求状态。
- 数据库连接可能在两次订阅之间失效。Laravel 会在“lost connection”错误时重连。如果你的
  数据库会积极关闭空闲连接，请在 `authorizeUsing()` 钩子中调用 `DB::reconnect()`，或定期
  重启服务器（由 supervisor 负责重启）。
- 部署：每次部署后重启服务器以加载新代码，与队列 worker 的做法相同。
