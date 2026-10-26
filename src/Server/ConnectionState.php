<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Server;

enum ConnectionState
{
    case Handshake;
    case Open;
    case Closing;
}
