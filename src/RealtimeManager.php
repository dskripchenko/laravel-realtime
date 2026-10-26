<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime;

use Closure;
use Dskripchenko\Realtime\Auth\IssuedTicket;
use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Broker\Broker;
use Dskripchenko\Realtime\Channels\ChannelAuthorizer;
use Dskripchenko\Realtime\Channels\ChannelName;
use Dskripchenko\Realtime\Channels\ChannelRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;

/**
 * Host-facing API: channel authorization, publishing and tickets.
 */
class RealtimeManager
{
    /** @var (Closure(): string)|null */
    private ?Closure $scopeResolver = null;

    public function __construct(
        private readonly Closure $brokerFactory,
        private readonly ChannelRegistry $registry,
        private readonly ChannelAuthorizer $authorizer,
        private readonly Config $config,
    ) {}

    /**
     * Registers an authorization callback, like Broadcast::channel(): Realtime::channel('board.{id}', fn ($user, $id) => ...).
     * Return true/false for private channels, or an array of member info for presence channels.
     * Callbacks run inside the realtime server process, so they must not rely on request state.
     */
    public function channel(string $pattern, callable $callback): static
    {
        $this->registry->register($pattern, $callback);

        return $this;
    }

    /**
     * Wraps user resolution and every channel callback, e.g. to enter the ticket's tenant:
     * Realtime::authorizeUsing(fn (Ticket $ticket, Closure $next) => Tenant::run($ticket->scope, $next)).
     *
     * @param  (Closure(Ticket, Closure(): mixed): mixed)|null  $around
     */
    public function authorizeUsing(?Closure $around): static
    {
        $this->authorizer->around($around);

        return $this;
    }

    /**
     * Overrides how the server loads the ticket's user (default: the guard's user provider).
     *
     * @param  Closure(Ticket): ?Authenticatable  $resolver
     */
    public function resolveUserUsing(Closure $resolver): static
    {
        $this->authorizer->resolveUserUsing($resolver);

        return $this;
    }

    /**
     * Tells the package the current scope (tenant) for tickets and publications.
     *
     * @param  (Closure(): string)|null  $resolver
     */
    public function resolveScopeUsing(?Closure $resolver): static
    {
        $this->scopeResolver = $resolver;

        return $this;
    }

    public function currentScope(): string
    {
        $configured = $this->config->get('realtime.scope', '');
        $scope = $this->scopeResolver !== null ? ($this->scopeResolver)() : (is_string($configured) ? $configured : '');
        ChannelName::assertValidScope($scope);

        return $scope;
    }

    /**
     * Publishes an event to everyone subscribed to the channel in the scope (current scope by default).
     *
     * @param  string|null  $except  socket id to skip (the sender's X-Socket-ID)
     * @return int the event's sequence number in the channel
     */
    public function publish(string $channel, string $event, mixed $data = [], ?string $scope = null, ?string $except = null): int
    {
        ChannelName::assertValid($channel);
        $scope ??= $this->currentScope();
        ChannelName::assertValidScope($scope);

        $message = ['type' => 'event', 'channel' => $channel, 'event' => $event, 'data' => $data];
        if ($except !== null && $except !== '') {
            $message['except'] = $except;
        }

        return $this->broker()->publish(ChannelName::key($scope, $channel), $message);
    }

    public function issueTicket(Authenticatable $user, ?string $guard = null, ?string $scope = null, ?Request $request = null): IssuedTicket
    {
        $id = $user->getAuthIdentifier();
        if (! is_int($id) && ! is_string($id)) {
            throw new \InvalidArgumentException('User identifier must be an integer or a string');
        }
        $scope ??= $this->currentScope();
        ChannelName::assertValidScope($scope);

        $ttl = $this->configInt('realtime.ticket.ttl', 30);
        $token = bin2hex(random_bytes(32));
        $this->broker()->storeTicket($token, new Ticket($id, $guard, $scope, time()), $ttl);

        return new IssuedTicket($token, $this->publicUrl($request), $ttl);
    }

    public function broker(): Broker
    {
        /** @var Broker */
        return ($this->brokerFactory)();
    }

    public function registry(): ChannelRegistry
    {
        return $this->registry;
    }

    public function authorizer(): ChannelAuthorizer
    {
        return $this->authorizer;
    }

    private function publicUrl(?Request $request): ?string
    {
        $url = $this->config->get('realtime.public_url');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        if ($request === null) {
            return null;
        }

        return ($request->isSecure() ? 'wss' : 'ws').'://'.$request->getHost().':'.$this->configInt('realtime.server.port', 6001);
    }

    private function configInt(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
