<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

enum Opcode: int
{
    case Continuation = 0x0;
    case Text = 0x1;
    case Binary = 0x2;
    case Close = 0x8;
    case Ping = 0x9;
    case Pong = 0xA;

    public function isControl(): bool
    {
        return $this->value >= 0x8;
    }
}
