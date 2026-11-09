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
    /**
     * Reserved event name of the control envelope published by reauthorize(). The server consumes it
     * and never forwards it to clients; the "realtime:" prefix is reserved for the package.
     */
    public const REAUTHORIZE = 'realtime:reauthorize';

    /**
     * Reserved broker key of the control channel every realtime server subscribes to (see disconnect()).
     * It has no "/" and starts with a character that channel names and scopes may not contain, so no
     * client subscription can ever map to it; its messages are never forwarded to clients.
     */
    public const CONTROL_KEY = '#realtime:control';

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

    /**
     * Asks every realtime server to re-run channel authorization for the existing subscriptions of the
     * channel in the scope (current scope by default) and to drop the ones that no longer pass, e.g. after
     * access to the channel was revoked. Dropped clients get an error with code "revoked".
     *
     * The request is an ordinary event named self::REAUTHORIZE, so it needs no broker support. Servers
     * that predate this feature forward it to clients as a regular event instead.
     *
     * @return int the request's sequence number in the channel
     */
    public function reauthorize(string $channel, ?string $scope = null): int
    {
        return $this->publish($channel, self::REAUTHORIZE, [], $scope);
    }

    /**
     * Asks every realtime server to close its open connections in the scope (current scope by default)
     * whose ticket claims contain all the given key/value pairs (strict comparison), e.g. after the API
     * token the tickets were issued for has been revoked. The clients get an error with code "revoked"
     * and a close with code 1008.
     *
     * The request goes over the broker's control channel and is not replayed: only connections open at
     * that moment are affected, so stop issuing tickets for the same claims first.
     *
     * @param  array<string, mixed>  $claims  must not be empty
     */
    public function disconnect(array $claims, ?string $scope = null): void
    {
        if ($claims === []) {
            throw new \InvalidArgumentException('Claims to disconnect by must not be empty');
        }
        self::assertClaims($claims);
        $scope ??= $this->currentScope();
        ChannelName::assertValidScope($scope);

        $this->broker()->publishVolatile(self::CONTROL_KEY, ['type' => 'disconnect', 'scope' => $scope, 'claims' => $claims]);
    }

    /**
     * Issues a single-use ticket for one WebSocket connection.
     *
     * @param  array<string, mixed>  $claims  JSON-safe, host-defined context the connection carries (Ticket::$claims),
     *                                        e.g. the scopes and id of an API token; disconnect() matches against it
     * @param  int|null  $expiresAt  unix seconds when the connection must end (e.g. the token's expiry); must be in the future
     */
    public function issueTicket(
        Authenticatable $user,
        ?string $guard = null,
        ?string $scope = null,
        ?Request $request = null,
        array $claims = [],
        ?int $expiresAt = null,
    ): IssuedTicket {
        $id = $user->getAuthIdentifier();
        if (! is_int($id) && ! is_string($id)) {
            throw new \InvalidArgumentException('User identifier must be an integer or a string');
        }
        self::assertClaims($claims);
        $now = time();
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new \InvalidArgumentException('Ticket expiry must be in the future');
        }
        $scope ??= $this->currentScope();
        ChannelName::assertValidScope($scope);

        $ttl = $this->configInt('realtime.ticket.ttl', 30);
        $token = bin2hex(random_bytes(32));
        $this->broker()->storeTicket($token, new Ticket($id, $guard, $scope, $now, $claims, $expiresAt), $ttl);

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

    /**
     * Claims arrive from host code, so their documented shape is checked at runtime: a list would not
     * survive the JSON round trip as a claims map.
     */
    private static function assertClaims(mixed $claims): void
    {
        if (! Ticket::isValidClaims($claims)) {
            throw new \InvalidArgumentException('Claim names must be strings');
        }
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
