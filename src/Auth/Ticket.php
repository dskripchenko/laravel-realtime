<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Auth;

/**
 * What a connection is allowed to be: who (user id + guard), in which scope (tenant), with which
 * host-defined claims (e.g. the scopes and id of the API token it was issued for) and until when.
 */
final class Ticket
{
    /**
     * @param  array<string, mixed>  $claims  JSON-safe, host-defined context carried by the connection
     * @param  int|null  $expiresAt  unix seconds; the connection ends then (null: lives until closed)
     */
    public function __construct(
        public readonly int|string $userId,
        public readonly ?string $guard,
        public readonly string $scope,
        public readonly int $issuedAt,
        public readonly array $claims = [],
        public readonly ?int $expiresAt = null,
    ) {}

    public function expired(?int $now = null): bool
    {
        return $this->expiresAt !== null && ($now ?? time()) >= $this->expiresAt;
    }

    /**
     * Whether the claims contain every given key with a strictly equal value.
     *
     * @param  array<string, mixed>  $claims
     */
    public function matches(array $claims): bool
    {
        if ($claims === []) {
            return false;
        }
        foreach ($claims as $key => $value) {
            if (! array_key_exists($key, $this->claims) || $this->claims[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the value is a claims map: an array whose keys are all strings (an empty array included).
     *
     * @phpstan-assert-if-true array<string, mixed> $claims
     */
    public static function isValidClaims(mixed $claims): bool
    {
        if (! is_array($claims)) {
            return false;
        }
        foreach (array_keys($claims) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{uid: int|string, guard: ?string, scope: string, iat: int, claims: array<string, mixed>, exp: ?int}
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->userId,
            'guard' => $this->guard,
            'scope' => $this->scope,
            'iat' => $this->issuedAt,
            'claims' => $this->claims,
            'exp' => $this->expiresAt,
        ];
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
        // Tickets stored before claims and expiry existed carry neither.
        $claims = $data['claims'] ?? [];
        $exp = $data['exp'] ?? null;
        if ((! is_int($uid) && ! is_string($uid)) || ! is_string($scope) || ($guard !== null && ! is_string($guard)) || ! is_int($iat)) {
            return null;
        }
        if (! self::isValidClaims($claims) || ($exp !== null && ! is_int($exp))) {
            return null;
        }

        return new self($uid, $guard, $scope, $iat, $claims, $exp);
    }
}
