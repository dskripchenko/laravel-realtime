<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Protocol;

/**
 * Builders for server -> client messages. Event messages are produced by the broker (they carry "seq").
 */
final class ServerMessage
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function welcome(string $socketId, int $heartbeat): string
    {
        return self::encode(['type' => 'welcome', 'socket_id' => $socketId, 'heartbeat' => $heartbeat]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function ack(int|string|null $id, ?string $channel, array $extra = []): string
    {
        return self::encode(array_filter(['type' => 'ack', 'id' => $id, 'channel' => $channel], static fn ($v) => $v !== null) + $extra);
    }

    public static function error(int|string|null $id, string $code, string $message, ?string $channel = null): string
    {
        return self::encode(array_filter(
            ['type' => 'error', 'id' => $id, 'channel' => $channel, 'code' => $code, 'message' => $message],
            static fn ($v) => $v !== null,
        ));
    }

    public static function pong(int|string|null $id): string
    {
        return self::encode(array_filter(['type' => 'pong', 'id' => $id], static fn ($v) => $v !== null));
    }

    /**
     * @param  list<array{id: int|string, info: array<mixed>}>  $members
     */
    public static function presenceList(int|string|null $id, string $channel, array $members): string
    {
        return self::encode(array_filter(
            ['type' => 'presence', 'id' => $id, 'channel' => $channel, 'action' => 'list', 'members' => $members],
            static fn ($v) => $v !== null,
        ));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public static function encode(array $message): string
    {
        return json_encode($message, self::FLAGS);
    }
}
