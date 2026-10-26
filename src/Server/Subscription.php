<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Server;

final class Subscription
{
    /**
     * @param  array{id: int|string, info: array<mixed>}|null  $member
     */
    public function __construct(
        public readonly string $name,
        public readonly string $key,
        public int $lastSeq,
        public readonly ?array $member = null,
    ) {}
}
