# JS 客户端：`@dskripchenko/realtime`

客户端使用 TypeScript 编写，以带类型声明的 ES 模块发布。它没有运行时依赖，可在浏览器和
带有全局 `WebSocket` 的 Node 22+ 中运行。源码位于 [`js/`](../../js)。

```bash
pnpm add @dskripchenko/realtime
```

## 连接

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

### 获取票据

默认情况下，每次连接尝试都会发送 `POST ticketUrl`。该请求：

- 使用 `credentials: 'same-origin'`，以便携带会话 cookie；
- 发送 `Accept: application/json` 和 `X-Requested-With`；
- 发送取自 Laravel `XSRF-TOKEN` cookie 的 `X-XSRF-TOKEN`。

用 `headers` 添加自定义请求头，或完全替换该请求：

```ts
new RealtimeClient({
  fetchTicket: async () => (await api.post('/api/realtime/ticket')).data, // { ticket, url }
});
```

如果票据端点返回 401、403 或 419，客户端会以 `UnauthorizedError` 拒绝 `connect()` 并停止：
用户已登出，重试无济于事。其他任何失败都会以退避方式重试。

### 重连

连接断开时，客户端会：

1. 等待 `min(maxDelay, initialDelay × factor^attempt)`，其中 `jitter` 比例的延迟是随机的，
   以免部署后成千上万个标签页同时重连；
2. 获取新票据并连接；
3. 以 `since = lastSeq` 重新订阅每个频道，由服务器重放错过的事件。

设置 `reconnect: false` 可在断开后保持关闭，设置 `maxAttempts` 可在一定次数后放弃。
`close()` 在再次调用 `connect()` 之前是最终的。订阅会保留到下一次 `connect()`。

### Keepalive

静默一个心跳间隔后，客户端发送 `{"type":"ping"}`。心跳间隔由服务器在 `welcome` 中告知，
或用 `heartbeat`（毫秒）设置。静默两个心跳间隔后，客户端认为链路已断并重连。这样可以发现
浏览器否则要几分钟后才会察觉的半开 TCP 连接。

### 握手

在 `connectTimeout` 毫秒（默认 10 000）内未收到服务器 `welcome` 的套接字会以关闭码 4001
关闭并按断开处理。`welcome` 之前发生的错误也算断开：浏览器随后会触发 close 事件，但 Node
内置的 `WebSocket` 会把升级被拒绝（`Origin` 错误、票据过期）的套接字一直留在
`CONNECTING` 状态，永远不会关闭。

## 频道

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

频道调用的行为：

- 在 `connect()` 之前调用 `subscribe`、`private` 或 `join` 没有问题，连接打开后会发送订阅。
- 再次订阅同一名称会返回同一个频道对象。
- `seq` 小于或等于已应用的最后一个序号的事件会被丢弃，因此重放与实时投递永远不会产生重复。

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

客户端未连接时，`whisper()` 返回 `false`。客户端事件不会被缓冲或重放。

## 测试与其他运行环境

传入一个 `WebSocket` 构造函数（任何具有 `send`、`close`、`readyState` 和 `on*` 处理器的
对象）以及一个 `fetch` 实现。本包自身的单元测试正是这样通过内存套接字驱动客户端的
（`js/test/fake-socket.ts`）。

## API 摘要

| 成员 | 说明 |
|--------|-------------|
| `new RealtimeClient(options)` | `ticketUrl`、`fetchTicket`、`url`、`headers`、`credentials`、`reconnect`、`heartbeat`、`connectTimeout`、`WebSocket`、`fetch`、`random` |
| `connect(): Promise<void>` | 打开（幂等） |
| `close()` | 永久关闭 |
| `subscribe(name)` / `private(name)` / `join(name)` | 获取 `Channel` / `PresenceChannel` |
| `leave(name)` | 按名称取消订阅 |
| `on('state' \| 'error' \| 'message', fn)` | 客户端事件；返回取消监听函数 |
| `state`、`socketId` | 当前状态与套接字 id |
| `Channel.on/onAny/onSubscribed/onError/onResync/whisper/unsubscribe` | 见上文 |
| `Channel.lastSeq`、`Channel.subscribed` | 在频道中的位置与订阅状态 |
| `PresenceChannel.members/onMembers/onJoin/onLeave/refresh` | Presence |

## 尚未包含

0.1 中没有用于 `@dskripchenko/ui` 组件（实时列表、在线头像）的适配器。
