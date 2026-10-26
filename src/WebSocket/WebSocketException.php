<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

use RuntimeException;

/**
 * A protocol-level failure that must terminate the connection with the given close code.
 */
final class WebSocketException extends RuntimeException
{
    public function __construct(string $message, public readonly int $closeCode = CloseCode::PROTOCOL_ERROR)
    {
        parent::__construct($message);
    }
}
