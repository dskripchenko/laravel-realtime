<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Broadcasting;

use Dskripchenko\Realtime\RealtimeManager;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * The "realtime" broadcasting driver: ShouldBroadcast events are published into the current scope.
 */
final class RealtimeBroadcaster extends Broadcaster
{
    public function __construct(private readonly RealtimeManager $realtime, private readonly ?string $scope = null) {}

    /**
     * Channel authorization happens inside the realtime server (Realtime::channel()), not via /broadcasting/auth.
     *
     * @param  Request  $request
     */
    public function auth($request): mixed
    {
        throw new AccessDeniedHttpException('The realtime driver authorizes channels in the realtime server.');
    }

    /**
     * @param  Request  $request
     */
    public function validAuthenticationResponse($request, $result): mixed
    {
        return $result;
    }

    /**
     * @param  array<mixed>  $channels
     * @param  string  $event
     * @param  array<mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $socket = Arr::pull($payload, 'socket');
        $except = is_string($socket) && $socket !== '' ? $socket : null;

        foreach ($this->formatChannels($channels) as $channel) {
            if (! is_string($channel)) {
                continue;
            }
            try {
                $this->realtime->publish($channel, $event, $payload, $this->scope, $except);
            } catch (Throwable $e) {
                throw new BroadcastException('Realtime publish failed: '.$e->getMessage(), 0, $e);
            }
        }
    }
}
