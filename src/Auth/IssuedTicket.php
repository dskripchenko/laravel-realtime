<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Auth;

use JsonSerializable;

final class IssuedTicket implements JsonSerializable
{
    public function __construct(
        public readonly string $token,
        public readonly ?string $url,
        public readonly int $expiresIn,
    ) {}

    /**
     * @return array{ticket: string, url: ?string, expires_in: int}
     */
    public function jsonSerialize(): array
    {
        return ['ticket' => $this->token, 'url' => $this->url, 'expires_in' => $this->expiresIn];
    }
}
