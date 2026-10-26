<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Protocol;

/**
 * A validated client -> server message.
 */
final class ClientMessage
{
    public const TYPES = ['subscribe', 'unsubscribe', 'presence', 'event', 'ping'];

    public function __construct(
        public readonly string $type,
        public readonly int|string|null $id = null,
        public readonly ?string $channel = null,
        public readonly ?int $since = null,
        public readonly ?string $action = null,
        public readonly ?string $event = null,
        public readonly mixed $data = null,
    ) {}

    /**
     * @throws ProtocolException
     */
    public static function parse(string $json): self
    {
        try {
            $raw = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolException('bad_request', 'Message is not valid JSON');
        }
        if (! is_array($raw) || array_is_list($raw)) {
            throw new ProtocolException('bad_request', 'Message must be a JSON object');
        }

        $id = $raw['id'] ?? null;
        if ($id !== null && ! is_int($id) && ! (is_string($id) && strlen($id) <= 64)) {
            throw new ProtocolException('bad_request', 'Field "id" must be an integer or a short string');
        }

        $type = $raw['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            throw new ProtocolException('unknown_type', 'Unknown message type', $id);
        }

        if ($type === 'ping') {
            return new self('ping', $id);
        }

        $channel = $raw['channel'] ?? null;
        if (! is_string($channel) || $channel === '') {
            throw new ProtocolException('bad_request', 'Field "channel" is required', $id);
        }

        return match ($type) {
            'subscribe' => new self($type, $id, $channel, self::since($raw, $id)),
            'unsubscribe' => new self($type, $id, $channel),
            'presence' => new self($type, $id, $channel, action: self::presenceAction($raw, $id)),
            default => new self($type, $id, $channel, event: self::eventName($raw, $id), data: $raw['data'] ?? null),
        };
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function since(array $raw, int|string|null $id): ?int
    {
        $since = $raw['since'] ?? null;
        if ($since !== null && (! is_int($since) || $since < 0)) {
            throw new ProtocolException('bad_request', 'Field "since" must be a non-negative integer', $id);
        }

        return $since;
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function presenceAction(array $raw, int|string|null $id): string
    {
        $action = $raw['action'] ?? 'list';
        if ($action !== 'list') {
            throw new ProtocolException('bad_request', 'Only the "list" presence action can be requested', $id);
        }

        return $action;
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function eventName(array $raw, int|string|null $id): string
    {
        $event = $raw['event'] ?? null;
        if (! is_string($event) || ! preg_match('/^client-[A-Za-z0-9_\-.:]{1,100}$/', $event)) {
            throw new ProtocolException('bad_request', 'Client event names must match "client-<name>"', $id);
        }

        return $event;
    }
}
