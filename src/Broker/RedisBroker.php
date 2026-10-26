<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Broker;

use Dskripchenko\Realtime\Auth\Ticket;
use Redis;
use RuntimeException;

/**
 * Redis / Valkey implementation over a phpredis client.
 *
 * Commands go through rawCommand() on purpose: phpredis' OPT_PREFIX and serializer settings of the host's
 * connection would otherwise rewrite keys and values differently from the pub/sub socket of the server.
 */
final class RedisBroker implements Broker
{
    // Sequence, replay log and fan-out in one atomic step so every subscriber sees the same order.
    private const PUBLISH = <<<'LUA'
local seq = redis.call('INCR', KEYS[1])
if seq == 1 then redis.call('DEL', KEYS[2]) end
local msg = '{"seq":' .. seq .. ',' .. string.sub(ARGV[2], 2)
redis.call('XADD', KEYS[2], 'MAXLEN', '~', ARGV[3], seq .. '-1', 'm', msg)
redis.call('EXPIRE', KEYS[1], ARGV[4])
redis.call('EXPIRE', KEYS[2], ARGV[4])
redis.call('PUBLISH', ARGV[1], msg)
return seq
LUA;

    private const REPLAY = <<<'LUA'
local head = tonumber(redis.call('GET', KEYS[1]) or '0')
local after = tonumber(ARGV[1])
if after == head then return {head, 0} end
if after > head or head - after > tonumber(ARGV[2]) then return {head, 1} end
local entries = redis.call('XRANGE', KEYS[2], (after + 1) .. '-1', '+')
if #entries < head - after then return {head, 1} end
local out = {head, 0}
for i, entry in ipairs(entries) do out[#out + 1] = entry[2][2] end
return out
LUA;

    private const JOIN = <<<'LUA'
local c = redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
redis.call('HSET', KEYS[2], ARGV[1], ARGV[2])
redis.call('HINCRBY', KEYS[3], ARGV[3], 1)
return c
LUA;

    private const LEAVE = <<<'LUA'
local n = tonumber(ARGV[3])
local c = redis.call('HINCRBY', KEYS[1], ARGV[1], -n)
local s = redis.call('HINCRBY', KEYS[3], ARGV[2], -n)
if s <= 0 then redis.call('HDEL', KEYS[3], ARGV[2]) end
if c <= 0 then
  redis.call('HDEL', KEYS[1], ARGV[1])
  redis.call('HDEL', KEYS[2], ARGV[1])
end
return c
LUA;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'realtime:',
        private readonly int $bufferSize = 100,
        private readonly int $retention = 86400,
    ) {}

    public function publish(string $key, array $message): int
    {
        $result = $this->command('EVAL', self::PUBLISH, 2, $this->prefix.'seq:'.$key, $this->prefix.'log:'.$key,
            $this->pubsubChannel($key), $this->encode($message), (string) $this->bufferSize, (string) $this->retention);

        return $this->toInt($result);
    }

    public function publishVolatile(string $key, array $message): void
    {
        $this->command('PUBLISH', $this->pubsubChannel($key), '{"seq":null,'.substr($this->encode($message), 1));
    }

    public function head(string $key): int
    {
        $value = $this->command('GET', $this->prefix.'seq:'.$key);

        return $value === false || $value === null ? 0 : $this->toInt($value);
    }

    public function replay(string $key, int $afterSeq, int $limit): ReplayResult
    {
        $result = $this->command('EVAL', self::REPLAY, 2, $this->prefix.'seq:'.$key, $this->prefix.'log:'.$key,
            (string) $afterSeq, (string) $limit);
        if (! is_array($result) || count($result) < 2) {
            throw new RuntimeException('Unexpected replay reply');
        }
        $head = $this->toInt(array_shift($result));
        $gap = $this->toInt(array_shift($result)) === 1;
        $messages = [];
        foreach ($result as $message) {
            if (is_string($message)) {
                $messages[] = $message;
            }
        }

        return new ReplayResult($head, $gap, $messages);
    }

    public function storeTicket(string $token, Ticket $ticket, int $ttl): void
    {
        $this->command('SET', $this->ticketKey($token), $this->encode($ticket->toArray()), 'EX', (string) $ttl);
    }

    public function consumeTicket(string $token): ?Ticket
    {
        $value = $this->command('GETDEL', $this->ticketKey($token));

        return is_string($value) ? Ticket::fromJson($value) : null;
    }

    public function presenceJoin(string $key, array $member, string $serverId): bool
    {
        $id = (string) $member['id'];
        $count = $this->command('EVAL', self::JOIN, 3,
            $this->prefix.'presence:'.$key, $this->prefix.'presence-info:'.$key, $this->prefix.'server-presence:'.$serverId,
            $id, $this->encode($member), $key."\n".$id);

        return $this->toInt($count) === 1;
    }

    public function presenceLeave(string $key, int|string $memberId, string $serverId, int $count = 1): bool
    {
        $id = (string) $memberId;
        $left = $this->command('EVAL', self::LEAVE, 3,
            $this->prefix.'presence:'.$key, $this->prefix.'presence-info:'.$key, $this->prefix.'server-presence:'.$serverId,
            $id, $key."\n".$id, (string) $count);

        return $this->toInt($left) <= 0;
    }

    public function presenceMembers(string $key): array
    {
        $raw = $this->command('HVALS', $this->prefix.'presence-info:'.$key);
        $members = [];
        foreach (is_array($raw) ? $raw : [] as $json) {
            $member = is_string($json) ? json_decode($json, true) : null;
            if (is_array($member) && (is_int($member['id'] ?? null) || is_string($member['id'] ?? null))) {
                $members[] = ['id' => $member['id'], 'info' => is_array($member['info'] ?? null) ? $member['info'] : []];
            }
        }

        return $members;
    }

    public function heartbeat(string $serverId, int $ttl): void
    {
        $this->command('SET', $this->prefix.'server:'.$serverId, (string) time(), 'EX', (string) $ttl);
        $this->command('SADD', $this->prefix.'servers', $serverId);
    }

    public function reapDeadServers(string $selfId): array
    {
        $left = [];
        $servers = $this->command('SMEMBERS', $this->prefix.'servers');
        foreach (is_array($servers) ? $servers : [] as $serverId) {
            if (! is_string($serverId) || $serverId === $selfId) {
                continue;
            }
            if ($this->toInt($this->command('EXISTS', $this->prefix.'server:'.$serverId)) === 1) {
                continue;
            }
            // Only one survivor may reap a given server, otherwise counts would be decremented twice.
            if ($this->command('SET', $this->prefix.'reap:'.$serverId, $selfId, 'NX', 'EX', '60') !== true) {
                continue;
            }
            $entries = $this->command('HGETALL', $this->prefix.'server-presence:'.$serverId);
            $entries = is_array($entries) ? array_values($entries) : [];
            for ($i = 0; $i + 1 < count($entries); $i += 2) {
                $field = $entries[$i];
                $count = $entries[$i + 1];
                if (! is_string($field) || ! str_contains($field, "\n")) {
                    continue;
                }
                [$key, $memberId] = explode("\n", $field, 2);
                if ($this->presenceLeave($key, $memberId, $serverId, max(1, $this->toInt($count)))) {
                    $left[] = ['key' => $key, 'id' => $memberId];
                }
            }
            $this->unregisterServer($serverId);
        }

        return $left;
    }

    public function unregisterServer(string $serverId): void
    {
        $this->command('DEL', $this->prefix.'server-presence:'.$serverId, $this->prefix.'server:'.$serverId);
        $this->command('SREM', $this->prefix.'servers', $serverId);
    }

    public function pubsubChannel(string $key): string
    {
        return $this->prefix.'ch:'.$key;
    }

    public function keyFromPubsubChannel(string $channel): ?string
    {
        $prefix = $this->prefix.'ch:';

        return str_starts_with($channel, $prefix) ? substr($channel, strlen($prefix)) : null;
    }

    private function ticketKey(string $token): string
    {
        // Only a hash is stored, so a Redis dump does not hand out usable tickets.
        return $this->prefix.'ticket:'.hash('sha256', $token);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    private function command(string $command, int|string ...$args): mixed
    {
        $result = $this->redis->rawCommand($command, ...$args);
        if ($result === false && ($error = $this->redis->getLastError()) !== null) {
            $this->redis->clearLastError();
            throw new RuntimeException("Redis {$command} failed: {$error}");
        }

        return $result;
    }

    private function toInt(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }
}
