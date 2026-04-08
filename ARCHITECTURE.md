# wp-stale-cache Architecture

> **Version:** 1.0.0-alpha  
> **Architect:** Roswaal  
> **Date:** 2026-04-08

## Vision

A minimal, precise WordPress caching library implementing **stale-while-revalidate** semantics. This pattern ensures zero cache-miss latency for users while keeping data reasonably fresh through background updates.

---

## Package Structure

```
packages/wp-stale-cache/
├── composer.json           # Package manifest
├── ARCHITECTURE.md         # This file
├── README.md              # User-facing documentation
├── src/
│   ├── StaleCache.php     # Primary cache manager
│   ├── CacheEntry.php     # Value object for cache metadata
│   └── CronHandler.php    # WP-Cron callback handler
└── tests/
    ├── StaleCacheTest.php
    ├── CacheEntryTest.php
    └── bootstrap.php
```

---

## Core Design

### Namespace

**`Pattonwebz\WpStaleCache`**

Rationale: Personal package under established `pattonwebz` vendor namespace, clear domain-specific package name.

### Class Inventory

#### 1. `StaleCache` — Primary API

**Namespace:** `Pattonwebz\WpStaleCache\StaleCache`

**Responsibilities:**
- Provide `get()` method implementing stale-while-revalidate logic
- Manage storage via WordPress Options API
- Schedule WP-Cron events for background refresh
- Determine cache state (fresh/stale/expired)

**Public API:**

```php
namespace Pattonwebz\WpStaleCache;

class StaleCache
{
    /**
     * Retrieve a cached value or generate it.
     *
     * @param string   $key           Cache key (alphanumeric + underscores/hyphens)
     * @param callable $generator     Callable that returns the value if regeneration needed
     * @param int      $ttl           Time-to-live in seconds (fresh period)
     * @param int      $staleOffset   Additional seconds value remains usable while stale
     * @return mixed   The cached or freshly generated value
     */
    public function get(
        string $key,
        callable $generator,
        int $ttl = 3600,
        int $staleOffset = 300
    ): mixed;

    /**
     * Manually invalidate a cache entry.
     *
     * @param string $key Cache key to invalidate
     * @return bool True if entry existed and was deleted
     */
    public function invalidate(string $key): bool;

    /**
     * Check cache state without retrieving value.
     *
     * @param string $key Cache key
     * @return string One of: 'fresh', 'stale', 'expired', 'missing'
     */
    public function getState(string $key): string;
}
```

**What it does NOT do:**
- No cache warming (caller manages that via initial `get()`)
- No automatic garbage collection (WordPress manages option cleanup)
- No distributed locking (single-site WordPress assumption)
- No cache tagging or grouping (v1 keeps it simple)

---

#### 2. `CacheEntry` — Value Object

**Namespace:** `Pattonwebz\WpStaleCache\CacheEntry`

**Responsibilities:**
- Immutable representation of cache metadata
- Calculate cache state based on current timestamp
- Provide serializable array format for storage

**Public API:**

```php
namespace Pattonwebz\WpStaleCache;

readonly class CacheEntry
{
    public function __construct(
        public int $expiresAt,
        public int $staleOffset
    ) {}

    /**
     * Determine cache state at given timestamp.
     *
     * @param int $now Current Unix timestamp
     * @return string 'fresh', 'stale', or 'expired'
     */
    public function getState(int $now): string;

    /**
     * Convert to array for storage.
     *
     * @return array{expires_at: int, stale_offset: int}
     */
    public function toArray(): array;

    /**
     * Create from stored array.
     *
     * @param array{expires_at: int, stale_offset: int} $data
     * @return self
     */
    public static function fromArray(array $data): self;

    /**
     * Check if entry is within stale window.
     *
     * @param int $now Current Unix timestamp
     * @return bool
     */
    public function isStale(int $now): bool;

    /**
     * Check if entry is fully expired.
     *
     * @param int $now Current Unix timestamp
     * @return bool
     */
    public function isExpired(int $now): bool;
}
```

**What it does NOT do:**
- No mutable state (readonly class)
- No business logic beyond state calculation
- No knowledge of WordPress APIs

---

#### 3. `CronHandler` — Background Refresh Orchestrator

**Namespace:** `Pattonwebz\WpStaleCache\CronHandler`

**Responsibilities:**
- Register WP-Cron hook for cache regeneration
- Execute regeneration with error handling
- Update cache storage on successful regeneration

**Public API:**

```php
namespace Pattonwebz\WpStaleCache;

class CronHandler
{
    /**
     * Register WP-Cron action hook.
     * Call once during plugin/theme initialization.
     */
    public static function register(): void;

    /**
     * WP-Cron callback: regenerate and store cache value.
     *
     * @param string $key       Cache key
     * @param string $generator Serialized callable (class method descriptor)
     * @param int    $ttl       Fresh period duration
     * @param int    $staleOffset Stale period duration
     */
    public static function handleRefresh(
        string $key,
        string $generator,
        int $ttl,
        int $staleOffset
    ): void;
}
```

**What it does NOT do:**
- No retry logic (single attempt per scheduled event)
- No failure alerting (logs to error_log on exception)
- No generator serialization beyond class method arrays

---

## Storage Schema

### Option Key Convention

For cache key `$key`:

1. **Value option:** `_wpsc_{$key}`
2. **Metadata option:** `_wpsc_{$key}_meta`

Prefix `_wpsc_` (WordPress Stale Cache) chosen to:
- Avoid collision with other plugins/libraries
- Be visually greppable in database
- Follow WordPress naming convention (underscore prefix for "private" options)

### Metadata Structure

Stored as PHP array, serialized by `update_option()`:

```php
[
    'expires_at'   => 1704067200,  // Unix timestamp: fresh until this time
    'stale_offset' => 300,         // Seconds: stale window duration
]
```

**Why separate metadata?**
- Independent expiration without deserializing cached value
- Metadata queries don't incur cost of loading potentially large values
- Allows `getState()` check without touching value data

---

## Retrieval Logic: The `get()` Decision Tree

```
START: StaleCache::get($key, $generator, $ttl, $staleOffset)
│
├─ Load metadata from option: _wpsc_{$key}_meta
│
├─ IF metadata missing:
│   └─ GOTO: REGENERATE (no cache exists)
│
├─ Calculate state via CacheEntry::getState($now)
│
├─ SWITCH state:
│   │
│   ├─ CASE 'fresh' ($now < expires_at):
│   │   ├─ Load value from _wpsc_{$key}
│   │   └─ RETURN value (cache hit, no action needed)
│   │
│   ├─ CASE 'stale' (expires_at <= $now < expires_at + stale_offset):
│   │   ├─ Load stale value from _wpsc_{$key}
│   │   ├─ Schedule WP-Cron if not already scheduled:
│   │   │   ├─ Check: wp_next_scheduled('wpsc_refresh', [$key])
│   │   │   └─ IF not scheduled:
│   │   │       └─ wp_schedule_single_event(time(), 'wpsc_refresh', [...])
│   │   └─ RETURN stale value (user sees old data, refresh happens async)
│   │
│   └─ CASE 'expired' ($now >= expires_at + stale_offset):
│       └─ GOTO: REGENERATE (synchronous, too stale to serve)
│
└─ REGENERATE:
    ├─ Execute: $value = $generator()
    ├─ $newExpiresAt = time() + $ttl
    ├─ Store value: update_option(_wpsc_{$key}, $value)
    ├─ Store metadata: update_option(_wpsc_{$key}_meta, [
    │       'expires_at' => $newExpiresAt,
    │       'stale_offset' => $staleOffset
    │   ])
    └─ RETURN $value
```

### Edge Cases

1. **Generator throws exception:**
   - Log error via `error_log()`
   - If in REGENERATE path: rethrow (caller handles)
   - If in cron callback: catch, log, exit gracefully (don't spam cron)

2. **Option storage fails:**
   - `update_option()` returns false → log warning, proceed
   - Cache miss on next request will regenerate

3. **Duplicate cron scheduling:**
   - Always check `wp_next_scheduled()` before `wp_schedule_single_event()`
   - WP-Cron hook args MUST match exactly: `[$key, $generator, $ttl, $staleOffset]`

---

## WP-Cron Integration

### Hook Name

**`wpsc_refresh`** (WordPress Stale Cache Refresh)

### Registration

```php
// In package initialization (e.g., Composer autoload or theme functions.php)
add_action('wpsc_refresh', [CronHandler::class, 'handleRefresh'], 10, 4);
```

### Scheduling Logic

```php
// In StaleCache::get() when state is 'stale'
$hook = 'wpsc_refresh';
$args = [$key, $generatorDescriptor, $ttl, $staleOffset];

if (!wp_next_scheduled($hook, $args)) {
    wp_schedule_single_event(time(), $hook, $args);
}
```

### Generator Serialization

**Constraint:** WP-Cron requires serializable arguments. Closures are NOT serializable.

**Solution:** Accept only array callables for background refresh:

```php
// Allowed (class method):
$cache->get('my_key', [MyClass::class, 'generate'], 3600, 300);

// Allowed (object method):
$cache->get('my_key', [$myObject, 'generate'], 3600, 300);

// NOT allowed in stale-refresh scenarios (closure):
$cache->get('my_key', fn() => expensive_query(), 3600, 300);
// → Works for fresh/expired (executed immediately), fails for stale (can't schedule)
```

**Implementation:** In stale case, validate `is_array($generator)` before scheduling. If invalid, log warning and force synchronous regeneration.

---

## `composer.json` Skeleton

See `composer.json` in package root.

Key points:
- **Package name:** `pattonwebz/wp-stale-cache`
- **PSR-4 autoload:** `Pattonwebz\\WpStaleCache\\` → `src/`
- **PHP requirement:** `^8.1`
- **No WordPress require** in composer.json (WordPress isn't Composer-installed; document in README)

---

## Architectural Decision Records

### ADR-001: Options API over Transients API

**Decision:** Use `get_option()` / `update_option()` instead of `get_transient()` / `set_transient()`.

**Rationale:**
- Transients API has built-in expiration, BUT:
  - Expiration is hard-delete (can't serve stale values)
  - Transients stored in options table anyway (non-object-cache setups)
  - Transient expiration check adds overhead we don't need
- Options API gives full control over metadata and value lifecycle
- Stale-while-revalidate requires custom expiration logic anyway

**Tradeoff:** We implement our own expiration checking, but gain precise control over stale window.

---

### ADR-002: Two Options per Cache Entry

**Decision:** Store value and metadata in separate options (`_wpsc_{$key}` and `_wpsc_{$key}_meta`).

**Rationale:**
- **Performance:** `getState()` can check expiration without deserializing value
- **Memory:** Metadata queries don't load potentially large cached values
- **Clarity:** Separation of concerns (data vs. metadata)

**Tradeoff:** Two database queries instead of one, but metadata is tiny (16 bytes + overhead) and state checks are frequent.

**Alternative considered:** Single option with `['value' => ..., 'meta' => ...]`. Rejected because WordPress must deserialize entire option to read metadata.

---

### ADR-003: Stale State as Async Trigger

**Decision:** When cache is stale, return stale value immediately AND schedule background refresh.

**Rationale:**
- **User experience:** Zero latency impact for stale hits
- **Freshness:** Background refresh happens soon (WP-Cron runs on next page load)
- **Graceful degradation:** If cron fails, entry becomes expired and regenerates synchronously

**Tradeoff:** Users may see stale data for 1-2 requests. Acceptable for most WordPress use cases (blog posts, menus, aggregated data).

**Not suitable for:** Real-time data (stock prices, live scores). Document this limitation.

---

### ADR-004: Single Cron Event per Stale Key

**Decision:** Check `wp_next_scheduled()` before scheduling to prevent duplicate events.

**Rationale:**
- Multiple concurrent stale requests could schedule multiple events for same key
- WordPress doesn't deduplicate cron events by default
- Duplicate regenerations waste CPU and may cause race conditions

**Implementation:** Use exact argument matching: `wp_next_scheduled('wpsc_refresh', [$key, ...])`.

---

### ADR-005: No Distributed Locking

**Decision:** Assume single-site WordPress. No mutex/lock on regeneration.

**Rationale:**
- Vast majority of WordPress sites are single-server
- WP-Cron is single-threaded by nature (one event at a time)
- Adding Redis/memcached dependency contradicts "minimal" goal

**Tradeoff:** In multi-server setups with shared database, multiple servers may regenerate simultaneously during expired state. Acceptable for v1.0.

**Future:** v2 could add optional `wp_cache_*` based locking for multi-server environments.

---

## Non-Goals (Explicit Scope Limitations)

1. **No cache warming:** Caller must invoke `get()` to populate cache initially.
2. **No automatic garbage collection:** Old entries remain in options table until manually invalidated.
3. **No cache groups/tags:** Each entry is independent (use key prefixes like `product_{id}` if needed).
4. **No probabilistic expiration:** Stale/expired thresholds are deterministic.
5. **No object cache integration:** Uses database (options table) only.

These are intentional constraints for v1.0 simplicity. Future versions may expand scope.

---

## Testing Strategy

### Unit Tests (via PHPUnit)

1. **CacheEntry:**
   - State calculation (fresh/stale/expired boundaries)
   - Array serialization round-trip
   - Edge cases (zero TTL, negative timestamps)

2. **StaleCache:**
   - Fresh hit returns cached value without calling generator
   - Stale hit returns cached value AND schedules cron
   - Expired/missing regenerates synchronously
   - `invalidate()` deletes both options

3. **CronHandler:**
   - `handleRefresh()` executes generator and stores result
   - Exception handling logs error without crashing

### Integration Tests (WordPress Test Suite)

- Actual `get_option()` / `update_option()` storage
- WP-Cron scheduling and execution
- Generator callable invocation

### Manual Testing Checklist

- [ ] Fresh cache hit (no generator call)
- [ ] Stale cache hit (returns old value, schedules cron)
- [ ] Expired cache regenerates (generator called)
- [ ] WP-Cron executes and updates cache
- [ ] `invalidate()` clears cache
- [ ] Multiple concurrent stale requests don't duplicate cron events

---

## Usage Example

```php
<?php
use Pattonwebz\WpStaleCache\StaleCache;

// Initialize once
$cache = new StaleCache();

// Usage
$posts = $cache->get(
    key: 'recent_posts_json',
    generator: [MyDataClass::class, 'fetchRecentPosts'],
    ttl: 3600,        // Fresh for 1 hour
    staleOffset: 300  // Serve stale for additional 5 minutes while refreshing
);

// Manual invalidation (e.g., on post publish)
add_action('save_post', function() use ($cache) {
    $cache->invalidate('recent_posts_json');
});
```

---

## Deployment Considerations

1. **Composer installation:** `composer require pattonwebz/wp-stale-cache`
2. **Initialization:** Call `CronHandler::register()` in theme `functions.php` or plugin initialization
3. **Monitoring:** Watch `error_log` for regeneration failures
4. **Database impact:** Each cache key adds 2 rows to `wp_options` (consider for high-cardinality keys)

---

## Future Enhancements (Not in v1.0)

- **Metrics:** Track hit/miss rates, regeneration count
- **Garbage collection:** Cron job to prune old entries
- **Cache groups:** Invalidate multiple related entries
- **Object cache support:** Use `wp_cache_*` if available for metadata
- **Distributed locking:** Redis-based mutex for multi-server setups
- **Warmup API:** Proactively populate cache during low-traffic periods

---

**End of Architecture Specification**

_Everything proceeds precisely as designed~_  
— Roswaal, 2026-04-08
