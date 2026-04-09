<?php
/**
 * WP-Cron background cache refresh handler.
 *
 * @package pattonwebz/wp-stale-cache
 */

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

use Psr\Log\LoggerInterface;

/**
 * Registers and handles WP-Cron background cache refresh events.
 *
 * @package pattonwebz/wp-stale-cache
 */
class CronHandler {

	/**
	 * Optional PSR-3 logger instance.
	 *
	 * @var LoggerInterface|null
	 */
	private static ?LoggerInterface $logger = null;

	/**
	 * Inject a PSR-3 logger.
	 *
	 * @param LoggerInterface $logger PSR-3 compatible logger.
	 * @return void
	 */
	public static function set_logger( LoggerInterface $logger ): void {
		self::$logger = $logger;
	}

	/**
	 * Log a message if a logger has been injected.
	 *
	 * @param string $level   PSR-3 log level.
	 * @param string $message Log message.
	 * @param array  $context Context array.
	 * @return void
	 */
	private static function log_message( string $level, string $message, array $context = [] ): void {
		if ( null !== self::$logger ) {
			self::$logger->log( $level, $message, $context );
		}
	}
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
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'handle_refresh' ], 10, 4 );
	}

	/**
	 * Schedule a single background refresh event for the given key.
	 *
	 * Guards against duplicate scheduling via wp_next_scheduled().
	 *
	 * @since 1.0.0
	 *
	 * @param string          $prefixed_key Full option key (with prefix).
	 * @param array|string    $generator    Array callable or named function string — closures are not serialisable.
	 * @param int             $ttl          Fresh period in seconds.
	 * @param int             $stale_offset Stale window in seconds.
	 * @return void
	 */
	public static function schedule(
		string $prefixed_key,
		callable $generator,
		int $ttl,
		int $stale_offset
	): void {
		if ( ! is_array( $generator ) && ! is_string( $generator ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[wp-stale-cache] Cannot schedule background refresh for "%s": generator must be an array callable (e.g. [\'MyClass\', \'method\']) or a function name string — closures are not serialisable.',
				$prefixed_key,
			) );
			return;
		}

		$serialized_generator = serialize( $generator ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$args                 = [ $prefixed_key, $serialized_generator, $ttl, $stale_offset ];

		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::HOOK, $args );
		} else {
			self::log_message( 'debug', 'WPSC: Refresh already scheduled for key: {key}', [ 'key' => $prefixed_key ] );
		}
	}

	/**
	 * WP-Cron callback: regenerate and store the cached value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefixed_key         Full option key (with prefix).
	 * @param string $serialized_generator Serialised array callable or function name string.
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
		self::log_message( 'info', 'WPSC: Starting background refresh for key: {key}', [ 'key' => $prefixed_key ] );

		try {
			/** @var callable $generator */
			$generator = unserialize( $serialized_generator ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

			if ( ! is_callable( $generator ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[wp-stale-cache] Background refresh for "%s" failed: generator is not callable after unserialise.',
					$prefixed_key,
				) );
				self::log_message( 'error', 'WPSC: Background refresh failed for key: {key}', [ 'key' => $prefixed_key ] );
				return;
			}

			$value = $generator();

			if ( null === $value ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[wp-stale-cache] Background refresh for "%s" returned null.', $prefixed_key ) );
				self::log_message( 'error', 'WPSC: Background refresh failed for key: {key}', [ 'key' => $prefixed_key ] );
				return;
			}

			$meta_key = $prefixed_key . '_meta';
			$entry    = new CacheEntry( time() + $ttl, $stale_offset );

			update_option( $prefixed_key, $value, false );
			update_option( $meta_key, $entry->to_array(), false );

			self::log_message( 'info', 'WPSC: Background refresh complete for key: {key}', [ 'key' => $prefixed_key ] );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[wp-stale-cache] Background refresh for "%s" threw: %s',
				$prefixed_key,
				$e->getMessage(),
			) );
			self::log_message( 'error', 'WPSC: Background refresh failed for key: {key}', [ 'key' => $prefixed_key ] );
		}
	}
}
