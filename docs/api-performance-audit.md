# OOHX Inventory API — Performance Audit Report

**Date:** 2026-04-08
**Focus:** Query performance, indexing, caching, payload optimization

---

## 1. Slow Endpoint Candidates

| Endpoint | Likely Bottleneck | Severity |
|----------|-------------------|----------|
| `GET /explore` (listing) | `buildScreenQuery()` chains up to 8 `whereHas()` + correlated subquery sort | CRITICAL |
| `GET /map` | Same `buildScreenQuery()` but loads ALL results (no pagination) | CRITICAL |
| `GET /` (homepage) | 6 service calls, ~15 queries if cache cold | HIGH |
| `GET /owners` | `withCount` + 2 batch queries per page, nested `whereHas` for type filter | HIGH |
| `GET /api/v1/inventory` | Same as listing — 8-12 queries with all filters | CRITICAL |
| `GET /api/v1/inventory/map` | Full result set, no pagination, all filters | CRITICAL |
| `GET /api/v1/inventory/stats` | 5 queries with JOINs, no index on `screens.active` | MEDIUM (cached) |
| `GET /explore/{id}` (detail) | `getSimilarScreens()` uses `inRandomOrder()` + dual `whereHas` OR | MEDIUM (cached) |

---

## 2. Query Efficiency Findings

### N+1 Risks

| Location | Pattern | Impact |
|----------|---------|--------|
| `Owner.getTotalScreensAttribute()` | Fires COUNT query per owner when accessed | 20 extra queries per paginated owner list |
| `Owner.getProgrammaticScreensAttribute()` | COUNT + whereHas per owner | Severe if ever serialized in collection |
| `buildScreenQuery()` with all filters | Up to 8 `whereHas()` = 8 EXISTS subqueries | Each adds ~50ms |
| `applyScreenTypeFilter()` | Per screen_type: 2-3 `whereHas()` nested in OR | Multiplies with number of types requested |
| `getFeaturedScreens()` | 2 `whereHas()` (spec.photo_url + inventory.floor_cpm) | 2 EXISTS subqueries |
| `getSimilarScreens()` | `whereHas('site') OR whereHas('inventory')` | OR condition prevents index usage |

### Missing Eager Loading
- `getMapPins()` loads full Screen models with relations — should use `select()` to limit columns

### Repeated Counts/Aggregates
- `loadOwnerExtras()` called from 3 methods — OK (batched)
- `getOwnerBySlug()` reimplements same logic inline instead of calling `loadOwnerExtras()`
- `getFilterAggregates()` internally calls `getVenueTypesWithCounts()` + `getTopCities()` — efficient (both cached)

### Expensive Subqueries

**`applySort()` — Correlated subquery per row:**
```sql
ORDER BY (SELECT floor_cpm FROM screen_inventory 
          WHERE screen_inventory.screen_id = screens.id LIMIT 1) ASC
```
Cost: ~0.5ms × N rows. For 1000 rows = 500ms+ per request. Executes on EVERY paginated request with price sort.

### Missing Composite Indexes

| Table | Missing Index | Used By | Frequency |
|-------|--------------|---------|-----------|
| `screens` | `(active)` | Every WHERE clause | 44+ queries |
| `screens` | `(active, site_id)` | JOIN sites pattern | 12+ queries |
| `screens` | `(owner_id, active, updated_at)` | Pagination + sort | 5+ queries |
| `screen_inventory` | `(screen_id, venue_type)` | whereHas filter | 20+ queries |
| `screen_inventory` | `(screen_id, floor_cpm)` | whereHas + sort | 10+ queries |
| `screen_inventory` | `(floor_cpm)` | MIN/MAX aggregate | 3+ queries |
| `screen_specs` | `(screen_id, photo_url)` | whereHas photo check | 8+ queries |
| `sites` | `(city)` | GROUP BY, WHERE, JOIN | 15+ queries |
| `sites` | `(owner_id, city)` | Batch city count | 6+ queries |
| `owners` | `(status)` | WHERE status='active' | 8+ queries |
| `owners` | `(status, featured)` | WHERE + ORDER BY | 3+ queries |
| `venue_types` | `(string_value)` | LEFT JOIN predicate | 3+ queries |

---

## 3. Payload Findings

### Oversized Responses
- `GET /map` returns ALL screens (no pagination) with full `spec`, `inventory`, `site` relations — can be 1MB+ for 1000+ screens
- `GET /api/v1/inventory/map` same issue — returns full ScreenMapCollection

### Unnecessary Nested Data
- Map pins only need: `id, name, lat, lng, price, venue_type, photo_url` — currently loads full Screen model with 4 relations
- Listing cards load full `owner` relation when only `owner.name` is needed

### Missing Lightweight Response Shape
- No dedicated "card" response shape — same full Screen model for list, detail, and map
- Recommendation: `ScreenCardResource` (lightweight) vs `ScreenDetailResource` (full)

---

## 4. Caching Findings

### Currently Cached (Good)

| Method | Key | TTL | Status |
|--------|-----|-----|--------|
| `getHeroStats()` | `fp:hero_stats` | 30m | OK |
| `getVenueTypesWithCounts()` | `fp:venue_types` | 30m | OK |
| `getTopCities()` | `fp:top_cities` | 30m | OK |
| `getLocationsByRegion()` | `fp:locations` | 30m | OK |
| `getFeaturedOwners()` | `fp:featured_owners` | 30m | OK |
| `getFeaturedScreens()` | `fp:featured_screens` | 15m | OK |
| `getOwnerBySlug()` | `fp:owner:{slug}` | 15m | OK |
| `getScreenDetail()` | `fp:screen:{id}` | 5m | OK |
| `getSimilarScreens()` | `fp:similar:{id}` | 10m | OK |
| `getFilterAggregates()` | `fp:filters` | 30m | OK |
| API `stats()` | `inventory_stats` | 30m | OK |
| API `venueTypes()` | `inventory_venue_types` | 30m | OK |
| API `networks()` | `inventory:networks` | 30m | OK |
| API `locations()` | `inventory:locations` | 30m | OK |

### NOT Cached (Should Be)

| Endpoint | Recommendation | TTL |
|----------|---------------|-----|
| API `owners()` | Cache first page (no search) | 5m |
| API `ownerDetail()` | Cache per slug | 15m |
| API `show()` | Cache per screen_id | 5m |
| Frontpage listing (default, no filters) | Cache first page | 2m |

### Cache Invalidation Concerns
- No invalidation when screen/owner is updated in dashboard
- Screen activation/deactivation doesn't flush `fp:*` keys
- Recommendation: Add `fp:cache:clear` Artisan command + hook to ScreenObserver

---

## 5. Recommended API Optimizations

### Quick Wins (< 1 hour, high impact)

**1. Add 13 missing database indexes**

```php
// Migration: add_performance_indexes
Schema::table('screens', function (Blueprint $table) {
    $table->index('active');
    $table->index(['active', 'site_id']);
    $table->index(['owner_id', 'active', 'updated_at']);
});
Schema::table('screen_inventory', function (Blueprint $table) {
    $table->index(['screen_id', 'venue_type']);
    $table->index(['screen_id', 'floor_cpm']);
    $table->index('floor_cpm');
});
Schema::table('screen_specs', function (Blueprint $table) {
    $table->index(['screen_id', 'photo_url']);
});
Schema::table('sites', function (Blueprint $table) {
    $table->index('city');
});
Schema::table('owners', function (Blueprint $table) {
    $table->index('status');
    $table->index(['status', 'featured']);
});
Schema::table('venue_types', function (Blueprint $table) {
    $table->index('string_value');
});
```

**Estimated impact:** 5-10x query speedup on filtered endpoints.

**2. Replace correlated subquery in `applySort()`**

```php
// BEFORE (both FrontpageService + InventoryController)
'price_asc' => $query->orderByRaw(
    '(SELECT floor_cpm FROM screen_inventory WHERE screen_inventory.screen_id = screens.id LIMIT 1) ASC'
),

// AFTER
'price_asc' => $query->leftJoin('screen_inventory as sort_inv', 'screens.id', '=', 'sort_inv.screen_id')
    ->orderBy('sort_inv.floor_cpm', 'asc')
    ->select('screens.*'),
```

**Estimated impact:** 500ms → 50ms per sorted request.

**3. Remove N+1 accessors from Owner model**

```php
// DELETE these methods from Owner.php:
// getTotalScreensAttribute()
// getProgrammaticScreensAttribute()
// Use withCount() in service queries instead
```

### Medium Refactors (1-4 hours)

**4. Optimize `buildScreenQuery()` — reduce whereHas() calls**

Replace multiple `whereHas()` with JOINs for frequently used filters:
```php
// BEFORE: 3 separate whereHas
->whereHas('site', fn($q) => $q->whereIn('city', $cities))
->whereHas('inventory', fn($q) => $q->whereIn('venue_type', $types))
->whereHas('spec', fn($q) => $q->where('width_cm', '>=', 300))

// AFTER: Single JOIN
->join('sites', 'screens.site_id', '=', 'sites.id')
->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
->join('screen_specs', 'screens.id', '=', 'screen_specs.screen_id')
->whereIn('sites.city', $cities)
->whereIn('screen_inventory.venue_type', $types)
->where('screen_specs.width_cm', '>=', 300)
->select('screens.*')
```

**5. Lightweight map response**

```php
// getMapPins() — select only needed columns
->select('screens.id', 'screens.uuid', 'screens.name', 'screens.site_id')
->with([
    'site:id,lat,lon,city',
    'inventory:screen_id,floor_cpm,venue_type',
])
```

**6. Cache invalidation via ScreenObserver**

```php
// In ScreenObserver::updated()
Cache::forget('fp:hero_stats');
Cache::forget('fp:venue_types');
Cache::forget('fp:top_cities');
// etc.
```

### Long-term Improvements

**7. Materialized view / summary table for screen aggregates**

Create `screen_stats_summary` table updated by cron/event:
```sql
CREATE TABLE screen_stats_summary (
    key VARCHAR(50) PRIMARY KEY,
    value JSON,
    updated_at TIMESTAMP
);
-- Keys: total_active, city_counts, venue_counts, owner_counts
```

**8. Separate read replica for frontpage**

Route all FrontpageService queries to read-only database connection.

**9. Full-text search with Scout/Meilisearch**

Replace LIKE queries in search with proper full-text search engine.

---

## 6. Query Count Summary

### Current State (cache cold)

| Page | Queries | With Filters |
|------|---------|-------------|
| Homepage `/` | 15 | 15 |
| Listing `/explore` | 8-12 | 12-18 |
| Detail `/explore/{id}` | 3 | 3 |
| Map `/map` | 8-12 | 12-18 |
| Owners `/owners` | 5 | 7 |
| Owner Detail `/owners/{slug}` | 5 | 5 |
| **Total per cold start** | **~50** | **~66** |

### After Optimizations (projected)

| Page | Queries | Improvement |
|------|---------|-------------|
| Homepage `/` | 8 (cached: 0) | 50% fewer |
| Listing `/explore` | 3-5 | 60% fewer |
| Detail `/explore/{id}` | 1 (cached: 0) | Same |
| Map `/map` | 3-5 | 60% fewer |
| Owners `/owners` | 3 | 40% fewer |
| Owner Detail `/owners/{slug}` | 3 (cached: 0) | Same |
| **Total per cold start** | **~25** | **50% reduction** |
