<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Tests;

use Dskripchenko\Realtime\RealtimeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RealtimeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => self::redisPort(),
            'database' => 0,
        ]);
        $app['config']->set('realtime.redis.prefix', static::redisPrefix());
    }

    protected static function redisPort(): int
    {
        return (int) (getenv('REALTIME_TEST_REDIS_PORT') ?: 63792);
    }

    protected static function redisPrefix(): string
    {
        return 'realtime-test:';
    }

    protected static function redisAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            try {
                $redis = new \Redis;
                $available = @$redis->connect('127.0.0.1', self::redisPort(), 0.5) && $redis->ping() !== false;
            } catch (\Throwable) {
                $available = false;
            }
        }

        return $available;
    }

    protected function requireRedis(): void
    {
        if (! self::redisAvailable()) {
            $this->markTestSkipped('Redis/Valkey is not reachable on 127.0.0.1:'.self::redisPort());
        }
    }
}
