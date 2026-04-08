<?php

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

/**
 * Immutable value object representing cache entry metadata.
 */
class CacheEntry
{
    private int $expiresAt;
    private int $staleOffset;

    public function __construct(int $expiresAt, int $staleOffset)
    {
        $this->expiresAt   = $expiresAt;
        $this->staleOffset = $staleOffset;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function getStaleOffset(): int
    {
        return $this->staleOffset;
    }

    /**
     * Determine cache state at a given timestamp.
     *
     * @return string One of: 'fresh', 'stale', 'expired'
     */
    public function getState(int $now): string
    {
        if ($now < $this->expiresAt) {
            return 'fresh';
        }

        if ($now < $this->expiresAt + $this->staleOffset) {
            return 'stale';
        }

        return 'expired';
    }

    public function isFresh(): bool
    {
        return $this->getState(time()) === 'fresh';
    }

    public function isStale(): bool
    {
        return $this->getState(time()) === 'stale';
    }

    public function isExpired(): bool
    {
        return $this->getState(time()) === 'expired';
    }

    /**
     * @return array{expires_at: int, stale_offset: int}
     */
    public function toArray(): array
    {
        return [
            'expires_at'   => $this->expiresAt,
            'stale_offset' => $this->staleOffset,
        ];
    }

    /**
     * @param array{expires_at: int, stale_offset: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['expires_at'],
            (int) $data['stale_offset']
        );
    }
}
