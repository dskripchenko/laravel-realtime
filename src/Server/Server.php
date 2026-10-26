<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Server;

use Closure;
use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Broker\Broker;
use Dskripchenko\Realtime\Broker\ReplayResult;
use Dskripchenko\Realtime\Broker\RespSubscriber;
use Dskripchenko\Realtime\Channels\ChannelAuthorizer;
use Dskripchenko\Realtime\Channels\ChannelName;
use Dskripchenko\Realtime\Channels\ChannelType;
use Dskripchenko\Realtime\Protocol\ClientMessage;
use Dskripchenko\Realtime\Protocol\ProtocolException;
use Dskripchenko\Realtime\Protocol\ServerMessage;
use Dskripchenko\Realtime\WebSocket\CloseCode;
use Dskripchenko\Realtime\WebSocket\Frame;
use Dskripchenko\Realtime\WebSocket\FrameCodec;
use Dskripchenko\Realtime\WebSocket\Handshake;
use Dskripchenko\Realtime\WebSocket\HandshakeException;
use Dskripchenko\Realtime\WebSocket\HttpRequest;
use Dskripchenko\Realtime\WebSocket\Opcode;
use Dskripchenko\Realtime\WebSocket\WebSocketException;
use RuntimeException;
use Throwable;

/**
 * Single-threaded event loop over stream_select(): client sockets plus one Redis pub/sub socket.
 *
 * Nothing here blocks for long except short Redis commands (replay, presence, tickets),
 * which are bounded by the Redis round-trip time.
 */
final class Server
{
    private const READ_CHUNK = 65536;

    private const CLOSE_GRACE = 2.0;

    /** @var resource|null */
    private $listener = null;

    /** @var array<int, Connection> keyed by resource id */
    private array $connections = [];

    /** @var array<string, array<int, Connection>> scoped channel key => subscribed local connections */
    private array $channels = [];

    /** @var array<string, int> highest sequence number seen per channel key, for gap filling after Redis reconnects */
    private array $channelSeq = [];

    private bool $running = false;

    private float $startedAt;

    private float $nextMaintenance = 0.0;

    private float $nextHeartbeat = 0.0;

    private float $nextReap = 0.0;

    private float $nextSubscriberRetry = 0.0;

    private float $subscriberBackoff = 0.5;

    private float $subscriberLastData = 0.0;

    private float $nextSubscriberPing = 0.0;

    /** @var Closure(string, string): void */
    private Closure $logger;

    /**
     * @param  (Closure(string, string): void)|null  $logger  receives (level, message)
     */
    public function __construct(
        private readonly ServerOptions $options,
        private readonly Broker $broker,
        private readonly RespSubscriber $subscriber,
        private readonly ChannelAuthorizer $authorizer,
        ?Closure $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $level, string $message): void {};
        $this->startedAt = microtime(true);
    }

    /**
     * Binds the listening socket and returns the bound "host:port" (useful with port 0).
     */
    public function listen(): string
    {
        $context = stream_context_create(['socket' => [
            'backlog' => 511,
            'so_reuseport' => $this->options->reusePort,
        ]]);
        $address = 'tcp://'.$this->options->host.':'.$this->options->port;
        $listener = @stream_socket_server($address, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($listener === false) {
            throw new RuntimeException("Cannot listen on {$address}: {$error}");
        }
        stream_set_blocking($listener, false);
        $this->listener = $listener;

        $this->connectSubscriber();

        return (string) stream_socket_get_name($listener, false);
    }

    public function run(): void
    {
        if ($this->listener === null) {
            $this->listen();
        }
        $this->running = true;
        while ($this->running) {
            $this->tick();
        }
        $this->shutdown();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * One loop iteration: wait for I/O, process it, run timers.
     */
    public function tick(float $maxWait = 1.0): void
    {
        $listener = $this->listener ?? throw new RuntimeException('Server is not listening');
        $read = [$listener];
        $write = [];
        foreach ($this->connections as $connection) {
            $read[] = $connection->stream;
            if ($connection->outbound !== '') {
                $write[] = $connection->stream;
            }
        }
        $subscriberStream = $this->subscriber->stream();
        if ($subscriberStream !== null) {
            $read[] = $subscriberStream;
        }

        $wait = $this->subscriber->hasQueued() ? 0.0 : $maxWait;
        $except = null;
        $seconds = (int) $wait;
        $ready = @stream_select($read, $write, $except, $seconds, (int) (($wait - $seconds) * 1_000_000));

        if ($ready !== false && $ready > 0) {
            foreach ($read as $stream) {
                if ($stream === $listener) {
                    $this->accept();
                } elseif ($stream === $subscriberStream) {
                    $this->readSubscriber();
                } elseif (isset($this->connections[(int) $stream])) {
                    $this->readConnection($this->connections[(int) $stream]);
                }
            }
        }
        if ($this->subscriber->hasQueued()) {
            $this->readSubscriber();
        }

        foreach ($this->connections as $connection) {
            if ($connection->outbound !== '') {
                $this->flush($connection);
            }
        }

        $now = microtime(true);
        if ($now >= $this->nextMaintenance) {
            $this->nextMaintenance = $now + 1.0;
            $this->maintenance($now);
        }
    }

    private function accept(): void
    {
        $listener = $this->listener ?? throw new RuntimeException('Server is not listening');
        for ($i = 0; $i < 100; $i++) {
            $stream = @stream_socket_accept($listener, 0, $peer);
            if ($stream === false) {
                return;
            }
            stream_set_blocking($stream, false);
            if (count($this->connections) >= $this->options->maxConnections) {
                @fwrite($stream, Handshake::httpResponse(503, 'Too many connections'));
                @fclose($stream);

                continue;
            }
            $connection = new Connection($stream, is_string($peer) ? $peer : '', microtime(true),
                $this->options->maxFrameSize, $this->options->maxMessageSize, $this->options->messagesPerSecond);
            $this->connections[(int) $stream] = $connection;
        }
    }

    private function readConnection(Connection $connection): void
    {
        $data = @fread($connection->stream, self::READ_CHUNK);
        if ($data === false || ($data === '' && feof($connection->stream))) {
            $this->drop($connection);

            return;
        }
        if ($data === '') {
            return;
        }
        $connection->lastActivity = microtime(true);
        $connection->pingSentAt = null;

        if ($connection->state === ConnectionState::Handshake) {
            $connection->inbound .= $data;
            $this->handshake($connection);

            return;
        }

        $this->processFrames($connection, $data);
    }

    private function processFrames(Connection $connection, string $data): void
    {
        try {
            foreach ($connection->parser->push($data) as $frame) {
                $message = $connection->assembler->add($frame);
                if ($message !== null) {
                    $this->handleFrame($connection, $message);
                }
                if (! isset($this->connections[(int) $connection->stream])) {
                    return;
                }
            }
        } catch (WebSocketException $e) {
            $this->close($connection, $e->closeCode, $e->getMessage());
        }
    }

    private function handshake(Connection $connection): void
    {
        $end = strpos($connection->inbound, "\r\n\r\n");
        if ($end === false) {
            if (strlen($connection->inbound) > Handshake::MAX_HEAD_SIZE) {
                $this->reject($connection, 431, 'Request head too large');
            }

            return;
        }

        $request = HttpRequest::parse(substr($connection->inbound, 0, $end));
        if ($request === null) {
            $this->reject($connection, 400, 'Malformed request');

            return;
        }

        if (! Handshake::isUpgrade($request)) {
            if ($request->method === 'GET' && $request->path === '/health') {
                $this->health($connection);
            } else {
                $this->reject($connection, 404, 'Not found');
            }

            return;
        }

        try {
            $key = Handshake::validate($request, $this->options->allowedOrigins);
            $ticket = $this->consumeTicket($request->query['ticket'] ?? '');
        } catch (HandshakeException $e) {
            $this->reject($connection, $e->status, $e->getMessage(), $e->headers);

            return;
        }

        $rest = substr($connection->inbound, $end + 4);
        $connection->ticket = $ticket;
        $connection->inbound = '';
        $connection->state = ConnectionState::Open;
        $connection->outbound .= Handshake::response($key);
        $this->sendText($connection, ServerMessage::welcome($connection->id, (int) $this->options->pingInterval));
        if ($rest !== '') {
            // Clients may pipeline their first frames right behind the upgrade request.
            $this->processFrames($connection, $rest);
        }
    }

    private function consumeTicket(string $token): Ticket
    {
        if ($token === '' || strlen($token) > 256) {
            throw new HandshakeException(401, 'Missing ticket');
        }
        try {
            $ticket = $this->broker->consumeTicket($token);
        } catch (Throwable $e) {
            $this->log('error', 'Ticket lookup failed: '.$e->getMessage());
            throw new HandshakeException(503, 'Ticket store unavailable');
        }
        if ($ticket === null || ! ChannelName::isValidScope($ticket->scope)) {
            throw new HandshakeException(401, 'Invalid or expired ticket');
        }

        return $ticket;
    }

    private function health(Connection $connection): void
    {
        $healthy = $this->subscriber->isConnected();
        $body = ServerMessage::encode([
            'status' => $healthy ? 'ok' : 'degraded',
            'server_id' => $this->options->serverId,
            'connections' => count($this->connections) - 1,
            'channels' => count($this->channels),
            'redis' => $healthy,
            'uptime' => (int) (microtime(true) - $this->startedAt),
            'memory' => memory_get_usage(true),
        ]);
        $connection->outbound .= Handshake::httpResponse($healthy ? 200 : 503, $body, 'application/json');
        $connection->closeAfterFlush = true;
        $connection->state = ConnectionState::Closing;
        $connection->closeDeadline = microtime(true) + self::CLOSE_GRACE;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function reject(Connection $connection, int $status, string $message, array $headers = []): void
    {
        $connection->outbound .= Handshake::httpResponse($status, $message, 'text/plain', $headers);
        $connection->closeAfterFlush = true;
        $connection->state = ConnectionState::Closing;
        $connection->closeDeadline = microtime(true) + self::CLOSE_GRACE;
    }

    private function handleFrame(Connection $connection, Frame $frame): void
    {
        if ($connection->state === ConnectionState::Closing) {
            // After our close frame only the peer's close matters.
            if ($frame->opcode === Opcode::Close) {
                $this->drop($connection);
            }

            return;
        }

        switch ($frame->opcode) {
            case Opcode::Ping:
                $this->send($connection, FrameCodec::encode(Opcode::Pong, $frame->payload));

                return;
            case Opcode::Pong:
                return;
            case Opcode::Close:
                [$code] = $frame->closeStatus();
                $this->cleanup($connection);
                $reply = $code === CloseCode::NO_STATUS ? CloseCode::NORMAL : $code;
                $connection->outbound .= FrameCodec::close($reply);
                $connection->state = ConnectionState::Closing;
                $connection->closeAfterFlush = true;
                $connection->closeDeadline = microtime(true) + self::CLOSE_GRACE;

                return;
            case Opcode::Binary:
                $this->close($connection, CloseCode::UNSUPPORTED_DATA, 'Binary messages are not supported');

                return;
            default:
                $this->handleMessage($connection, $frame->payload);
        }
    }

    private function handleMessage(Connection $connection, string $payload): void
    {
        if (! $connection->allowMessage(microtime(true))) {
            $this->close($connection, CloseCode::POLICY_VIOLATION, 'Rate limit exceeded');

            return;
        }

        try {
            $message = ClientMessage::parse($payload);
            match ($message->type) {
                'ping' => $this->sendText($connection, ServerMessage::pong($message->id)),
                'subscribe' => $this->subscribe($connection, $message),
                'unsubscribe' => $this->unsubscribe($connection, $message),
                'presence' => $this->presence($connection, $message),
                'event' => $this->clientEvent($connection, $message),
                default => throw new ProtocolException('unknown_type', 'Unknown message type', $message->id),
            };
        } catch (ProtocolException $e) {
            $this->sendText($connection, ServerMessage::error($e->requestId, $e->errorCode, $e->getMessage()));
        } catch (Throwable $e) {
            $this->log('error', 'Message handling failed: '.$e->getMessage());
            $this->sendText($connection, ServerMessage::error(null, 'internal', 'Internal server error'));
        }
    }

    private function subscribe(Connection $connection, ClientMessage $message): void
    {
        $ticket = $connection->ticket ?? throw new ProtocolException('unauthorized', 'Not authenticated');
        $name = (string) $message->channel;
        if (! ChannelName::isValid($name)) {
            throw new ProtocolException('bad_channel', 'Invalid channel name', $message->id);
        }
        $key = ChannelName::key($ticket->scope, $name);

        if (isset($connection->subscriptions[$key])) {
            $existing = $connection->subscriptions[$key];
            $this->sendText($connection, ServerMessage::ack($message->id, $name, ['seq' => $existing->lastSeq, 'gap' => false]));

            return;
        }
        if (count($connection->subscriptions) >= $this->options->maxSubscriptions) {
            throw new ProtocolException('limit', 'Too many subscriptions', $message->id);
        }

        $type = ChannelName::type($name);
        $result = $this->authorizer->authorize($ticket, $name);
        if (! $result->allowed) {
            $this->sendText($connection, ServerMessage::error($message->id, 'forbidden', 'Subscription denied: '.$result->reason, $name));

            return;
        }

        try {
            $this->attach($connection, $key);
            $replay = $message->since !== null
                ? $this->broker->replay($key, $message->since, $this->options->replayLimit)
                : new ReplayResult($this->broker->head($key), false);
        } catch (Throwable $e) {
            $this->detach($connection, $key);
            $this->log('error', "Subscribe to {$key} failed: ".$e->getMessage());
            throw new ProtocolException('unavailable', 'Channel temporarily unavailable', $message->id);
        }

        $lastSeq = $message->since === null || $replay->gap ? $replay->head : $message->since;
        $subscription = new Subscription($name, $key, $lastSeq, $result->member);
        $connection->subscriptions[$key] = $subscription;
        $this->channelSeq[$key] = max($this->channelSeq[$key] ?? 0, $replay->head);

        $extra = ['seq' => $replay->head, 'gap' => $replay->gap];
        if ($type === ChannelType::Presence && $result->member !== null) {
            try {
                if ($this->broker->presenceJoin($key, $result->member, $this->options->serverId)) {
                    $this->broker->publishVolatile($key, ['type' => 'presence', 'channel' => $name, 'action' => 'join', 'member' => $result->member]);
                }
                $extra['members'] = $this->broker->presenceMembers($key);
            } catch (Throwable $e) {
                $this->leave($connection, $subscription);
                $this->log('error', "Presence join on {$key} failed: ".$e->getMessage());
                throw new ProtocolException('unavailable', 'Channel temporarily unavailable', $message->id);
            }
        }

        $this->sendText($connection, ServerMessage::ack($message->id, $name, $extra));

        if (! $replay->gap) {
            foreach ($replay->messages as $encoded) {
                $seq = $this->decodeEnvelope($encoded)['seq'] ?? null;
                if (is_int($seq) && $seq > $subscription->lastSeq) {
                    $subscription->lastSeq = $seq;
                    $this->sendText($connection, $encoded);
                }
            }
        }
    }

    private function unsubscribe(Connection $connection, ClientMessage $message): void
    {
        $ticket = $connection->ticket ?? throw new ProtocolException('unauthorized', 'Not authenticated');
        $key = ChannelName::key($ticket->scope, (string) $message->channel);
        if (isset($connection->subscriptions[$key])) {
            $this->leave($connection, $connection->subscriptions[$key]);
        }
        $this->sendText($connection, ServerMessage::ack($message->id, $message->channel));
    }

    private function presence(Connection $connection, ClientMessage $message): void
    {
        $subscription = $this->subscriptionFor($connection, $message);
        if ($subscription->member === null) {
            throw new ProtocolException('bad_request', 'Not a presence channel', $message->id);
        }
        $this->sendText($connection, ServerMessage::presenceList($message->id, $subscription->name,
            $this->broker->presenceMembers($subscription->key)));
    }

    private function clientEvent(Connection $connection, ClientMessage $message): void
    {
        $subscription = $this->subscriptionFor($connection, $message);
        if (! $this->options->clientEvents || ChannelName::type($subscription->name) === ChannelType::Public) {
            throw new ProtocolException('forbidden', 'Client events are allowed only on private and presence channels', $message->id);
        }
        $this->broker->publishVolatile($subscription->key, [
            'type' => 'event',
            'channel' => $subscription->name,
            'event' => $message->event,
            'data' => $message->data,
            'user_id' => $connection->ticket?->userId,
            'except' => $connection->id,
        ]);
        if ($message->id !== null) {
            $this->sendText($connection, ServerMessage::ack($message->id, $subscription->name));
        }
    }

    private function subscriptionFor(Connection $connection, ClientMessage $message): Subscription
    {
        $ticket = $connection->ticket ?? throw new ProtocolException('unauthorized', 'Not authenticated');
        $key = ChannelName::key($ticket->scope, (string) $message->channel);

        return $connection->subscriptions[$key]
            ?? throw new ProtocolException('not_subscribed', 'Subscribe to the channel first', $message->id);
    }

    /**
     * Registers the connection as a local subscriber; the first one on this server opens the pub/sub subscription.
     */
    private function attach(Connection $connection, string $key): void
    {
        if (! isset($this->channels[$key])) {
            if (! $this->subscriber->isConnected()) {
                throw new RuntimeException('Redis subscriber is not connected');
            }
            $this->subscriber->subscribe($this->broker->pubsubChannel($key));
            $this->channels[$key] = [];
        }
        $this->channels[$key][(int) $connection->stream] = $connection;
    }

    private function detach(Connection $connection, string $key): void
    {
        unset($this->channels[$key][(int) $connection->stream]);
        if (isset($this->channels[$key]) && $this->channels[$key] === []) {
            unset($this->channels[$key], $this->channelSeq[$key]);
            try {
                $this->subscriber->unsubscribe($this->broker->pubsubChannel($key));
            } catch (Throwable $e) {
                $this->log('warning', 'Unsubscribe failed: '.$e->getMessage());
            }
        }
    }

    private function leave(Connection $connection, Subscription $subscription): void
    {
        unset($connection->subscriptions[$subscription->key]);
        $this->detach($connection, $subscription->key);
        if ($subscription->member === null) {
            return;
        }
        try {
            if ($this->broker->presenceLeave($subscription->key, $subscription->member['id'], $this->options->serverId)) {
                $this->broker->publishVolatile($subscription->key, [
                    'type' => 'presence', 'channel' => $subscription->name, 'action' => 'leave', 'member' => $subscription->member,
                ]);
            }
        } catch (Throwable $e) {
            // The heartbeat reaper of another server cannot clean up after a live server, so log loudly.
            $this->log('error', 'Presence leave failed: '.$e->getMessage());
        }
    }

    private function readSubscriber(): void
    {
        try {
            $messages = $this->subscriber->read();
            $this->subscriberLastData = microtime(true);
        } catch (Throwable $e) {
            $this->subscriberDown($e);

            return;
        }
        foreach ($messages as [$channel, $payload]) {
            $key = $this->broker->keyFromPubsubChannel($channel);
            if ($key !== null) {
                $this->dispatch($key, $payload);
            }
        }
    }

    /**
     * Delivers one broker message to the local subscribers of a channel, encoding the frame once.
     */
    private function dispatch(string $key, string $payload): void
    {
        $connections = $this->channels[$key] ?? [];
        if ($connections === []) {
            return;
        }
        $envelope = $this->decodeEnvelope($payload);
        if ($envelope === null) {
            return;
        }
        $seq = is_int($envelope['seq'] ?? null) ? $envelope['seq'] : null;
        $except = is_string($envelope['except'] ?? null) ? $envelope['except'] : null;
        if ($seq !== null && $seq > ($this->channelSeq[$key] ?? 0)) {
            $this->channelSeq[$key] = $seq;
        }

        $frame = FrameCodec::encode(Opcode::Text, $payload);
        foreach ($connections as $connection) {
            if ($except !== null && $connection->id === $except) {
                continue;
            }
            $subscription = $connection->subscriptions[$key] ?? null;
            if ($subscription === null) {
                continue;
            }
            if ($seq !== null) {
                // Replay and live delivery may overlap right after subscribing; the sequence number dedupes them.
                if ($seq <= $subscription->lastSeq) {
                    continue;
                }
                $subscription->lastSeq = $seq;
            }
            $this->send($connection, $frame);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeEnvelope(string $payload): ?array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function connectSubscriber(): void
    {
        try {
            $this->subscriber->connect();
            $this->subscriberBackoff = 0.5;
            $this->subscriberLastData = microtime(true);
            $this->log('info', 'Connected to Redis pub/sub');
        } catch (Throwable $e) {
            $this->subscriberDown($e);

            return;
        }
        $this->fillGaps();
    }

    private function subscriberDown(Throwable $e): void
    {
        $this->subscriber->disconnect();
        $this->log('error', 'Redis pub/sub unavailable: '.$e->getMessage());
        $this->nextSubscriberRetry = microtime(true) + $this->subscriberBackoff;
        $this->subscriberBackoff = min(30.0, $this->subscriberBackoff * 2);
    }

    /**
     * After the pub/sub link was down, catch up from the replay log; channels that fell out of it get "resync".
     */
    private function fillGaps(): void
    {
        foreach (array_keys($this->channels) as $key) {
            try {
                $replay = $this->broker->replay($key, $this->channelSeq[$key] ?? 0, $this->options->replayLimit);
            } catch (Throwable $e) {
                $this->log('error', "Gap fill for {$key} failed: ".$e->getMessage());

                continue;
            }
            if (! $replay->gap) {
                foreach ($replay->messages as $payload) {
                    $this->dispatch($key, $payload);
                }

                continue;
            }
            $this->channelSeq[$key] = $replay->head;
            foreach ($this->channels[$key] as $connection) {
                $subscription = $connection->subscriptions[$key] ?? null;
                if ($subscription !== null) {
                    $subscription->lastSeq = $replay->head;
                    $this->sendText($connection, ServerMessage::encode(['type' => 'resync', 'channel' => $subscription->name, 'seq' => $replay->head]));
                }
            }
        }
    }

    private function maintenance(float $now): void
    {
        foreach ($this->connections as $connection) {
            match ($connection->state) {
                ConnectionState::Handshake => $now - $connection->connectedAt > $this->options->handshakeTimeout
                    ? $this->drop($connection) : null,
                ConnectionState::Closing => $now > ($connection->closeDeadline ?? 0.0) ? $this->drop($connection) : null,
                ConnectionState::Open => $this->keepalive($connection, $now),
            };
        }

        if (! $this->subscriber->isConnected()) {
            if ($now >= $this->nextSubscriberRetry) {
                $this->connectSubscriber();
            }
        } elseif ($now >= $this->nextSubscriberPing) {
            $this->nextSubscriberPing = $now + 15.0;
            if ($now - $this->subscriberLastData > 45.0) {
                $this->subscriberDown(new RuntimeException('No reply from Redis within 45s'));
            } else {
                try {
                    $this->subscriber->ping();
                } catch (Throwable $e) {
                    $this->subscriberDown($e);
                }
            }
        }

        try {
            if ($now >= $this->nextHeartbeat) {
                $this->nextHeartbeat = $now + 10.0;
                $this->broker->heartbeat($this->options->serverId, 30);
            }
            if ($now >= $this->nextReap) {
                $this->nextReap = $now + 15.0;
                foreach ($this->broker->reapDeadServers($this->options->serverId) as $left) {
                    $this->broker->publishVolatile($left['key'], [
                        'type' => 'presence',
                        'channel' => substr($left['key'], (int) strpos($left['key'], '/') + 1),
                        'action' => 'leave',
                        'member' => ['id' => $left['id'], 'info' => []],
                    ]);
                }
            }
        } catch (Throwable $e) {
            $this->log('error', 'Maintenance failed: '.$e->getMessage());
        }
    }

    private function keepalive(Connection $connection, float $now): void
    {
        if ($connection->pingSentAt !== null) {
            if ($now - $connection->pingSentAt > $this->options->pongTimeout) {
                $this->close($connection, CloseCode::IDLE_TIMEOUT, 'Keepalive timeout');
            }

            return;
        }
        if ($now - $connection->lastActivity >= $this->options->pingInterval) {
            $connection->pingSentAt = $now;
            $this->send($connection, FrameCodec::encode(Opcode::Ping, ''));
        }
    }

    private function sendText(Connection $connection, string $json): void
    {
        $this->send($connection, FrameCodec::encode(Opcode::Text, $json));
    }

    private function send(Connection $connection, string $bytes): void
    {
        if ($connection->state !== ConnectionState::Open) {
            return;
        }
        $connection->outbound .= $bytes;
        if (strlen($connection->outbound) > $this->options->maxBuffer) {
            $connection->outbound = '';
            $this->close($connection, CloseCode::SLOW_CONSUMER, 'Client is not reading fast enough');
        }
    }

    private function flush(Connection $connection): void
    {
        $written = @fwrite($connection->stream, $connection->outbound);
        if ($written === false) {
            $this->drop($connection);

            return;
        }
        $connection->outbound = (string) substr($connection->outbound, $written);
        if ($connection->outbound === '' && $connection->closeAfterFlush) {
            $this->drop($connection);
        }
    }

    /**
     * Starts the closing handshake: send a close frame, stop deliveries, wait briefly for the peer's close.
     */
    private function close(Connection $connection, int $code, string $reason = ''): void
    {
        if ($connection->state !== ConnectionState::Open) {
            $this->drop($connection);

            return;
        }
        $this->cleanup($connection);
        $connection->outbound .= FrameCodec::close($code, $reason);
        $connection->state = ConnectionState::Closing;
        $connection->closeDeadline = microtime(true) + self::CLOSE_GRACE;
    }

    private function cleanup(Connection $connection): void
    {
        foreach ($connection->subscriptions as $subscription) {
            $this->leave($connection, $subscription);
        }
    }

    private function drop(Connection $connection): void
    {
        $this->cleanup($connection);
        unset($this->connections[(int) $connection->stream]);
        @fclose($connection->stream);
    }

    private function shutdown(): void
    {
        foreach ($this->connections as $connection) {
            $this->close($connection, CloseCode::GOING_AWAY, 'Server shutting down');
        }
        $deadline = microtime(true) + 1.0;
        while ($this->connections !== [] && microtime(true) < $deadline) {
            foreach ($this->connections as $connection) {
                if ($connection->outbound === '') {
                    $this->drop($connection);
                } else {
                    $this->flush($connection);
                }
            }
            usleep(10_000);
        }
        foreach ($this->connections as $connection) {
            $this->drop($connection);
        }
        try {
            $this->broker->unregisterServer($this->options->serverId);
        } catch (Throwable $e) {
            $this->log('warning', 'Unregister failed: '.$e->getMessage());
        }
        $this->subscriber->disconnect();
        if ($this->listener !== null) {
            @fclose($this->listener);
            $this->listener = null;
        }
    }

    private function log(string $level, string $message): void
    {
        ($this->logger)($level, $message);
    }
}
