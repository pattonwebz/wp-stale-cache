<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: stubs the WordPress global functions used by wp-stale-cache
 * using a simple in-memory options array and a controllable clock.
 *
 * All WordPress-API stubs live in the global namespace.
 * A time() override lives in the package namespace so that StaleCache,
 * CacheEntry, and CronHandler all pick it up via PHP's namespace fallback.
 */

// ---------------------------------------------------------------------------
// Global namespace — WordPress API stubs
// ---------------------------------------------------------------------------
namespace {
    $GLOBALS['_wpsc_options']         = [];
    $GLOBALS['_wpsc_cron']            = []; // serialized-key => scheduled timestamp
    $GLOBALS['_wpsc_cron_call_count'] = 0;  // how many times wp_schedule_single_event was called
    $GLOBALS['_wpsc_mock_time']       = null;

    function get_option(string $option, $default = false)
    {
        return array_key_exists($option, $GLOBALS['_wpsc_options'])
            ? $GLOBALS['_wpsc_options'][$option]
            : $default;
    }

    function update_option(string $option, $value, $autoload = null): bool
    {
        $GLOBALS['_wpsc_options'][$option] = $value;
        return true;
    }

    function delete_option(string $option): bool
    {
        if (array_key_exists($option, $GLOBALS['_wpsc_options'])) {
            unset($GLOBALS['_wpsc_options'][$option]);
            return true;
        }
        return false;
    }

    /** @return int|false */
    function wp_next_scheduled(string $hook, array $args = [])
    {
        $cronKey = serialize([$hook, $args]);
        return $GLOBALS['_wpsc_cron'][$cronKey] ?? false;
    }

    function wp_schedule_single_event(
        int $timestamp,
        string $hook,
        array $args = [],
        bool $wp_error = false
    ): bool {
        $cronKey = serialize([$hook, $args]);
        $GLOBALS['_wpsc_cron'][$cronKey] = $timestamp;
        $GLOBALS['_wpsc_cron_call_count']++;
        return true;
    }

    function add_action(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): bool {
        return true;
    }

    function error_log(
        string $message,
        int $message_type = 0,
        ?string $destination = null,
        ?string $extra_headers = null
    ): bool {
        return true;
    }
}

// ---------------------------------------------------------------------------
// Package namespace — time() override (PHP resolves unqualified calls here first)
// ---------------------------------------------------------------------------
namespace Pattonwebz\WpStaleCache {
    function time(): int
    {
        return $GLOBALS['_wpsc_mock_time'] ?? \time();
    }
}
