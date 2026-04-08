<?php

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
 */
class TransientStaleCache
{
    /** @var string */
    private string $prefix;

    public function __construct(string $prefix = '_wpsc_')
    {
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
     * @param string   $key         Cache key (alphanumeric, underscores, hyphens)
     * @param callable $generator   Returns the value to cache
     * @param int      $ttl         Fresh period in seconds (default: 1 hour)
     * @param int      $staleOffset Additional seconds value is served while stale (default: 5 min)
     * @return mixed
     */
    public function get(
        string $key,
        callable $generator,
        int $ttl = 3600,
        int $staleOffset = 300
    ) {
        $prefixedKey = $this->prefix . $key;
        $metaKey = $prefixedKey . '_meta';

        $rawMeta = get_transient($metaKey);

        if ($rawMeta === false || !is_array($rawMeta)) {
            return $this->regenerate($prefixedKey, $metaKey, $generator, $ttl, $staleOffset);
        }

        $entry = CacheEntry::fromArray($rawMeta);
        $state = $entry->getState(time());

        if ($state === 'fresh') {
            return get_transient($prefixedKey);
        }
        if ($state === 'stale') {
            return $this->serveStale($prefixedKey, $metaKey, $entry, $generator, $ttl, $staleOffset);
        }
        return $this->regenerate($prefixedKey, $metaKey, $generator, $ttl, $staleOffset);
    }

    /**
     * Delete both the value and metadata transients for a key.
     */
    public function forget(string $key): void
    {
        $prefixedKey = $this->prefix . $key;
        delete_transient($prefixedKey);
        delete_transient($prefixedKey . '_meta');
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Execute the generator synchronously and persist the result as transients.
     */
    private function regenerate(
        string $prefixedKey,
        string $metaKey,
        callable $generator,
        int $ttl,
        int $staleOffset
    ) {
        $value = $generator();
        $entry = new CacheEntry(time() + $ttl, $staleOffset);

        // Store the value for the full stale window so it remains retrievable
        // while stale. The meta transient tracks the true expiry boundary.
        $totalTtl = $ttl + $staleOffset;

        $stored = set_transient($prefixedKey, $value, $totalTtl);
        if ($stored === false) {
            error_log(sprintf('[wp-stale-cache] Failed to store value for transient "%s".', $prefixedKey));
        }

        $storedMeta = set_transient($metaKey, $entry->toArray(), $totalTtl);
        if ($storedMeta === false) {
            error_log(sprintf('[wp-stale-cache] Failed to store metadata for transient "%s".', $metaKey));
        }

        return $value;
    }

    /**
     * Return the stale cached value and enqueue a background refresh.
     */
    private function serveStale(
        string $prefixedKey,
        string $metaKey,
        CacheEntry $entry,
        callable $generator,
        int $ttl,
        int $staleOffset
    ) {
        $value = get_transient($prefixedKey);

        CronHandler::schedule($prefixedKey, $generator, $ttl, $staleOffset);

        return $value;
    }
}
