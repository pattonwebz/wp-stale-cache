<?php

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache\Tests;

use Pattonwebz\WpStaleCache\CronHandler;
use Pattonwebz\WpStaleCache\StaleCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Minimal serialisable generator for logger tests (loaded before DummyGenerator
 * in alphabetical order, so we cannot rely on StaleCacheTest's DummyGenerator).
 */
final class LoggerDummyGenerator {
	public static function generate(): string {
		return 'generated-value';
	}
}

class LoggerTest extends TestCase {

	private const NOW = 1_700_000_000;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wpsc_options']         = [];
		$GLOBALS['_wpsc_cron']            = [];
		$GLOBALS['_wpsc_cron_call_count'] = 0;
		$GLOBALS['_wpsc_mock_time']       = self::NOW;
	}

	protected function tearDown(): void {
		parent::tearDown();
		$GLOBALS['_wpsc_mock_time'] = null;
		// Reset static CronHandler logger to avoid bleed between tests.
		CronHandler::set_logger( new NullLogger() );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function seedCache(
		string $key,
		$value,
		int $expires_at,
		int $stale_offset = 300,
		string $prefix = '_wpsc_'
	): void {
		$prefixed_key                                       = $prefix . $key;
		$GLOBALS['_wpsc_options'][ $prefixed_key ]          = $value;
		$GLOBALS['_wpsc_options'][ $prefixed_key . '_meta' ] = [
			'expires_at'   => $expires_at,
			'stale_offset' => $stale_offset,
		];
	}

	// -------------------------------------------------------------------------
	// No-logger path (NullLogger / no logger set)
	// -------------------------------------------------------------------------

	public function test_no_logger_silent_on_fresh(): void {
		$this->seedCache( 'fresh_key', 'cached-value', self::NOW + 500 );

		$cache  = new StaleCache();
		$result = $cache->get( 'fresh_key', [ LoggerDummyGenerator::class, 'generate' ] );

		self::assertSame( 'cached-value', $result );
	}

	// -------------------------------------------------------------------------
	// Fluent setter
	// -------------------------------------------------------------------------

	public function test_set_logger_fluent_returns_self(): void {
		$cache  = new StaleCache();
		$logger = new NullLogger();

		$returned = $cache->set_logger( $logger );

		self::assertSame( $cache, $returned );
	}

	// -------------------------------------------------------------------------
	// Constructor injection
	// -------------------------------------------------------------------------

	public function test_constructor_logger_injection(): void {
		$this->seedCache( 'fresh_key', 'cached-value', self::NOW + 500 );

		$mock_logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		$mock_logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->equalTo( 'debug' ),
				$this->stringContains( 'fresh' )
			);

		$cache = new StaleCache( '_wpsc_', $mock_logger );
		$cache->get( 'fresh_key', [ LoggerDummyGenerator::class, 'generate' ] );
	}

	// -------------------------------------------------------------------------
	// Fresh state → debug
	// -------------------------------------------------------------------------

	public function test_fresh_state_logs_debug(): void {
		$this->seedCache( 'fresh_key', 'cached-value', self::NOW + 500 );

		$mock_logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		$mock_logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->equalTo( 'debug' ),
				$this->stringContains( 'fresh' )
			);

		$cache = new StaleCache();
		$cache->set_logger( $mock_logger );
		$cache->get( 'fresh_key', [ LoggerDummyGenerator::class, 'generate' ] );
	}

	// -------------------------------------------------------------------------
	// Stale state → info
	// -------------------------------------------------------------------------

	public function test_stale_state_logs_info(): void {
		// expires_at in the past; stale window still open
		$this->seedCache( 'stale_key', 'stale-value', self::NOW - 100, 300 );

		$mock_logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		$mock_logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->equalTo( 'info' ),
				$this->stringContains( 'stale' )
			);

		$cache = new StaleCache();
		$cache->set_logger( $mock_logger );
		$cache->get( 'stale_key', [ LoggerDummyGenerator::class, 'generate' ], 3600, 300 );
	}

	// -------------------------------------------------------------------------
	// Expired state → warning
	// -------------------------------------------------------------------------

	public function test_expired_state_logs_warning(): void {
		// now >= expires_at + stale_offset → expired
		$this->seedCache( 'exp_key', 'old-value', self::NOW - 400, 300 );

		$mock_logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		$mock_logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->equalTo( 'warning' ),
				$this->stringContains( 'expired' )
			);

		$cache = new StaleCache();
		$cache->set_logger( $mock_logger );
		$cache->get( 'exp_key', [ LoggerDummyGenerator::class, 'generate' ], 3600, 300 );
	}

	// -------------------------------------------------------------------------
	// Missing state (cache miss) → warning
	// -------------------------------------------------------------------------

	public function test_missing_state_logs_warning(): void {
		$mock_logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		$mock_logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->equalTo( 'warning' ),
				$this->stringContains( 'missing' )
			);

		$cache = new StaleCache();
		$cache->set_logger( $mock_logger );
		$cache->get( 'no_such_key', [ LoggerDummyGenerator::class, 'generate' ], 3600, 300 );
	}

	// -------------------------------------------------------------------------
	// NullLogger — no exceptions through fresh / stale / expired paths
	// -------------------------------------------------------------------------

	public function test_null_logger_no_exceptions(): void {
		$null_logger = new NullLogger();

		// fresh
		$this->seedCache( 'nl_fresh', 'fresh-val', self::NOW + 500 );
		$cache = new StaleCache( '_wpsc_', $null_logger );
		$cache->get( 'nl_fresh', [ LoggerDummyGenerator::class, 'generate' ] );

		// stale
		$this->seedCache( 'nl_stale', 'stale-val', self::NOW - 100, 300 );
		$cache->get( 'nl_stale', [ LoggerDummyGenerator::class, 'generate' ], 3600, 300 );

		// expired
		$this->seedCache( 'nl_exp', 'old-val', self::NOW - 400, 300 );
		$cache->get( 'nl_exp', [ LoggerDummyGenerator::class, 'generate' ], 3600, 300 );

		self::assertTrue( true ); // reaching here = no exception thrown
	}

	// -------------------------------------------------------------------------
	// CronHandler static logger — info logged on successful refresh
	// -------------------------------------------------------------------------

	public function test_cronhandler_set_logger_static(): void {
		$prefixed_key        = '_wpsc_cron_log_key';
		$serialized_generator = serialize( [ LoggerDummyGenerator::class, 'generate' ] );

		// Seed the cache entry so handle_refresh can store the result.
		$GLOBALS['_wpsc_options'][ $prefixed_key ]          = 'old-val';
		$GLOBALS['_wpsc_options'][ $prefixed_key . '_meta' ] = [
			'expires_at'   => self::NOW - 400,
			'stale_offset' => 300,
		];

		$mock_logger = $this->createMock( \Psr\Log\LoggerInterface::class );
		// Expect at least one 'info' call (start + complete both use 'info').
		$mock_logger->expects( $this->atLeastOnce() )
			->method( 'log' )
			->with(
				$this->equalTo( 'info' ),
				$this->anything()
			);

		CronHandler::set_logger( $mock_logger );
		CronHandler::handle_refresh( $prefixed_key, $serialized_generator, 3600, 300 );
	}
}
