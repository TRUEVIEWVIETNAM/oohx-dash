# Phase 4.2 Consolidated Handoff — Laravel Updates

> **Role of this document**: tổng hợp 3 updates mới Phase 4.2 (Population Grid + Analytics Layer + config schema widen) cho Laravel team. Thay thế gộp cho PHASE-4.2.1 + PHASE-4.2.7 + các mini-fixes.
>
> **Status**: backend 100% shipped + production data loaded (HRSL 30m, 1.9M cells cover Hanoi/HCMC/Đà Nẵng/Hải Phòng).
>
> **Laravel actions required**: 2 features mới + 1 UI polish (chi tiết §4-5)
>
> **Reference**:
> - [PHASE-4.1-HANDOFF.md](PHASE-4.1-HANDOFF.md) — Campaign Planner (đã ship Laravel)
> - [DATA-ENGINE-ACTION-PLAN.md](../DATA-ENGINE-ACTION-PLAN.md)

---

## 1. What changed on DE side (TL;DR cho Laravel)

### 1.1. Numbers Laravel sees sẽ khác

Sau Phase 4.2.1, **estimate numbers đã update** cho mọi outdoor screen:

| Screen location | Before (4.1) | After (4.2.1 HRSL) | Factor |
|---|---|---|---|
| Hà Bà Trưng (dense urban) | baseline × 1.0 | baseline × 2.0 | +100% |
| Hoàn Kiếm core | baseline × 1.0 | baseline × 2.0 | +100% |
| Hanoi residential | baseline × 1.0 | baseline × ~1.5 | +50% |
| HCMC core | baseline × 1.0 | baseline × 2.0 | +100% |
| Rural suburb (Hà Đông outer) | baseline × 1.0 | baseline × ~0.8 | -20% |
| Rural province (not yet covered) | baseline × 1.0 | baseline × 1.0 | 0% fallback |

**Root cause**: `population_factor` trước đây hardcoded = 1.0 cho mọi screen. Giờ drive bởi actual population density trong bán kính 300m quanh screen (data source = Meta HRSL 30m granular).

**Laravel impact**:
- Existing campaigns đã compute vẫn giữ nguyên numbers (historical in `output.campaign_estimates`)
- Campaigns **mới** tạo sau 2026-04-23 sẽ dùng new factor → numbers khác
- Individual screen estimates đã được recomputed tất cả → dashboard numbers update luôn

### 1.2. Schema changes (no Laravel migration needed)

| Object | Change | Affects Laravel? |
|---|---|---|
| `source.population_grid` | New table populated (1.9M rows HRSL) | No (internal) |
| `metrics.screen_context_metrics.population_density_300m` | Populated real values (before: always NULL) | Yes — filterable/displayable |
| `config.delivery_defaults` CHECK constraint | Widened `value <= 100` → `<= 1000000` | Yes — nếu có admin UI edit config |
| `output.mv_*` | 4 materialized views mới | Yes — **build dashboard** |

### 1.3. New `formula_version` active

Active version bumped: **`v-4.2.1-hrsl`** với baseline 15000/km² + HRSL source.

```sql
SELECT * FROM config.formula_versions WHERE is_active = TRUE;
-- Expect: tag='v-4.2.1-hrsl', activated_at=<recent>
```

Laravel Formula admin page sẽ hiện version này trong dropdown.

---

## 2. New feature #1 — Analytics Dashboard (Phase 4.2.7)

### 2.1. 4 Materialized Views available

Populated + refreshed daily 04:15 UTC qua DE cron. Laravel query qua `oohx_readonly` role.

| View | Purpose | Refresh |
|---|---|---|
| `output.mv_campaign_weekly` | Trailing 12 weeks campaign activity | Daily |
| `output.mv_city_performance` | Per-city rollup (screens, impressions, density) | Daily |
| `output.mv_screen_utilization` | Screen booking activity (90d window) | Daily |
| `output.mv_formula_version_impact` | Version comparison metrics | Daily |

### 2.2. Eloquent models (read-only)

```php
// app/Models/Oohx/AnalyticsCampaignWeekly.php
namespace App\Models\Oohx;

class AnalyticsCampaignWeekly extends Model
{
    protected $connection = 'data_engine';
    protected $table = 'output.mv_campaign_weekly';
    public $timestamps = false;
    protected $primaryKey = 'week_start';
    public $incrementing = false;
    protected $keyType = 'date';

    // Columns
    protected $casts = [
        'week_start'                => 'date',
        'campaigns_count'           => 'integer',
        'total_impressions'         => 'integer',
        'total_reach'               => 'integer',
        'avg_frequency'             => 'float',
        'avg_screens_per_campaign'  => 'float',
        'avg_confidence'            => 'float',
        'avg_cpm_vnd'               => 'integer',
        'unique_users'              => 'integer',
    ];

    // Guard against accidental writes
    public function save(array $options = []) { throw new \Exception('MV is read-only'); }
    public function delete() { throw new \Exception('MV is read-only'); }
}
```

Tương tự cho:
- `AnalyticsCityPerformance` (table `output.mv_city_performance`, PK `city`)
- `AnalyticsScreenUtilization` (table `output.mv_screen_utilization`, PK `screen_id`)
- `AnalyticsFormulaVersionImpact` (table `output.mv_formula_version_impact`, PK `formula_version_id`)

### 2.3. UI sections đề xuất

#### 2.3.1. Dashboard homepage

```
┌ OOHX Data Engine Overview ──────────────────────────────┐
│                                                          │
│  📊 Last 12 weeks                                        │
│  ┌────────────────────────────────────────┐             │
│  │ [line chart: total_impressions/week]    │             │
│  └────────────────────────────────────────┘             │
│                                                          │
│  This week vs last week                                  │
│  Campaigns: 12 (+3)                                      │
│  Impressions: 45.2M (+12%)                               │
│  Avg frequency: 15.3× (-2.1)                            │
│                                                          │
│  Top cities                                              │
│  1. Hanoi       120 screens · 8.2M daily impr           │
│  2. HCMC        98 screens  · 6.5M daily impr           │
│  3. Đà Nẵng     15 screens  · 1.1M daily impr           │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

#### 2.3.2. Screen inventory page — "unused screens" filter

```php
// Unused in last 90 days
$unusedScreenIds = AnalyticsScreenUtilization::query()
    ->where('campaign_count_90d', 0)
    ->orWhereNull('campaign_count_90d')
    ->pluck('screen_id');

// Hoặc LEFT JOIN với Laravel screens để find never-booked
```

UI: dropdown filter "Utilization: All / Top booked / Unused 90d".

#### 2.3.3. Formula version admin — impact comparison

Existing page (Phase 2.A) có `config.formula_versions` table. Thêm cột:
- `screens_with_this_version` — từ `mv_formula_version_impact`
- `avg_daily_impressions` — average output
- `total_daily_impressions` — sum

### 2.4. Refresh cadence

- Auto: daily 04:15 UTC (DE cron)
- Manual: expose admin button "Refresh analytics" — gọi DE CLI qua SSH hoặc thêm API endpoint

Acceptable staleness: 24h (dashboard không cần realtime).

### 2.5. Staleness indicator

Laravel UI nên hiện "Last updated: <computed_at>" ở dashboard header. Value lấy từ:

```php
$lastRefresh = AnalyticsCityPerformance::query()
    ->max('computed_at');  // col có sẵn trong mv_city_performance
```

---

## 3. New feature #2 — Screen detail page với population data

Với population data populated, Laravel screen detail page có thể show:

```
┌ Screen #42 — Vincom Ba Trieu LED Billboard ────────────┐
│  Location: 5 Quan 1, HCMC                              │
│                                                         │
│  📊 Traffic Estimate (formula v-4.2.1-hrsl)            │
│  Daily passby:        45,320                           │
│  Daily impressions:   3,412                            │
│  Confidence:          0.72 (high) ●                    │
│                                                         │
│  🗺️ Context Enrichment                                  │
│  Nearest road:        Đường Lê Thánh Tôn (primary)     │
│  Lane count:          4                                 │
│  POI within 300m:     47 (shops + food + office)       │
│  Population density:  38,500/km² (dense urban) 🏢      │
│                                                         │
│  Formula factors applied:                              │
│  road × 2.5 × lane × 1.4 × poi × 1.5 × pop × 2.0       │
│  weather × 0.95 × season × 1.0 = × 10.0 multiplier     │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

Query:
```php
$metrics = DB::connection('data_engine')
    ->table('metrics.screen_context_metrics')
    ->where('screen_id', $deScreenId)
    ->first();

// metrics->population_density_300m = 38500.0 (giờ populated)
```

**Enhancement chính**: Phase 4.1 screen detail đã có nearest_road, POI count, venue info. Phase 4.2.1 thêm `population_density_300m` để user hiểu tại sao urban screens có estimate cao hơn rural.

---

## 4. UI polish #1 — Admin config edit (delivery_defaults)

### 4.1. Breaking change

Migration 014 widened `delivery_defaults.value` CHECK constraint: `<= 100` → `<= 1,000,000`.

Nếu Laravel có admin UI edit config values (vd: view `config.delivery_defaults` trong Filament), **input validation Laravel-side cần update** để cho phép values lớn:

**Before**:
```php
// Phase 2.A expected: value 0..100
TextInput::make('value')
    ->numeric()
    ->rules(['between:0,100']);
```

**After**:
```php
TextInput::make('value')
    ->numeric()
    ->rules(['between:0,1000000'])
    ->helperText('0..100 cho factors (visibility, etc). >1000 cho absolute constants (baseline, etc)');
```

### 4.2. New keys Laravel admin có thể edit

Phase 4.1 + 4.2.1 thêm 6 keys mới:

| Key | Default | Range | Description |
|---|---|---|---|
| `campaign_reach_base_per_cell` | 500 | 50-5000 | Fallback reach (khi không có population data) |
| `campaign_reach_saturation_cap` | 2.0 | 1-5 | Max duration multiplier |
| `campaign_reach_capture_rate` | 0.05 | 0.01-0.5 | % population see screen daily |
| `population_density_baseline_per_km2` | 15000 | 1000-50000 | Urban baseline reference |
| `population_factor_min` | 0.3 | 0.1-1.0 | Floor cho rural |
| `population_factor_max` | 2.0 | 1.0-5.0 | Cap cho dense core |

Laravel Formula admin UI (Phase 2.A) nên extend:
- Hiện 6 keys mới này với helperText giải thích
- Hint "Publish new version" sau khi edit để persist vào snapshot

---

## 5. UI enhancement #2 — Screen inspector badge đổi

### 5.1. Bỏ badge "Incomplete data" cho HCMC

Phase 4.2.1 đã ingest HRSL cover HCMC (~1 triệu cells region). HCMC screens giờ có:
- `nearest_road_class`: populated (Phase 2.D roads)
- `population_density_300m`: populated (Phase 4.2.1 HRSL)
- POI counts: populated (Phase 2.C POI collector)

Laravel condition cũ (Phase 2.D):
```php
// Old: badge "Incomplete" nếu HCMC
if ($screen->city === 'HCMC') {
    return ['incomplete', 'red'];
}
```

New: dynamic check, apply cho mọi city:
```php
public function dataQualityBadge(Screen $screen): array
{
    $metrics = $this->getMetrics($screen);

    if (!$metrics) return ['not_enriched', 'gray', 'No enrichment data'];

    $hasRoad = $metrics->nearest_road_id !== null;
    $hasPoi  = ($metrics->poi_count_300m ?? 0) > 0;
    $hasPop  = $metrics->population_density_300m !== null;

    if ($hasRoad && $hasPoi && $hasPop)
        return ['complete', 'green', 'All data sources present'];
    if ($hasRoad && $hasPoi)
        return ['partial', 'amber', 'Population data missing (Phase 4.2.1)'];
    return ['incomplete', 'red', 'Missing road and/or POI data'];
}
```

Works cho Hanoi, HCMC, Đà Nẵng, Hải Phòng (all 4 HRSL-covered cities).

---

## 6. Campaign Planner update — reach formula changed

Phase 4.1 campaign reach dùng **constant 500/cell × saturation**. Phase 4.2.1 chuyển sang **2-path**:

- **Density-driven** (cells có density data ≥ 50% coverage): `reach = avg_density × 0.0234 km² × 0.05 × unique_cells × saturation`
- **Fallback** (< 50% cells có density): keep Phase 4.1 formula

### 6.1. Impact

Cho campaign Hanoi/HCMC/Đà Nẵng/Hải Phòng: path 1 (density-driven) sẽ được dùng. Reach numbers **giảm so với Phase 4.1** — vì capture_rate 0.05 realistic hơn 500/cell constant.

Ví dụ:
- Campaign 10 screens urban Hanoi, duration 30d:
  - Phase 4.1: reach ~10 × 500 × 2.0 = 10,000
  - Phase 4.2.1 với HRSL density 50k: reach ~10 × (50000 × 0.0234 × 0.05) × 2.0 = ~1,170
  - **Thực tế hơn**: 1k unique viewers cho 10 screens × 30 days là realistic cho 150m² cells

### 6.2. Laravel UI update

Campaign Planner result page nên:
- Hiện cảnh báo "Reach estimate updated in Phase 4.2.1 (population-driven). Numbers may differ from campaigns created before 2026-04-23."
- Keep historical campaigns unchanged (đã persist trong DB)

### 6.3. Warning thresholds cần tune

Phase 4.1 warning `frequency > 50 → high` có thể không còn phù hợp. Với reach nhỏ hơn → frequency lớn hơn tự nhiên.

Suggest new tiers:
- `frequency > 100` → warning (was 50)
- `frequency > 500` → critical (was 100)

Laravel team chốt lại sau 1-2 tuần gather real numbers.

---

## 7. Deploy checklist (Laravel ops)

```bash
# 1. Pull Laravel code
cd /www/wwwroot/dash.oohx.net && git pull

# 2. (If có Filament resources mới cho analytics)
php artisan migrate --force    # Laravel side nếu có migration
php artisan optimize:clear

# 3. Test Eloquent connection tới 4 MVs
php artisan tinker -x '
    echo App\Models\Oohx\AnalyticsCityPerformance::count();
    echo App\Models\Oohx\AnalyticsCampaignWeekly::count();
    echo App\Models\Oohx\AnalyticsScreenUtilization::count();
    echo App\Models\Oohx\AnalyticsFormulaVersionImpact::count();
'
# Expect 4 non-zero counts

# 4. Verify screen detail page mới (với population_density_300m)
# Pick 1 HCMC screen → check inspector infolist có "Population density" field

# 5. Verify campaign planner vẫn work
# Tạo test campaign 3 screens → reach number realistic (không phải 10k fallback)
```

---

## 8. Test plan (Laravel)

### 8.1. Analytics dashboard

- [ ] Login admin → navigate Data Engine → Dashboard page exists
- [ ] 4 widgets render (campaigns weekly trend, city performance, screen utilization, formula impact)
- [ ] Numbers match raw SQL `SELECT * FROM output.mv_*`
- [ ] Click "Refresh analytics" button → data reload (nếu Laravel build button)
- [ ] Performance: dashboard load < 2s

### 8.2. Campaign Planner vẫn work (regression)

- [ ] Tạo campaign mới với 3 Hanoi screens, 30d duration → reach 300-3000 (realistic range)
- [ ] Save → campaign_id tồn tại trong `output.campaign_estimates`
- [ ] Compare với campaign cũ (pre-4.2.1): numbers khác nhưng UI không crash

### 8.3. Screen detail page với population data

- [ ] Open 1 HCMC Quận 1 screen → population_density ~30-60k/km² displayed
- [ ] Open 1 Đà Nẵng screen → density ~20-40k/km²
- [ ] Open 1 rural screen (ngoài 4 city) → density NULL → fallback "Data not available"

### 8.4. Config admin (if exists)

- [ ] Edit `population_density_baseline_per_km2` = 20000 → save
- [ ] No validation error (widened to 1M)
- [ ] Publish new formula version → audit log entry
- [ ] Recompute → numbers adjust

### 8.5. Data quality badge

- [ ] Hanoi screen → "Complete" badge (green) — road + POI + pop data
- [ ] HCMC screen → "Complete" badge — now no longer "Incomplete"
- [ ] Rural city (vd Thái Nguyên) → "Partial" badge — no population data yet

---

## 9. Open questions — need Laravel decision

1. **Refresh analytics button** — Laravel build hay defer? Nếu build, endpoint gọi DE qua SSH hoặc expose HTTP?
2. **Dashboard route path** — `/admin/oohx-dashboard` hay integrate vào existing page?
3. **Formula version impact widget visibility** — chỉ admin hay show cả sales team?
4. **Warning thresholds frequency cao** — chốt tiers mới như §6.3?
5. **Config edit UI** — đã có page cho `config.delivery_defaults` chưa? Nếu chưa → defer thêm keys mới hiển thị.

---

## 10. Data freshness SLA

| Data | Refresh | Delay |
|---|---|---|
| Campaign estimates (per campaign) | Realtime (job queue 1min) | ≤ 2 phút |
| Screen estimates | Nightly recompute_all (03:00 UTC) hoặc manual | ≤ 24h |
| Analytics MVs | Daily 04:15 UTC | ≤ 24h |
| Weather snapshots | 6h per city | ≤ 6h |
| POI data | Weekly | ≤ 7d |
| Roads data | Monthly | ≤ 30d |
| Population data (HRSL) | Manual update khi có source mới | ≤ 1 year |

---

## 11. Quick reference — DE CLI nếu Laravel cần trigger

Laravel team có SSH access tới DE VPS thì có thể trigger:

```bash
# Refresh MVs ngay
ssh oohx@139.162.20.95 "cd apps/oohx-matrix/python-data-engine && .venv/bin/python -m app.cli refresh-analytics"

# Recompute all screens (force)
ssh oohx@139.162.20.95 "cd apps/oohx-matrix/python-data-engine && .venv/bin/python -m app.cli enqueue-recompute-all"

# Check health
ssh oohx@139.162.20.95 "cd apps/oohx-matrix/python-data-engine && .venv/bin/python -m app.cli health-check --json"
```

---

## 12. Summary for Laravel team lead

**Must-do**:
1. Build Analytics Dashboard với 4 MVs (Phase 4.2.7) — this is the main product UX upgrade
2. Update screen inspector — hiện `population_density_300m` field
3. Update data quality badge logic — dynamic check, remove HCMC-specific

**Should-do**:
4. Update config admin UI validation (nếu có edit page) — accept value > 100
5. Tune campaign frequency warning thresholds sau 2 tuần feedback

**Nice-to-have**:
6. "Refresh analytics" button
7. Display staleness indicator "Last updated: ..."

**Don't-do**:
- KHÔNG viết migration Laravel-side — tất cả DE
- KHÔNG tự query raw tables cho dashboard — dùng MVs
- KHÔNG hardcode city-specific logic — dynamic qua metrics

---

## 13. Changelog

| Ngày | Change |
|---|---|
| 2026-04-21 | Phase 4.1 shipped (Laravel đã integrate) |
| 2026-04-22 | Phase 4.2.1 DE + Phase 4.2.7 DE shipped |
| 2026-04-23 | HRSL data ingested + Laravel consolidated handoff (this doc) |

---

*Handoff owner: Data Engine tech lead. Questions? Paste vào #oohx-dataengine.*
