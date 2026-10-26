# Protocol

Each message is a JSON object sent as a WebSocket **text** message. A binary message
closes the connection with code 1003. Every message has a `type` field.

A client message may carry an `id`, which is an integer or a string of up to 64
characters. The server copies the `id` into the `ack`, `error`, `pong` or `presence`
reply, so the client can match replies to requests.

## Connecting

```text
GET /?ticket=<ticket> HTTP/1.1
Upgrade: websocket
Connection: Upgrade
Sec-WebSocket-Version: 13
Sec-WebSocket-Key: ...
Origin: https://app.example.com
```

The server may reject the upgrade with one of these HTTP statuses:

| Status | Reason |
|--------|--------|
| 400 | Malformed upgrade request |
| 401 | Missing, unknown, expired or already used ticket |
| 403 | `Origin` not in `realtime.allowed_origins` |
| 426 | `Sec-WebSocket-Version` is not 13 |
| 431 | Request head larger than 8 KiB |
| 503 | Connection limit reached, or the ticket store is unavailable |

A browser cannot see these statuses, because the `WebSocket` API only reports close code
1006. Clients should fetch a new ticket before each attempt, which is what the JS client
does.

When the upgrade succeeds, the server sends a welcome message:

```json
{"type":"welcome","socket_id":"4161587040080015970.4500350542000801717","heartbeat":25}
```

- `socket_id` is the value to send as `X-Socket-ID` on HTTP requests, so that
  `broadcast(...)->toOthers()` skips this connection.
- `heartbeat` is the server's keepalive interval in seconds.

## Channels

| Name | Type | Who may subscribe |
|------|------|-------------------|
| `news`, `board.42.public` | public | any connection in the same scope |
| `private-board.42` | private | the callback for `board.{id}` returns truthy |
| `presence-page.7` | presence | the callback for `page.{id}` returns truthy; an array return value becomes the member's `info` |

A channel name matches `[A-Za-z0-9_\-=@,.;:]+` and is at most 200 bytes long. Inside the
server and in Redis, every channel is keyed as `<scope>/<name>`, where the scope comes
from the ticket. Two tenants that both subscribe to `private-board.1` use two different
channels.

## Client → server

### subscribe

```json
{"type":"subscribe","id":1,"channel":"private-board.42"}
{"type":"subscribe","id":2,"channel":"private-board.42","since":17}
```

`since` is the last sequence number the client has applied. Omit it on the first
subscription.

The server replies with an `ack`. Presence channels also get a `members` list in the
`ack`:

```json
{"type":"ack","id":2,"channel":"private-board.42","seq":19,"gap":false}
{"type":"ack","id":3,"channel":"presence-page.7","seq":0,"gap":false,
 "members":[{"id":7,"info":{"name":"Ann"}}]}
```

After the `ack`, the server sends the events from `since + 1` up to the current head, oldest
first. Then live events follow. The `ack` fields mean:

- `seq` is the current head of the channel.
- `gap: true` means the missed events can no longer be replayed. Either more than
  `realtime.replay.size` events were missed, the log expired, or the channel was reset
  (`since` > head). In that case nothing is replayed. The client must reload its state
  (for example over HTTP) and continue from `seq`.

Subscribing to a channel the connection already has returns another `ack` and changes
nothing.

Errors use `code` `forbidden` (the callback denied access, or no callback is registered for
a private or presence channel), `bad_channel`, `limit` (more than
`limits.max_subscriptions`) or `unavailable` (Redis is down):

```json
{"type":"error","id":1,"channel":"private-board.13","code":"forbidden","message":"Subscription denied: forbidden"}
```

### unsubscribe

```json
{"type":"unsubscribe","id":4,"channel":"private-board.42"}
```

The reply is `{"type":"ack","id":4,"channel":"private-board.42"}`. On a presence channel,
the other members receive `leave` when this was the user's last connection.

### presence (list)

```json
{"type":"presence","id":5,"channel":"presence-page.7","action":"list"}
```

The reply is `{"type":"presence","id":5,"channel":"presence-page.7","action":"list","members":[...]}`.
Clients cannot send `join` or `leave`; those follow from subscribing.

### event (client events)

```json
{"type":"event","id":6,"channel":"presence-page.7","event":"client-typing","data":{"on":true}}
```

Client events are delivered to the other subscribers of the channel on every server. They
are meant for typing indicators, cursors and similar short-lived signals. The rules:

- The connection must already be subscribed to the channel.
- The channel must be private or presence.
- The event name must match `client-<name>`.
- `realtime.client_events` must be enabled.

Client events have no sequence number and are not replayed. If `id` is given, the sender
gets an `ack`. Receivers see the sender's user id:

```json
{"seq":null,"type":"event","channel":"presence-page.7","event":"client-typing","data":{"on":true},"user_id":2,"except":"..."}
```

### ping

```json
{"type":"ping","id":"p1"}
```

The reply is `{"type":"pong","id":"p1"}`. Browsers cannot send WebSocket ping frames, so
this message is how a client checks that the link is alive. The server also sends ping
frames itself (see Keepalive below).

## Server → client

### event

```json
{"seq":21,"type":"event","channel":"private-board.42","event":"CardMoved","data":{"card":5}}
```

- `seq` increases by one per published event in the channel. Clients must ignore an event
  whose `seq` is ≤ the last one they applied, because replay and live delivery can overlap
  for a moment.
- `seq: null` marks messages that are not replayable: presence notices and client events.
- `except` is internal. It is the socket id the server skipped.

### presence notices

```json
{"seq":null,"type":"presence","channel":"presence-page.7","action":"join","member":{"id":2,"info":{"name":"Bob"}}}
{"seq":null,"type":"presence","channel":"presence-page.7","action":"leave","member":{"id":2,"info":{"name":"Bob"}}}
```

`join` is sent when a user gets their first connection to the channel. `leave` is sent
when their last connection goes away. Several tabs of one user count as one member. The
joining connection also receives its own `join`.

### resync

```json
{"type":"resync","channel":"private-board.42","seq":250}
```

The server sends `resync` when its own Redis pub/sub link was down longer than the replay
buffer covers. The client should reload its state and continue from `seq`.

### error

```json
{"type":"error","id":7,"code":"bad_request","message":"Field \"channel\" is required"}
```

| Code | Meaning |
|------|---------|
| `bad_request` | Invalid JSON or invalid fields |
| `unknown_type` | Unsupported `type` |
| `bad_channel` | Invalid channel name |
| `forbidden` | Authorization denied, or client event not allowed |
| `not_subscribed` | `presence`/`event` on a channel the connection has not subscribed to |
| `limit` | Too many subscriptions |
| `unavailable` | Redis temporarily unavailable |
| `internal` | Unexpected server error (logged) |

Errors never close the connection.

## Keepalive

- The server sends a ping frame after `heartbeat.interval` seconds of silence (25 by
  default). It closes the connection with code 4000 if nothing arrives within
  `heartbeat.timeout` seconds (20 by default).
- Clients send `{"type":"ping"}` after one heartbeat interval of silence. They drop the
  link and reconnect after two intervals.

## Close codes

| Code | When |
|------|------|
| 1000 | Normal close (the server echoes the client's code) |
| 1001 | Server shutting down |
| 1002 | Protocol error (unmasked frame, bad opcode, RSV bits, bad continuation, bad close payload) |
| 1003 | Binary message |
| 1007 | Text message is not valid UTF-8 |
| 1008 | Rate limit (`limits.messages_per_second`) exceeded |
| 1009 | Frame or message larger than the limits |
| 4000 | Keepalive timeout |
| 4008 | Slow consumer: more than `limits.max_buffer` bytes are waiting to be sent |

## Not supported

- Extensions, including permessage-deflate. RSV bits must be zero.
- Subprotocols. `Sec-WebSocket-Protocol` is ignored.
