<?php

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

/**
 * Immutable value object representing cache entry metadata.
 */
readonly class CacheEntry
{
    public function __construct(
        public int $expiresAt,
        public int $staleOffset,
    ) {}

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
            expiresAt:   (int) $data['expires_at'],
            staleOffset: (int) $data['stale_offset'],
        );
    }
}
