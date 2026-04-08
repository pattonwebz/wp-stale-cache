<?php

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache\Tests;

use Pattonwebz\WpStaleCache\CacheEntry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CacheEntry.
 *
 * Time-sensitive methods (isFresh, isStale, isExpired) rely on the
 * Pattonwebz\WpStaleCache\time() override defined in bootstrap.php.
 * Tests that exercise getState(int $now) directly pass the timestamp
 * as an argument and require no clock mock.
 */
class CacheEntryTest extends TestCase
{
    // Anchor timestamps — chosen to be obviously arbitrary, not "now"
    private const EXPIRES_AT    = 2_000_000;
    private const STALE_OFFSET  = 300;
    private const STALE_END     = self::EXPIRES_AT + self::STALE_OFFSET; // 2_000_300

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_wpsc_mock_time'] = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $GLOBALS['_wpsc_mock_time'] = null;
    }

    // -------------------------------------------------------------------------
    // isFresh() — delegates to time() internally
    // -------------------------------------------------------------------------

    public function testIsFreshReturnsTrueWhenNowIsBeforeExpiresAt(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::EXPIRES_AT - 1;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertTrue($entry->isFresh());
    }

    public function testIsFreshReturnsFalseAtExactExpiryBoundary(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::EXPIRES_AT;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertFalse($entry->isFresh());
    }

    public function testIsFreshReturnsFalseWhenInStaleWindow(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::EXPIRES_AT + 1;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertFalse($entry->isFresh());
    }

    // -------------------------------------------------------------------------
    // isStale() — delegates to time() internally
    // -------------------------------------------------------------------------

    public function testIsStaleReturnsFalseWhenFresh(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::EXPIRES_AT - 1;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertFalse($entry->isStale());
    }

    public function testIsStaleReturnsTrueAtExactExpiryBoundary(): void
    {
        // expires_at <= now < expires_at + stale_offset  →  stale
        $GLOBALS['_wpsc_mock_time'] = self::EXPIRES_AT;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertTrue($entry->isStale());
    }

    public function testIsStaleReturnsTrueInsideStaleWindow(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::EXPIRES_AT + 150; // midpoint of window
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertTrue($entry->isStale());
    }

    public function testIsStaleReturnsTrueOneBeforeStaleWindowEnd(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::STALE_END - 1;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertTrue($entry->isStale());
    }

    public function testIsStaleReturnsFalseAtExactStaleEndBoundary(): void
    {
        // now === expires_at + stale_offset  →  expired, not stale
        $GLOBALS['_wpsc_mock_time'] = self::STALE_END;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertFalse($entry->isStale());
    }

    // -------------------------------------------------------------------------
    // isExpired() — delegates to time() internally
    // -------------------------------------------------------------------------

    public function testIsExpiredReturnsFalseWhenFresh(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::EXPIRES_AT - 1;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertFalse($entry->isExpired());
    }

    public function testIsExpiredReturnsFalseInsideStaleWindow(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::EXPIRES_AT + 1;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertFalse($entry->isExpired());
    }

    public function testIsExpiredReturnsTrueAtExactStaleEndBoundary(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::STALE_END;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertTrue($entry->isExpired());
    }

    public function testIsExpiredReturnsTrueWellPastExpiry(): void
    {
        $GLOBALS['_wpsc_mock_time'] = self::STALE_END + 99_999;
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);

        self::assertTrue($entry->isExpired());
    }

    // -------------------------------------------------------------------------
    // getState(int $now) — explicit timestamp, no clock mock required
    // -------------------------------------------------------------------------

    public function testGetStateReturnsFreshOneBeforeExpiresAt(): void
    {
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);
        self::assertSame('fresh', $entry->getState(self::EXPIRES_AT - 1));
    }

    public function testGetStateReturnsStaleAtExactExpiryBoundary(): void
    {
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);
        self::assertSame('stale', $entry->getState(self::EXPIRES_AT));
    }

    public function testGetStateReturnsStaleOneBeforeStaleWindowEnd(): void
    {
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);
        self::assertSame('stale', $entry->getState(self::STALE_END - 1));
    }

    public function testGetStateReturnsExpiredAtExactStaleEndBoundary(): void
    {
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);
        self::assertSame('expired', $entry->getState(self::STALE_END));
    }

    public function testGetStateReturnsExpiredWellAfterStaleWindow(): void
    {
        $entry = new CacheEntry(self::EXPIRES_AT, self::STALE_OFFSET);
        self::assertSame('expired', $entry->getState(self::STALE_END + 100_000));
    }

    // -------------------------------------------------------------------------
    // toArray() / fromArray() round-trip
    // -------------------------------------------------------------------------

    public function testToArrayContainsExpectedKeys(): void
    {
        $entry = new CacheEntry(1_704_067_200, 600);
        $array = $entry->toArray();

        self::assertArrayHasKey('expires_at', $array);
        self::assertArrayHasKey('stale_offset', $array);
    }

    public function testToArrayContainsExpectedValues(): void
    {
        $entry = new CacheEntry(1_704_067_200, 600);
        $array = $entry->toArray();

        self::assertSame(1_704_067_200, $array['expires_at']);
        self::assertSame(600, $array['stale_offset']);
    }

    public function testFromArrayRestoresExactValues(): void
    {
        $entry     = new CacheEntry(1_704_067_200, 600);
        $restored  = CacheEntry::fromArray($entry->toArray());

        self::assertSame($entry->expiresAt,   $restored->expiresAt);
        self::assertSame($entry->staleOffset, $restored->staleOffset);
    }

    public function testFromArrayCastsStringValuesToInt(): void
    {
        $restored = CacheEntry::fromArray(['expires_at' => '1704067200', 'stale_offset' => '300']);

        self::assertSame(1_704_067_200, $restored->expiresAt);
        self::assertSame(300, $restored->staleOffset);
    }
}
