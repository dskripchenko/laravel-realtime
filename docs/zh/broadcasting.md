# 广播驱动

本包注册了一个 `realtime` 广播驱动。`ShouldBroadcast` 与 `ShouldBroadcastNow` 事件、
`broadcast()` 以及匿名广播都无需修改即可使用。

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

## 客户端收到什么

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

客户端收到：

```json
{"seq":42,"type":"event","channel":"private-board.7","event":"card.moved","data":{"id":5,"column":"done"}}
```

Laravel 事件设置与消息的对应关系：

- `event` 在定义了 `broadcastAs()` 时取其值，否则为完整类名，与任何 Laravel 广播器一致。
- `data` 为 `broadcastWith()`，或事件的公共属性。
- 频道名保留 Laravel 的前缀：`PrivateChannel('x')` 变为 `private-x`，
  `PresenceChannel('x')` 变为 `presence-x`。
- `broadcastOn()` 中的每个频道都有各自的序号。

## toOthers()

Laravel 会把当前请求的 `X-Socket-ID` 头作为 `socket` 放入负载。驱动会将其从负载中移除，
服务器则跳过该连接。请在浏览器中发送这个头：

```ts
axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId ?? '';
realtime.on('state', () => {
  axios.defaults.headers.common['X-Socket-ID'] = realtime.socketId ?? '';
});
```

## Scope

广播事件时，scope 按以下顺序确定：

1. 连接的 `scope` 选项（如已设置）；
2. 否则为 `Realtime::resolveScopeUsing()`；
3. 否则为 `config('realtime.scope')`。

排队广播（`ShouldBroadcast`）在队列 worker 中解析 scope。请确保排队任务会恢复租户上下文。

## 不通过事件发布

```php
use Dskripchenko\Realtime\Facades\Realtime;

$seq = Realtime::publish('private-board.7', 'card.moved', ['id' => 5]);              // current scope
Realtime::publish('news', 'Released', ['version' => '2.0'], scope: 'acme');           // explicit scope
Realtime::publish('private-job.91', 'progress', ['done' => 40, 'total' => 100], except: $socketId);
```

`publish()` 返回事件的序号。频道名或 scope 无效、Redis 不可用时会抛出异常。在广播器内部，
这些错误会变为 `BroadcastException`。

在命令行中同样可用：

```bash
php artisan realtime:publish news Released '{"version":"2.0"}' --scope=acme
```

## 频道授权

该驱动**不**使用 `/broadcasting/auth` 或 `Broadcast::channel()`。授权在 realtime 服务器中
通过 `Realtime::channel()` 完成（参见[授权](authorization.md)）。在此驱动下调用
`Broadcast::auth()` 会抛出 `AccessDeniedHttpException`。

## 典型用法

| 场景 | 频道 | 说明 |
|----------|---------|-------|
| 实时看板 | `private-board.{id}` | 事件带 `seq`；收到 `resync` 时重新加载看板 |
| 通知 | `private-App.Models.User.{id}` | 与 Laravel 的通知频道相同 |
| 谁在编辑 | `presence-page.{id}` | 成员列表加 `client-typing` / `client-cursor` |
| 任务进度 | `private-job.{id}` | 在任务中用 `Realtime::publish()` 发布 |
