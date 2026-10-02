# Phase 4.2.1 — Population Grid + Accuracy Upgrade

> **Role of this document**: handoff Phase 4.2.1 (Population grid ingestion + wire into enrichment/estimation/campaign reach). Fix 2 limitations biết trước: `population_factor=1.0` hardcode + `campaign reach 500/cell` constant.
>
> **Status**: backend shipped + syntax-validated. Ops cần download Kontur CSV + ingest + trigger recompute-stale.
>
> **Laravel changes**: **ZERO**. UI không đổi. Numbers sẽ tự update sau khi ops ingest + recompute — advertiser thấy estimate/reach chính xác hơn.
>
> **Reference**:
> - [PHASE-4.1-HANDOFF.md](PHASE-4.1-HANDOFF.md) — Campaign Planner (Phase 4.1 §7.3 flagged cần population data)
> - [DATA-ENGINE-ACTION-PLAN.md](../DATA-ENGINE-ACTION-PLAN.md)

---

## 1. Business impact

**Trước Phase 4.2.1**:
- Mọi outdoor screen có `population_factor = 1.0` — không phân biệt urban core vs suburban
- Campaign reach dùng `500 unique viewers/cell` constant — over-estimate cho rural, under-estimate cho dense core
- Advertiser không tin numbers: "Sao 2 biển (1 Hoàn Kiếm, 1 ngoại thành) impressions giống nhau?"

**Sau Phase 4.2.1**:
- Urban screens (density >20k/km²) → `population_factor` lên tới 2.0 → passby × 2
- Rural/suburban (density <5k/km²) → factor 0.3 → passby × 0.3
- Campaign reach: tỷ lệ với actual density per cell, không còn constant
- Numbers sẽ differ 3-6× giữa dense urban và rural trong cùng city

---

## 2. What's shipped

### 2.1. Migration

| File | Change |
|---|---|
| [`sql/012_population_grid.sql`](../../python-data-engine/sql/012_population_grid.sql) | **NEW** `source.population_grid` (cell_id, population, centroid geom, resolution) + unique constraint `(source_name, cell_id)` + GIST index + GRANTs |

### 2.2. Code

| File | Change |
|---|---|
| [`app/config.py`](../../python-data-engine/app/config.py) | +4 constants: `population_density_baseline_per_km2=15000`, `population_factor_min=0.3`, `population_factor_max=2.0`, `campaign_reach_capture_rate=0.05` |
| [`app/repositories/population_grid.py`](../../python-data-engine/app/repositories/population_grid.py) | **NEW** `PopulationGridRepository.bulk_ingest_csv/summary/delete_source/compute_density_for_point` |
| [`app/services/enrichment.py`](../../python-data-engine/app/services/enrichment.py) | +`_SQL_POPULATION_DENSITY` query, wire outdoor enrichment → populate `population_density_300m/500m` + `population_score` |
| [`app/services/traffic_estimation.py`](../../python-data-engine/app/services/traffic_estimation.py) | +`_compute_population_factor(density, cfg)` — thay hardcode 1.0 bằng density-driven |
| [`app/services/campaign_planner.py`](../../python-data-engine/app/services/campaign_planner.py) | SQL aggregate + reach formula cộng 2 fields `cells_with_density` và `sum_density`; `_compute_reach` chuyển 2 path: density-driven (coverage ≥ 50%) hoặc fallback constant |
| [`app/cli.py`](../../python-data-engine/app/cli.py) | 3 CLI mới: `ingest-population-grid`, `population-grid-summary`, `delete-population-source` |

### 2.3. Tests

- [`tests/unit/test_population_grid.py`](../../python-data-engine/tests/unit/test_population_grid.py) — 22 tests: CSV parsing, formula clipping, helpers
- [`tests/unit/test_campaign_planner.py`](../../python-data-engine/tests/unit/test_campaign_planner.py) — updated với 5 new tests về density-driven reach
- [`tests/integration/test_population_grid.py`](../../python-data-engine/tests/integration/test_population_grid.py) — 4 tests: bulk ingest roundtrip, enrichment wiring, dense vs sparse estimate differ

---

## 3. Data source options

### 3.1. Kontur Population Dataset (đề xuất — free, H3 hex)

- URL: https://data.humdata.org/dataset/kontur-population-vietnam
- Format: GeoPackage (gpkg) + CSV
- Coverage: toàn VN, ~100MB, res 8 hex (~461m edge)
- Update: mỗi 2-3 năm
- Preprocess (one-time):
  ```bash
  # Option A — dùng QGIS: export Centroid → CSV với columns lat, lon, h3, population
  # Option B — Python one-liner với geopandas (nếu đã có):
  python -c "
  import geopandas as gpd
  gdf = gpd.read_file('kontur_vn.gpkg')
  gdf['centroid'] = gdf.geometry.centroid
  gdf['lat'] = gdf.centroid.y
  gdf['lon'] = gdf.centroid.x
  gdf[['h3','population','lat','lon']].rename(columns={'h3':'cell_id'}).to_csv('kontur_vn_ingest.csv', index=False)
  "
  ```

### 3.2. GHSL 2020 Population Grid (backup option)

- URL: https://human-settlement.emergency.copernicus.eu/ghs_pop.php
- Format: GeoTIFF 1km resolution
- Preprocess: `gdal_translate -of XYZ ghsl_vn.tif ghsl_vn.xyz` → awk → CSV
- Coverage: global, cần crop VN trước
- Không bắt buộc nếu đã có Kontur

### 3.3. Manual proxy (fallback, small scale)

Nếu không ingest được raster/gpkg, có thể tạo CSV thủ công từ open data VN:
- https://data.humdata.org/dataset/vietnam-subnational-population-statistics
- Dạng district-level → 63 tỉnh × 3-5 district → ~300 rows
- Less granular nhưng OK cho MVP

---

## 4. Ops workflow (one-time)

### 4.1. Download + preprocess data

```bash
# Trên DE VPS
mkdir -p /home/oohx/data/population
cd /home/oohx/data/population

# Kontur CSV (pre-processed theo §3.1)
wget -O kontur_vn_2023.csv <URL>

# Verify format (required cols: cell_id, population, lat, lon)
head -3 kontur_vn_2023.csv
```

### 4.2. Apply migration

```bash
cd /home/oohx/apps/oohx-matrix/python-data-engine
.venv/bin/python -m app.cli init-db --sql-dir sql
```

### 4.3. Ingest

```bash
.venv/bin/python -m app.cli ingest-population-grid \
    --file /home/oohx/data/population/kontur_vn_2023.csv \
    --source kontur_2023 \
    --year 2023
```

Output expected:
```json
{
  "rows_read": 85000,
  "rows_inserted": 85000,
  "rows_skipped": 0,
  "source_name": "kontur_2023",
  "year": 2023
}
```

### 4.4. Verify

```bash
.venv/bin/python -m app.cli population-grid-summary
# Expect 1 source row với cell_count ≥ 50000

# SQL check spatial index
psql -U oohx -d oohx_data -c "
SELECT COUNT(*) FROM source.population_grid
WHERE ST_DWithin(centroid, ST_SetSRID(ST_MakePoint(105.8542,21.0285),4326)::geography, 500);
"
# Expect: > 0 cells near Hanoi center
```

### 4.5. Trigger enrichment refresh + recompute

```bash
# Enrichment update population_density_300m cho mọi outdoor screen
.venv/bin/python -m app.cli enqueue-recompute-stale

# Wait cron drain (≤ 1 phút với interval 1/min), hoặc manual:
.venv/bin/python -m app.cli recompute-pending-jobs --max 200
```

### 4.6. Spot check impact

```sql
-- Density distribution across screens
SELECT
    COUNT(*)                                    AS total,
    COUNT(*) FILTER (WHERE m.population_density_300m IS NOT NULL) AS with_density,
    ROUND(AVG(m.population_density_300m)::numeric, 0) AS avg_density,
    ROUND(MAX(m.population_density_300m)::numeric, 0) AS max_density
FROM metrics.screen_context_metrics m
JOIN core.screens s ON s.id = m.screen_id
WHERE s.status = 'active' AND s.indoor_outdoor = 'outdoor';

-- Compare estimate before/after on 1 urban vs 1 suburban screen
-- (expect dense screen impressions 3-6× suburban, cùng road_class)
```

---

## 5. Formula changes — impact explained

### 5.1. population_factor (traffic estimation)

**Before** (hardcoded):
```python
population_factor = 1.0
passby = base × road × lane × poi × weather × seasonality × population_factor × ...
```

**After** (density-driven):
```python
ratio = density_per_km2 / 15000   # baseline urban VN
population_factor = clip(ratio, 0.3, 2.0)
```

**Examples**:
| Screen location | density | ratio | factor | Effect on passby |
|---|---|---|---|---|
| Hoàn Kiếm core | 45,000 | 3.0 | **2.0** (capped) | × 2.0 |
| Cầu Giấy residential | 20,000 | 1.33 | 1.33 | × 1.33 |
| Hà Đông suburban | 8,000 | 0.53 | 0.53 | × 0.53 |
| Sóc Sơn rural | 2,000 | 0.13 | **0.3** (floored) | × 0.3 |

**NULL handling**: screens chưa enrich với data → factor = 1.0 fallback (no change).

### 5.2. Campaign reach (2 paths)

**Path 1 — Density-driven** (coverage ≥ 50% cells có density):
```python
per_cell_reach = avg_density × 0.0234 × 0.05  # cell_area × capture_rate
raw_reach = unique_cells × per_cell_reach
```

**Path 2 — Fallback** (coverage < 50% hoặc no density data):
```python
raw_reach = unique_cells × 500  # Phase 4.1 constant
```

**Both paths** apply: `reach = raw_reach × saturation(duration)`.

### 5.3. Side effect: Laravel numbers change

Sau ingest + recompute-stale:
- Urban screens: `estimated_daily_impressions` tăng tới 2× (vs pre-4.2.1)
- Rural screens: giảm tới 0.3× (vs pre-4.2.1)
- Campaigns đã compute pre-4.2.1: giữ nguyên trong `output.campaign_estimates` (historical). Chỉ campaigns mới recompute với formula mới.

**Laravel UI cần note**: có thể show "Last updated: <ngày>" trên screen estimate + campaign result, để user biết data freshness.

---

## 6. Regression check — Phase 4.1 campaigns

Campaign Planner backward-compat: ngay cả khi KHÔNG có population_grid data, code vẫn hoạt động (fallback Path 2 = Phase 4.1 formula). Không break existing campaigns.

Verify manual:
```bash
# Enqueue campaign sau khi deploy 4.2.1 nhưng TRƯỚC ingest Kontur
.venv/bin/python -m app.cli estimate-campaign --screens 1,2,3 --duration 30 --no-persist

# Expect: result có `reach` > 0, không crash. Formula dùng fallback 500/cell.
```

---

## 7. Config tuning (optional, sau ingest)

Sau khi có data thực tế, ops có thể tune formula via `config.delivery_defaults`:

```sql
-- Tăng baseline nếu average VN urban density thực tế ≠ 15k (do Kontur đo năm cũ)
UPDATE config.delivery_defaults
SET value = 18000.0
WHERE key = 'population_density_baseline_per_km2';

-- Tighten factor range nếu thấy 2.0 cap quá rộng
UPDATE config.delivery_defaults
SET value = 1.5
WHERE key = 'population_factor_max';

-- Tăng capture rate nếu sales feedback reach under-estimate
UPDATE config.delivery_defaults
SET value = 0.08
WHERE key = 'campaign_reach_capture_rate';
```

Config TTL cache 5 phút → changes auto-apply sau 5 phút. Không cần restart.

Publish qua `publish-config-version` để persist snapshot + audit trail.

---

## 8. Laravel test plan

### 8.1. After ops ingest

- [ ] Dashboard `Data Engine Health` không có check cho `population_grid` (tạm thời — sẽ add Phase 4.2.6 nếu cần)
- [ ] Pick 1 urban screen (Hoàn Kiếm) + 1 suburban (Hà Đông) — `estimated_daily_impressions` khác biệt ≥ 2×
- [ ] Tạo campaign 5 urban screens → reach number giảm so với pre-4.2.1 (500/cell over-estimate cũ → density thực tế thấp hơn)

### 8.2. Existing features

- [ ] Campaign Planner Phase 4.1 vẫn work — no regression
- [ ] Preview version Phase 3.A vẫn work
- [ ] Health check 9 checks vẫn pass

---

## 9. Known limitations / Phase 4.2.2+ todo

| Gap | Fix ở |
|---|---|
| Kontur data phải manual preprocess → CSV (cần QGIS hoặc geopandas) | Document trong runbook. Phase 4.2.6 có thể thêm `ingest-population-raster` CLI nếu dùng rasterio OK |
| Indoor screens KHÔNG dùng population_density | Phase 4.2.2 Google Places footfall sẽ thay vai trò tương tự cho indoor |
| capture_rate 0.05 hardcoded | Cần real traffic samples (Phase 4.2.3-4) để calibrate theo location type |
| Data lần update → recompute toàn bộ thủ công | Phase 4.2.5 có thể trigger auto-recompute khi ingest |
| `population_grid_freshness` chưa có health check | Add Phase 4.2.6 nếu ops muốn monitor |

---

## 10. Open questions

1. **Kontur vs GHSL** — ops chọn source nào? Đề xuất Kontur vì preprocess đơn giản hơn GeoTIFF.
2. **Retention** — giữ multiple years? Hiện schema cho phép multi-source/multi-year nhưng enrichment query chỉ lấy union all. Có thể filter theo `WHERE source_name = 'latest'` Phase 4.2.6.
3. **Update cadence** — Kontur update 2-3 năm/lần. Cron auto-refresh annually?
4. **VN-specific density baseline** — 15k/km² có đúng cho đa số tỉnh VN? Có thể cần per-city baseline (Phase 4.2.5 config).

---

## 11. Deploy checklist (ops)

```bash
# 1. Pull code
cd /home/oohx/apps/oohx-matrix && git pull

# 2. Apply migration 012
cd python-data-engine
.venv/bin/python -m app.cli init-db --sql-dir sql

# 3. Regression: all tests still pass
.venv/bin/pytest tests/unit -v | tail -5
# Expect: 200+ passed

# 4. Ingest population data (khi có CSV ready)
.venv/bin/python -m app.cli ingest-population-grid \
    --file /path/to/kontur_vn_2023.csv \
    --source kontur_2023 --year 2023

# 5. Verify
.venv/bin/python -m app.cli population-grid-summary

# 6. Trigger refresh
.venv/bin/python -m app.cli enqueue-recompute-stale
# Đợi cron drain

# 7. Spot check impact SQL §4.6
```

---

## 12. Changelog

| Ngày | Version | Change |
|---|---|---|
| 2026-04-22 | 4.2.1 | Phase 4.2.1 shipped: migration 012, population_grid repo + CLI, enrichment + estimation + campaign wiring, 26+ tests. Laravel zero change. Ops action: ingest Kontur CSV + recompute-stale. |

---

*Handoff owner: Data Engine tech lead. Ship Phase 4.2.2 (Google Places footfall) sau khi Phase 4.2.1 verify production stable ≥ 1 tuần.*
