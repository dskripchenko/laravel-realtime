<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

final class Frame
{
    public function __construct(
        public readonly Opcode $opcode,
        public readonly string $payload,
        public readonly bool $fin = true,
    ) {}

    /**
     * Parses a close frame body into [code, reason]. An empty body means "no status".
     *
     * @return array{int, string}
     */
    public function closeStatus(): array
    {
        $length = strlen($this->payload);
        if ($length === 0) {
            return [CloseCode::NO_STATUS, ''];
        }
        if ($length === 1) {
            throw new WebSocketException('Close frame payload of one byte');
        }
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', substr($this->payload, 0, 2));
        $code = $unpacked[1];
        if (! CloseCode::isValidOnWire($code)) {
            throw new WebSocketException("Invalid close code {$code}");
        }
        $reason = substr($this->payload, 2);
        if (! mb_check_encoding($reason, 'UTF-8')) {
            throw new WebSocketException('Close reason is not valid UTF-8', CloseCode::INVALID_PAYLOAD);
        }

        return [$code, $reason];
    }
}
