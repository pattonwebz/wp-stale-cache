<?php

declare(strict_types=1);

namespace Pattonwebz\WpStaleCache;

/**
 * Registers and handles WP-Cron background cache refresh events.
 */
class CronHandler
{
    /** WP-Cron hook name for background refresh events. */
    public const HOOK = 'wpsc_refresh';

    /**
     * Register the WP-Cron action hook.
     * Call once during plugin/theme initialisation.
     */
    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'handleRefresh'], 10, 4);
    }

    /**
     * Schedule a single background refresh event for the given key.
     * Guards against duplicate scheduling via wp_next_scheduled().
     *
     * @param string   $key             Cache key (without prefix)
     * @param string   $prefixedKey     Full option key (with prefix)
     * @param callable $generator       Array callable — closures are not serialisable
     * @param int      $ttl             Fresh period in seconds
     * @param int      $staleOffset     Stale window in seconds
     */
    public static function schedule(
        string $prefixedKey,
        callable $generator,
        int $ttl,
        int $staleOffset,
    ): void {
        if (!is_array($generator)) {
            error_log(sprintf(
                '[wp-stale-cache] Cannot schedule background refresh for "%s": generator must be an array callable (closures are not serialisable).',
                $prefixedKey,
            ));
            return;
        }

        $serializedGenerator = serialize($generator);
        $args = [$prefixedKey, $serializedGenerator, $ttl, $staleOffset];

        if (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time(), self::HOOK, $args);
        }
    }

    /**
     * WP-Cron callback: regenerate and store the cached value.
     *
     * @param string $prefixedKey        Full option key (with prefix)
     * @param string $serializedGenerator Serialised array callable
     * @param int    $ttl               Fresh period in seconds
     * @param int    $staleOffset       Stale window in seconds
     */
    public static function handleRefresh(
        string $prefixedKey,
        string $serializedGenerator,
        int $ttl,
        int $staleOffset,
    ): void {
        try {
            /** @var callable $generator */
            $generator = unserialize($serializedGenerator);

            if (!is_callable($generator)) {
                error_log(sprintf(
                    '[wp-stale-cache] Background refresh for "%s" failed: generator is not callable after unserialise.',
                    $prefixedKey,
                ));
                return;
            }

            $value = $generator();
            $metaKey = $prefixedKey . '_meta';
            $entry = new CacheEntry(
                expiresAt:   time() + $ttl,
                staleOffset: $staleOffset,
            );

            update_option($prefixedKey, $value, false);
            update_option($metaKey, $entry->toArray(), false);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[wp-stale-cache] Background refresh for "%s" threw: %s',
                $prefixedKey,
                $e->getMessage(),
            ));
        }
    }
}
