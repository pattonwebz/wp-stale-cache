<?php
/**
 * Stale-while-revalidate cache manager (Transients API).
 *
 * @package pattonwebz/wp-stale-cache
 */

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

/**
 * Stale-while-revalidate cache manager backed by WordPress transients.
 *
 * Provides the same public API as StaleCache but stores data via
 * set_transient / get_transient / delete_transient instead of the Options API.
 *
 * Entries may be silently evicted by the object cache backend.
 * Use StaleCache for guaranteed persistence.
 *
 * @package pattonwebz/wp-stale-cache
 */
class TransientStaleCache {
	/**
	 * Transient name prefix applied to all keys.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Transient name prefix (default: '_wpsc_').
	 */
	public function __construct( string $prefix = '_wpsc_' ) {
		$this->prefix = $prefix;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a cached value, generating it synchronously when necessary.
	 *
	 * States:
	 *  - Missing / expired → call $generator(), store, return fresh value.
	 *  - Fresh             → return cached value immediately.
	 *  - Stale             → return cached value AND schedule background refresh.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $key          Cache key (alphanumeric, underscores, hyphens).
	 * @param callable $generator    Returns the value to cache.
	 * @param int      $ttl          Fresh period in seconds (default: 1 hour).
	 * @param int      $stale_offset Additional seconds value is served while stale (default: 5 min).
	 * @return mixed
	 */
	public function get(
		string $key,
		callable $generator,
		int $ttl = 3600,
		int $stale_offset = 300
	) {
		$prefixed_key = $this->prefix . $key;
		$meta_key     = $prefixed_key . '_meta';

		$raw_meta = get_transient( $meta_key );

		if ( false === $raw_meta || ! is_array( $raw_meta ) ) {
			return $this->regenerate( $prefixed_key, $meta_key, $generator, $ttl, $stale_offset );
		}

		$entry = CacheEntry::from_array( $raw_meta );
		$state = $entry->get_state( time() );

		if ( 'fresh' === $state ) {
			return get_transient( $prefixed_key );
		}
		if ( 'stale' === $state ) {
			return $this->serve_stale( $prefixed_key, $meta_key, $entry, $generator, $ttl, $stale_offset );
		}
		return $this->regenerate( $prefixed_key, $meta_key, $generator, $ttl, $stale_offset );
	}

	/**
	 * Delete both the value and metadata transients for a key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public function forget( string $key ): void {
		$prefixed_key = $this->prefix . $key;
		delete_transient( $prefixed_key );
		delete_transient( $prefixed_key . '_meta' );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Execute the generator synchronously and persist the result as transients.
	 *
	 * @param string   $prefixed_key Full transient key (with prefix).
	 * @param string   $meta_key     Metadata transient key.
	 * @param callable $generator    Returns the value to cache.
	 * @param int      $ttl          Fresh period in seconds.
	 * @param int      $stale_offset Stale window in seconds.
	 * @return mixed
	 */
	private function regenerate(
		string $prefixed_key,
		string $meta_key,
		callable $generator,
		int $ttl,
		int $stale_offset
	) {
		$value = $generator();
		$entry = new CacheEntry( time() + $ttl, $stale_offset );

		// Store the value for the full stale window so it remains retrievable
		// while stale. The meta transient tracks the true expiry boundary.
		$total_ttl = $ttl + $stale_offset;

		$stored = set_transient( $prefixed_key, $value, $total_ttl );
		if ( false === $stored ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[wp-stale-cache] Failed to store value for transient "%s".', $prefixed_key ) );
		}

		$stored_meta = set_transient( $meta_key, $entry->to_array(), $total_ttl );
		if ( false === $stored_meta ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[wp-stale-cache] Failed to store metadata for transient "%s".', $meta_key ) );
		}

		return $value;
	}

	/**
	 * Return the stale cached value and enqueue a background refresh.
	 *
	 * @param string     $prefixed_key Full transient key (with prefix).
	 * @param string     $meta_key     Metadata transient key.
	 * @param CacheEntry $entry        Current cache entry.
	 * @param callable   $generator    Returns the value to cache.
	 * @param int        $ttl          Fresh period in seconds.
	 * @param int        $stale_offset Stale window in seconds.
	 * @return mixed
	 */
	private function serve_stale(
		string $prefixed_key,
		string $meta_key,
		CacheEntry $entry,
		callable $generator,
		int $ttl,
		int $stale_offset
	) {
		$value = get_transient( $prefixed_key );

		CronHandler::schedule( $prefixed_key, $generator, $ttl, $stale_offset );

		return $value;
	}
}
