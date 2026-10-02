# Phase 4.2.2 — Venue Footfall Multi-Provider (DE handoff → Laravel)

> DE ship ngày: 2026-04-23
> Laravel effort: ~2h must-do, ~1h should-do
> Sprint 4.2.2.A scope (Sprint B sau khi OOHX đăng ký Google Places).

---

## 1. TL;DR — Laravel cần làm gì

| Priority | Task | Effort |
|---|---|---|
| **Must-do** | Extend `DeliveryDefault::KEYS` với 11 venue_footfall_* keys | ~15 phút |
| **Must-do** | Add 3 entries vào `config/oohx_collectors.php` | ~10 phút |
| **Must-do** | Update `ScreenContextMetrics` casts (2 new cột) | ~5 phút |
| **Must-do** | `OohxEstimateResource` infolist — source badge + updated_at | ~30 phút |
| **Should-do** | Admin config UI range update — priority keys (1-99 int) | ~15 phút |
| **Nice-to-have** | Provider quota panel trong collectors dashboard | ~30 phút |
| **Don't-do** | Không tạo migration Laravel, không query raw DE tables |

---

## 2. Schema changes (DE-side migration 015)

### 2.1. Table mới — `source.venue_footfall_snapshots`

| Column | Type | Note |
|---|---|---|
| `id` | BIGSERIAL PK | |
| `screen_id` | BIGINT FK → `core.screens` | CASCADE delete |
| `source_name` | TEXT | `foursquare` / `osm` / `google` / future |
| `radius_m` | INT | default 300 |
| `venues_count` | INT | |
| `footfall_proxy` | DOUBLE PRECISION | aggregated signal |
| `confidence` | DOUBLE PRECISION (0..1) | |
| `raw_payload` | JSONB | metadata only, heavy data trimmed |
| `cost_usd` | DOUBLE PRECISION | |
| `fetched_at` | TIMESTAMPTZ | |
| `ttl_days` | INT | default 30 |
| `error_message` | TEXT | set khi provider fail |
| `expires_at` | TIMESTAMPTZ GENERATED | `fetched_at + ttl_days` |
| UNIQUE `(screen_id, source_name)` | | upsert semantics |

### 2.2. Table mới — `source.provider_rate_budgets`

Local counter vì Fused Places API không expose quota qua HTTP header.

| Column | Type | Note |
|---|---|---|
| `source_name` | TEXT | PK part |
| `date` | DATE | PK part |
| `calls_made` | INT | |
| `daily_limit` | INT | |
| `cost_usd` | DOUBLE PRECISION | accumulated |
| `updated_at` | TIMESTAMPTZ | |

### 2.3. Cột mới trên `metrics.screen_context_metrics`

```sql
venue_footfall_source      TEXT         -- 'foursquare' | 'osm' | 'google'
venue_footfall_updated_at  TIMESTAMPTZ  -- khi provider winner last wrote
```

`venue_footfall` (numeric) **không đổi** — logic upsert giờ do venue_footfall service drive, Phase 2.D enrichment sẽ không overwrite cột này.

### 2.4. CHECK constraint `core.recompute_jobs.job_type`

Thêm giá trị `venue_footfall_refresh`.

---

## 3. Laravel code changes

### 3.1. `ScreenContextMetrics` model — thêm 2 casts

```php
// app/Models/ScreenContextMetrics.php
protected $casts = [
    // ... existing casts
    'venue_footfall'            => 'float',
    'venue_footfall_source'     => 'string',
    'venue_footfall_updated_at' => 'datetime',
];
```

### 3.2. `DeliveryDefault::KEYS` — extend với 11 venue_footfall keys

```php
// app/Models/DeliveryDefault.php hoặc nơi đang whitelist keys
public const KEYS = [
    // ... existing keys
    // Phase 4.2.2 — venue footfall multi-provider
    'venue_footfall_enable_foursquare'          => ['label' => 'Foursquare enabled',   'range' => [0, 1]],
    'venue_footfall_enable_osm'                 => ['label' => 'OSM fallback enabled', 'range' => [0, 1]],
    'venue_footfall_enable_google'              => ['label' => 'Google enabled',       'range' => [0, 1]],
    'venue_footfall_priority_foursquare'        => ['label' => 'Foursquare priority',  'range' => [1, 99]],
    'venue_footfall_priority_osm'               => ['label' => 'OSM priority',         'range' => [1, 99]],
    'venue_footfall_priority_google'            => ['label' => 'Google priority',      'range' => [1, 99]],
    'venue_footfall_cache_ttl_days'             => ['label' => 'Cache TTL (days)',     'range' => [1, 365]],
    'venue_footfall_min_confidence'             => ['label' => 'Min confidence',       'range' => [0, 1]],
    'venue_footfall_radius_m'                   => ['label' => 'Search radius (m)',    'range' => [50, 1000]],
    'venue_footfall_foursquare_daily_budget'    => ['label' => 'Foursquare daily cap', 'range' => [0, 100000]],
    'venue_footfall_foursquare_cost_per_call_usd' => ['label' => 'Cost per call USD',  'range' => [0, 1]],
];
```

**Default values** (DE set sẵn trong TrafficConfig):
- Foursquare: enabled=1, priority=1, daily_budget=3000, cost=0.006
- OSM: enabled=1, priority=99 (fallback)
- Google: enabled=0 (stub — flip sau khi Sprint 4.2.2.B)
- min_confidence=0.25, radius_m=300, cache_ttl=30

### 3.3. `config/oohx_collectors.php` — add 3 cards

```php
return [
    // ... existing collectors
    'venue_footfall_foursquare' => [
        'label'         => 'Venue Footfall — Foursquare',
        'icon'          => 'heroicon-o-map-pin',
        'description'   => 'Places API (Fused). Popularity + rating signal for urban venue density.',
        'cadence_hours' => 24 * 7,
        'supports_bbox' => false,
        'supports_city' => true,
    ],
    'venue_footfall_osm' => [
        'label'         => 'Venue Footfall — OSM (fallback)',
        'icon'          => 'heroicon-o-globe-alt',
        'description'   => 'Overpass POI count × category weight. Always-on fallback.',
        'cadence_hours' => 24 * 7,
        'supports_bbox' => false,
        'supports_city' => true,
    ],
    'venue_footfall_google' => [
        'label'         => 'Venue Footfall — Google (STUB)',
        'icon'          => 'heroicon-o-building-office',
        'description'   => 'Stub. Kích hoạt sau khi OOHX đăng ký Google Places API.',
        'cadence_hours' => 24 * 7,
        'supports_bbox' => false,
        'supports_city' => true,
        'disabled'      => true,
    ],
];
```

> **DE bridge note**: `enqueue-collector --name venue_footfall_<provider>` auto-redirects sang `recompute_jobs` với `job_type='venue_footfall_refresh'` (không chạy qua `collectors.collector_runs` table như Phase 2.C — shape per-screen khác per-bbox). Laravel "Run" button hoạt động transparently, zero code change. Drain bởi cron `recompute-pending-jobs` mỗi 10 phút (không phải `run-pending-collectors` cron 15 phút).

### 3.4. `OohxEstimateResource` infolist — Source tracking section

Thêm vào infolist section "Data completeness" hoặc tạo section riêng "Venue Footfall":

```php
Infolists\Components\Section::make('Venue Footfall')
    ->schema([
        Infolists\Components\TextEntry::make('context_metrics.venue_footfall')
            ->label('Signal')
            ->numeric(2)
            ->placeholder('No data'),
        Infolists\Components\TextEntry::make('context_metrics.venue_footfall_source')
            ->label('Source')
            ->badge()
            ->color(fn (?string $state): string => match ($state) {
                'foursquare' => 'success',
                'osm'        => 'warning',
                'google'     => 'primary',
                default      => 'gray',
            })
            ->placeholder('—'),
        Infolists\Components\TextEntry::make('context_metrics.venue_footfall_updated_at')
            ->label('Fetched')
            ->since()
            ->placeholder('never'),
    ])
    ->columns(3),
```

**Stale badge logic** (if venue_footfall_updated_at > 45 days ago, show warning badge):

```php
->suffixBadge(fn ($record) => match (true) {
    $record->context_metrics?->venue_footfall_updated_at?->diffInDays() > 45 => 'STALE',
    default => null,
})
```

---

## 4. CLI tham khảo (Laravel không gọi trực tiếp, DE ops)

```bash
# Enqueue refresh 1 screen
.venv/bin/python -m app.cli refresh-venue-footfall --screen-id 42

# Enqueue bulk city
.venv/bin/python -m app.cli refresh-venue-footfall --city HCMC --stale-days 30

# Force provider (skip fallback)
.venv/bin/python -m app.cli refresh-venue-footfall --screen-id 42 --provider osm

# Sync fetch (no enqueue — debug only)
.venv/bin/python -m app.cli fetch-venue-footfall --screen-id 42

# Summary + budget today
.venv/bin/python -m app.cli venue-footfall-summary
.venv/bin/python -m app.cli venue-footfall-health
```

Cron đã wire tự động (weekly Sunday 03:00 UTC, `refresh-venue-footfall --stale-days 30`).

---

## 5. Health check integration

`/admin/oohx-health` sẽ tự pick up check mới `venue_footfall_providers`:

```json
{
  "venue_footfall_providers": {
    "status": "ok",
    "value": {
      "providers_enabled": 2,
      "providers": {
        "foursquare": {
          "status":   "ok",
          "priority": 1,
          "budget":   {"calls_made": 47, "daily_limit": 3000, "remaining": 2953, "used_pct": 1.57}
        },
        "osm": {
          "status":   "ok",
          "priority": 99,
          "budget":   {"note": "no calls today"}
        }
      },
      "snapshots_24h": {"total": 62, "errored": 2, "err_rate": 0.032}
    },
    "note": "2 provider(s) active"
  }
}
```

Laravel health page rendering đã generic — **zero code change**.

---

## 6. Test plan (Laravel, sau khi DE ship)

### 6.1. Happy path
- [ ] DE apply migration 015 + `refresh-venue-footfall --screen-id X` chạy xong
- [ ] `/admin/oohx-estimates/{id}` → venue footfall section hiện signal + source badge `foursquare` + "since Xm"
- [ ] Pick 1 screen rural → source badge `osm` (fallback)
- [ ] `/admin/oohx-collectors` → 3 cards venue_footfall_* xuất hiện
- [ ] `/admin/oohx-config/delivery-defaults` → 11 keys mới trong dropdown

### 6.2. Failure modes
- [ ] Flip `venue_footfall_enable_foursquare` = 0 → next refresh → source flip sang `osm`
- [ ] Disable all 3 → enqueue job done với result `no_providers_enabled`
- [ ] DE budget exhausted → health page hiện `venue_footfall_providers` = warn/critical

### 6.3. Sprint 4.2.2.B (Google activate — sau khi OOHX register)
- [ ] DE implement `GoogleProvider.fetch()`
- [ ] Admin flip `venue_footfall_enable_google` = 1, set `priority_google = 1`
- [ ] Next refresh → source badge `google` thay thế `foursquare`
- [ ] Zero Laravel code change

---

## 7. Budget considerations

**Foursquare Fused Places API pricing** (2026-04-23 confirmed by POC):
- Pay-as-you-go, **không có free tier public** (initial signup cần thêm credit card)
- ~$0.006-0.01 per call
- Free trial credits một vài $ cho account mới

**Projected monthly cost**:
- 5,000 screens × weekly refresh × $0.006/call = **$30/month**
- 5,000 screens × daily refresh × $0.006/call = **$210/month** (NOT recommended)

**Default `venue_footfall_cache_ttl_days = 30` + weekly cron** keeps cost ~$30/month.

Alert threshold: health check warn khi `budget.used_pct ≥ 80%`, critical khi `remaining ≤ 0`.

---

## 8. Changelog

| Date | Change |
|---|---|
| 2026-04-23 | Initial ship — Sprint 4.2.2.A (Foursquare primary, OSM fallback, Google stub) |

## 9. References

- [PHASE-4.2.2-PROPOSAL.md](PHASE-4.2.2-PROPOSAL.md) — original Laravel proposal
- DE migration: `python-data-engine/sql/015_venue_footfall_multi_provider.sql`
- DE providers: `python-data-engine/app/collectors/venue_footfall/`
- DE service: `python-data-engine/app/services/venue_footfall.py`
- DE tests: `python-data-engine/tests/unit/test_venue_footfall_*.py`
- Foursquare migration guide: https://docs.foursquare.com/fsq-developers-places/reference/migration-guide
