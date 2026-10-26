<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Support;

use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Broker\Broker;
use Dskripchenko\Realtime\Broker\ReplayResult;

/**
 * Records publications; enough for tests that do not need a real Redis.
 */
final class FakeBroker implements Broker
{
    /** @var list<array{key: string, message: array<string, mixed>}> */
    public array $published = [];

    /** @var array<string, Ticket> */
    public array $tickets = [];

    /** @var array<string, int> */
    private array $seq = [];

    public function publish(string $key, array $message): int
    {
        $this->published[] = ['key' => $key, 'message' => $message];

        return $this->seq[$key] = ($this->seq[$key] ?? 0) + 1;
    }

    public function publishVolatile(string $key, array $message): void
    {
        $this->published[] = ['key' => $key, 'message' => $message];
    }

    public function head(string $key): int
    {
        return $this->seq[$key] ?? 0;
    }

    public function replay(string $key, int $afterSeq, int $limit): ReplayResult
    {
        return new ReplayResult($this->head($key), false);
    }

    public function storeTicket(string $token, Ticket $ticket, int $ttl): void
    {
        $this->tickets[$token] = $ticket;
    }

    public function consumeTicket(string $token): ?Ticket
    {
        $ticket = $this->tickets[$token] ?? null;
        unset($this->tickets[$token]);

        return $ticket;
    }

    public function presenceJoin(string $key, array $member, string $serverId): bool
    {
        return true;
    }

    public function presenceLeave(string $key, int|string $memberId, string $serverId, int $count = 1): bool
    {
        return true;
    }

    public function presenceMembers(string $key): array
    {
        return [];
    }

    public function heartbeat(string $serverId, int $ttl): void {}

    public function reapDeadServers(string $selfId): array
    {
        return [];
    }

    public function unregisterServer(string $serverId): void {}

    public function pubsubChannel(string $key): string
    {
        return 'ch:'.$key;
    }

    public function keyFromPubsubChannel(string $channel): ?string
    {
        return str_starts_with($channel, 'ch:') ? substr($channel, 3) : null;
    }
}
