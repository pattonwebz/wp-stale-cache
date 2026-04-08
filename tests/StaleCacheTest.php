<?php

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache\Tests;

use Pattonwebz\WpStaleCache\CronHandler;
use Pattonwebz\WpStaleCache\StaleCache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A static generator whose call-count and return value are controllable from tests.
 */
final class DummyGenerator
{
    public static int   $callCount   = 0;
    /** @var mixed */
    public static $returnValue = 'generated-value';

    /** @return mixed */
    public static function generate()
    {
        self::$callCount++;
        return self::$returnValue;
    }

    /**
     * @param mixed $returnValue
     */
    public static function reset($returnValue = 'generated-value'): void
    {
        self::$callCount   = 0;
        self::$returnValue = $returnValue;
    }

    /** @return mixed */
    public static function throwing()
    {
        self::$callCount++;
        throw new RuntimeException('generator exploded');
    }
}

/**
 * Integration tests for StaleCache::get() covering the full decision tree:
 *
 *   now < expires_at                         → FRESH  (return, no cron)
 *   expires_at <= now < expires_at + offset  → STALE  (return + schedule cron)
 *   now >= expires_at + offset               → EXPIRED (regenerate sync)
 */
class StaleCacheTest extends TestCase
{
    /** Arbitrary "current time" used across most tests. */
    private const NOW = 1_700_000_000;

    private StaleCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global WordPress stubs
        $GLOBALS['_wpsc_options']         = [];
        $GLOBALS['_wpsc_cron']            = [];
        $GLOBALS['_wpsc_cron_call_count'] = 0;
        $GLOBALS['_wpsc_mock_time']       = self::NOW;

        DummyGenerator::reset();

        $this->cache = new StaleCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $GLOBALS['_wpsc_mock_time'] = null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Seed a cache entry directly into the WP options mock.
     */
    private function seedCache(
        string $key,
        $value,
        int    $expiresAt,
        int    $staleOffset = 300,
        string $prefix = '_wpsc_'
    ): void {
        $prefixedKey = $prefix . $key;
        $GLOBALS['_wpsc_options'][$prefixedKey]              = $value;
        $GLOBALS['_wpsc_options'][$prefixedKey . '_meta']    = [
            'expires_at'   => $expiresAt,
            'stale_offset' => $staleOffset,
        ];
    }

    /**
     * Build the cron-store key matching what CronHandler::schedule() produces
     * so tests can pre-seed or assert on it.
     *
     * @param array $generator Array callable, e.g. [DummyGenerator::class, 'generate']
     */
    private function cronKey(
        string $prefixedKey,
        array  $generator,
        int    $ttl,
        int    $staleOffset
    ): string {
        $args = [$prefixedKey, serialize($generator), $ttl, $staleOffset];
        return serialize([CronHandler::HOOK, $args]);
    }

    // -------------------------------------------------------------------------
    // Scenario 1: Cache miss — generator called, value stored and returned
    // -------------------------------------------------------------------------

    public function testCacheMissCallsGeneratorAndReturnsValue(): void
    {
        $result = $this->cache->get('miss_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertSame('generated-value', $result);
        self::assertSame(1, DummyGenerator::$callCount);
    }

    public function testCacheMissStoresBothValueAndMetaOptions(): void
    {
        $this->cache->get('miss_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertArrayHasKey('_wpsc_miss_key', $GLOBALS['_wpsc_options']);
        self::assertArrayHasKey('_wpsc_miss_key_meta', $GLOBALS['_wpsc_options']);
    }

    public function testCacheMissStoresCorrectExpiresAt(): void
    {
        $this->cache->get('miss_key', [DummyGenerator::class, 'generate'], 3600, 300);

        $meta = $GLOBALS['_wpsc_options']['_wpsc_miss_key_meta'];
        self::assertSame(self::NOW + 3600, $meta['expires_at']);
    }

    // -------------------------------------------------------------------------
    // Scenario 2: Fresh hit — generator NOT called, cached value returned
    // -------------------------------------------------------------------------

    public function testFreshHitReturnsCachedValueWithoutCallingGenerator(): void
    {
        $this->seedCache('fresh_key', 'cached-content', self::NOW + 500);

        $result = $this->cache->get('fresh_key', [DummyGenerator::class, 'generate']);

        self::assertSame('cached-content', $result);
        self::assertSame(0, DummyGenerator::$callCount);
    }

    public function testFreshHitDoesNotScheduleCron(): void
    {
        $this->seedCache('fresh_key', 'cached-content', self::NOW + 500);

        $this->cache->get('fresh_key', [DummyGenerator::class, 'generate']);

        self::assertSame(0, $GLOBALS['_wpsc_cron_call_count']);
    }

    // -------------------------------------------------------------------------
    // Scenario 3: Stale hit — cached value returned AND cron scheduled
    // -------------------------------------------------------------------------

    public function testStaleHitReturnsCachedValueWithoutCallingGenerator(): void
    {
        // expires_at is in the past; stale window extends 300 s further
        $this->seedCache('stale_key', 'stale-content', self::NOW - 100, 300);

        $result = $this->cache->get('stale_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertSame('stale-content', $result);
        self::assertSame(0, DummyGenerator::$callCount);
    }

    public function testStaleHitSchedulesCronRefresh(): void
    {
        $this->seedCache('stale_key', 'stale-content', self::NOW - 100, 300);

        $this->cache->get('stale_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertSame(1, $GLOBALS['_wpsc_cron_call_count']);
    }

    public function testStaleHitSchedulesCronWithCorrectHookAndArgs(): void
    {
        $this->seedCache('stale_key', 'stale-content', self::NOW - 100, 300);

        $this->cache->get('stale_key', [DummyGenerator::class, 'generate'], 3600, 300);

        $expectedKey = $this->cronKey('_wpsc_stale_key', [DummyGenerator::class, 'generate'], 3600, 300);
        self::assertArrayHasKey($expectedKey, $GLOBALS['_wpsc_cron']);
    }

    // -------------------------------------------------------------------------
    // Scenario 4: Duplicate stale — cron NOT double-scheduled
    // -------------------------------------------------------------------------

    public function testStaleHitDoesNotDoubleScheduleCronWhenAlreadyQueued(): void
    {
        $this->seedCache('dup_key', 'stale-content', self::NOW - 100, 300);

        // Pre-populate the cron store as if it was already scheduled
        $cronKey = $this->cronKey('_wpsc_dup_key', [DummyGenerator::class, 'generate'], 3600, 300);
        $GLOBALS['_wpsc_cron'][$cronKey] = self::NOW - 5;

        $this->cache->get('dup_key', [DummyGenerator::class, 'generate'], 3600, 300);

        // wp_schedule_single_event must not have been invoked
        self::assertSame(0, $GLOBALS['_wpsc_cron_call_count']);
    }

    // -------------------------------------------------------------------------
    // Scenario 5: Expired — generator called synchronously, new value returned
    // -------------------------------------------------------------------------

    public function testExpiredEntryCallsGeneratorAndReturnsNewValue(): void
    {
        DummyGenerator::reset('fresh-regenerated');
        // now >= expires_at + stale_offset  →  expired
        $this->seedCache('exp_key', 'old-value', self::NOW - 400, 300); // ends at NOW - 100

        $result = $this->cache->get('exp_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertSame('fresh-regenerated', $result);
        self::assertSame(1, DummyGenerator::$callCount);
    }

    public function testExpiredEntryUpdatesStoredValue(): void
    {
        DummyGenerator::reset('fresh-regenerated');
        $this->seedCache('exp_key', 'old-value', self::NOW - 400, 300);

        $this->cache->get('exp_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertSame('fresh-regenerated', $GLOBALS['_wpsc_options']['_wpsc_exp_key']);
    }

    public function testExpiredEntryDoesNotScheduleCron(): void
    {
        $this->seedCache('exp_key', 'old-value', self::NOW - 400, 300);

        $this->cache->get('exp_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertSame(0, $GLOBALS['_wpsc_cron_call_count']);
    }

    // -------------------------------------------------------------------------
    // Scenario 6: forget() — both options deleted
    // -------------------------------------------------------------------------

    public function testForgetRemovesValueOption(): void
    {
        $this->seedCache('del_key', 'some-value', self::NOW + 1000);

        $this->cache->forget('del_key');

        self::assertArrayNotHasKey('_wpsc_del_key', $GLOBALS['_wpsc_options']);
    }

    public function testForgetRemovesMetaOption(): void
    {
        $this->seedCache('del_key', 'some-value', self::NOW + 1000);

        $this->cache->forget('del_key');

        self::assertArrayNotHasKey('_wpsc_del_key_meta', $GLOBALS['_wpsc_options']);
    }

    public function testForgetOnMissingKeyDoesNotThrow(): void
    {
        // Should silently succeed even when the key doesn't exist
        $this->cache->forget('nonexistent_key');
        self::assertTrue(true); // reaching here is the assertion
    }

    // -------------------------------------------------------------------------
    // Scenario 7: Generator exception — nothing stored, exception propagates
    // -------------------------------------------------------------------------

    public function testGeneratorExceptionPropagatesOnCacheMiss(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('generator exploded');

        $this->cache->get('throw_key', [DummyGenerator::class, 'throwing'], 3600, 300);
    }

    public function testGeneratorExceptionLeavesNoOptionsStored(): void
    {
        try {
            $this->cache->get('throw_key', [DummyGenerator::class, 'throwing'], 3600, 300);
        } catch (RuntimeException $e) {
            // expected
        }

        self::assertArrayNotHasKey('_wpsc_throw_key', $GLOBALS['_wpsc_options']);
        self::assertArrayNotHasKey('_wpsc_throw_key_meta', $GLOBALS['_wpsc_options']);
    }

    // -------------------------------------------------------------------------
    // Scenario 8: Zero stale offset — stale window collapses; expired immediately
    // -------------------------------------------------------------------------

    public function testZeroStaleOffsetMeansExpiredImmediatelyAfterTtl(): void
    {
        // With stale_offset = 0, expires_at + 0 = expires_at, so now >= expires_at → expired
        $this->seedCache('zero_offset_key', 'old-value', self::NOW - 1, 0);

        DummyGenerator::reset('fresh-zero');
        $result = $this->cache->get('zero_offset_key', [DummyGenerator::class, 'generate'], 3600, 0);

        self::assertSame('fresh-zero', $result);
        self::assertSame(1, DummyGenerator::$callCount);
    }

    public function testZeroStaleOffsetNeverSchedulesCron(): void
    {
        $this->seedCache('zero_offset_key', 'old-value', self::NOW - 1, 0);

        $this->cache->get('zero_offset_key', [DummyGenerator::class, 'generate'], 3600, 0);

        self::assertSame(0, $GLOBALS['_wpsc_cron_call_count']);
    }

    public function testZeroStaleOffsetFreshEntryStillReturnsCachedValue(): void
    {
        // expires_at is in the future; zero stale_offset doesn't affect fresh entries
        $this->seedCache('zero_offset_fresh', 'still-fresh', self::NOW + 100, 0);

        $result = $this->cache->get('zero_offset_fresh', [DummyGenerator::class, 'generate']);

        self::assertSame('still-fresh', $result);
        self::assertSame(0, DummyGenerator::$callCount);
    }

    // -------------------------------------------------------------------------
    // Scenario 9: Custom prefix — option keys use the configured prefix
    // -------------------------------------------------------------------------

    public function testCustomPrefixUsedForValueOptionKey(): void
    {
        $custom = new StaleCache('_custom_');

        $custom->get('my_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertArrayHasKey('_custom_my_key', $GLOBALS['_wpsc_options']);
        self::assertArrayNotHasKey('_wpsc_my_key', $GLOBALS['_wpsc_options']);
    }

    public function testCustomPrefixUsedForMetaOptionKey(): void
    {
        $custom = new StaleCache('_custom_');

        $custom->get('my_key', [DummyGenerator::class, 'generate'], 3600, 300);

        self::assertArrayHasKey('_custom_my_key_meta', $GLOBALS['_wpsc_options']);
        self::assertArrayNotHasKey('_wpsc_my_key_meta', $GLOBALS['_wpsc_options']);
    }

    public function testCustomPrefixFreshHitReadsFromCorrectOption(): void
    {
        $custom = new StaleCache('_custom_');
        $this->seedCache('pref_key', 'prefixed-value', self::NOW + 500, 300, '_custom_');

        $result = $custom->get('pref_key', [DummyGenerator::class, 'generate']);

        self::assertSame('prefixed-value', $result);
        self::assertSame(0, DummyGenerator::$callCount);
    }

    // -------------------------------------------------------------------------
    // Stale hit with closure generator — cron NOT scheduled (closures can't serialize)
    // -------------------------------------------------------------------------

    public function testStaleHitWithClosureGeneratorDoesNotScheduleCron(): void
    {
        $this->seedCache('closure_stale', 'stale-content', self::NOW - 100, 300);

        $called = false;
        // Closure is not serialisable → CronHandler::schedule() skips scheduling
        $result = $this->cache->get('closure_stale', static function () use (&$called): string {
            $called = true;
            return 'closure-value';
        }, 3600, 300);

        self::assertSame('stale-content', $result);
        self::assertFalse($called, 'Closure generator must not be called on stale hit');
        self::assertSame(0, $GLOBALS['_wpsc_cron_call_count']);
    }
}
