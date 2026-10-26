<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Integration;

use Dskripchenko\Realtime\Facades\Realtime;
use Dskripchenko\Realtime\Tests\Support\HandshakeFailed;
use Dskripchenko\Realtime\Tests\Support\WebSocketClient;
use Dskripchenko\Realtime\Tests\TestCase;
use Dskripchenko\Realtime\WebSocket\CloseCode;
use Dskripchenko\Realtime\WebSocket\FrameCodec;
use Dskripchenko\Realtime\WebSocket\Opcode;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use RuntimeException;

/**
 * Runs `realtime:serve` as a child process and talks to it over real sockets.
 */
final class ServerTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;

    /** @var array<int, resource> */
    private static array $pipes = [];

    private static int $port = 0;

    private static string $prefix = '';

    private static string $log = '';

    protected static function redisPrefix(): string
    {
        return self::$prefix;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (! self::redisAvailable()) {
            return;
        }
        self::$prefix = 'rt-it-'.bin2hex(random_bytes(4)).':';
        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        if (self::redisAvailable()) {
            $redis = new \Redis;
            $redis->connect('127.0.0.1', self::redisPort());
            $keys = $redis->keys(self::$prefix.'*');
            if (is_array($keys) && $keys !== []) {
                $redis->del($keys);
            }
        }
        parent::tearDownAfterClass();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('broadcasting.default', 'realtime');
        $app['config']->set('broadcasting.connections.realtime', ['driver' => 'realtime']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        if (self::$process === null) {
            $this->fail("Server did not start:\n".self::$log);
        }
    }

    public function test_subscribe_publish_receive(): void
    {
        $client = $this->connect(7);
        $client->sendJson(['type' => 'subscribe', 'id' => 1, 'channel' => 'news']);
        $this->assertSame(['type' => 'ack', 'id' => 1, 'channel' => 'news', 'seq' => 0, 'gap' => false], $client->receiveJson());

        $seq = Realtime::publish('news', 'Posted', ['title' => 'Hello, мир']);

        $this->assertSame(1, $seq);
        $this->assertSame(
            ['seq' => 1, 'type' => 'event', 'channel' => 'news', 'event' => 'Posted', 'data' => ['title' => 'Hello, мир']],
            $client->receiveJson(),
        );
        $client->close();
    }

    public function test_reconnect_resumes_from_last_seq(): void
    {
        $client = $this->connect(7);
        $client->sendJson(['type' => 'subscribe', 'id' => 1, 'channel' => 'private-board.1']);
        $this->assertSame('ack', $client->receiveJson()['type'] ?? null);

        foreach ([1, 2, 3] as $i) {
            Realtime::publish('private-board.1', 'CardMoved', ['i' => $i]);
        }
        $last = 0;
        foreach ([1, 2, 3] as $i) {
            $event = $client->receiveJson() ?? $this->fail('missing event');
            $this->assertSame($i, $event['data']['i'] ?? null);
            $last = $event['seq'];
        }
        $client->abort();

        // Published while the client is away.
        Realtime::publish('private-board.1', 'CardMoved', ['i' => 4]);
        Realtime::publish('private-board.1', 'CardMoved', ['i' => 5]);

        $again = $this->connect(7);
        $again->sendJson(['type' => 'subscribe', 'id' => 2, 'channel' => 'private-board.1', 'since' => $last]);
        $this->assertSame(['type' => 'ack', 'id' => 2, 'channel' => 'private-board.1', 'seq' => 5, 'gap' => false], $again->receiveJson());
        foreach ([4, 5] as $i) {
            $replayed = $again->receiveJson() ?? $this->fail('missing replayed event');
            $this->assertSame([$i, $i], [$replayed['seq'], $replayed['data']['i'] ?? null]);
        }

        Realtime::publish('private-board.1', 'CardMoved', ['i' => 6]);
        $this->assertSame(6, $again->receiveJson()['seq'] ?? null, 'live delivery continues after replay without duplicates');
        $again->close();
    }

    public function test_resume_from_the_future_reports_gap(): void
    {
        $client = $this->connect(7);
        $client->sendJson(['type' => 'subscribe', 'id' => 1, 'channel' => 'fresh', 'since' => 50]);
        $this->assertSame(['type' => 'ack', 'id' => 1, 'channel' => 'fresh', 'seq' => 0, 'gap' => true], $client->receiveJson());
        $client->close();
    }

    public function test_private_channel_authorization(): void
    {
        $client = $this->connect(7);
        $client->sendJson(['type' => 'subscribe', 'id' => 1, 'channel' => 'private-board.13']);
        $error = $client->receiveJson();
        $this->assertSame('error', $error['type'] ?? null);
        $this->assertSame('forbidden', $error['code'] ?? null);
        $this->assertSame(1, $error['id'] ?? null);

        $client->sendJson(['type' => 'subscribe', 'id' => 2, 'channel' => 'private-unregistered.1']);
        $this->assertSame('forbidden', $client->receiveJson()['code'] ?? null);
        $client->close();
    }

    public function test_authorization_runs_inside_the_ticket_scope(): void
    {
        $client = $this->connect(7, 'acme');
        $client->sendJson(['type' => 'subscribe', 'id' => 1, 'channel' => 'private-tenant-only.acme']);
        $this->assertSame('ack', $client->receiveJson()['type'] ?? null);
        $client->sendJson(['type' => 'subscribe', 'id' => 2, 'channel' => 'private-tenant-only.other']);
        $this->assertSame('forbidden', $client->receiveJson()['code'] ?? null);
        $client->close();
    }

    public function test_scopes_are_isolated(): void
    {
        $acme = $this->connect(7, 'acme');
        $globex = $this->connect(7, 'globex');
        foreach ([$acme, $globex] as $client) {
            $client->sendJson(['type' => 'subscribe', 'channel' => 'news']);
            $this->assertSame('ack', $client->receiveJson()['type'] ?? null);
        }

        Realtime::publish('news', 'ForGlobex', [], 'globex');
        Realtime::publish('news', 'ForAcme', [], 'acme');

        $this->assertSame('ForAcme', $acme->receiveJson()['event'] ?? null);
        $this->assertSame('ForGlobex', $globex->receiveJson()['event'] ?? null);
        $this->assertNull($acme->receiveJson(0.3));
        $acme->close();
        $globex->close();
    }

    public function test_broadcasting_driver_and_to_others(): void
    {
        $sender = $this->connect(1);
        $other = $this->connect(2);
        $senderId = (string) ($sender->welcome['socket_id'] ?? '');
        foreach ([$sender, $other] as $client) {
            $client->sendJson(['type' => 'subscribe', 'channel' => 'private-board.7']);
            $this->assertSame('ack', $client->receiveJson()['type'] ?? null);
        }

        $this->app?->make('request')->headers->set('X-Socket-ID', $senderId);
        broadcast(new BoardRenamed('Roadmap'))->toOthers();

        $event = $other->receiveJson() ?? $this->fail('event not delivered');
        $this->assertSame(BoardRenamed::class, $event['event'], 'Laravel names events by class unless broadcastAs() says otherwise');
        $this->assertSame(['name' => 'Roadmap'], $event['data']);
        $this->assertNull($sender->receiveJson(0.3), 'toOthers() skips the sender socket');
        $sender->close();
        $other->close();
    }

    public function test_presence_join_list_whisper_and_leave(): void
    {
        $ann = $this->connect(1);
        $ann->sendJson(['type' => 'subscribe', 'id' => 1, 'channel' => 'presence-page.1']);
        $ack = $ann->receiveJson() ?? $this->fail();
        $this->assertSame([['id' => 1, 'info' => ['name' => 'User 1']]], $ack['members']);
        $ann->waitFor(static fn (array $m): bool => ($m['type'] ?? null) === 'presence' && ($m['action'] ?? null) === 'join');

        $bob = $this->connect(2);
        $bob->sendJson(['type' => 'subscribe', 'id' => 1, 'channel' => 'presence-page.1']);
        $this->assertCount(2, ($bob->receiveJson() ?? [])['members'] ?? []);
        $join = $ann->waitFor(static fn (array $m): bool => ($m['type'] ?? null) === 'presence');
        $this->assertSame(['seq' => null, 'type' => 'presence', 'channel' => 'presence-page.1', 'action' => 'join',
            'member' => ['id' => 2, 'info' => ['name' => 'User 2']]], $join);

        $ann->sendJson(['type' => 'presence', 'id' => 5, 'channel' => 'presence-page.1', 'action' => 'list']);
        $list = $ann->waitFor(static fn (array $m): bool => ($m['id'] ?? null) === 5);
        $this->assertCount(2, $list['members']);

        $bob->sendJson(['type' => 'event', 'channel' => 'presence-page.1', 'event' => 'client-typing', 'data' => ['on' => true]]);
        $typing = $ann->waitFor(static fn (array $m): bool => ($m['event'] ?? null) === 'client-typing');
        $this->assertSame(['on' => true], $typing['data']);
        $this->assertSame(2, $typing['user_id']);

        $bob->close();
        $leave = $ann->waitFor(static fn (array $m): bool => ($m['action'] ?? null) === 'leave');
        $this->assertSame(2, $leave['member']['id']);
        $ann->close();
    }

    public function test_client_events_are_rejected_on_public_channels(): void
    {
        $client = $this->connect(1);
        $client->sendJson(['type' => 'subscribe', 'channel' => 'news']);
        $client->receiveJson();
        $client->sendJson(['type' => 'event', 'id' => 3, 'channel' => 'news', 'event' => 'client-x']);
        $this->assertSame('forbidden', $client->receiveJson()['code'] ?? null);
        $client->sendJson(['type' => 'event', 'id' => 4, 'channel' => 'private-board.99', 'event' => 'client-x']);
        $this->assertSame('not_subscribed', $client->receiveJson()['code'] ?? null);
        $client->close();
    }

    public function test_ping_and_protocol_errors_keep_the_connection_open(): void
    {
        $client = $this->connect(1);
        $client->sendJson(['type' => 'ping', 'id' => 'p1']);
        $this->assertSame(['type' => 'pong', 'id' => 'p1'], $client->receiveJson());
        $client->sendFrame(Opcode::Text, 'not json');
        $this->assertSame('bad_request', $client->receiveJson()['code'] ?? null);

        // Fragmented text message plus a WebSocket-level ping in between.
        $client->sendFrame(Opcode::Text, '{"type":"pi', false);
        $client->sendFrame(Opcode::Ping, 'hb');
        $pong = $client->receive();
        $this->assertSame([Opcode::Pong, 'hb'], [$pong?->opcode, $pong?->payload]);
        $client->sendFrame(Opcode::Continuation, 'ng","id":2}');
        $this->assertSame(['type' => 'pong', 'id' => 2], $client->receiveJson());
        $client->close();
    }

    public function test_binary_and_unmasked_frames_close_the_connection(): void
    {
        $client = $this->connect(1);
        $client->sendFrame(Opcode::Binary, 'x');
        $close = $client->receive() ?? $this->fail('expected close');
        $this->assertSame(Opcode::Close, $close->opcode);
        $this->assertSame(CloseCode::UNSUPPORTED_DATA, $close->closeStatus()[0]);

        $raw = $this->connect(1);
        $raw->sendRaw("\x81\x01x");
        $this->assertSame(CloseCode::PROTOCOL_ERROR, ($raw->receive() ?? $this->fail())->closeStatus()[0]);
    }

    public function test_close_handshake_echoes_the_code(): void
    {
        $client = $this->connect(1);
        $client->sendRaw(FrameCodec::close(4321, 'done', 'abcd'));
        $this->assertSame([4321, ''], ($client->receive() ?? $this->fail())->closeStatus());
    }

    public function test_tickets_are_single_use_and_required(): void
    {
        $ticket = Realtime::issueTicket(new GenericUser(['id' => 1]));
        WebSocketClient::connect('127.0.0.1', self::$port, '/?ticket='.$ticket->token)->close();

        foreach (['/?ticket='.$ticket->token, '/?ticket=forged', '/'] as $path) {
            try {
                WebSocketClient::connect('127.0.0.1', self::$port, $path);
                $this->fail("{$path} should be rejected");
            } catch (HandshakeFailed $e) {
                $this->assertSame(401, $e->status);
            }
        }
    }

    public function test_events_published_while_the_pubsub_link_is_down_are_delivered_after_reconnect(): void
    {
        $client = $this->connect(1);
        $client->sendJson(['type' => 'subscribe', 'channel' => 'outage']);
        $this->assertSame('ack', $client->receiveJson()['type'] ?? null);
        Realtime::publish('outage', 'Before');
        $this->assertSame(1, $client->receiveJson()['seq'] ?? null);

        $redis = new \Redis;
        $redis->connect('127.0.0.1', self::redisPort());
        $killed = 0;
        foreach ((array) $redis->rawCommand('CLIENT', 'LIST', 'TYPE', 'pubsub') as $line) {
            foreach (explode("\n", (string) $line) as $row) {
                if (str_contains($row, 'name=realtime-'.self::serverId().' ') && preg_match('/\bid=(\d+)/', $row, $m)) {
                    $killed += (int) $redis->rawCommand('CLIENT', 'KILL', 'ID', $m[1]);
                }
            }
        }
        $this->assertSame(1, $killed, 'the server subscriber connection is identifiable by name');

        Realtime::publish('outage', 'During1');
        Realtime::publish('outage', 'During2');

        $this->assertSame(['During1', 2], [($e = $client->receiveJson(5.0))['event'] ?? null, $e['seq'] ?? null]);
        $this->assertSame(['During2', 3], [($e = $client->receiveJson(5.0))['event'] ?? null, $e['seq'] ?? null]);
        Realtime::publish('outage', 'After');
        $this->assertSame('After', $client->receiveJson()['event'] ?? null);
        $client->close();
    }

    public function test_rate_limit_closes_flooding_connections(): void
    {
        $client = $this->connect(1);
        for ($i = 0; $i < 80; $i++) {
            $client->sendJson(['type' => 'ping']);
        }
        $last = null;
        while (($frame = $client->receive(2.0)) !== null) {
            $last = $frame;
        }
        $this->assertSame(Opcode::Close, $last?->opcode);
        $this->assertSame(CloseCode::POLICY_VIOLATION, $last->closeStatus()[0]);
    }

    public function test_pipelined_frames_after_the_upgrade_request_are_processed(): void
    {
        $ticket = Realtime::issueTicket(new GenericUser(['id' => 1]));
        $stream = stream_socket_client('tcp://127.0.0.1:'.self::$port);
        $this->assertNotFalse($stream);
        stream_set_timeout($stream, 3);
        fwrite($stream, "GET /?ticket={$ticket->token} HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n"
            .FrameCodec::encode(Opcode::Text, '{"type":"ping","id":"early"}', true, 'abcd'));
        $received = '';
        $deadline = microtime(true) + 3;
        while (! str_contains($received, '"early"') && microtime(true) < $deadline) {
            $received .= (string) fread($stream, 8192);
        }
        fclose($stream);
        $this->assertStringContainsString('{"type":"pong","id":"early"}', $received);
    }

    public function test_health_endpoint(): void
    {
        $response = WebSocketClient::http('127.0.0.1', self::$port, "GET /health HTTP/1.1\r\nHost: x\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $response);
        $body = json_decode(substr($response, (int) strpos($response, "\r\n\r\n") + 4), true);
        $this->assertIsArray($body);
        $this->assertSame('ok', $body['status']);
        $this->assertTrue($body['redis']);

        $this->assertStringStartsWith('HTTP/1.1 404', WebSocketClient::http('127.0.0.1', self::$port, "GET /nope HTTP/1.1\r\n\r\n"));
    }

    private function connect(int $userId, string $scope = ''): WebSocketClient
    {
        $ticket = Realtime::issueTicket(new GenericUser(['id' => $userId]), null, $scope);
        $client = WebSocketClient::connect('127.0.0.1', self::$port, '/?ticket='.$ticket->token);
        $welcome = $client->receiveJson() ?? throw new RuntimeException('no welcome');
        $this->assertSame('welcome', $welcome['type'] ?? null);
        $client->welcome = $welcome;

        return $client;
    }

    private static function startServer(): void
    {
        $root = dirname(__DIR__, 2);
        $env = array_merge(getenv(), [
            'REALTIME_REDIS_PREFIX' => self::$prefix,
            'REALTIME_TEST_REDIS_PORT' => (string) self::redisPort(),
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
        ]);
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=256M', $root.'/vendor/bin/testbench', 'realtime:serve', '--host=127.0.0.1', '--port=0',
                '--server-id='.self::serverId()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env,
        );
        if (! is_resource($process)) {
            return;
        }
        self::$pipes = $pipes;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            self::$log .= (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
            if (preg_match('/listening on 127\.0\.0\.1:(\d+)/', self::$log, $m)) {
                self::$port = (int) $m[1];
                self::$process = $process;

                return;
            }
            if (! proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        proc_terminate($process);
        proc_close($process);
    }

    private static function serverId(): string
    {
        return 'it-'.rtrim(self::$prefix, ':');
    }

    private static function stopServer(): void
    {
        if (self::$process === null) {
            return;
        }
        proc_terminate(self::$process, 15);
        $deadline = microtime(true) + 5;
        while (proc_get_status(self::$process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        foreach ([1, 2] as $i) {
            self::$log .= (string) @stream_get_contents(self::$pipes[$i]);
        }
        if (is_string($path = getenv('REALTIME_TEST_LOG')) && $path !== '') {
            file_put_contents($path, self::$log);
        }
        foreach (self::$pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close(self::$process);
        self::$process = null;
    }
}

final class BoardRenamed implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public string $name) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('board.7');
    }
}
