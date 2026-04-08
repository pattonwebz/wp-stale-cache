<?php
/**
 * Stale-while-revalidate cache manager (Options API).
 *
 * @package pattonwebz/wp-stale-cache
 */

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

/**
 * Stale-while-revalidate cache manager backed by the WordPress Options API.
 *
 * @package pattonwebz/wp-stale-cache
 */
class StaleCache {
	/**
	 * Option name prefix applied to all keys.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Option name prefix (default: '_wpsc_').
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

		$raw_meta = get_option( $meta_key, null );

		if ( null === $raw_meta || ! is_array( $raw_meta ) ) {
			return $this->regenerate( $prefixed_key, $meta_key, $generator, $ttl, $stale_offset );
		}

		$entry = CacheEntry::from_array( $raw_meta );
		$state = $entry->get_state( time() );

		if ( 'fresh' === $state ) {
			return get_option( $prefixed_key );
		}
		if ( 'stale' === $state ) {
			return $this->serve_stale( $prefixed_key, $meta_key, $entry, $generator, $ttl, $stale_offset );
		}
		return $this->regenerate( $prefixed_key, $meta_key, $generator, $ttl, $stale_offset );
	}

	/**
	 * Delete both the value and metadata options for a key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public function forget( string $key ): void {
		$prefixed_key = $this->prefix . $key;
		delete_option( $prefixed_key );
		delete_option( $prefixed_key . '_meta' );
	}

	/**
	 * Delete all options whose names start with the given prefix.
	 *
	 * Defaults to the instance prefix when no argument is provided.
	 *
	 * @since 1.0.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string $prefix Option name prefix to match (defaults to instance prefix).
	 * @return void
	 */
	public function flush( string $prefix = '' ): void {
		global $wpdb;

		$like_prefix = $wpdb->esc_like( '' !== $prefix ? $prefix : $this->prefix ) . '%';

		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like_prefix,
			),
		);

		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
		}
	}

	/**
	 * Check the current state of a cache entry without loading its value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Cache key.
	 * @return string One of: 'fresh', 'stale', 'expired', 'missing'.
	 */
	public function get_state( string $key ): string {
		$meta_key = $this->prefix . $key . '_meta';
		$raw_meta = get_option( $meta_key, null );

		if ( null === $raw_meta || ! is_array( $raw_meta ) ) {
			return 'missing';
		}

		return CacheEntry::from_array( $raw_meta )->get_state( time() );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Execute the generator synchronously and persist the result.
	 *
	 * @param string   $prefixed_key Full option key (with prefix).
	 * @param string   $meta_key     Metadata option key.
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

		$stored = update_option( $prefixed_key, $value, false );
		if ( false === $stored ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[wp-stale-cache] Failed to store value for option "%s".', $prefixed_key ) );
		}

		$stored_meta = update_option( $meta_key, $entry->to_array(), false );
		if ( false === $stored_meta ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[wp-stale-cache] Failed to store metadata for option "%s".', $meta_key ) );
		}

		return $value;
	}

	/**
	 * Return the stale cached value and enqueue a background refresh.
	 *
	 * @param string     $prefixed_key Full option key (with prefix).
	 * @param string     $meta_key     Metadata option key.
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
		$value = get_option( $prefixed_key );

		CronHandler::schedule( $prefixed_key, $generator, $ttl, $stale_offset );

		return $value;
	}
}
