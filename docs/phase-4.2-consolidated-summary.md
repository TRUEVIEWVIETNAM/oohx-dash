# Phase 4.2 (4-A + 4-B) — Laravel Build Summary

**Build date**: 2026-04-23
**Scope**: Analytics Dashboard (must-do #1) + 4 polish items từ Phase 4.2 Consolidated handoff §12
**Status**: Code shipped + smoke tested. Phase 4-A chờ ops apply DE migration 013; Phase 4-B fully functional.

---

## Phase 4-A — Analytics Dashboard

Build 4 Eloquent projections + helper service + custom Filament page rendering 4 analytics sections.

### Files (7 new)

| File | Role |
|---|---|
| `app/Models/Oohx/AnalyticsCampaignWeekly.php` | MV `output.mv_campaign_weekly` — 12 tuần trailing |
| `app/Models/Oohx/AnalyticsCityPerformance.php` | MV `output.mv_city_performance` — per-city rollup |
| `app/Models/Oohx/AnalyticsScreenUtilization.php` | MV `output.mv_screen_utilization` — booked 90d |
| `app/Models/Oohx/AnalyticsFormulaVersionImpact.php` | MV `output.mv_formula_version_impact` — active+history |
| `app/Services/Oohx/AnalyticsService.php` | Cached (5m) helpers + graceful fallback khi MV missing |
| `app/Filament/Pages/OohxAnalytics.php` | Custom page `/admin/oohx-analytics` (sort 57), super_admin only |
| `resources/views/filament/pages/oohx-analytics.blade.php` | 4-section layout |

### 4 UI sections

1. **Header banner** — staleness (color-coded age), last refresh timestamp GMT+7, WoW KPI cards (campaigns / impressions / reach)
2. **Last 12 weeks trend** — horizontal bar chart với impressions + campaigns count per week
3. **Top cities** — table: city, screens (outdoor/indoor), daily impressions, avg confidence
4. **Formula versions impact** — list: tag, active badge, activated ago, screens count, total impressions
5. **Screen utilization** — top 20 booked screens 90d + count summary

### Graceful fallback

`AnalyticsService::isAvailable()` detect MVs existence via try/catch. Khi migration 013 chưa apply:
- Page hiện empty state với ops instructions (no crash)
- Service returns empty collections (log warning, not throw)
- Cache `analytics:available` 5 phút → không hammer DE khi hỏng

### Read-only guards

Mọi MV model override `save()` + `delete()` → throw `RuntimeException`. Đảm bảo Laravel không accidental write (dù `oohx` role không có permission).

### Cache strategy

- Each query cached 5 phút
- `flushCache()` xoá tất cả
- Matches DE refresh cadence (daily 04:15 UTC) — 5m staleness trên 24h base acceptable

---

## Phase 4-B — 4 polish items (all must-do/should-do per handoff §12)

### B.1: Data completeness 3-tier (ScreenContextMetrics)

**Before** (Phase 3.A Part 2): 2-source check (road + POI), 2-tier badge (complete/incomplete).

**After** (Phase 4.2.1): 3-source check (road + POI + population), 3-tier badge:

| Tier | Sources present | Color | Label |
|---|---|---|---|
| `complete` | 3/3 | success (green) | Complete |
| `partial` | 2/3 | warning (amber) | Partial |
| `incomplete` | 0-1/3 | danger (red) | Incomplete data |

Smoke-tested 5 permutations — all correct.

### B.2: Population density in inspector

**OohxEstimateResource** table:
- `data_completeness` column promoted từ IconColumn → TextColumn badge (3 colors + tier label)
- New `contextMetrics.population_density_300m` column (toggleable hidden default) với suffix `/km²` + color per tier:
  - ≥30k → success (dense urban)
  - ≥10k → info (residential)
  - < 10k → warning (suburban/rural)

**Infolist** "Data completeness" section:
- Expanded từ 3 cols → 4 cols
- New `population_density_300m` entry với helperText "Dense urban"/"Residential"/"Suburban / rural"
- Missing reasons expanded — giờ list 3 reasons khi tier ≠ complete

### B.3: DeliveryDefault widen + 6 new keys

**Model** (`app/Models/Oohx/Config/DeliveryDefault.php`):
- KEYS extended từ 9 → 15 (added Phase 4.1 reach + Phase 4.2.1 population keys)
- Docstring updated với migration 014 note
- New `rangeFor(string $key): [min, max, label]` static helper — per-key tight range

**Resource** (`app/Filament/Resources/OohxConfig/DeliveryDefaultResource.php`):
- `maxValue(100)` → `maxValue(1000000)` (matches DE CHECK constraint migration 014)
- `helperText` now dynamic — pulls range từ `DeliveryDefault::rangeFor($get('key'))`
- Select key made `->live()` để helperText update ngay khi đổi key

**6 new keys available trong dropdown**:
```
campaign_reach_base_per_cell          50..5000
campaign_reach_saturation_cap         1..5
campaign_reach_capture_rate           0.01..0.5
population_density_baseline_per_km2   1000..50000
population_factor_min                 0.1..1.0
population_factor_max                 1.0..5.0
```

### B.4: CampaignEstimate frequency tuned

**Before**: >50 warning, >100 danger (Phase 4.1 baseline)
**After** (handoff §6.3): **>100 warning, >500 danger**

Rationale: Phase 4.2.1 shift campaign reach từ constant 500/cell → density-driven → reach numbers thực tế thấp hơn → frequency tự nhiên cao hơn. Tiers cũ thành noise.

Changes:
- `CampaignEstimate::getFrequencyWarningAttribute()` — 2 messages tuned
- `CampaignEstimate::getFrequencyColorAttribute()` — thresholds updated
- `OohxCampaignEstimateResource::infolist()` — warnings section visible condition `> 50` → `> 100`

---

## Navigation structure after Phase 4.x

```
OOHX · Data Engine/
├── Collectors              (sort 55)
├── Health Monitor          (sort 56)
├── Analytics               (sort 57) — Phase 4-A NEW
├── Campaign Planner        (sort 60) — Phase 4.1
├── Recompute Jobs          (sort 65)
├── Collector Runs          (sort 67)
├── Delivery defaults       (sort 74) — Phase 4-B.3 updated
└── [other config]
```

---

## Smoke test results

```
── Completeness tier (5 cases) ──
✓ road=1 poi=5 pop=30000   → complete
✓ road=1 poi=5 pop=null    → partial
✓ road=null poi=5 pop=30000 → partial
✓ road=null poi=0 pop=null → incomplete
✓ road=null poi=0 pop=30000 → incomplete

── Frequency thresholds (4 cases) ──
✓ f=30  → success / null
✓ f=50  → success / null        (was warning before — now correctly clean)
✓ f=101 → warning / "Frequency cao"
✓ f=501 → danger / "Over-saturation severe"

── DeliveryDefault rangeFor ──
✓ visibility_outdoor: [0, 1] '0..1 (factor)'
✓ campaign_reach_base_per_cell: [50, 5000] '50..5000 viewers/cell'
✓ population_density_baseline_per_km2: [1000, 50000] '1k..50k /km²'
✓ unknown_key → fallback [0, 1000000] '0..1,000,000'

── Routes registered ──
GET admin/oohx-analytics → filament.admin.pages.oohx-analytics

── AnalyticsService graceful (MV missing local) ──
✓ isAvailable() → false (no crash)
✓ weeklyTrend(2) → empty collection (no crash)
✓ topCities(5) → empty collection (no crash)
```

---

## Deploy checklist (Laravel ops)

```bash
cd /www/wwwroot/dash.oohx.net
git pull
php artisan optimize:clear

# 1. Verify routes
php artisan route:list --path=admin/oohx-analytics | head -2
# Expect: GET admin/oohx-analytics

# 2. Ops DE prerequisite — apply migration 013 + refresh MVs
# Đã done nếu ops chạy:
#   cd /home/oohx/apps/oohx-matrix/python-data-engine
#   .venv/bin/python -m app.cli init-db --sql-dir sql
#   .venv/bin/python -m app.cli refresh-analytics

# 3. Test availability từ Laravel
php artisan tinker --execute="echo app(App\Services\Oohx\AnalyticsService::class)->isAvailable() ? 'READY' : 'MV missing';"

# 4. UI smoke test
# - /admin/oohx-analytics → 4 sections render hoặc empty state
# - /admin/oohx-estimates → "Data" column badge 3 tiers
# - /admin/oohx-estimates/<id> → infolist has population_density_300m
# - /admin/oohx-config/delivery-defaults/create → maxValue 1M + 15 keys
# - /admin/oohx-campaign-estimates → frequency warnings >100/>500
```

---

## Files changed (full Phase 4-A + 4-B delta)

**NEW (7)**:
- `app/Models/Oohx/AnalyticsCampaignWeekly.php`
- `app/Models/Oohx/AnalyticsCityPerformance.php`
- `app/Models/Oohx/AnalyticsScreenUtilization.php`
- `app/Models/Oohx/AnalyticsFormulaVersionImpact.php`
- `app/Services/Oohx/AnalyticsService.php`
- `app/Filament/Pages/OohxAnalytics.php`
- `resources/views/filament/pages/oohx-analytics.blade.php`

**MODIFY (5)**:
- `app/Models/Oohx/ScreenContextMetrics.php` — cast + 3-tier accessors + extended reasons
- `app/Filament/Resources/OohxEstimateResource.php` — completeness badge + population column + infolist extend
- `app/Models/Oohx/Config/DeliveryDefault.php` — +6 KEYS + `rangeFor()` helper
- `app/Filament/Resources/OohxConfig/DeliveryDefaultResource.php` — maxValue 1M + live helperText
- `app/Models/Oohx/CampaignEstimate.php` — frequency thresholds 50/100 → 100/500
- `app/Filament/Resources/OohxCampaignEstimateResource.php` — infolist warning visible condition

**Total**: 7 new + 6 modify.

---

## Handoff coverage (§12 checklist)

| Handoff item | Priority | Status |
|---|---|---|
| 1. Analytics Dashboard | Must | ✅ Phase 4-A |
| 2. Population display in inspector | Must | ✅ Phase 4-B.2 |
| 3. Data quality badge dynamic | Must | ✅ Phase 4-B.1 (extended từ Phase 3.A Part 2) |
| 4. Config admin validation widen | Should | ✅ Phase 4-B.3 |
| 5. Frequency thresholds tune | Should | ✅ Phase 4-B.4 |
| 6. Refresh analytics button | Nice | ❌ Deferred per user decision |
| 7. Staleness indicator | Nice | ✅ Included in Phase 4-A header banner |

---

## Risks / next ops action

### Required for Phase 4-A to be functional

1. Ops apply DE migration 013 (4 materialized views):
   ```bash
   cd /home/oohx/apps/oohx-matrix/python-data-engine
   .venv/bin/python -m app.cli init-db --sql-dir sql
   .venv/bin/python -m app.cli refresh-analytics
   crontab -e    # add cron từ deploy/cron/oohx-analytics.crontab
   ```
2. Laravel `php artisan optimize:clear` sau deploy
3. Cache flush sau lần first refresh: `php artisan cache:forget analytics:available` (hoặc chờ 5 phút TTL)

### Required for Phase 4-B display

1. **4-B.1/4-B.2 population display** — HRSL đã ingest (user confirmed). Model cast + accessor sẽ pick up ngay khi DE data refresh next.
2. **4-B.3 DeliveryDefault** — DE migration 014 (widen CHECK) phải đã apply cùng 013. Nếu chưa, sẽ throw CHECK violation khi save value > 100. Verify:
   ```sql
   SELECT pg_get_constraintdef(oid) FROM pg_constraint
   WHERE conname = 'delivery_defaults_value_check';
   -- Expect: "value >= 0 AND value <= 1000000"
   ```
3. **4-B.4 frequency tiers** — immediate, no ops action.

### Risks remaining

- **MV cache** — nếu user refresh dashboard right sau khi ops apply migration 013, `analytics:available` cache `false` từ lần trước còn 5 phút → empty state. Manual fix: `php artisan cache:clear` sau deploy.
- **`screen_utilization` bigint screen_id** — MV dùng DE internal bigint. Để link sang Laravel screens cần 2-hop resolve (bigint → external_id → Laravel uuid → Laravel ULID). Hiện chỉ hiện `#screen_id` — đủ cho ops debug, chưa đẹp cho sales. Deferrable to Phase 4.3+ nếu cần.
- **Tests** — no PHPUnit tests added. All smoke via tinker. Nếu cần regression safety: recommended `AnalyticsServiceTest` + `ScreenContextMetricsCompletenessTest`.

---

## Tests added
None — all via smoke script in tinker. Lint clean on all 13 files.

## Files changed summary
See "Files changed" section above. 7 new + 6 modify.

## Production smoke checklist
Deploy → `/admin/oohx-analytics` should:
- Empty state until migration 013 applied → ops log hint visible
- After migration: 4 sections render với real data
- Graceful fallback nếu 1-2 MVs missing (per-query try/catch trong AnalyticsService)
