<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

final class FrameCodec
{
    /**
     * Encodes one frame. Servers send unmasked frames; clients must pass a 4-byte mask.
     */
    public static function encode(Opcode $opcode, string $payload, bool $fin = true, ?string $mask = null): string
    {
        $length = strlen($payload);
        if ($opcode->isControl() && ($length > 125 || ! $fin)) {
            throw new WebSocketException('Control frames must be final and at most 125 bytes');
        }

        $header = pack('C', ($fin ? 0x80 : 0) | $opcode->value);
        $maskBit = $mask === null ? 0 : 0x80;

        if ($length <= 125) {
            $header .= pack('C', $maskBit | $length);
        } elseif ($length <= 0xFFFF) {
            $header .= pack('Cn', $maskBit | 126, $length);
        } else {
            $header .= pack('CJ', $maskBit | 127, $length);
        }

        if ($mask === null) {
            return $header.$payload;
        }
        if (strlen($mask) !== 4) {
            throw new WebSocketException('Mask must be exactly 4 bytes');
        }

        return $header.$mask.self::applyMask($payload, $mask);
    }

    public static function close(int $code, string $reason = '', ?string $mask = null): string
    {
        // Truncate so the frame stays within the 125-byte control frame limit.
        $reason = mb_strcut($reason, 0, 123, 'UTF-8');

        return self::encode(Opcode::Close, pack('n', $code).$reason, true, $mask);
    }

    public static function applyMask(string $payload, string $mask): string
    {
        $length = strlen($payload);
        if ($length === 0) {
            return '';
        }

        // XOR with the mask repeated to the payload length: one C-level op instead of a byte loop.
        return $payload ^ str_repeat($mask, intdiv($length, 4) + 1);
    }
}
