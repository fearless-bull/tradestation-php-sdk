<?php

/*
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FearlessBull\TradeStation\Sdk\Auth;

final readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?string $idToken = null,
        public string $tokenType = 'Bearer',
        public ?string $scope = null,
        public ?int $expiresAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromTokenResponse(array $payload, ?self $previous = null, ?int $issuedAt = null): self
    {
        $issuedAt ??= time();
        $expiresIn = isset($payload['expires_in']) ? (int) $payload['expires_in'] : null;

        return new self(
            accessToken: (string) ($payload['access_token'] ?? ''),
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : $previous?->refreshToken,
            idToken: isset($payload['id_token']) ? (string) $payload['id_token'] : $previous?->idToken,
            tokenType: (string) ($payload['token_type'] ?? $previous?->tokenType ?? 'Bearer'),
            scope: isset($payload['scope']) ? (string) $payload['scope'] : $previous?->scope,
            expiresAt: $expiresIn === null ? $previous?->expiresAt : $issuedAt + $expiresIn,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $accessToken = $payload['access_token'] ?? null;

        if (! \is_string($accessToken) || '' === $accessToken) {
            return null;
        }

        return new self(
            accessToken: $accessToken,
            refreshToken: \is_string($payload['refresh_token'] ?? null) ? $payload['refresh_token'] : null,
            idToken: \is_string($payload['id_token'] ?? null) ? $payload['id_token'] : null,
            tokenType: \is_string($payload['token_type'] ?? null) ? $payload['token_type'] : 'Bearer',
            scope: \is_string($payload['scope'] ?? null) ? $payload['scope'] : null,
            expiresAt: isset($payload['expires_at']) ? (int) $payload['expires_at'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'id_token' => $this->idToken,
            'token_type' => $this->tokenType,
            'scope' => $this->scope,
            'expires_at' => $this->expiresAt,
        ], static fn (mixed $value): bool => null !== $value);
    }

    public function expiresWithin(int $seconds, ?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= (($now ?? time()) + $seconds);
    }
}
