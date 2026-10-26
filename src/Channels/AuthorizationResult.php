<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Channels;

final class AuthorizationResult
{
    /**
     * @param  array{id: int|string, info: array<mixed>}|null  $member  set for presence channels
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly ?array $member = null,
        public readonly string $reason = '',
    ) {}

    /**
     * @param  array{id: int|string, info: array<mixed>}|null  $member
     */
    public static function allow(?array $member = null): self
    {
        return new self(true, $member);
    }

    public static function deny(string $reason = 'forbidden'): self
    {
        return new self(false, null, $reason);
    }
}
