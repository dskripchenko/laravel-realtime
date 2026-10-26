<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Server;

use Dskripchenko\Realtime\Auth\Ticket;
use Dskripchenko\Realtime\WebSocket\FrameParser;
use Dskripchenko\Realtime\WebSocket\MessageAssembler;

final class Connection
{
    public readonly string $id;

    public ConnectionState $state = ConnectionState::Handshake;

    public string $inbound = '';

    public string $outbound = '';

    public bool $closeAfterFlush = false;

    public ?Ticket $ticket = null;

    /** @var array<string, Subscription> keyed by scoped channel key */
    public array $subscriptions = [];

    public float $lastActivity;

    public ?float $pingSentAt = null;

    public ?float $closeDeadline = null;

    public readonly FrameParser $parser;

    public readonly MessageAssembler $assembler;

    private float $tokens;

    private float $tokensAt;

    /**
     * @param  resource  $stream
     */
    public function __construct(
        public readonly mixed $stream,
        public readonly string $remote,
        public readonly float $connectedAt,
        int $maxFrameSize,
        int $maxMessageSize,
        private readonly int $messagesPerSecond,
    ) {
        // The socket id is what clients send back as X-Socket-ID for toOthers(); "<n>.<n>" mirrors Pusher/Echo.
        $this->id = random_int(1, PHP_INT_MAX >> 1).'.'.random_int(1, PHP_INT_MAX >> 1);
        $this->lastActivity = $connectedAt;
        $this->parser = new FrameParser(true, $maxFrameSize);
        $this->assembler = new MessageAssembler($maxMessageSize);
        $this->tokens = (float) $messagesPerSecond;
        $this->tokensAt = $connectedAt;
    }

    /**
     * Token bucket allowing short bursts of up to one second's worth of messages.
     */
    public function allowMessage(float $now): bool
    {
        if ($this->messagesPerSecond <= 0) {
            return true;
        }
        $this->tokens = min((float) $this->messagesPerSecond, $this->tokens + ($now - $this->tokensAt) * $this->messagesPerSecond);
        $this->tokensAt = $now;
        if ($this->tokens < 1.0) {
            return false;
        }
        $this->tokens -= 1.0;

        return true;
    }
}
