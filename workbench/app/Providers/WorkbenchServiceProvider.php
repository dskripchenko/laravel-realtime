<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Closure;
use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Facades\Realtime;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
            $previous = [$GLOBALS['realtime_scope'] ?? null, $GLOBALS['realtime_claims'] ?? null];
            $GLOBALS['realtime_scope'] = $ticket->scope;
            $GLOBALS['realtime_claims'] = $ticket->claims;
            try {
                return $next();
            } finally {
                [$GLOBALS['realtime_scope'], $GLOBALS['realtime_claims']] = $previous;
            }
        });

        Realtime::channel('board.{id}', static fn (Authenticatable $user, string $id): bool => $id !== '13');
        Realtime::channel('tenant-only.{scope}', static fn (Authenticatable $user, string $scope): bool => ($GLOBALS['realtime_scope'] ?? null) === $scope);
        // Only connections whose ticket claims list the ability may subscribe.
        Realtime::channel('ability.{name}', static fn (Authenticatable $user, string $name): bool => in_array($name, (array) (($GLOBALS['realtime_claims'] ?? [])['abilities'] ?? []), true));
        Realtime::channel('page.{id}', static fn (Authenticatable $user, string $id): array => ['name' => 'User '.$user->getAuthIdentifier()]);

        // Access the tests can revoke at runtime: a flag file per channel id and user ("throw" makes the callback fail).
        $access = static function (Authenticatable $user, string $id): bool {
            $flag = sys_get_temp_dir().'/realtime-revoked-'.$id.'-'.$user->getAuthIdentifier();
            if (! is_file($flag)) {
                return true;
            }
            if (file_get_contents($flag) === 'throw') {
                throw new RuntimeException('Access check failed');
            }

            return false;
        };
        Realtime::channel('revocable.{id}', $access);
        Realtime::channel('room.{id}', static fn (Authenticatable $user, string $id): array|false => $access($user, $id)
            ? ['name' => 'User '.$user->getAuthIdentifier()] : false);
    }
}
