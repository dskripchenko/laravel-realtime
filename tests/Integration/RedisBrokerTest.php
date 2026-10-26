<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Integration;

use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Broker\RedisBroker;
use Dskripchenko\Realtime\Tests\TestCase;
use Redis;

/**
 * Replay buffer, tickets and presence bookkeeping against a real Redis/Valkey.
 */
final class RedisBrokerTest extends TestCase
{
    private Redis $redis;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redis = new Redis;
        $this->redis->connect('127.0.0.1', self::redisPort());
        $this->prefix = 'rt-unit-'.bin2hex(random_bytes(4)).':';
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = $this->redis->keys($this->prefix.'*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        }
        parent::tearDown();
    }

    public function test_sequence_numbers_and_replay(): void
    {
        $broker = $this->broker(bufferSize: 10);
        $this->assertSame(0, $broker->head('s/news'));

        foreach (range(1, 5) as $i) {
            $this->assertSame($i, $broker->publish('s/news', ['type' => 'event', 'channel' => 'news', 'event' => 'E', 'data' => ['i' => $i]]));
        }
        $this->assertSame(5, $broker->head('s/news'));

        $replay = $broker->replay('s/news', 2, 10);
        $this->assertFalse($replay->gap);
        $this->assertSame(5, $replay->head);
        $this->assertSame([
            '{"seq":3,"type":"event","channel":"news","event":"E","data":{"i":3}}',
            '{"seq":4,"type":"event","channel":"news","event":"E","data":{"i":4}}',
            '{"seq":5,"type":"event","channel":"news","event":"E","data":{"i":5}}',
        ], $replay->messages);

        $upToDate = $broker->replay('s/news', 5, 10);
        $this->assertFalse($upToDate->gap);
        $this->assertSame([], $upToDate->messages);
    }

    public function test_replay_reports_gap_when_too_far_behind(): void
    {
        $broker = $this->broker();
        foreach (range(1, 20) as $i) {
            $broker->publish('s/news', ['type' => 'event', 'channel' => 'news', 'event' => 'E', 'data' => $i]);
        }

        $this->assertTrue($broker->replay('s/news', 1, 10)->gap, 'more missed events than the replay limit');
        $this->assertFalse($broker->replay('s/news', 10, 10)->gap);
        $this->assertTrue($broker->replay('s/news', 99, 10)->gap, 'client ahead of the server means the channel was reset');
    }

    public function test_replay_reports_gap_when_the_log_was_trimmed(): void
    {
        $broker = $this->broker();
        foreach (range(1, 5) as $i) {
            $broker->publish('s/news', ['type' => 'event', 'channel' => 'news', 'event' => 'E', 'data' => $i]);
        }
        $this->redis->rawCommand('XTRIM', $this->prefix.'log:s/news', 'MAXLEN', '2');

        $this->assertTrue($broker->replay('s/news', 1, 100)->gap);
        $this->assertFalse($broker->replay('s/news', 3, 100)->gap);
    }

    public function test_capped_log_and_retention(): void
    {
        $broker = $this->broker(bufferSize: 5, retention: 60);
        foreach (range(1, 300) as $i) {
            $broker->publish('s/busy', ['type' => 'event', 'channel' => 'busy', 'event' => 'E', 'data' => $i]);
        }

        $length = $this->redis->rawCommand('XLEN', $this->prefix.'log:s/busy');
        $this->assertIsInt($length);
        // MAXLEN ~ trims whole radix-tree nodes, so the log is bounded but may exceed the nominal size.
        $this->assertLessThan(300, $length);
        $this->assertGreaterThanOrEqual(5, $length);
        $ttl = $this->redis->ttl($this->prefix.'log:s/busy');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    public function test_sequence_restart_clears_a_stale_log(): void
    {
        $broker = $this->broker();
        $broker->publish('s/x', ['type' => 'event', 'channel' => 'x', 'event' => 'E', 'data' => 1]);
        $broker->publish('s/x', ['type' => 'event', 'channel' => 'x', 'event' => 'E', 'data' => 2]);
        $this->redis->del($this->prefix.'seq:s/x');

        $this->assertSame(1, $broker->publish('s/x', ['type' => 'event', 'channel' => 'x', 'event' => 'E', 'data' => 3]));
    }

    public function test_scopes_do_not_share_sequences(): void
    {
        $broker = $this->broker();
        $broker->publish('a/news', ['type' => 'event', 'channel' => 'news', 'event' => 'E', 'data' => 1]);

        $this->assertSame(0, $broker->head('b/news'));
        $this->assertNotSame($broker->pubsubChannel('a/news'), $broker->pubsubChannel('b/news'));
        $this->assertSame('a/news', $broker->keyFromPubsubChannel($broker->pubsubChannel('a/news')));
        $this->assertNull($broker->keyFromPubsubChannel('other:ch:a/news'));
    }

    public function test_tickets_are_single_use_and_expire(): void
    {
        $broker = $this->broker();
        $broker->storeTicket('token-1', new Ticket(5, 'web', 'acme', 1), 30);

        $this->assertSame([], $this->redis->keys($this->prefix.'ticket:token-1'), 'raw token is never a key');
        $ticket = $broker->consumeTicket('token-1');
        $this->assertNotNull($ticket);
        $this->assertSame(5, $ticket->userId);
        $this->assertSame('acme', $ticket->scope);
        $this->assertNull($broker->consumeTicket('token-1'));

        $broker->storeTicket('token-2', new Ticket(5, null, '', 1), 1);
        $this->assertGreaterThan(0, $this->redis->ttl($this->prefix.'ticket:'.hash('sha256', 'token-2')));
    }

    public function test_presence_counts_connections_per_member(): void
    {
        $broker = $this->broker();
        $ann = ['id' => 1, 'info' => ['name' => 'Ann']];

        $this->assertTrue($broker->presenceJoin('s/presence-p', $ann, 'srv-a'));
        $this->assertFalse($broker->presenceJoin('s/presence-p', $ann, 'srv-b'), 'second tab of the same user');
        $this->assertTrue($broker->presenceJoin('s/presence-p', ['id' => 2, 'info' => []], 'srv-a'));
        $this->assertCount(2, $broker->presenceMembers('s/presence-p'));

        $this->assertFalse($broker->presenceLeave('s/presence-p', 1, 'srv-a'));
        $this->assertTrue($broker->presenceLeave('s/presence-p', 1, 'srv-b'));
        $this->assertSame([['id' => 2, 'info' => []]], $broker->presenceMembers('s/presence-p'));
    }

    public function test_dead_server_presence_is_reaped_once(): void
    {
        $broker = $this->broker();
        $broker->heartbeat('alive', 30);
        $broker->heartbeat('dead', 30);
        $broker->presenceJoin('s/presence-p', ['id' => 1, 'info' => []], 'dead');
        $broker->presenceJoin('s/presence-p', ['id' => 1, 'info' => []], 'dead');
        $broker->presenceJoin('s/presence-p', ['id' => 2, 'info' => []], 'alive');
        $this->redis->del($this->prefix.'server:dead');

        $this->assertSame([['key' => 's/presence-p', 'id' => '1']], $broker->reapDeadServers('alive'));
        $this->assertSame([['id' => 2, 'info' => []]], $broker->presenceMembers('s/presence-p'));
        $this->assertSame([], $broker->reapDeadServers('alive'));
        $this->assertSame(['alive'], $this->redis->sMembers($this->prefix.'servers'));
    }

    private function broker(int $bufferSize = 100, int $retention = 3600): RedisBroker
    {
        return new RedisBroker($this->redis, $this->prefix, $bufferSize, $retention);
    }
}
