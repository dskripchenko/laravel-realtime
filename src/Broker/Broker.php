<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Broker;

use Dskripchenko\Realtime\Auth\Ticket;

/**
 * Shared state between web processes and realtime servers. Keys are scoped channel keys ("<scope>/<name>").
 */
interface Broker
{
    /**
     * Publishes a buffered event: assigns the next sequence number, appends it to the replay log and fans it out.
     *
     * @param  array<string, mixed>  $message  client wire message without "seq"
     */
    public function publish(string $key, array $message): int;

    /**
     * Fans a message out without a sequence number or replay (presence notices, client events).
     *
     * @param  array<string, mixed>  $message
     */
    public function publishVolatile(string $key, array $message): void;

    public function head(string $key): int;

    /**
     * Returns the encoded messages after $afterSeq, or null when they can no longer be replayed (gap).
     */
    public function replay(string $key, int $afterSeq, int $limit): ReplayResult;

    public function storeTicket(string $token, Ticket $ticket, int $ttl): void;

    public function consumeTicket(string $token): ?Ticket;

    /**
     * @param  array{id: int|string, info: array<mixed>}  $member
     * @return bool true when this is the member's first connection to the channel
     */
    public function presenceJoin(string $key, array $member, string $serverId): bool;

    /**
     * @return bool true when the member has no connections left on the channel
     */
    public function presenceLeave(string $key, int|string $memberId, string $serverId, int $count = 1): bool;

    /**
     * @return list<array{id: int|string, info: array<mixed>}>
     */
    public function presenceMembers(string $key): array;

    public function heartbeat(string $serverId, int $ttl): void;

    /**
     * Removes presence entries of servers whose heartbeat expired.
     *
     * @return list<array{key: string, id: string}> members that left as a result
     */
    public function reapDeadServers(string $selfId): array;

    public function unregisterServer(string $serverId): void;

    /**
     * The pub/sub channel a server must SUBSCRIBE to for the given key.
     */
    public function pubsubChannel(string $key): string;

    /**
     * Inverse of pubsubChannel(), or null for foreign channels.
     */
    public function keyFromPubsubChannel(string $channel): ?string;
}
