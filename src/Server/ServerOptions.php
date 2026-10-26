<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Server;

final class ServerOptions
{
    /**
     * @param  list<string>  $allowedOrigins
     */
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 6001,
        public readonly string $serverId = '',
        public readonly array $allowedOrigins = [],
        public readonly bool $reusePort = false,
        public readonly int $maxConnections = 1000,
        public readonly int $maxFrameSize = 65536,
        public readonly int $maxMessageSize = 65536,
        public readonly int $maxSubscriptions = 100,
        public readonly int $maxBuffer = 4_194_304,
        public readonly int $messagesPerSecond = 50,
        public readonly float $handshakeTimeout = 10.0,
        public readonly float $pingInterval = 25.0,
        public readonly float $pongTimeout = 20.0,
        public readonly int $replayLimit = 100,
        public readonly bool $clientEvents = true,
    ) {}

    /**
     * @param  array<mixed>  $config  the "realtime" config array
     */
    public static function fromConfig(array $config, ?string $host = null, ?int $port = null, ?string $serverId = null): self
    {
        $get = static function (string $path, mixed $default) use ($config): mixed {
            $value = $config;
            foreach (explode('.', $path) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }

            return $value ?? $default;
        };
        $int = static fn (string $path, int $default): int => is_numeric($v = $get($path, $default)) ? (int) $v : $default;
        $float = static fn (string $path, float $default): float => is_numeric($v = $get($path, $default)) ? (float) $v : $default;
        $origins = $get('allowed_origins', []);
        $configHost = $get('server.host', '0.0.0.0');

        return new self(
            host: $host ?? (is_string($configHost) ? $configHost : '0.0.0.0'),
            port: $port ?? $int('server.port', 6001),
            serverId: $serverId ?? (gethostname() ?: 'realtime').'-'.getmypid().'-'.bin2hex(random_bytes(3)),
            allowedOrigins: is_array($origins) ? array_values(array_filter($origins, 'is_string')) : [],
            reusePort: (bool) $get('server.reuse_port', false),
            maxConnections: $int('limits.max_connections', 1000),
            maxFrameSize: $int('limits.max_frame_size', 65536),
            maxMessageSize: $int('limits.max_message_size', 65536),
            maxSubscriptions: $int('limits.max_subscriptions', 100),
            maxBuffer: $int('limits.max_buffer', 4_194_304),
            messagesPerSecond: $int('limits.messages_per_second', 50),
            handshakeTimeout: $float('limits.handshake_timeout', 10.0),
            pingInterval: $float('heartbeat.interval', 25.0),
            pongTimeout: $float('heartbeat.timeout', 20.0),
            replayLimit: $int('replay.size', 100),
            clientEvents: (bool) $get('client_events', true),
        );
    }
}
