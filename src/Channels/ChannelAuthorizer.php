<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Channels;

use Closure;
use Dskripchenko\Realtime\Auth\Ticket;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Runs the host's channel callbacks inside the realtime server process.
 *
 * There is no HTTP request here: callbacks get the user and the channel parameters only.
 * The "around" hook lets a multi-tenant host enter the ticket's tenant before the user is loaded.
 */
final class ChannelAuthorizer
{
    /** @var Closure(Ticket, Closure(): mixed): mixed|null */
    private ?Closure $around = null;

    /** @var Closure(Throwable): void|null */
    private ?Closure $reporter = null;

    /**
     * @param  Closure(Ticket): ?Authenticatable  $userResolver
     */
    public function __construct(
        private readonly ChannelRegistry $registry,
        private Closure $userResolver,
    ) {}

    /**
     * @param  Closure(Ticket): ?Authenticatable  $resolver
     */
    public function resolveUserUsing(Closure $resolver): void
    {
        $this->userResolver = $resolver;
    }

    /**
     * @param  Closure(Ticket, Closure(): mixed): mixed|null  $around
     */
    public function around(?Closure $around): void
    {
        $this->around = $around;
    }

    /**
     * @param  Closure(Throwable): void|null  $reporter
     */
    public function reportUsing(?Closure $reporter): void
    {
        $this->reporter = $reporter;
    }

    public function authorize(Ticket $ticket, string $channel): AuthorizationResult
    {
        $type = ChannelName::type($channel);
        if ($type === ChannelType::Public) {
            return AuthorizationResult::allow();
        }

        $match = $this->registry->match(ChannelName::bare($channel));
        if ($match === null) {
            return AuthorizationResult::deny('no_authorizer');
        }
        [$callback, $params] = $match;

        $run = function () use ($ticket, $callback, $params, $type): AuthorizationResult {
            $user = ($this->userResolver)($ticket);
            if ($user === null) {
                return AuthorizationResult::deny('unknown_user');
            }

            $result = $callback($user, ...$params);
            if ($result === false || $result === null) {
                return AuthorizationResult::deny();
            }

            if ($type === ChannelType::Presence) {
                $id = $user->getAuthIdentifier();
                if (! is_int($id) && ! is_string($id)) {
                    return AuthorizationResult::deny('unknown_user');
                }

                return AuthorizationResult::allow(['id' => $id, 'info' => is_array($result) ? $result : []]);
            }

            return AuthorizationResult::allow();
        };

        try {
            $result = $this->around === null ? $run() : ($this->around)($ticket, $run);
        } catch (Throwable $e) {
            if ($this->reporter !== null) {
                ($this->reporter)($e);
            }

            return AuthorizationResult::deny('error');
        }

        return $result instanceof AuthorizationResult ? $result : AuthorizationResult::deny('error');
    }
}
