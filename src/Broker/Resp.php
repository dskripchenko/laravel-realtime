<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Broker;

use RuntimeException;

/**
 * RESP2 reply parser for the subscriber socket.
 */
final class Resp
{
    /**
     * Parses one value starting at $offset. Returns [value] and advances $offset, or null if incomplete.
     * Error replies come back as RuntimeException values.
     *
     * @return array{mixed}|null
     */
    public static function parse(string $buffer, int &$offset): ?array
    {
        $end = strpos($buffer, "\r\n", $offset);
        if ($end === false) {
            return null;
        }
        $type = $buffer[$offset] ?? '';
        $line = substr($buffer, $offset + 1, $end - $offset - 1);
        $next = $end + 2;

        switch ($type) {
            case '+':
                $offset = $next;

                return [$line];
            case '-':
                $offset = $next;

                return [new RuntimeException('Redis error: '.$line)];
            case ':':
                $offset = $next;

                return [(int) $line];
            case '$':
                $length = (int) $line;
                if ($length < 0) {
                    $offset = $next;

                    return [null];
                }
                if (strlen($buffer) < $next + $length + 2) {
                    return null;
                }
                $offset = $next + $length + 2;

                return [substr($buffer, $next, $length)];
            case '*':
                $count = (int) $line;
                if ($count < 0) {
                    $offset = $next;

                    return [null];
                }
                $items = [];
                $cursor = $next;
                for ($i = 0; $i < $count; $i++) {
                    $item = self::parse($buffer, $cursor);
                    if ($item === null) {
                        return null;
                    }
                    $items[] = $item[0];
                }
                $offset = $cursor;

                return [$items];
            default:
                throw new RuntimeException('Malformed RESP reply');
        }
    }
}
