<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Console;

use Dskripchenko\Realtime\Broker\RespSubscriber;
use Dskripchenko\Realtime\RealtimeManager;
use Dskripchenko\Realtime\Server\Server;
use Dskripchenko\Realtime\Server\ServerOptions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

final class ServeCommand extends Command
{
    protected $signature = 'realtime:serve
        {--host= : Interface to bind (default: realtime.server.host)}
        {--port= : Port to bind, 0 picks a free one (default: realtime.server.port)}
        {--server-id= : Stable id of this process (default: hostname-pid-random)}';

    protected $description = 'Run the realtime WebSocket server';

    public function handle(RealtimeManager $realtime, Config $config, ExceptionHandler $exceptions): int
    {
        $realtimeConfig = $config->get('realtime', []);
        $host = $this->option('host');
        $port = $this->option('port');
        $serverId = $this->option('server-id');
        $options = ServerOptions::fromConfig(
            is_array($realtimeConfig) ? $realtimeConfig : [],
            is_string($host) && $host !== '' ? $host : null,
            is_numeric($port) ? (int) $port : null,
            is_string($serverId) && $serverId !== '' ? $serverId : null,
        );

        $connection = $config->get('realtime.redis.connection', 'default');
        $redisConfig = $config->get('database.redis.'.(is_string($connection) ? $connection : 'default'));
        if (! is_array($redisConfig)) {
            $this->error('Redis connection ['.(is_string($connection) ? $connection : '?').'] is not configured.');

            return self::FAILURE;
        }
        /** @var array<string, mixed> $redisConfig */
        $realtime->authorizer()->reportUsing(static function (Throwable $e) use ($exceptions): void {
            $exceptions->report($e);
        });

        $subscriber = RespSubscriber::fromConfig($redisConfig);
        $subscriber->setClientName('realtime-'.$options->serverId);

        $server = new Server(
            $options,
            $realtime->broker(),
            $subscriber,
            $realtime->authorizer(),
            function (string $level, string $message): void {
                $line = '['.date('Y-m-d H:i:s').'] '.$level.': '.$message;
                $level === 'error' ? $this->error($line) : ($this->output->isVerbose() || $level !== 'info' ? $this->line($line) : null);
            },
        );

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn () => $server->stop());
            pcntl_signal(SIGINT, static fn () => $server->stop());
        }

        $address = $server->listen();
        // Integration tests and supervisors parse this line, keep its format stable.
        $this->line("Realtime server listening on {$address} (id {$options->serverId})");
        $server->run();
        $this->line('Realtime server stopped');

        return self::SUCCESS;
    }
}
