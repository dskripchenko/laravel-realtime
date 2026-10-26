<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Unit;

use Dskripchenko\Realtime\WebSocket\CloseCode;
use Dskripchenko\Realtime\WebSocket\Frame;
use Dskripchenko\Realtime\WebSocket\FrameCodec;
use Dskripchenko\Realtime\WebSocket\FrameParser;
use Dskripchenko\Realtime\WebSocket\MessageAssembler;
use Dskripchenko\Realtime\WebSocket\Opcode;
use Dskripchenko\Realtime\WebSocket\WebSocketException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameCodecTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function lengths(): iterable
    {
        foreach ([0, 1, 125, 126, 127, 65535, 65536, 200000] as $length) {
            yield "{$length} bytes" => [$length];
        }
    }

    #[DataProvider('lengths')]
    public function test_unmasked_round_trip_for_every_length_encoding(int $length): void
    {
        $payload = str_repeat('a', $length);
        $frames = (new FrameParser(false, 1 << 20))->push(FrameCodec::encode(Opcode::Text, $payload));

        $this->assertCount(1, $frames);
        $this->assertSame(Opcode::Text, $frames[0]->opcode);
        $this->assertTrue($frames[0]->fin);
        $this->assertSame($payload, $frames[0]->payload);
    }

    #[DataProvider('lengths')]
    public function test_masked_round_trip(int $length): void
    {
        $payload = $length === 0 ? '' : random_bytes($length);
        $encoded = FrameCodec::encode(Opcode::Binary, $payload, true, "\x01\x02\x03\x04");

        if ($length > 0) {
            $this->assertNotSame($payload, substr($encoded, -$length), 'payload must be masked on the wire');
        }
        $frames = (new FrameParser(true, 1 << 20))->push($encoded);
        $this->assertSame($payload, $frames[0]->payload);
    }

    public function test_header_bytes_match_rfc_examples(): void
    {
        // RFC 6455 5.7: unmasked "Hello" and masked "Hello" with mask 37 fa 21 3d.
        $this->assertSame("\x81\x05Hello", FrameCodec::encode(Opcode::Text, 'Hello'));
        $this->assertSame("\x81\x85\x37\xfa\x21\x3d\x7f\x9f\x4d\x51\x58", FrameCodec::encode(Opcode::Text, 'Hello', true, "\x37\xfa\x21\x3d"));
        $this->assertSame("\x82\x7e\x01\x00", substr(FrameCodec::encode(Opcode::Binary, str_repeat('x', 256)), 0, 4));
        $this->assertSame("\x82\x7f\x00\x00\x00\x00\x00\x01\x00\x00", substr(FrameCodec::encode(Opcode::Binary, str_repeat('x', 65536)), 0, 10));
    }

    public function test_parser_handles_byte_by_byte_input(): void
    {
        $parser = new FrameParser(true);
        $bytes = FrameCodec::encode(Opcode::Text, 'incremental', true, 'abcd').FrameCodec::encode(Opcode::Ping, 'p', true, 'efgh');
        $frames = [];
        foreach (str_split($bytes) as $byte) {
            array_push($frames, ...$parser->push($byte));
        }

        $this->assertCount(2, $frames);
        $this->assertSame('incremental', $frames[0]->payload);
        $this->assertSame(Opcode::Ping, $frames[1]->opcode);
        $this->assertSame(0, $parser->bufferedBytes());
    }

    public function test_fragmented_message_with_interleaved_control_frame(): void
    {
        $mask = 'mask';
        $bytes = FrameCodec::encode(Opcode::Text, 'Hel', false, $mask)
            .FrameCodec::encode(Opcode::Ping, 'hi', true, $mask)
            .FrameCodec::encode(Opcode::Continuation, 'lo ', false, $mask)
            .FrameCodec::encode(Opcode::Continuation, 'world', true, $mask);

        $assembler = new MessageAssembler;
        $out = [];
        foreach ((new FrameParser(true))->push($bytes) as $frame) {
            $message = $assembler->add($frame);
            if ($message !== null) {
                $out[] = $message;
            }
        }

        $this->assertCount(2, $out);
        $this->assertSame(Opcode::Ping, $out[0]->opcode);
        $this->assertSame(Opcode::Text, $out[1]->opcode);
        $this->assertSame('Hello world', $out[1]->payload);
    }

    public function test_fragmented_utf8_split_inside_a_code_point_is_accepted(): void
    {
        $text = 'Привет';
        $assembler = new MessageAssembler;
        $this->assertNull($assembler->add(new Frame(Opcode::Text, substr($text, 0, 3), false)));
        $message = $assembler->add(new Frame(Opcode::Continuation, substr($text, 3), true));
        $this->assertSame($text, $message?->payload);
    }

    public function test_unmasked_client_frame_is_rejected(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new FrameParser(true))->push(FrameCodec::encode(Opcode::Text, 'x')));
    }

    public function test_masked_server_frame_is_rejected_by_client_parser(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new FrameParser(false))->push(FrameCodec::encode(Opcode::Text, 'x', true, 'abcd')));
    }

    public function test_reserved_bits_are_rejected(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new FrameParser(true))->push("\xC1\x80abcd"));
    }

    public function test_unknown_opcode_is_rejected(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new FrameParser(true))->push("\x83\x80abcd"));
    }

    public function test_fragmented_control_frame_is_rejected(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new FrameParser(true))->push("\x09\x80abcd"));
    }

    public function test_oversized_control_frame_is_rejected(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new FrameParser(true))->push("\x89\xFE\x00\x7Eabcd".str_repeat('x', 126)));
    }

    public function test_control_frame_encoding_limits(): void
    {
        $this->expectException(WebSocketException::class);
        FrameCodec::encode(Opcode::Ping, str_repeat('x', 126));
    }

    public function test_64bit_length_with_msb_set_is_rejected(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new FrameParser(true))->push("\x82\xFF\x80\x00\x00\x00\x00\x00\x00\x00"));
    }

    public function test_frame_over_limit_closes_with_1009(): void
    {
        $this->assertProtocolError(CloseCode::MESSAGE_TOO_BIG, fn () => (new FrameParser(true, 100))->push(FrameCodec::encode(Opcode::Text, str_repeat('x', 101), true, 'abcd')));
    }

    public function test_message_over_limit_closes_with_1009(): void
    {
        $assembler = new MessageAssembler(10);
        $assembler->add(new Frame(Opcode::Text, '123456', false));
        $this->assertProtocolError(CloseCode::MESSAGE_TOO_BIG, fn () => $assembler->add(new Frame(Opcode::Continuation, '123456', true)));
    }

    public function test_continuation_without_start_is_rejected(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new MessageAssembler)->add(new Frame(Opcode::Continuation, 'x', true)));
    }

    public function test_new_data_frame_during_fragmented_message_is_rejected(): void
    {
        $assembler = new MessageAssembler;
        $assembler->add(new Frame(Opcode::Text, 'a', false));
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => $assembler->add(new Frame(Opcode::Text, 'b', true)));
    }

    public function test_invalid_utf8_text_closes_with_1007(): void
    {
        $this->assertProtocolError(CloseCode::INVALID_PAYLOAD, fn () => (new MessageAssembler)->add(new Frame(Opcode::Text, "\xC3\x28", true)));
    }

    public function test_binary_messages_are_not_utf8_checked(): void
    {
        $this->assertSame("\xC3\x28", (new MessageAssembler)->add(new Frame(Opcode::Binary, "\xC3\x28"))?->payload);
    }

    public function test_close_frame_round_trip(): void
    {
        $frames = (new FrameParser(true))->push(FrameCodec::close(CloseCode::GOING_AWAY, 'bye', 'abcd'));
        $this->assertSame([1001, 'bye'], $frames[0]->closeStatus());
        $this->assertSame([CloseCode::NO_STATUS, ''], (new Frame(Opcode::Close, ''))->closeStatus());
    }

    public function test_close_reason_is_truncated_to_fit_a_control_frame(): void
    {
        $frame = (new FrameParser(false))->push(FrameCodec::close(1000, str_repeat('я', 100)))[0];
        $this->assertLessThanOrEqual(125, strlen($frame->payload));
        [, $reason] = $frame->closeStatus();
        $this->assertTrue(mb_check_encoding($reason, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function closeCodes(): iterable
    {
        foreach ([1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014, 3000, 4999] as $code) {
            yield "valid {$code}" => [$code, true];
        }
        foreach ([0, 999, 1004, 1005, 1006, 1015, 1016, 2000, 2999, 5000] as $code) {
            yield "invalid {$code}" => [$code, false];
        }
    }

    #[DataProvider('closeCodes')]
    public function test_close_code_validation(int $code, bool $valid): void
    {
        $this->assertSame($valid, CloseCode::isValidOnWire($code));
        $frame = new Frame(Opcode::Close, pack('n', $code));
        if ($valid) {
            $this->assertSame($code, $frame->closeStatus()[0]);
        } else {
            $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => $frame->closeStatus());
        }
    }

    public function test_one_byte_close_payload_is_a_protocol_error(): void
    {
        $this->assertProtocolError(CloseCode::PROTOCOL_ERROR, fn () => (new Frame(Opcode::Close, "\x03"))->closeStatus());
    }

    public function test_close_reason_must_be_utf8(): void
    {
        $this->assertProtocolError(CloseCode::INVALID_PAYLOAD, fn () => (new Frame(Opcode::Close, pack('n', 1000)."\xFF"))->closeStatus());
    }

    private function assertProtocolError(int $code, callable $action): void
    {
        try {
            $action();
        } catch (WebSocketException $e) {
            $this->assertSame($code, $e->closeCode, $e->getMessage());

            return;
        }
        $this->fail('Expected a WebSocketException');
    }
}
