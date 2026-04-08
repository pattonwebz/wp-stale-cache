<?php
/**
 * WP-Cron background cache refresh handler.
 *
 * @package pattonwebz/wp-stale-cache
 */

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

/**
 * Registers and handles WP-Cron background cache refresh events.
 *
 * @package pattonwebz/wp-stale-cache
 */
class CronHandler
{
	/**
	 * WP-Cron hook name for background refresh events.
	 */
	public const HOOK = 'wpsc_refresh';

	/**
	 * Register the WP-Cron action hook.
	 *
	 * Call once during plugin/theme initialisation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register(): void
	{
		add_action( self::HOOK, [ self::class, 'handle_refresh' ], 10, 4 );
	}

	/**
	 * Schedule a single background refresh event for the given key.
	 *
	 * Guards against duplicate scheduling via wp_next_scheduled().
	 *
	 * @since 1.0.0
	 *
	 * @param string   $prefixed_key Full option key (with prefix).
	 * @param callable $generator    Array callable — closures are not serialisable.
	 * @param int      $ttl          Fresh period in seconds.
	 * @param int      $stale_offset Stale window in seconds.
	 * @return void
	 */
	public static function schedule(
		string $prefixed_key,
		callable $generator,
		int $ttl,
		int $stale_offset
	): void {
		if ( ! is_array( $generator ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[wp-stale-cache] Cannot schedule background refresh for "%s": generator must be an array callable (closures are not serialisable).',
				$prefixed_key,
			) );
			return;
		}

		$serialized_generator = serialize( $generator ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$args                 = [ $prefixed_key, $serialized_generator, $ttl, $stale_offset ];

		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::HOOK, $args );
		}
	}

	/**
	 * WP-Cron callback: regenerate and store the cached value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefixed_key         Full option key (with prefix).
	 * @param string $serialized_generator Serialised array callable.
	 * @param int    $ttl                  Fresh period in seconds.
	 * @param int    $stale_offset         Stale window in seconds.
	 * @return void
	 */
	public static function handle_refresh(
		string $prefixed_key,
		string $serialized_generator,
		int $ttl,
		int $stale_offset
	): void {
		try {
			/** @var callable $generator */
			$generator = unserialize( $serialized_generator ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

			if ( ! is_callable( $generator ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[wp-stale-cache] Background refresh for "%s" failed: generator is not callable after unserialise.',
					$prefixed_key,
				) );
				return;
			}

			$value    = $generator();
			$meta_key = $prefixed_key . '_meta';
			$entry    = new CacheEntry( time() + $ttl, $stale_offset );

			update_option( $prefixed_key, $value, false );
			update_option( $meta_key, $entry->to_array(), false );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[wp-stale-cache] Background refresh for "%s" threw: %s',
				$prefixed_key,
				$e->getMessage(),
			) );
		}
	}
}
