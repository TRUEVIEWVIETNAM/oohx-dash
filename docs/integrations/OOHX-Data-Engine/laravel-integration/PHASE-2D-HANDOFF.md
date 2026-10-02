# Phase 2.D — Data Engine Handoff for Laravel Team

> **Role of this document**: handoff Phase 2.D (roads collector + contextual formula factors) cho team Laravel.
> **Status**: backend production-ready. Laravel UI update minor (2 chỗ).
> **Reference**:
> - [03-collectors-admin.md](03-collectors-admin.md) — Guide 03
> - [04-screen-context-inspector.md](04-screen-context-inspector.md) — Guide 04 (thêm 3 factor mới)
> - [PHASE-2C-HANDOFF.md](PHASE-2C-HANDOFF.md) — Phase 2.C (2 collector đầu)
> - [DATA-ENGINE-ACTION-PLAN.md](../DATA-ENGINE-ACTION-PLAN.md)

---

## 1. What's shipped (Phase 2.D)

### 1.1. New SQL migration

- [`sql/009_seasonality.sql`](../../python-data-engine/sql/009_seasonality.sql) — `config.seasonality_factors (city, month, factor)` PK composite, GRANT `oohx_control` read+write.

### 1.2. New collector: `osm_roads`

- [`app/collectors/osm_roads.py`](../../python-data-engine/app/collectors/osm_roads.py)
- Query Overpass `way[highway=~"...primary|secondary|tertiary|residential|service..."]`
- Returns LineString geometry → PostGIS LINESTRING → `source.roads`
- Tile split 0.08° (nhỏ hơn POI vì roads nhiều hơn)
- Multi-endpoint fallback giống POI collector
- Cache 24h

**Impact**: chuyển từ 5 sample roads → ước 5,000-15,000 roads/city thực tế.
Nearest-road enrichment sẽ match đúng tên đường, class, lane count, oneway.

### 1.3. Formula factors mới — weather, seasonality, calibration

Được tự động compute trong `EnrichmentService` + multiply trong `TrafficEstimationService`:

**Outdoor**:
```
passby = base × road × lane × intersection × poi × population
       × weather_factor × seasonality_factor × calibration_factor
OTS     = passby × visibility × direction
impr    = OTS × SOV × dwell
```

**Indoor** (weather/seasonality scale nhẹ hơn, `indoor_sens = 0.3`):
```
effective_weather = 1 - (1 - weather_factor) × 0.3
screen_flow = venue_footfall × zone × effective_weather × seasonality × calibration
OTS         = screen_flow × visibility × direction
impr        = OTS × SOV × dwell
```

Ví dụ: `weather_factor = 0.75` (mưa lớn) → outdoor giảm 25%, indoor chỉ giảm 7.5%.

### 1.4. Contextual factor computation

| Factor | Source | Logic |
|---|---|---|
| `weather_factor` | `source.weather_snapshots` mới nhất ≤ 6h | precip > 5mm → 0.75; 1-5mm → 0.90; thunderstorm code 95/96/99 → 0.70; temp > 38°C hoặc < 10°C → 0.85; else 1.0; **NULL nếu chưa có snapshot** |
| `seasonality_factor` | `config.seasonality_factors (city, month)` | lookup tháng hiện tại; **NULL nếu chưa seed** |
| `calibration_factor` | (Phase 3 — auto tune từ `traffic_samples`) | hiện tại luôn NULL → estimator dùng 1.0 |

Null-handling: bất kỳ factor nào NULL → estimator dùng `1.0` (không ảnh hưởng kết quả). An toàn default — thêm data sau không crash.

### 1.5. Traffic samples ingest

- [`app/repositories/traffic_samples.py`](../../python-data-engine/app/repositories/traffic_samples.py)
- CLI `ingest-traffic-samples --file sample.csv` bulk insert vào `source.traffic_samples`
- CSV schema: `lat, lon, observed_passby, observed_at` required; `city, observation_type, duration_hours, source_name, notes` optional
- Dữ liệu dùng cho calibration phase sau (chưa active trong formula 2.D)

### 1.6. New CLI commands

| Command | Purpose |
|---|---|
| `collect-roads --city X` | Sync shortcut cho osm_roads |
| `seed-seasonality [--force]` | Seed `config.seasonality_factors` cho VN cities (Hanoi/HCMC/Da Nang/Hải Phòng) |
| `list-seasonality [--city X]` | List seasonality factors |
| `ingest-traffic-samples --file X.csv` | Bulk ingest ground-truth samples |

---

## 2. Data contract changes cho Laravel

### 2.1. `metrics.screen_context_metrics` — 3 cột mới (đã migrate từ 007)

| Cột | Type | Meaning |
|---|---|---|
| `weather_factor` | NUMERIC | 0.7-1.0, NULL nếu chưa có weather data |
| `seasonality_factor` | NUMERIC | 0.85-1.10, NULL nếu city chưa có seed |
| `calibration_factor` | NUMERIC | NULL (phase 3 placeholder) |

Laravel `ScreenContextMetrics` Eloquent model cần thêm casts:

```php
protected $casts = [
    // ... existing ...
    'weather_factor'     => 'float',
    'seasonality_factor' => 'float',
    'calibration_factor' => 'float',
];
```

### 2.2. `config.seasonality_factors` — new table

Role `oohx_control` có SELECT + INSERT + UPDATE + DELETE. Laravel có thể admin qua UI.

Schema:
```sql
(city TEXT, month INT CHECK 1-12, factor NUMERIC CHECK 0 < x <= 2,
 note TEXT, updated_by TEXT, updated_at TIMESTAMPTZ, PK (city, month))
```

Eloquent model ví dụ:

```php
namespace App\Models\Oohx\Config;

class SeasonalityFactor extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'config.seasonality_factors';
    public $timestamps    = false;
    protected $primaryKey = null;          // composite PK (city, month)
    public $incrementing  = false;

    protected $fillable = ['city', 'month', 'factor', 'note', 'updated_by'];
    protected $casts = ['factor' => 'float', 'updated_at' => 'datetime'];
}
```

### 2.3. Collector mới `osm_roads` — metadata cho `config/oohx_collectors.php`

```php
'osm_roads' => [
    'display_name'  => 'Roads (OpenStreetMap / Overpass)',
    'description'   => 'Fetch highway ways (motorway..service) in bbox. '
                     . 'UPSERT into source.roads with LineString geometry + '
                     . 'road_class / lane_count / oneway / maxspeed.',
    'provider'      => 'Overpass API (multi-endpoint fallback)',
    'cost'          => 'free',
    'rate_limit'    => '10,000 queries/day per IP',
    'cadence_hours' => 720,           // monthly
    'supports_city' => true,
    'supports_bbox' => true,
    'cache_ttl_hours' => 24,
    'expected_runtime_seconds' => 180,  // ~15 tiles × 5-15s
],
```

### 2.4. Output estimate — không đổi shape

`output.screen_traffic_estimates` giữ nguyên cột. Giá trị số sẽ thay đổi sau khi recompute dùng formula mới (weather/seasonality apply).

Cột `model_version` sẽ point sang version mới khi ops publish (xem §4 below).

---

## 3. UI updates cần làm (Laravel side)

### 3.1. Guide 03 — thêm 1 collector card

Trong `/admin/oohx/collectors` list thêm `osm_roads` row. Staleness cadence = 30 days (monthly).

UI auto-generate từ `config/oohx_collectors.php` — chỉ cần add entry ở §2.3.

### 3.2. Guide 04 — Inspector hiển thị 3 factor mới

Trong breakdown panel (`ScreenInspectorService::buildBreakdown`) thêm 3 step giữa passby/screen_flow và OTS:

**Outdoor updated breakdown**:
```
Base city × road × lane × intersection × poi × population → <base_passby>
× weather_factor         = 0.90       note: "Light rain 2h ago"
× seasonality_factor     = 0.85       note: "Tet period"
× calibration_factor     = 1.00       note: "(phase 3)"
──────────────────────────────
⇒ daily passby           = <adjusted_passby>
× visibility × direction = ...
⇒ daily OTS              = ...
× SOV × dwell            = ...
⇒ daily impressions      = ...
```

Banner warnings:
- `weather_factor IS NULL` → "⚠ No weather data (ensure open_meteo_weather collector ran within 6h)"
- `seasonality_factor IS NULL` → "⚠ City not in seasonality table. Run `seed-seasonality` hoặc add via config UI"

### 3.3. New admin resource: `SeasonalityFactorResource` (Filament)

Giống pattern `RoadClassMultiplierResource`:

- List table: city, month, factor, updated_by, updated_at
- Filters: city dropdown, month number
- Create: city text + month int + factor decimal (0 < x ≤ 2)
- Edit: factor + note
- No delete (audit trail); soft via factor=1.0 nếu muốn "no-op"
- Bulk action: seed from defaults (calls Laravel-side replica of `_DEFAULT_SEASONALITY`)

**Helpful UI**: heatmap view — 12 cột tháng × N hàng city, color-code factor (xanh >1, đỏ <1).

---

## 4. Ops action items

### 4.1. Sau khi deploy code

```bash
# Pull + apply migration 009
cd ~/apps/oohx-matrix && git pull
cd python-data-engine && source .venv/bin/activate
python -m app.cli init-db --sql-dir sql

# Seed seasonality factors
python -m app.cli seed-seasonality
python -m app.cli list-seasonality

# Collect roads cho city chính (chạy 1 lần, monthly sau đó)
python -m app.cli collect-roads --city Hanoi
python -m app.cli collect-roads --city HCMC

# (Optional) publish version mới để tách biệt trước/sau với Laravel
python -m app.cli publish-config-version \
    --tag v2-contextual-2026q2 \
    --description "Phase 2.D: weather + seasonality integration + real roads" \
    --activate

# Recompute để stamp formula_version_id mới + pick up osm_roads data
python -m app.cli enqueue-recompute-all
python -m app.cli recompute-pending-jobs --max 100
```

### 4.2. Cron updates

```cron
# Weekly roads refresh (Sunday 5-6am, sau POI)
0 5 * * 0   cd /home/oohx/apps/oohx-matrix/python-data-engine && \
    .venv/bin/python -m app.cli collect-roads --city Hanoi \
    >> /home/oohx/logs/roads.log 2>&1
0 6 * * 0   cd /home/oohx/apps/oohx-matrix/python-data-engine && \
    .venv/bin/python -m app.cli collect-roads --city HCMC \
    >> /home/oohx/logs/roads.log 2>&1

# Existing weather (every 6h) vẫn giữ — weather_factor cần fresh data
```

---

## 5. Smoke test round-trip (VPS)

```bash
# 1. Apply migration
python -m app.cli init-db --sql-dir sql

# 2. Seed seasonality
python -m app.cli seed-seasonality

# 3. Collect roads (first run ~2-5 phút, 15 tiles)
python -m app.cli collect-roads --city Hanoi

# 4. Verify road data
psql -h 127.0.0.1 -U oohx -d oohx_data -c "
SELECT road_class, COUNT(*)::int AS cnt,
       AVG(lane_count)::numeric(5,2) AS avg_lanes,
       COUNT(*) FILTER (WHERE oneway)::int AS oneway_cnt
FROM source.roads WHERE source_name='overpass' GROUP BY road_class ORDER BY cnt DESC;"

# 5. Ensure weather snapshot recent
python -m app.cli collect-weather --city Hanoi

# 6. Publish new version (link Python formula code + DB config)
python -m app.cli publish-config-version \
    --tag v2-smoke-$(date +%Y%m%d) \
    --description "Phase 2.D smoke test" \
    --activate

# 7. Recompute active screens với formula mới
python -m app.cli enqueue-recompute-all
python -m app.cli recompute-pending-jobs --max 100

# 8. Verify 3 factor mới đã được populate
psql -h 127.0.0.1 -U oohx -d oohx_data -c "
SELECT s.external_id, s.city,
       m.nearest_road_class, m.weather_factor, m.seasonality_factor,
       ROUND(e.estimated_daily_impressions::numeric, 0) AS impr,
       e.model_version, e.formula_version_id
FROM core.screens s
JOIN metrics.screen_context_metrics m ON m.screen_id = s.id
JOIN output.screen_traffic_estimates e ON e.screen_id = s.id
WHERE s.status='active' AND s.city='Hanoi'
ORDER BY impr DESC LIMIT 10;"

# 9. Test traffic samples ingest
cat > /tmp/samples.csv <<'CSV'
lat,lon,observed_passby,observed_at,city,observation_type,source_name
21.0285,105.8542,28500,2026-04-18T12:00:00,Hanoi,vehicle,manual-survey
21.0175,105.8501,32100,2026-04-18T14:00:00,Hanoi,vehicle,manual-survey
CSV
python -m app.cli ingest-traffic-samples --file /tmp/samples.csv
psql -h 127.0.0.1 -U oohx -d oohx_data -c "
SELECT city, observed_passby, observed_at, source_name FROM source.traffic_samples;"
```

---

## 6. Expected estimate shifts

Sau recompute với formula 2.D, các thay đổi phổ biến:

| Scenario | weather | seasonality | Combined effect outdoor |
|---|---|---|---|
| Ngày nắng Hanoi tháng 4 | 1.00 | 1.05 | **+5%** |
| Mưa nhẹ Hanoi tháng 4 | 0.90 | 1.05 | **-5.5%** (0.9 × 1.05) |
| Mưa lớn HCMC tháng 7 | 0.75 | 0.95 | **-28.75%** |
| Tet Hanoi tháng 2 nắng | 1.00 | 0.85 | **-15%** |
| Tet HCMC tháng 2 mưa | 0.90 | 0.85 | **-23.5%** |

Indoor chịu tác động ~30% của outdoor (indoor_sensitivity=0.3).

---

## 7. Things Laravel team KHÔNG làm

- ❌ Không tự compute weather/seasonality — Python làm ở enrichment time. Laravel chỉ **hiển thị** factor đã resolved.
- ❌ Không DELETE rows trong `source.traffic_samples` từ Laravel — là lịch sử calibration.
- ❌ Không trigger `collect-roads` nhiều lần/tuần — cadence monthly, over-fetching tốn Overpass quota.

---

## 8. Next — Phase 3 preview

Có thể làm sau nếu cần:
- **worldpop_population** — raster → H3 grid, bật `population_factor` thật
- **Auto-calibration** — scheduled job so `observed_passby` vs `predicted` → update `calibration_factor` per screen
- **Dry-run preview** — "nếu activate version X, estimate thay đổi bao nhiêu?" (TASK-DE-2A.4 optional)
- **Weather-aware cron** — trigger recompute-stale mỗi khi weather_factor thay đổi drastically (mưa lớn/thông báo bão)

---

## 9. Changelog

| Ngày | Change |
|---|---|
| 2026-04-20 | Phase 2.D backend shipped: osm_roads collector, config.seasonality_factors, weather/seasonality/calibration factors trong enrichment+estimation, traffic_samples CSV ingest, 4 CLI command mới. Formula version bump cần ops publish. |

---

*Handoff owner: Data Engine tech lead.*
