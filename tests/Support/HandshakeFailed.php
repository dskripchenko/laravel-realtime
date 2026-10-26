<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Support;

use RuntimeException;

final class HandshakeFailed extends RuntimeException
{
    public function __construct(public readonly int $status, string $response)
    {
        parent::__construct("Handshake failed with HTTP {$status}: {$response}");
    }
}
