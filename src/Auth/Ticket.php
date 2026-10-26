<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Auth;

/**
 * What a connection is allowed to be: who (user id + guard) and in which scope (tenant).
 */
final class Ticket
{
    public function __construct(
        public readonly int|string $userId,
        public readonly ?string $guard,
        public readonly string $scope,
        public readonly int $issuedAt,
    ) {}

    /**
     * @return array{uid: int|string, guard: ?string, scope: string, iat: int}
     */
    public function toArray(): array
    {
        return ['uid' => $this->userId, 'guard' => $this->guard, 'scope' => $this->scope, 'iat' => $this->issuedAt];
    }

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        $uid = $data['uid'] ?? null;
        $guard = $data['guard'] ?? null;
        $scope = $data['scope'] ?? null;
        $iat = $data['iat'] ?? 0;
        if ((! is_int($uid) && ! is_string($uid)) || ! is_string($scope) || ($guard !== null && ! is_string($guard)) || ! is_int($iat)) {
            return null;
        }

        return new self($uid, $guard, $scope, $iat);
    }
}
