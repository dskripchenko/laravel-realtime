<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Unit;

use Dskripchenko\Realtime\Broker\Resp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RespTest extends TestCase
{
    public function test_parses_pubsub_message(): void
    {
        $buffer = "*3\r\n\$7\r\nmessage\r\n\$2\r\nch\r\n\$12\r\n{\"a\":\"b\r\nc\"}\r\n";
        $offset = 0;
        $this->assertSame([['message', 'ch', "{\"a\":\"b\r\nc\"}"]], Resp::parse($buffer, $offset));
        $this->assertSame(strlen($buffer), $offset);
    }

    public function test_incomplete_input_returns_null_without_consuming(): void
    {
        $buffer = "*3\r\n\$7\r\nmessage\r\n\$2\r\nch\r\n\$11\r\n{\"a\"";
        $offset = 0;
        $this->assertNull(Resp::parse($buffer, $offset));
        $this->assertSame(0, $offset);
    }

    public function test_scalars_and_errors(): void
    {
        $offset = 0;
        $this->assertSame(['OK'], Resp::parse("+OK\r\n", $offset));
        $offset = 0;
        $this->assertSame([12], Resp::parse(":12\r\n", $offset));
        $offset = 0;
        $this->assertSame([null], Resp::parse("\$-1\r\n", $offset));
        $offset = 0;
        $error = Resp::parse("-ERR nope\r\n", $offset)[0] ?? null;
        $this->assertInstanceOf(RuntimeException::class, $error);
    }
}
