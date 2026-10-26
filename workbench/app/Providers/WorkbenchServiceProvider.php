<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Closure;
use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Facades\Realtime;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider;

/**
 * Host wiring used by the integration tests' realtime:serve child process.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['config']->set('database.redis.client', 'phpredis');
        $this->app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => (int) (getenv('REALTIME_TEST_REDIS_PORT') ?: 63792),
            'database' => 0,
        ]);

        // No database in the test app: users exist as long as they have an id.
        Realtime::resolveUserUsing(static fn (Ticket $ticket): Authenticatable => new GenericUser(['id' => $ticket->userId]));

        // Stands in for "enter the tenant": channel callbacks can see which scope they run for.
        Realtime::authorizeUsing(static function (Ticket $ticket, Closure $next): mixed {
            $previous = $GLOBALS['realtime_scope'] ?? null;
            $GLOBALS['realtime_scope'] = $ticket->scope;
            try {
                return $next();
            } finally {
                $GLOBALS['realtime_scope'] = $previous;
            }
        });

        Realtime::channel('board.{id}', static fn (Authenticatable $user, string $id): bool => $id !== '13');
        Realtime::channel('tenant-only.{scope}', static fn (Authenticatable $user, string $scope): bool => ($GLOBALS['realtime_scope'] ?? null) === $scope);
        Realtime::channel('page.{id}', static fn (Authenticatable $user, string $id): array => ['name' => 'User '.$user->getAuthIdentifier()]);
    }
}
