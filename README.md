# wp-stale-cache

> Stale-while-revalidate caching for WordPress using the Options API and WP-Cron.

## What it does

Instead of letting your cache expire and forcing a slow synchronous regeneration on every miss, `wp-stale-cache` keeps serving the last known value while quietly refreshing it in the background.

Three states:

| State | Condition | Behaviour |
|-------|-----------|-----------|
| **Fresh** | `now < expires_at` | Return cached value immediately. |
| **Stale** | `expires_at ≤ now < expires_at + stale_offset` | Return old value instantly; schedule a background WP-Cron refresh. |
| **Expired** | `now ≥ expires_at + stale_offset` | Regenerate synchronously, store, return fresh value. |

## Why Options, not Transients?

Most WordPress SWR cache libraries — including [`ryanhellyer/stale-cache`](https://github.com/ryanhellyer/stale-cache) and [`humanmade/hm-swr-cache`](https://github.com/humanmade/hm-swr-cache) — store their data in transients. That works for many use cases, but it comes with a silent failure mode: **transients can be evicted**.

When WordPress is running with an external object cache (Memcached, Redis, or any `WP_Object_Cache` drop-in), transients are stored in that layer rather than the database. Under memory pressure, the object cache backend is free to evict entries with no notice. Your cached data disappears. WordPress does not throw an error, log a warning, or return a meaningful signal — it just returns `false` from `get_transient()`. Your code regenerates synchronously, or worse, silently misses.

That is the expected behaviour of transients, they are transient after all.

**WordPress options are different.** `update_option()` writes to the `wp_options` database table unconditionally. A Redis flush, a Memcached restart, or a full object cache wipe does not remove an option. It survives because it lives in the database, not in a volatile memory layer.

`wp-stale-cache` is a **persistent cache** by design. That is the point. If you need a cache entry to survive a Redis flush, a Memcached restart, or a server reboot, use the default `StaleCache` class — it uses the Options API.

If you are comfortable with eviction risk — for example, you want object cache layer support and your data is cheap to regenerate — `TransientStaleCache` is available as an opt-in alternative (see [Using the Transient Backend](#using-the-transient-backend) below). But it is not the default, and it does not reflect this package's design intent.

## Requirements

- PHP **7.4+**
- WordPress **6.0+**
- No additional PHP extensions beyond `ext-json` (already required)

## Installation

```bash
composer require pattonwebz/wp-stale-cache
```

> WordPress itself is not a Composer package, so it is not listed as a dependency. Ensure WordPress is loaded before using this library.

## Quick start

### 1. Register the cron handler

Call this once during plugin or theme initialisation (e.g. `functions.php`):

```php
use Pattonwebz\WpStaleCache\CronHandler;

add_action('init', function () {
    CronHandler::register();
});
```

### 2. Cache a value

```php
use Pattonwebz\WpStaleCache\StaleCache;

$cache = new StaleCache(); // default prefix: _wpsc_

$posts = $cache->get(
    'recent_posts',
    [MyDataService::class, 'fetchRecentPosts'],
    3600,       // Fresh for 1 hour
    300         // Serve stale for 5 extra minutes while refreshing in background
);
```

The `generator` must be an **array callable** (`[ClassName::class, 'method']` or `[$object, 'method']`) when background refresh is needed, because PHP closures cannot be serialised for WP-Cron. Closures work for the initial fill and synchronous regeneration, but will fall back to logging a warning instead of scheduling a background refresh when the entry is stale.

### 3. Invalidate on change

```php
// Delete a single entry (e.g. when a post is saved)
add_action('save_post', function () use ($cache) {
    $cache->forget('recent_posts');
});
```

### 4. Flush by prefix

```php
// Flush every option this instance manages
$cache->flush();

// Flush options matching a custom prefix
$cache->flush('_wpsc_products_');
```

### 5. Check state without loading value

```php
$state = $cache->getState('recent_posts');
// Returns: 'fresh' | 'stale' | 'expired' | 'missing'
```

## Custom prefix

Pass a prefix string to the constructor to namespace your cache entries:

```php
$cache = new StaleCache( '_mysite_cache_' );
```

## Storage

Each cache key creates **two rows** in `wp_options`:

| Option name | Contains |
|-------------|----------|
| `_wpsc_{key}` | The cached value |
| `_wpsc_{key}_meta` | `['expires_at' => int, 'stale_offset' => int]` |

Both options are stored with `autoload = false` to avoid loading them on every page.

## Tradeoffs

The two-option design provides persistence and stale-while-revalidate guarantees, but comes with a cost: **every `get()`, `set()`, and `remove()` operation requires two database lookups or writes** — one for the value, one for the metadata.

This makes `wp-stale-cache` **best suited for longer-lived cached items** — hours or days — where the overhead of two DB operations is negligible compared to the benefit of persistent stale-while-revalidate behaviour and surviving object cache evictions.

**It is not ideal for:**
- **Very short TTLs** (seconds or minutes) — the two DB operations per hit dominate the cost.
- **High-frequency cache operations** — where transients or the object cache layer would be more performant.

For those use cases, consider using WordPress transients directly or `TransientStaleCache` (see [Using the Transient Backend](#using-the-transient-backend) below).

## Not suitable for

- Real-time data (stock prices, live scores) — stale data may be served for up to `stale_offset` seconds.
- Multi-server setups without a shared database — simultaneous regeneration across servers is possible in the expired state (by design; see ADR-005 in `ARCHITECTURE.md`).

## Using the Transient Backend

`TransientStaleCache` provides the same public API as `StaleCache` but stores data using `set_transient` / `get_transient` / `delete_transient` instead of the Options API. Use it if you specifically want your cache to live in the object cache layer — for example, when you are fine with the data being regenerated on eviction and you want the performance characteristics of Memcached or Redis. The trade-off is clear: **entries may be silently evicted** by the object cache backend under memory pressure. Do not use this class for anything that must survive a Redis flush or a Memcached restart.

```php
use Pattonwebz\WpStaleCache\TransientStaleCache;

$cache = new TransientStaleCache( '_wpsc_' ); // same constructor, same API

$posts = $cache->get(
    'recent_posts',
    [MyDataService::class, 'fetchRecentPosts'],
    3600,
    300,
);

$cache->forget('recent_posts');
```

> **Note:** `TransientStaleCache` does not implement a `flush()` method. WordPress provides no native way to query transients by prefix without a direct database query, and that is intentionally deferred to a future version.

## Optional Logging

`wp-stale-cache` has zero required dependencies beyond PSR-3 interfaces. For logging cache events and background refresh lifecycle, install the optional PSR-3 logger:

```bash
composer require pattonwebz/psr3-logger
```

Then inject it:

```php
use PattonWebz\Psr3Logger\Logger;
use Pattonwebz\WpStaleCache\StaleCache;

$logger = new Logger();
$cache  = new StaleCache( '_myplugin_' );
$cache->set_logger( $logger );
```

For background refresh logging, wire the logger into `CronHandler` too:

```php
use PattonWebz\Psr3Logger\Logger;
use Pattonwebz\WpStaleCache\CronHandler;

CronHandler::set_logger( new Logger() );
```

Any PSR-3 compatible logger works — not just `pattonwebz/psr3-logger`. If no logger is injected, all logging is silently suppressed.

## Examples

The examples below use PHP 7.4-compatible positional arguments. The generator must be a **static array callable** (`[ ClassName::class, 'method_name' ]`) whenever background refresh via WP-Cron is needed — PHP closures cannot be serialised for scheduling.

---

### 1. Basic usage

Register the cron handler once during plugin or theme boot, then call `get()` with a generator callable, a TTL (fresh window), and a stale offset (background-refresh window).

```php
use Pattonwebz\WpStaleCache\CronHandler;
use Pattonwebz\WpStaleCache\StaleCache;

// Register once — e.g. in the plugin bootstrap or functions.php.
add_action( 'init', function () {
    CronHandler::register();
} );

$cache = new StaleCache(); // default prefix: _wpsc_

// Fresh for 1 hour; serve stale for 5 minutes while WP-Cron refreshes.
$recent_posts = $cache->get(
    'recent_posts',
    [ MyDataService::class, 'get_recent_posts' ], // array callable — serialisable for cron
    3600, // ttl: fresh window in seconds
    300   // stale_offset: extra seconds to serve stale before forcing a sync regeneration
);
```

---

### 2. Custom prefix — namespace per plugin or theme

Pass a unique prefix string so your keys never collide with another plugin's or the default `_wpsc_` namespace.

```php
use Pattonwebz\WpStaleCache\StaleCache;

// All keys are stored as _myplugin_{key} and _myplugin_{key}_meta in wp_options.
$cache = new StaleCache( '_myplugin_' );

$menu_items = $cache->get(
    'primary_nav',
    [ MyMenuHelper::class, 'build_primary_nav' ],
    1800, // fresh for 30 minutes
    120   // serve stale for 2 minutes
);
```

You can create multiple instances with different prefixes in the same project to keep caches logically separated:

```php
$products_cache = new StaleCache( '_myshop_products_' );
$settings_cache = new StaleCache( '_myshop_settings_' );
```

---

### 3. Explicit cache busting — `forget()` and `flush()`

Use `forget()` to invalidate a single key and `flush()` to wipe all keys under a prefix.

```php
use Pattonwebz\WpStaleCache\StaleCache;

$cache = new StaleCache( '_myplugin_' );

// Invalidate a single entry whenever the underlying data changes.
// Both the value option and its _meta companion are deleted.
add_action( 'save_post', function ( $post_id ) use ( $cache ) {
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    $cache->forget( 'recent_posts' );
} );

// Flush every option managed by this cache instance.
// Useful during plugin deactivation or after a bulk import.
add_action( 'my_plugin_data_import_complete', function () use ( $cache ) {
    $cache->flush(); // flushes all _myplugin_* options
} );

// Flush a different prefix without a separate instance.
// Handy when you need to clear one logical group from a shared context.
add_action( 'switch_theme', function () {
    $cache = new StaleCache( '_mytheme_' );
    $cache->flush( '_mytheme_nav_' ); // clears only nav-related keys
} );
```

You can also inspect whether a key is already stale before deciding to bust it:

```php
$state = $cache->get_state( 'recent_posts' ); // 'fresh' | 'stale' | 'expired' | 'missing'

if ( 'fresh' !== $state ) {
    $cache->forget( 'recent_posts' );
}
```

---

### 4. Transient backend — `TransientStaleCache` as a drop-in alternative

`TransientStaleCache` exposes the same `get()`, `forget()`, and `get_state()` methods as `StaleCache`. Swap the class name to store data in transients instead of `wp_options`. The trade-off: entries may be silently evicted by the object cache under memory pressure.

```php
use Pattonwebz\WpStaleCache\TransientStaleCache;

$cache = new TransientStaleCache( '_myplugin_' );

// Identical call signature to StaleCache::get().
$feed_items = $cache->get(
    'rss_feed',
    [ MyFeedReader::class, 'fetch_items' ],
    900, // fresh for 15 minutes
    180  // serve stale for 3 more minutes
);

// Invalidate a single key.
$cache->forget( 'rss_feed' );

// Note: TransientStaleCache does not implement flush().
// WordPress provides no native API to query transients by prefix,
// so bulk deletion is deferred to a future version.
```

Choose `TransientStaleCache` when:
- Your data is cheap to regenerate on eviction.
- You want the performance characteristics of Memcached or Redis.
- You do **not** need the entry to survive a Redis flush or Memcached restart.

---

### 5. Real-world scenario — caching an external API response

The following example caches a remote weather API call for 1 hour and serves stale data for 5 minutes while WP-Cron regenerates it silently in the background. The generator is a named static method so it can be serialised for cron scheduling.

```php
<?php
// MyPlugin/WeatherService.php
namespace MyPlugin;

class WeatherService {
    /**
     * Fetch current weather data.
     *
     * Static method — required so the array callable [ WeatherService::class, 'fetch_current' ]
     * can be serialised by WP-Cron for background scheduling.
     *
     * @return array<string, mixed>
     */
    public static function fetch_current() {
        $response = wp_remote_get(
            'https://api.example.com/weather?city=London',
            [ 'timeout' => 10 ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[my-plugin] Weather API error: ' . $response->get_error_message() );
            return [];
        }

        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return is_array( $data ) ? $data : [];
    }
}
```

```php
<?php
// my-plugin.php (or a service class loaded during init)
use Pattonwebz\WpStaleCache\CronHandler;
use Pattonwebz\WpStaleCache\StaleCache;
use MyPlugin\WeatherService;

add_action( 'init', function () {
    CronHandler::register();
} );

/**
 * Return weather data for London, served from cache wherever possible.
 *
 * - Hit within the first hour   → value returned instantly from wp_options.
 * - Hit in minutes 60–65        → stale value returned instantly;
 *                                  WP-Cron schedules a background refresh.
 * - Hit after 65 minutes        → synchronous regeneration on this request.
 *
 * @return array<string, mixed>
 */
function myplugin_get_weather() {
    static $cache = null;
    if ( null === $cache ) {
        $cache = new StaleCache( '_myplugin_' );
    }

    $weather = $cache->get(
        'weather_london',
        [ WeatherService::class, 'fetch_current' ], // serialisable for WP-Cron
        3600, // fresh for 1 hour
        300   // serve stale for 5 minutes while cron refreshes
    );

    return is_array( $weather ) ? $weather : [];
}

// Invalidate if a user manually triggers a cache clear from the admin.
add_action( 'admin_post_myplugin_clear_weather_cache', function () {
    $cache = new StaleCache( '_myplugin_' );
    $cache->forget( 'weather_london' );
    wp_safe_redirect( admin_url( 'options-general.php?page=myplugin&cleared=1' ) );
    exit;
} );
```

## Licence

MIT — © William Patton
