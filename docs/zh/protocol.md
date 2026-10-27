# 协议

每条消息都是一个以 WebSocket **文本**消息发送的 JSON 对象。二进制消息会以关闭码 1003
关闭连接。每条消息都有 `type` 字段。

客户端消息可以携带 `id`，即整数或最多 64 个字符的字符串。服务器会把 `id` 复制到
`ack`、`error`、`pong` 或 `presence` 回复中，便于客户端将回复与请求对应。

## 建立连接

```text
GET /?ticket=<ticket> HTTP/1.1
Upgrade: websocket
Connection: Upgrade
Sec-WebSocket-Version: 13
Sec-WebSocket-Key: ...
Origin: https://app.example.com
```

服务器可能以下列 HTTP 状态码拒绝升级：

| 状态码 | 原因 |
|--------|--------|
| 400 | 升级请求格式错误 |
| 401 | 票据缺失、未知、已过期或已被使用 |
| 403 | `Origin` 不在 `realtime.allowed_origins` 中 |
| 426 | `Sec-WebSocket-Version` 不是 13 |
| 431 | 请求头大于 8 KiB |
| 503 | 达到连接上限，或票据存储不可用 |

浏览器看不到这些状态码，因为 `WebSocket` API 只报告关闭码 1006。客户端应在每次尝试前
获取新票据，JS 客户端正是这样做的。

升级成功后，服务器发送欢迎消息：

```json
{"type":"welcome","socket_id":"4161587040080015970.4500350542000801717","heartbeat":25}
```

- `socket_id` 是在 HTTP 请求中作为 `X-Socket-ID` 发送的值，使
  `broadcast(...)->toOthers()` 跳过该连接。
- `heartbeat` 是服务器的 keepalive 间隔，单位为秒。

## 频道

| 名称 | 类型 | 谁可以订阅 |
|------|------|-------------------|
| `news`、`board.42.public` | public | 同一 scope 中的任何连接 |
| `private-board.42` | private | `board.{id}` 的回调返回真值 |
| `presence-page.7` | presence | `page.{id}` 的回调返回真值；返回的数组成为成员的 `info` |

频道名匹配 `[A-Za-z0-9_\-=@,.;:]+`，长度不超过 200 字节。在服务器内部和 Redis 中，每个
频道的键为 `<scope>/<name>`，其中 scope 来自票据。两个租户都订阅 `private-board.1` 时，
使用的是两个不同的频道。

## 客户端 → 服务器

### subscribe

```json
{"type":"subscribe","id":1,"channel":"private-board.42"}
{"type":"subscribe","id":2,"channel":"private-board.42","since":17}
```

`since` 是客户端已应用的最后一个序号。首次订阅时省略。

服务器以 `ack` 回复。presence 频道的 `ack` 中还包含 `members` 列表：

```json
{"type":"ack","id":2,"channel":"private-board.42","seq":19,"gap":false}
{"type":"ack","id":3,"channel":"presence-page.7","seq":0,"gap":false,
 "members":[{"id":7,"info":{"name":"Ann"}}]}
```

`ack` 之后，服务器按从旧到新的顺序发送从 `since + 1` 到当前头部的事件，然后是实时事件。
`ack` 字段含义：

- `seq` 是频道的当前头部。
- `gap: true` 表示错过的事件已无法重放：错过了超过 `realtime.replay.size` 个事件、日志
  已过期，或频道已被重置（`since` > 头部）。此时不会重放任何内容。客户端必须重新加载状态
  （例如通过 HTTP），并从 `seq` 继续。

订阅连接已有的频道会再返回一个 `ack`，不做任何改变。

错误的 `code` 为 `forbidden`（回调拒绝，或 private/presence 频道没有注册回调）、
`bad_channel`、`limit`（超过 `limits.max_subscriptions`）或 `unavailable`（Redis 不可用）：

```json
{"type":"error","id":1,"channel":"private-board.13","code":"forbidden","message":"Subscription denied: forbidden"}
```

### unsubscribe

```json
{"type":"unsubscribe","id":4,"channel":"private-board.42"}
```

回复为 `{"type":"ack","id":4,"channel":"private-board.42"}`。在 presence 频道中，如果这是
该用户的最后一个连接，其他成员会收到 `leave`。

### presence（list）

```json
{"type":"presence","id":5,"channel":"presence-page.7","action":"list"}
```

回复为 `{"type":"presence","id":5,"channel":"presence-page.7","action":"list","members":[...]}`。
客户端不能发送 `join` 或 `leave`，它们由订阅行为产生。

### event（客户端事件）

```json
{"type":"event","id":6,"channel":"presence-page.7","event":"client-typing","data":{"on":true}}
```

客户端事件会投递给所有服务器上该频道的其他订阅者，用于“正在输入”提示、光标等短暂信号。
规则如下：

- 连接必须已订阅该频道。
- 频道必须是 private 或 presence。
- 事件名必须匹配 `client-<name>`。
- 必须启用 `realtime.client_events`。

客户端事件没有序号，也不会被重放。如果提供了 `id`，发送方会收到 `ack`。接收方能看到
发送方的用户 id：

```json
{"seq":null,"type":"event","channel":"presence-page.7","event":"client-typing","data":{"on":true},"user_id":2,"except":"..."}
```

### ping

```json
{"type":"ping","id":"p1"}
```

回复为 `{"type":"pong","id":"p1"}`。浏览器无法发送 WebSocket ping 帧，因此客户端用这条
消息检查链路是否存活。服务器自身也会发送 ping 帧（见下文 Keepalive）。

## 服务器 → 客户端

### event

```json
{"seq":21,"type":"event","channel":"private-board.42","event":"CardMoved","data":{"card":5}}
```

- 频道中每发布一个事件，`seq` 加一。客户端必须忽略 `seq` ≤ 已应用的最后一个序号的事件，
  因为重放与实时投递可能短暂重叠。
- `seq: null` 标记不可重放的消息：presence 通知和客户端事件。
- `except` 是内部字段，即服务器跳过的套接字 id。

### presence 通知

```json
{"seq":null,"type":"presence","channel":"presence-page.7","action":"join","member":{"id":2,"info":{"name":"Bob"}}}
{"seq":null,"type":"presence","channel":"presence-page.7","action":"leave","member":{"id":2,"info":{"name":"Bob"}}}
```

用户获得到该频道的第一个连接时发送 `join`，最后一个连接断开时发送 `leave`。同一用户的
多个标签页算作一个成员。加入的连接本身也会收到自己的 `join`。

### resync

```json
{"type":"resync","channel":"private-board.42","seq":250}
```

当服务器自身到 Redis 的 pub/sub 链路中断时间超过重放缓冲区覆盖的范围时，服务器发送
`resync`。客户端应重新加载状态并从 `seq` 继续。

### error

```json
{"type":"error","id":7,"code":"bad_request","message":"Field \"channel\" is required"}
```

| 代码 | 含义 |
|------|---------|
| `bad_request` | JSON 无效或字段无效 |
| `unknown_type` | 不支持的 `type` |
| `bad_channel` | 频道名无效 |
| `forbidden` | 授权被拒绝，或不允许该客户端事件 |
| `not_subscribed` | 对未订阅的频道执行 `presence`/`event` |
| `limit` | 订阅过多 |
| `unavailable` | Redis 暂时不可用 |
| `internal` | 意外的服务器错误（已记录日志） |

错误从不关闭连接。

## Keepalive

- 服务器在静默 `heartbeat.interval` 秒（默认 25）后发送 ping 帧。如果在
  `heartbeat.timeout` 秒（默认 20）内没有收到任何数据，就以关闭码 4000 关闭连接。
- 客户端在静默一个心跳间隔后发送 `{"type":"ping"}`；静默两个间隔后断开链路并重连。

## 关闭码

| 代码 | 何时 |
|------|------|
| 1000 | 正常关闭（服务器回显客户端的关闭码） |
| 1001 | 服务器正在关闭 |
| 1002 | 协议错误（未掩码的帧、错误的 opcode、RSV 位、错误的续帧、错误的 close 负载） |
| 1003 | 二进制消息 |
| 1007 | 文本消息不是有效的 UTF-8 |
| 1008 | 超出速率限制（`limits.messages_per_second`） |
| 1009 | 帧或消息超过限制 |
| 4000 | Keepalive 超时 |
| 4008 | 慢消费者：等待发送的数据超过 `limits.max_buffer` 字节 |

## 不支持

- 扩展，包括 permessage-deflate。RSV 位必须为零。
- 子协议。`Sec-WebSocket-Protocol` 会被忽略。
