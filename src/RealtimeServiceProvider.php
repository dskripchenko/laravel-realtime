<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime;

use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\Broadcasting\RealtimeBroadcaster;
use Dskripchenko\Realtime\Broker\Broker;
use Dskripchenko\Realtime\Broker\RedisBroker;
use Dskripchenko\Realtime\Channels\ChannelAuthorizer;
use Dskripchenko\Realtime\Channels\ChannelRegistry;
use Dskripchenko\Realtime\Console\ServeCommand;
use Dskripchenko\Realtime\Http\TicketController;
use Illuminate\Auth\AuthManager;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Redis;
use RuntimeException;

final class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/realtime.php', 'realtime');

        $this->app->singleton(ChannelRegistry::class);

        $this->app->singleton(Broker::class, static function (Application $app): Broker {
            /** @var Config $config */
            $config = $app->make(Config::class);
            $name = $config->get('realtime.redis.connection', 'default');
            /** @var RedisManager $redis */
            $redis = $app->make('redis');
            $client = $redis->connection(is_string($name) ? $name : 'default')->client();
            if (! $client instanceof Redis) {
                throw new RuntimeException('laravel-realtime requires a phpredis (non-cluster) Redis connection.');
            }
            $prefix = $config->get('realtime.redis.prefix', 'realtime:');
            $size = $config->get('realtime.replay.size', 100);
            $retention = $config->get('realtime.replay.retention', 86400);

            return new RedisBroker(
                $client,
                is_string($prefix) ? $prefix : 'realtime:',
                is_numeric($size) ? (int) $size : 100,
                is_numeric($retention) ? (int) $retention : 86400,
            );
        });

        $this->app->singleton(ChannelAuthorizer::class, static function (Application $app): ChannelAuthorizer {
            return new ChannelAuthorizer(
                $app->make(ChannelRegistry::class),
                static function (Ticket $ticket) use ($app): ?Authenticatable {
                    /** @var AuthManager $auth */
                    $auth = $app->make('auth');
                    /** @var Config $config */
                    $config = $app->make(Config::class);
                    $guard = $ticket->guard ?? $config->get('auth.defaults.guard');
                    $provider = $config->get('auth.guards.'.(is_string($guard) ? $guard : '').'.provider');
                    $users = $auth->createUserProvider(is_string($provider) ? $provider : null);

                    return $users?->retrieveById($ticket->userId);
                },
            );
        });

        $this->app->singleton(RealtimeManager::class, static fn (Application $app): RealtimeManager => new RealtimeManager(
            static fn (): Broker => $app->make(Broker::class),
            $app->make(ChannelRegistry::class),
            $app->make(ChannelAuthorizer::class),
            $app->make(Config::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/realtime.php' => $this->app->configPath('realtime.php')], 'realtime-config');
            $this->commands([ServeCommand::class]);
        }

        $this->app->afterResolving(BroadcastManager::class, static function (BroadcastManager $broadcast): void {
            $broadcast->extend('realtime', function (Application $app, array $config): RealtimeBroadcaster {
                $scope = $config['scope'] ?? null;

                return new RealtimeBroadcaster($app->make(RealtimeManager::class), is_string($scope) ? $scope : null);
            });
        });

        /** @var Config $config */
        $config = $this->app->make(Config::class);
        if ($config->get('realtime.routes.enabled', true) === true && ! ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            $prefix = $config->get('realtime.routes.prefix', 'realtime');
            $middleware = $config->get('realtime.routes.middleware', ['web', 'auth']);
            Route::post((is_string($prefix) ? trim($prefix, '/') : 'realtime').'/ticket', TicketController::class)
                ->middleware(is_array($middleware) ? $middleware : [])
                ->name('realtime.ticket');
        }
    }
}
