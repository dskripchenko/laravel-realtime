<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Channels;

use InvalidArgumentException;

/**
 * Channel naming rules. Names follow Laravel's convention: "private-" and "presence-" prefixes select the type.
 * Internally every channel is keyed by "<scope>/<name>" so two scopes (tenants) can never share a channel.
 */
final class ChannelName
{
    public const MAX_LENGTH = 200;

    public static function isValid(string $name): bool
    {
        return strlen($name) <= self::MAX_LENGTH && preg_match('/^[A-Za-z0-9_\-=@,.;:]+$/', $name) === 1;
    }

    public static function assertValid(string $name): void
    {
        if (! self::isValid($name)) {
            throw new InvalidArgumentException("Invalid channel name [{$name}]");
        }
    }

    public static function isValidScope(string $scope): bool
    {
        return strlen($scope) <= 100 && preg_match('/^[A-Za-z0-9_\-.:]*$/', $scope) === 1;
    }

    public static function assertValidScope(string $scope): void
    {
        if (! self::isValidScope($scope)) {
            throw new InvalidArgumentException("Invalid realtime scope [{$scope}]");
        }
    }

    public static function type(string $name): ChannelType
    {
        return match (true) {
            str_starts_with($name, 'private-') => ChannelType::Private,
            str_starts_with($name, 'presence-') => ChannelType::Presence,
            default => ChannelType::Public,
        };
    }

    /**
     * The name the authorization pattern is matched against: "private-board.1" -> "board.1".
     */
    public static function bare(string $name): string
    {
        return match (self::type($name)) {
            ChannelType::Private => substr($name, 8),
            ChannelType::Presence => substr($name, 9),
            ChannelType::Public => $name,
        };
    }

    public static function key(string $scope, string $name): string
    {
        return $scope.'/'.$name;
    }
}
