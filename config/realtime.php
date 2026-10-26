<?php

declare(strict_types=1);

return [

    /*
     * Where `php artisan realtime:serve` listens. Terminate TLS in a reverse proxy in front of it.
     */
    'server' => [
        'host' => env('REALTIME_HOST', '0.0.0.0'),
        'port' => (int) env('REALTIME_PORT', 6001),
        // Lets several server processes share one port (Linux SO_REUSEPORT), one per CPU core.
        'reuse_port' => (bool) env('REALTIME_REUSE_PORT', false),
    ],

    /*
     * The URL browsers connect to, returned with every ticket, e.g. "wss://app.example.com/realtime".
     * When null it is derived from the ticket request host and the server port (development only).
     */
    'public_url' => env('REALTIME_PUBLIC_URL'),

    /*
     * Origins allowed to open a WebSocket ("https://app.example.com", "*.example.com"). Empty allows any;
     * tickets already bind connections to a logged-in user, the origin check is defence in depth.
     */
    'allowed_origins' => array_values(array_filter(explode(',', (string) env('REALTIME_ALLOWED_ORIGINS', '')))),

    'redis' => [
        // A phpredis connection from config/database.php "redis" section.
        'connection' => env('REALTIME_REDIS_CONNECTION', 'default'),
        // Applied to every key and pub/sub channel; the connection's own "prefix" option is not used.
        'prefix' => env('REALTIME_REDIS_PREFIX', 'realtime:'),
    ],

    'ticket' => [
        // Seconds a ticket stays valid. It is single use, so keep it short.
        'ttl' => (int) env('REALTIME_TICKET_TTL', 30),
        // Guard used to resolve the user for tickets and channel authorization; null = default guard.
        'guard' => env('REALTIME_GUARD'),
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'realtime',
        'middleware' => ['web', 'auth'],
    ],

    /*
     * Scope used when nothing else is given (single-tenant apps). Multi-tenant hosts register
     * Realtime::resolveScopeUsing() instead.
     */
    'scope' => env('REALTIME_SCOPE', ''),

    'replay' => [
        // Events kept per channel for reconnecting clients.
        'size' => (int) env('REALTIME_REPLAY_SIZE', 100),
        // Seconds an idle channel keeps its sequence counter and replay log.
        'retention' => (int) env('REALTIME_REPLAY_RETENTION', 86400),
    ],

    'heartbeat' => [
        // Ping a connection after this many idle seconds...
        'interval' => 25,
        // ...and drop it if nothing comes back within this many seconds.
        'timeout' => 20,
    ],

    'limits' => [
        'max_connections' => (int) env('REALTIME_MAX_CONNECTIONS', 10000),
        'max_frame_size' => 65536,
        'max_message_size' => 65536,
        'max_subscriptions' => 100,
        // Outgoing bytes buffered per connection before it is dropped as a slow consumer.
        'max_buffer' => 4194304,
        'messages_per_second' => 50,
        'handshake_timeout' => 10,
    ],

    // Allow "client-*" events (typing indicators, cursors) on private and presence channels.
    'client_events' => true,
];
