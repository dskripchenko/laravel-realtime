<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Unit;

use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Broker\Broker;
use Dskripchenko\Realtime\Channels\ChannelName;
use Dskripchenko\Realtime\Facades\Realtime;
use Dskripchenko\Realtime\RealtimeManager;
use Dskripchenko\Realtime\Tests\Support\FakeBroker;
use Dskripchenko\Realtime\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class TicketTest extends TestCase
{
    private FakeBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new FakeBroker;
        $this->app?->instance(Broker::class, $this->broker);
    }

    public function test_round_trip_keeps_claims_and_expiry(): void
    {
        $claims = ['token' => 42, 'abilities' => ['read', 'write'], 'name' => 'CI', 'ratio' => 0.5, 'off' => null, 'on' => true];
        $ticket = new Ticket(7, 'api', 'acme', 1_700_000_000, $claims, 1_700_003_600);

        $json = json_encode($ticket->toArray(), JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['uid' => 7, 'guard' => 'api', 'scope' => 'acme', 'iat' => 1_700_000_000, 'claims' => $claims, 'exp' => 1_700_003_600],
            json_decode($json, true),
        );

        $back = Ticket::fromJson($json) ?? $this->fail('round trip failed');
        $this->assertSame([7, 'api', 'acme', 1_700_000_000, $claims, 1_700_003_600],
            [$back->userId, $back->guard, $back->scope, $back->issuedAt, $back->claims, $back->expiresAt]);
    }

    public function test_defaults_are_no_claims_and_no_expiry(): void
    {
        $ticket = new Ticket('u-1', null, '', 100);

        $this->assertSame([], $ticket->claims);
        $this->assertNull($ticket->expiresAt);
        $this->assertFalse($ticket->expired());
        $back = Ticket::fromJson(json_encode($ticket->toArray(), JSON_THROW_ON_ERROR)) ?? $this->fail();
        $this->assertSame([[], null], [$back->claims, $back->expiresAt]);
    }

    public function test_tickets_stored_before_claims_existed_are_accepted(): void
    {
        $ticket = Ticket::fromJson('{"uid":9,"guard":null,"scope":"acme","iat":123}') ?? $this->fail('old ticket rejected');

        $this->assertSame([9, null, 'acme', 123, [], null],
            [$ticket->userId, $ticket->guard, $ticket->scope, $ticket->issuedAt, $ticket->claims, $ticket->expiresAt]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'claims is a string' => ['{"uid":1,"scope":"","iat":1,"claims":"x"}'];
        yield 'claims is a number' => ['{"uid":1,"scope":"","iat":1,"claims":5}'];
        yield 'claims is a list' => ['{"uid":1,"scope":"","iat":1,"claims":["a","b"]}'];
        yield 'claims has a numeric key' => ['{"uid":1,"scope":"","iat":1,"claims":{"a":1,"7":2}}'];
        yield 'exp is a string' => ['{"uid":1,"scope":"","iat":1,"exp":"1700000000"}'];
        yield 'exp is a float' => ['{"uid":1,"scope":"","iat":1,"exp":1.5}'];
        yield 'exp is a bool' => ['{"uid":1,"scope":"","iat":1,"exp":true}'];
        yield 'no scope' => ['{"uid":1,"iat":1}'];
        yield 'not an object' => ['"ticket"'];
    }

    #[DataProvider('malformed')]
    public function test_malformed_tickets_are_rejected(string $json): void
    {
        $this->assertNull(Ticket::fromJson($json));
    }

    public function test_expired(): void
    {
        $ticket = new Ticket(1, null, '', 100, [], 200);

        $this->assertFalse($ticket->expired(199));
        $this->assertTrue($ticket->expired(200));
        $this->assertTrue($ticket->expired(201));
        $this->assertFalse((new Ticket(1, null, '', 100, [], time() + 60))->expired());
        $this->assertTrue((new Ticket(1, null, '', 100, [], time() - 1))->expired());
    }

    public function test_matches_requires_every_pair_strictly(): void
    {
        $ticket = new Ticket(1, null, '', 100, ['token' => 5, 'kind' => 'api', 'abilities' => ['read']]);

        $this->assertTrue($ticket->matches(['token' => 5]));
        $this->assertTrue($ticket->matches(['token' => 5, 'kind' => 'api']));
        $this->assertTrue($ticket->matches(['abilities' => ['read']]));
        $this->assertFalse($ticket->matches(['token' => '5']), 'strict comparison');
        $this->assertFalse($ticket->matches(['token' => 5, 'kind' => 'web']));
        $this->assertFalse($ticket->matches(['missing' => null]));
        $this->assertFalse($ticket->matches([]), 'empty claims never match');
        $this->assertFalse((new Ticket(1, null, '', 100))->matches(['token' => 5]));
    }

    public function test_issue_ticket_stores_claims_and_expiry(): void
    {
        $expiresAt = time() + 3600;
        $issued = Realtime::issueTicket(new GenericUser(['id' => 3]), 'api', 'acme', null, ['token' => 11, 'abilities' => ['read']], $expiresAt);

        $ticket = $this->broker->consumeTicket($issued->token) ?? $this->fail('ticket not stored');
        $this->assertSame([3, 'api', 'acme', ['token' => 11, 'abilities' => ['read']], $expiresAt],
            [$ticket->userId, $ticket->guard, $ticket->scope, $ticket->claims, $ticket->expiresAt]);

        $plain = Realtime::issueTicket(new GenericUser(['id' => 3]));
        $ticket = $this->broker->consumeTicket($plain->token) ?? $this->fail('ticket not stored');
        $this->assertSame([[], null], [$ticket->claims, $ticket->expiresAt]);
    }

    public function test_issue_ticket_rejects_an_expiry_that_is_not_in_the_future(): void
    {
        foreach ([time(), time() - 10] as $expiresAt) {
            try {
                Realtime::issueTicket(new GenericUser(['id' => 3]), expiresAt: $expiresAt);
                $this->fail('expiry in the past must be rejected');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->broker->tickets);
    }

    public function test_issue_ticket_rejects_claims_without_string_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Realtime::issueTicket(new GenericUser(['id' => 3]), claims: ['read', 'write']);
    }

    public function test_disconnect_publishes_a_control_message_on_the_reserved_key(): void
    {
        Realtime::resolveScopeUsing(static fn (): string => 'acme');

        Realtime::disconnect(['token' => 11]);
        Realtime::disconnect(['token' => 11, 'kind' => 'api'], 'tenant_7');

        $this->assertSame([
            ['key' => RealtimeManager::CONTROL_KEY, 'message' => ['type' => 'disconnect', 'scope' => 'acme', 'claims' => ['token' => 11]]],
            ['key' => RealtimeManager::CONTROL_KEY, 'message' => ['type' => 'disconnect', 'scope' => 'tenant_7', 'claims' => ['token' => 11, 'kind' => 'api']]],
        ], $this->broker->published);
    }

    public function test_disconnect_refuses_empty_claims(): void
    {
        try {
            Realtime::disconnect([]);
            $this->fail('empty claims must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must not be empty', $e->getMessage());
        }
        $this->assertSame([], $this->broker->published, 'nothing is published');
    }

    public function test_disconnect_rejects_list_claims_and_invalid_scopes(): void
    {
        foreach ([fn () => Realtime::disconnect(['token']), fn () => Realtime::disconnect(['token' => 1], 'bad scope')] as $call) {
            try {
                $call();
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->broker->published);
    }

    public function test_the_control_key_cannot_be_reached_by_a_client_subscription(): void
    {
        $key = RealtimeManager::CONTROL_KEY;

        $this->assertFalse(ChannelName::isValid($key), 'not a valid channel name');
        $this->assertFalse(ChannelName::isValidScope($key), 'not a valid scope');
        $this->assertStringNotContainsString('/', $key, 'every subscription key is "<scope>/<name>"');

        $this->expectException(InvalidArgumentException::class);
        Realtime::publish($key, 'x');
    }
}
