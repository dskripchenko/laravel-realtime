<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Facades;

use Dskripchenko\Realtime\RealtimeManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RealtimeManager channel(string $pattern, callable $callback)
 * @method static RealtimeManager authorizeUsing(?\Closure $around)
 * @method static RealtimeManager resolveUserUsing(\Closure $resolver)
 * @method static RealtimeManager resolveScopeUsing(?\Closure $resolver)
 * @method static string currentScope()
 * @method static int publish(string $channel, string $event, mixed $data = [], ?string $scope = null, ?string $except = null)
 * @method static \Dskripchenko\Realtime\Auth\IssuedTicket issueTicket(\Illuminate\Contracts\Auth\Authenticatable $user, ?string $guard = null, ?string $scope = null, ?\Illuminate\Http\Request $request = null)
 * @method static \Dskripchenko\Realtime\Broker\Broker broker()
 *
 * @see RealtimeManager
 */
final class Realtime extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RealtimeManager::class;
    }
}
