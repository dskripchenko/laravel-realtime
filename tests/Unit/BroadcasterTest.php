<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests\Unit;

use Dskripchenko\Realtime\Broker\Broker;
use Dskripchenko\Realtime\Facades\Realtime;
use Dskripchenko\Realtime\Tests\Support\FakeBroker;
use Dskripchenko\Realtime\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

final class BroadcasterTest extends TestCase
{
    private FakeBroker $broker;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('broadcasting.default', 'realtime');
        $app['config']->set('broadcasting.connections.realtime', ['driver' => 'realtime']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new FakeBroker;
        $this->app?->instance(Broker::class, $this->broker);
    }

    public function test_should_broadcast_events_reach_the_broker_unchanged(): void
    {
        Realtime::resolveScopeUsing(static fn (): string => 'acme');

        event(new CardMoved(5));

        $this->assertSame([
            ['key' => 'acme/private-board.1', 'message' => ['type' => 'event', 'channel' => 'private-board.1', 'event' => 'card.moved', 'data' => ['card' => 5]]],
            ['key' => 'acme/presence-page.2', 'message' => ['type' => 'event', 'channel' => 'presence-page.2', 'event' => 'card.moved', 'data' => ['card' => 5]]],
            ['key' => 'acme/news', 'message' => ['type' => 'event', 'channel' => 'news', 'event' => 'card.moved', 'data' => ['card' => 5]]],
        ], $this->broker->published);
    }

    public function test_socket_id_becomes_except(): void
    {
        Broadcast::connection('realtime')->broadcast(['news'], 'Ping', ['x' => 1, 'socket' => '1.2']);

        $this->assertSame(['type' => 'event', 'channel' => 'news', 'event' => 'Ping', 'data' => ['x' => 1], 'except' => '1.2'],
            $this->broker->published[0]['message']);
        $this->assertSame('/news', $this->broker->published[0]['key'], 'default scope is the configured empty string');
    }

    public function test_fixed_scope_from_connection_config(): void
    {
        config()->set('broadcasting.connections.central', ['driver' => 'realtime', 'scope' => 'central']);
        Broadcast::connection('central')->broadcast(['news'], 'Ping', []);

        $this->assertSame('central/news', $this->broker->published[0]['key']);
    }

    public function test_facade_publish_with_explicit_scope(): void
    {
        $seq = Realtime::publish('private-board.1', 'Updated', ['a' => 1], 'tenant_7');

        $this->assertSame(1, $seq);
        $this->assertSame('tenant_7/private-board.1', $this->broker->published[0]['key']);
    }

    public function test_publish_rejects_invalid_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Realtime::publish('bad channel', 'x');
    }

    public function test_ticket_endpoint_requires_a_user_and_stores_a_ticket(): void
    {
        Realtime::resolveScopeUsing(static fn (): string => 'acme');
        config()->set('realtime.public_url', 'wss://rt.example.com');

        $this->postJson('/realtime/ticket')->assertStatus(401);

        $response = $this->actingAs(new GenericUser(['id' => 9]))->postJson('/realtime/ticket')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['ticket', 'url', 'expires_in']);

        $this->assertSame('wss://rt.example.com', $response->json('url'));
        $this->assertSame(30, $response->json('expires_in'));
        $ticket = $this->broker->consumeTicket((string) $response->json('ticket'));
        $this->assertNotNull($ticket);
        $this->assertSame(9, $ticket->userId);
        $this->assertSame('acme', $ticket->scope);
        $this->assertTrue(Route::has('realtime.ticket'));
    }
}

final class CardMoved implements ShouldBroadcastNow
{
    public function __construct(public int $card) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.1'), new PresenceChannel('page.2'), new Channel('news')];
    }

    public function broadcastAs(): string
    {
        return 'card.moved';
    }
}
