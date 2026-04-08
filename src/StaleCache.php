<?php

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

/**
 * Stale-while-revalidate cache manager backed by the WordPress Options API.
 */
class StaleCache
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

        $rawMeta = get_option($metaKey, null);

        if ($rawMeta === null || !is_array($rawMeta)) {
            return $this->regenerate($prefixedKey, $metaKey, $generator, $ttl, $staleOffset);
        }

        $entry = CacheEntry::fromArray($rawMeta);
        $state = $entry->getState(time());

        if ($state === 'fresh') {
            return get_option($prefixedKey);
        }
        if ($state === 'stale') {
            return $this->serveStale($prefixedKey, $metaKey, $entry, $generator, $ttl, $staleOffset);
        }
        return $this->regenerate($prefixedKey, $metaKey, $generator, $ttl, $staleOffset);
    }

    /**
     * Delete both the value and metadata options for a key.
     */
    public function forget(string $key): void
    {
        $prefixedKey = $this->prefix . $key;
        delete_option($prefixedKey);
        delete_option($prefixedKey . '_meta');
    }

    /**
     * Delete all options whose names start with the given prefix.
     * Defaults to the instance prefix when no argument is provided.
     *
     * @param string $prefix Option name prefix to match (defaults to instance prefix)
     */
    public function flush(string $prefix = ''): void
    {
        global $wpdb;

        $likePrefix = $wpdb->esc_like($prefix !== '' ? $prefix : $this->prefix) . '%';

        $optionNames = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $likePrefix,
            ),
        );

        foreach ($optionNames as $optionName) {
            delete_option($optionName);
        }
    }

    /**
     * Check the current state of a cache entry without loading its value.
     *
     * @return string One of: 'fresh', 'stale', 'expired', 'missing'
     */
    public function getState(string $key): string
    {
        $metaKey = $this->prefix . $key . '_meta';
        $rawMeta = get_option($metaKey, null);

        if ($rawMeta === null || !is_array($rawMeta)) {
            return 'missing';
        }

        return CacheEntry::fromArray($rawMeta)->getState(time());
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Execute the generator synchronously and persist the result.
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

        $stored = update_option($prefixedKey, $value, false);
        if ($stored === false) {
            error_log(sprintf('[wp-stale-cache] Failed to store value for option "%s".', $prefixedKey));
        }

        $storedMeta = update_option($metaKey, $entry->toArray(), false);
        if ($storedMeta === false) {
            error_log(sprintf('[wp-stale-cache] Failed to store metadata for option "%s".', $metaKey));
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
        $value = get_option($prefixedKey);

        CronHandler::schedule($prefixedKey, $generator, $ttl, $staleOffset);

        return $value;
    }
}
