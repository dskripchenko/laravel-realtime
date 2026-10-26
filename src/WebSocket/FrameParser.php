<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

/**
 * Incremental frame decoder: feed raw bytes as they arrive, collect complete frames.
 */
final class FrameParser
{
    private string $buffer = '';

    /**
     * @param  bool  $expectMasked  true on the server side (client frames must be masked), false on the client side
     */
    public function __construct(
        private readonly bool $expectMasked = true,
        private readonly int $maxFrameSize = 1_048_576,
    ) {}

    /**
     * @return list<Frame>
     */
    public function push(string $bytes): array
    {
        $this->buffer .= $bytes;
        $frames = [];

        while (($frame = $this->next()) !== null) {
            $frames[] = $frame;
        }

        return $frames;
    }

    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    private function next(): ?Frame
    {
        $available = strlen($this->buffer);
        if ($available < 2) {
            return null;
        }

        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);

        if (($first & 0x70) !== 0) {
            // No extensions are negotiated, so RSV bits must be clear.
            throw new WebSocketException('Reserved bits set without a negotiated extension');
        }

        $opcode = Opcode::tryFrom($first & 0x0F) ?? throw new WebSocketException('Unknown opcode '.($first & 0x0F));
        $fin = ($first & 0x80) !== 0;
        $masked = ($second & 0x80) !== 0;

        if ($masked !== $this->expectMasked) {
            throw new WebSocketException($this->expectMasked ? 'Client frames must be masked' : 'Server frames must not be masked');
        }

        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if ($available < 4) {
                return null;
            }
            /** @var array{1: int} $u */
            $u = unpack('n', substr($this->buffer, 2, 2));
            $length = $u[1];
            $offset = 4;
        } elseif ($length === 127) {
            if ($available < 10) {
                return null;
            }
            if ((ord($this->buffer[2]) & 0x80) !== 0) {
                throw new WebSocketException('Most significant bit of a 64-bit length must be zero');
            }
            /** @var array{1: int} $u */
            $u = unpack('J', substr($this->buffer, 2, 8));
            $length = $u[1];
            $offset = 10;
        }

        if ($opcode->isControl() && (! $fin || $length > 125)) {
            throw new WebSocketException('Control frames must be final and at most 125 bytes');
        }

        if ($length > $this->maxFrameSize) {
            throw new WebSocketException('Frame exceeds the size limit', CloseCode::MESSAGE_TOO_BIG);
        }

        $maskLength = $masked ? 4 : 0;
        if ($available < $offset + $maskLength + $length) {
            return null;
        }

        $mask = $masked ? substr($this->buffer, $offset, 4) : '';
        $payload = substr($this->buffer, $offset + $maskLength, $length);
        $this->buffer = substr($this->buffer, $offset + $maskLength + $length);

        if ($masked) {
            $payload = FrameCodec::applyMask($payload, $mask);
        }

        return new Frame($opcode, $payload, $fin);
    }
}
