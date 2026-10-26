<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Http;

use Dskripchenko\Realtime\RealtimeManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TicketController
{
    public function __invoke(Request $request, RealtimeManager $realtime, Config $config): JsonResponse
    {
        $guard = $config->get('realtime.ticket.guard');
        $guard = is_string($guard) && $guard !== '' ? $guard : null;
        $user = $request->user($guard);
        if (! $user instanceof Authenticatable) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $ticket = $realtime->issueTicket($user, $guard, null, $request);

        return new JsonResponse($ticket, 200, ['Cache-Control' => 'no-store']);
    }
}
