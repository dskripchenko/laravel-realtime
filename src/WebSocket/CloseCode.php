<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\WebSocket;

/**
 * Close status codes (RFC 6455, section 7.4) plus the private range used by the protocol.
 */
final class CloseCode
{
    public const NORMAL = 1000;

    public const GOING_AWAY = 1001;

    public const PROTOCOL_ERROR = 1002;

    public const UNSUPPORTED_DATA = 1003;

    public const NO_STATUS = 1005;

    public const ABNORMAL = 1006;

    public const INVALID_PAYLOAD = 1007;

    public const POLICY_VIOLATION = 1008;

    public const MESSAGE_TOO_BIG = 1009;

    public const INTERNAL_ERROR = 1011;

    public const TRY_AGAIN_LATER = 1013;

    /** The peer could not keep up with outgoing traffic. */
    public const SLOW_CONSUMER = 4008;

    /** No traffic within the idle timeout. */
    public const IDLE_TIMEOUT = 4000;

    /**
     * Whether a code may appear on the wire in a close frame.
     * 1005, 1006 and 1015 are reserved for local reporting only.
     */
    public static function isValidOnWire(int $code): bool
    {
        if ($code >= 3000 && $code <= 4999) {
            return true;
        }

        return in_array($code, [1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014], true);
    }
}
