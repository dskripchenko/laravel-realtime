<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Unit;

use Closure;
use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Channels\ChannelAuthorizer;
use Dskripchenko\Realtime\Channels\ChannelName;
use Dskripchenko\Realtime\Channels\ChannelRegistry;
use Dskripchenko\Realtime\Channels\ChannelType;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChannelAuthorizationTest extends TestCase
{
    public function test_channel_types_and_bare_names(): void
    {
        $this->assertSame(ChannelType::Public, ChannelName::type('news'));
        $this->assertSame(ChannelType::Private, ChannelName::type('private-board.1'));
        $this->assertSame(ChannelType::Presence, ChannelName::type('presence-page.1'));
        $this->assertSame('board.1', ChannelName::bare('private-board.1'));
        $this->assertSame('page.1', ChannelName::bare('presence-page.1'));
        $this->assertSame('news', ChannelName::bare('news'));
        $this->assertSame('acme/private-board.1', ChannelName::key('acme', 'private-board.1'));
    }

    public function test_channel_and_scope_validation(): void
    {
        $this->assertTrue(ChannelName::isValid('private-App.Models.User.1'));
        $this->assertFalse(ChannelName::isValid(''));
        $this->assertFalse(ChannelName::isValid('a/b'), 'slash would break scope keys');
        $this->assertFalse(ChannelName::isValid('a b'));
        $this->assertFalse(ChannelName::isValid(str_repeat('a', 201)));
        $this->assertTrue(ChannelName::isValidScope(''));
        $this->assertTrue(ChannelName::isValidScope('tenant_42'));
        $this->assertFalse(ChannelName::isValidScope('a/b'));

        $this->expectException(InvalidArgumentException::class);
        ChannelName::assertValidScope('evil/scope');
    }

    public function test_registry_extracts_parameters(): void
    {
        $registry = new ChannelRegistry;
        $registry->register('board.{board}.column.{column}', static fn () => true);
        $registry->register('App.Models.User.{id}', static fn () => true);

        $this->assertSame(['5', 'todo'], $this->params($registry, 'board.5.column.todo'));
        $this->assertSame(['7'], $this->params($registry, 'App.Models.User.7'));
        $this->assertNull($registry->match('board.5'));
        $this->assertNull($registry->match('board.5.column.a.b'), 'a parameter never spans a dot');
        $this->assertNull($registry->match('AppXModels.User.7'), 'dots in patterns are literal');
    }

    public function test_public_channels_need_no_callback(): void
    {
        $this->assertTrue($this->authorizer(new ChannelRegistry)->authorize($this->ticket(), 'news')->allowed);
    }

    public function test_private_channel_without_callback_is_denied(): void
    {
        $result = $this->authorizer(new ChannelRegistry)->authorize($this->ticket(), 'private-board.1');
        $this->assertFalse($result->allowed);
        $this->assertSame('no_authorizer', $result->reason);
    }

    public function test_private_channel_callback_receives_user_and_params(): void
    {
        $registry = new ChannelRegistry;
        $seen = [];
        $registry->register('board.{id}', static function (Authenticatable $user, string $id) use (&$seen): bool {
            $seen = [$user->getAuthIdentifier(), $id];

            return $id === '1';
        });
        $authorizer = $this->authorizer($registry);

        $this->assertTrue($authorizer->authorize($this->ticket(), 'private-board.1')->allowed);
        $this->assertSame([42, '1'], $seen);
        $this->assertFalse($authorizer->authorize($this->ticket(), 'private-board.2')->allowed);
    }

    public function test_presence_callback_returns_member_info(): void
    {
        $registry = new ChannelRegistry;
        $registry->register('page.{id}', static fn (Authenticatable $user) => ['name' => 'Ann']);
        $registry->register('doc.{id}', static fn () => true);

        $result = $this->authorizer($registry)->authorize($this->ticket(), 'presence-page.1');
        $this->assertTrue($result->allowed);
        $this->assertSame(['id' => 42, 'info' => ['name' => 'Ann']], $result->member);
        $this->assertSame(['id' => 42, 'info' => []], $this->authorizer($registry)->authorize($this->ticket(), 'presence-doc.1')->member);
    }

    public function test_unknown_user_is_denied(): void
    {
        $registry = new ChannelRegistry;
        $registry->register('board.{id}', static fn () => true);
        $authorizer = new ChannelAuthorizer($registry, static fn (): ?Authenticatable => null);

        $this->assertSame('unknown_user', $authorizer->authorize($this->ticket(), 'private-board.1')->reason);
    }

    public function test_around_hook_wraps_user_resolution_and_callback(): void
    {
        $log = [];
        $registry = new ChannelRegistry;
        $registry->register('board.{id}', static function () use (&$log): bool {
            $log[] = 'callback';

            return true;
        });
        $authorizer = new ChannelAuthorizer($registry, static function (Ticket $ticket) use (&$log): Authenticatable {
            $log[] = 'user';

            return new GenericUser(['id' => $ticket->userId]);
        });
        $authorizer->around(static function (Ticket $ticket, Closure $next) use (&$log): mixed {
            $log[] = 'enter '.$ticket->scope;
            try {
                return $next();
            } finally {
                $log[] = 'leave';
            }
        });

        $this->assertTrue($authorizer->authorize($this->ticket('acme'), 'private-board.1')->allowed);
        $this->assertSame(['enter acme', 'user', 'callback', 'leave'], $log);
    }

    public function test_around_hook_must_return_the_result(): void
    {
        $registry = new ChannelRegistry;
        $registry->register('board.{id}', static fn () => true);
        $authorizer = $this->authorizer($registry);
        $authorizer->around(static function (Ticket $ticket, Closure $next): mixed {
            $next();

            return null;
        });

        $this->assertFalse($authorizer->authorize($this->ticket(), 'private-board.1')->allowed);
    }

    public function test_exceptions_deny_and_are_reported(): void
    {
        $registry = new ChannelRegistry;
        $registry->register('board.{id}', static fn () => throw new RuntimeException('db down'));
        $authorizer = $this->authorizer($registry);
        $reported = [];
        $authorizer->reportUsing(static function (\Throwable $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $result = $authorizer->authorize($this->ticket(), 'private-board.1');
        $this->assertFalse($result->allowed);
        $this->assertSame('error', $result->reason);
        $this->assertSame(['db down'], $reported);
    }

    public function test_ticket_json_round_trip(): void
    {
        $ticket = new Ticket('u-1', 'web', 'acme', 1700000000);
        $copy = Ticket::fromJson((string) json_encode($ticket->toArray()));
        $this->assertEquals($ticket, $copy);
        $this->assertNull(Ticket::fromJson('{"uid":[],"scope":"x"}'));
        $this->assertNull(Ticket::fromJson('nope'));
    }

    private function authorizer(ChannelRegistry $registry): ChannelAuthorizer
    {
        return new ChannelAuthorizer($registry, static fn (Ticket $ticket): Authenticatable => new GenericUser(['id' => $ticket->userId]));
    }

    private function ticket(string $scope = ''): Ticket
    {
        return new Ticket(42, null, $scope, time());
    }

    /**
     * @return list<string>|null
     */
    private function params(ChannelRegistry $registry, string $name): ?array
    {
        return $registry->match($name)[1] ?? null;
    }
}
