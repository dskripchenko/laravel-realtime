<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

use RuntimeException;

final class HandshakeException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(public readonly int $status, string $message, public readonly array $headers = [])
    {
        parent::__construct($message);
    }
}
