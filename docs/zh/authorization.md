# 授权与多租户

一个连接能看到什么，由三个步骤决定。

1. **票据**确定用户和 scope。它通过 HTTP 在你正常的会话和租户上下文中签发。
2. **Scope 隔离**：服务器以票据的 scope 作为每个频道的键。连接根本无法访问其他 scope 的
   频道。
3. **频道回调**决定用户能否订阅 private 和 presence 频道。

## 票据

`POST /realtime/ticket` 默认使用 `web` 和 `auth` 中间件，返回：

```json
{"ticket":"9f3c…64 hex chars…","url":"wss://app.example.com/realtime","expires_in":30}
```

- 票据是 32 个随机字节。Redis 以 `sha256(ticket)` 为键存储它，TTL 为 `ticket.ttl`
  （默认 30 秒）。
- 服务器在 WebSocket 升级时通过 `GETDEL` 消费票据，因此每张票据只能打开一个连接。
- 票据携带用户的认证标识、guard（`realtime.ticket.guard`，`null` 表示默认 guard）和
  scope。
- scope 来自 `Realtime::resolveScopeUsing()`，在票据请求处理期间求值。

可以通过 `realtime.routes`（前缀和中间件）修改路由，或关闭它并自行签发票据：

```php
Route::middleware(['api', 'auth:sanctum'])->post('/api/realtime/ticket', function (Request $request) {
    return Realtime::issueTicket($request->user(), 'sanctum', scope: tenant()->id, request: $request);
});
```

## Scope

```php
Realtime::resolveScopeUsing(fn (): string => (string) app(CurrentTenant::class)->id());
```

解析器会在两处使用：

- 签发票据时，用于确定连接的 scope；
- 在未显式指定 scope 的情况下发布（`Realtime::publish()`、广播）时。

这意味着排队的 `ShouldBroadcast` 任务必须在租户上下文中运行——对多租户队列来说这本来就是
必需的。也可以固定 scope：

- 按调用：`Realtime::publish($channel, $event, $data, scope: 'acme')`；
- 按广播连接：`['driver' => 'realtime', 'scope' => 'central']`。

scope 可包含 `[A-Za-z0-9_\-.:]`，长度不超过 100 字节。空字符串是合法的 scope，也是单租户
应用的默认值。

## 频道回调

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

回调的匹配与调用方式：

- 模式与去掉 `private-` 或 `presence-` 前缀后的频道名进行匹配。占位符 `{name}` 匹配一个
  以点分隔的片段，并按顺序以字符串形式传入。不支持隐式模型绑定。
- 对 private 频道，任何真值返回都允许订阅。
- 对 presence 频道，返回的数组成为成员的 `info`；`true` 表示空 `info`。
- 没有匹配回调的 private 或 presence 频道会被**拒绝**。
- public 频道不需要回调。
- 如果回调抛出异常，订阅以 `forbidden` 被拒绝，异常交给你的异常处理器。

### 回调在 realtime 服务器中运行

回调运行在 `realtime:serve` 进程中，而不是 HTTP 请求中。服务器直接调用它们，没有额外的
HTTP 往返。因此：

- `request()`、会话、cookie 和 `auth()->user()` 不可用或不属于任何请求。请使用 `$user`
  参数。
- 每次订阅通过 guard 的用户提供者（`retrieveById`）加载一次用户。如需以其他方式加载：

  ```php
  Realtime::resolveUserUsing(fn (Ticket $ticket) => User::query()->find($ticket->userId));
  ```

- 频道回调在服务提供者中注册，因此服务器进程必须加载与 Web 应用相同的提供者。作为 artisan
  命令，它正是这样做的。

### 进入租户（`authorizeUsing`）

在多租户应用中（例如每个租户一个 schema），用户加载和回调必须在票据所属的租户中运行。
将它们包裹起来：

```php
use Dskripchenko\Realtime\Auth\Ticket;

Realtime::authorizeUsing(function (Ticket $ticket, Closure $next): mixed {
    return Tenancy::run($ticket->scope, $next);   // enter, call $next(), always leave
});
```

该钩子：

- 接收 `Ticket`（`userId`、`guard`、`scope`、`issuedAt`）；
- 必须返回 `$next()` 的返回值，或返回 `false` 以在不调用它的情况下拒绝（例如票据所属租户
  已不存在或已停用）；
- 每次订阅运行一次；
- 即使发生异常也必须恢复之前的上下文（`try`/`finally`），因为该进程服务于所有租户。

## 加固清单

- 优先使用 private 频道。public 频道对同一 scope 中所有已登录用户可见。
- 保持 `ticket.ttl` 较短。票据位于查询字符串中，因此请确保代理不记录 realtime 路径的查询
  字符串，或者接受：一次性票据在使用或过期后出现在日志中是无害的。
- 设置 `allowed_origins`。
- 让 presence `info` 尽量精简，频道的每个成员都能看到它。
- 客户端事件（`client-*`）直接来自其他用户。请将其 `data` 视为不可信输入；如果不需要，
  用 `client_events => false` 关闭。
- 撤销访问权限不会断开已有订阅，因为授权只在订阅时检查。要立即切断某个用户，请发布一个
  让客户端取消订阅的事件，或重启服务器。0.1 不支持按连接踢出。
