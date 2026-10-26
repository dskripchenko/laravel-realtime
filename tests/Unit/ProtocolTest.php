<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Unit;

use Dskripchenko\Realtime\Protocol\ClientMessage;
use Dskripchenko\Realtime\Protocol\ProtocolException;
use Dskripchenko\Realtime\Protocol\ServerMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    public function test_subscribe_with_since(): void
    {
        $message = ClientMessage::parse('{"type":"subscribe","id":1,"channel":"private-board.1","since":17}');
        $this->assertSame('subscribe', $message->type);
        $this->assertSame(1, $message->id);
        $this->assertSame('private-board.1', $message->channel);
        $this->assertSame(17, $message->since);
    }

    public function test_subscribe_without_since(): void
    {
        $this->assertNull(ClientMessage::parse('{"type":"subscribe","channel":"news"}')->since);
    }

    public function test_unsubscribe_ping_presence_and_client_event(): void
    {
        $this->assertSame('unsubscribe', ClientMessage::parse('{"type":"unsubscribe","channel":"news"}')->type);
        $this->assertSame('abc', ClientMessage::parse('{"type":"ping","id":"abc"}')->id);
        $this->assertSame('list', ClientMessage::parse('{"type":"presence","channel":"presence-page.1"}')->action);

        $event = ClientMessage::parse('{"type":"event","channel":"presence-page.1","event":"client-typing","data":{"on":true}}');
        $this->assertSame('client-typing', $event->event);
        $this->assertSame(['on' => true], $event->data);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'not json' => ['{', 'bad_request'];
        yield 'json list' => ['[1,2]', 'bad_request'];
        yield 'scalar' => ['"x"', 'bad_request'];
        yield 'unknown type' => ['{"type":"hack"}', 'unknown_type'];
        yield 'missing type' => ['{"channel":"x"}', 'unknown_type'];
        yield 'object id' => ['{"type":"ping","id":{}}', 'bad_request'];
        yield 'missing channel' => ['{"type":"subscribe"}', 'bad_request'];
        yield 'negative since' => ['{"type":"subscribe","channel":"x","since":-1}', 'bad_request'];
        yield 'string since' => ['{"type":"subscribe","channel":"x","since":"5"}', 'bad_request'];
        yield 'presence join requested by client' => ['{"type":"presence","channel":"presence-x","action":"join"}', 'bad_request'];
        yield 'client event without prefix' => ['{"type":"event","channel":"private-x","event":"CardMoved"}', 'bad_request'];
        yield 'too deep' => [str_repeat('{"a":', 40).'1'.str_repeat('}', 40), 'bad_request'];
    }

    #[DataProvider('invalid')]
    public function test_invalid_messages(string $json, string $code): void
    {
        try {
            ClientMessage::parse($json);
            $this->fail('expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_error_carries_request_id(): void
    {
        try {
            ClientMessage::parse('{"type":"subscribe","id":9}');
            $this->fail('expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame(9, $e->requestId);
        }
    }

    public function test_server_messages(): void
    {
        $this->assertSame('{"type":"welcome","socket_id":"1.2","heartbeat":25}', ServerMessage::welcome('1.2', 25));
        $this->assertSame('{"type":"ack","id":1,"channel":"news","seq":3,"gap":false}', ServerMessage::ack(1, 'news', ['seq' => 3, 'gap' => false]));
        $this->assertSame('{"type":"ack","channel":"news"}', ServerMessage::ack(null, 'news'));
        $this->assertSame('{"type":"error","id":2,"code":"forbidden","message":"no"}', ServerMessage::error(2, 'forbidden', 'no'));
        $this->assertSame('{"type":"pong","id":"x"}', ServerMessage::pong('x'));
        $this->assertSame(
            '{"type":"presence","id":3,"channel":"presence-p","action":"list","members":[{"id":7,"info":{"name":"Ann"}}]}',
            ServerMessage::presenceList(3, 'presence-p', [['id' => 7, 'info' => ['name' => 'Ann']]]),
        );
        $this->assertSame('{"x":"Привет/"}', ServerMessage::encode(['x' => 'Привет/']));
    }
}
