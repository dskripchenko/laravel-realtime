<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Protocol;

use RuntimeException;

/**
 * A recoverable protocol error: reported to the client as an "error" message, the connection stays open.
 */
final class ProtocolException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int|string|null $requestId = null,
    ) {
        parent::__construct($message);
    }
}
