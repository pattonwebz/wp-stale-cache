<?php
/**
 * Cache entry value object.
 *
 * @package pattonwebz/wp-stale-cache
 */

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

/**
 * Immutable value object representing cache entry metadata.
 *
 * @package pattonwebz/wp-stale-cache
 */
class CacheEntry
{
	/**
	 * Unix timestamp when the entry transitions from fresh to stale.
	 *
	 * @var int
	 */
	private int $expires_at;

	/**
	 * Additional seconds the entry is served stale before expiry.
	 *
	 * @var int
	 */
	private int $stale_offset;

	/**
	 * Constructor.
	 *
	 * @param int $expires_at   Unix timestamp of expiry.
	 * @param int $stale_offset Stale window in seconds.
	 */
	public function __construct( int $expires_at, int $stale_offset )
	{
		$this->expires_at   = $expires_at;
		$this->stale_offset = $stale_offset;
	}

	/**
	 * Get the expiry timestamp.
	 *
	 * @return int
	 */
	public function get_expires_at(): int
	{
		return $this->expires_at;
	}

	/**
	 * Get the stale offset in seconds.
	 *
	 * @return int
	 */
	public function get_stale_offset(): int
	{
		return $this->stale_offset;
	}

	/**
	 * Determine cache state at a given timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @param int $now Current Unix timestamp.
	 * @return string One of: 'fresh', 'stale', 'expired'.
	 */
	public function get_state( int $now ): string
	{
		if ( $now < $this->expires_at ) {
			return 'fresh';
		}

		if ( $now < $this->expires_at + $this->stale_offset ) {
			return 'stale';
		}

		return 'expired';
	}

	/**
	 * Whether the entry is currently fresh.
	 *
	 * @return bool
	 */
	public function is_fresh(): bool
	{
		return 'fresh' === $this->get_state( time() );
	}

	/**
	 * Whether the entry is currently stale.
	 *
	 * @return bool
	 */
	public function is_stale(): bool
	{
		return 'stale' === $this->get_state( time() );
	}

	/**
	 * Whether the entry is currently expired.
	 *
	 * @return bool
	 */
	public function is_expired(): bool
	{
		return 'expired' === $this->get_state( time() );
	}

	/**
	 * Serialise the entry to an associative array.
	 *
	 * @return array{expires_at: int, stale_offset: int}
	 */
	public function to_array(): array
	{
		return [
			'expires_at'   => $this->expires_at,
			'stale_offset' => $this->stale_offset,
		];
	}

	/**
	 * Hydrate a CacheEntry from a stored array.
	 *
	 * @param array{expires_at: int, stale_offset: int} $data Stored metadata array.
	 * @return self
	 */
	public static function from_array( array $data ): self
	{
		return new self(
			(int) $data['expires_at'],
			(int) $data['stale_offset']
		);
	}
}
