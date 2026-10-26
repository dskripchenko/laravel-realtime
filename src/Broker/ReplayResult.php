<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Broker;

final class ReplayResult
{
    /**
     * @param  list<string>  $messages  encoded wire messages, oldest first
     */
    public function __construct(
        public readonly int $head,
        public readonly bool $gap,
        public readonly array $messages = [],
    ) {}
}
