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
    key: 'recent_posts',
    generator: [MyDataService::class, 'fetchRecentPosts'],
    ttl: 3600,       // Fresh for 1 hour
    staleOffset: 300 // Serve stale for 5 extra minutes while refreshing in background
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
$cache = new StaleCache(prefix: '_mysite_cache_');
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

$cache = new TransientStaleCache(prefix: '_wpsc_'); // same constructor, same API

$posts = $cache->get(
    key: 'recent_posts',
    generator: [MyDataService::class, 'fetchRecentPosts'],
    ttl: 3600,
    staleOffset: 300,
);

$cache->forget('recent_posts');
```

> **Note:** `TransientStaleCache` does not implement a `flush()` method. WordPress provides no native way to query transients by prefix without a direct database query, and that is intentionally deferred to a future version.

## Licence

MIT — © William Patton
