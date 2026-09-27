# Phase 4.1 — Campaign Planner (Branch A)

> **Role of this document**: handoff Phase 4.1 (Campaign Planner backend) cho Laravel team. Đây là **product feature** lớn — advertiser có thể chọn N biển × duration → xem forecast impressions, reach, frequency, CPM.
>
> **Status**: backend shipped + syntax-validated. Cần Laravel build UI.
>
> **Scope Phase 4.1**:
> - Migration 011: `output.campaign_estimates` table + `job_type='campaign_estimate'`
> - `CampaignPlannerService` — aggregate + reach via PostGIS `ST_GeoHash`
> - `CampaignEstimateRepository` — CRUD
> - Job dispatch async (giống pattern preview)
> - 4 CLI commands
> - 25+ unit tests + 4 integration tests
>
> **Phase 4.2 Data Quality** design chi tiết §8 — sẽ implement session kế tiếp.
>
> **Reference**:
> - [PHASE-3A-HANDOFF.md](PHASE-3A-HANDOFF.md) — preview job pattern (Laravel đã tích hợp)
> - [PHASE-4.0-HANDOFF.md](PHASE-4.0-HANDOFF.md) — CI + test infra
> - [DATA-ENGINE-ACTION-PLAN.md](../DATA-ENGINE-ACTION-PLAN.md)

---

## 1. Business context

**Vấn đề Phase 4.1 giải quyết**:
- Advertiser mua quảng cáo OOH không mua 1 biển — mua **campaign** = N biển × D days + optional budget
- Sales team cần tool để quote "100 biển Hanoi × 30 ngày → reach ~500k người, frequency 4×, CPM 200k VND"
- Hiện tại estimate chỉ per-screen → phải Laravel tự sum. Sum = impressions đúng, nhưng **reach** cần spatial dedup (2 biển gần nhau cùng thấy bởi cùng người)

**Output Phase 4.1**:
- Laravel gọi 1 API (qua job queue) → nhận aggregate: `total_impressions`, `unique_reach`, `frequency`, `CPM`
- Persist vào `output.campaign_estimates` → list/history/compare được

---

## 2. What's shipped

### 2.1. SQL migration

| File | Change |
|---|---|
| [`sql/011_campaigns.sql`](../../python-data-engine/sql/011_campaigns.sql) | **NEW** `output.campaign_estimates` + open `job_type='campaign_estimate'` + GRANTs cho `oohx_control`/`oohx_readonly` |

### 2.2. Python code

| File | Change |
|---|---|
| [`app/config.py`](../../python-data-engine/app/config.py) | +2 campaign reach constants (`campaign_reach_base_per_cell`, `campaign_reach_saturation_cap`) trong `TrafficConfig` — snapshot-safe |
| [`app/services/campaign_planner.py`](../../python-data-engine/app/services/campaign_planner.py) | **NEW** `CampaignPlannerService.compute() + estimate_and_persist()` |
| [`app/repositories/campaigns.py`](../../python-data-engine/app/repositories/campaigns.py) | **NEW** `CampaignEstimateRepository.insert/get/list_recent/delete` |
| [`app/repositories/jobs.py`](../../python-data-engine/app/repositories/jobs.py) | +`enqueue_campaign(screen_ids, duration_days, ...)` |
| [`app/jobs/recompute.py`](../../python-data-engine/app/jobs/recompute.py) | Dispatch `job_type='campaign_estimate'` → `_process_campaign_job` |
| [`app/cli.py`](../../python-data-engine/app/cli.py) | 4 new commands: `estimate-campaign`, `enqueue-campaign`, `list-campaigns`, `show-campaign` |

### 2.3. Tests

- [`tests/unit/test_campaign_planner.py`](../../python-data-engine/tests/unit/test_campaign_planner.py) — 25 tests: validation, aggregate logic, reach model, CPM, persist
- [`tests/integration/test_campaign_planner.py`](../../python-data-engine/tests/integration/test_campaign_planner.py) — 4 tests: DB roundtrip, job dispatch, missing screens, list/delete

---

## 3. Architecture

```
[Advertiser picks N screens in Laravel UI]
    ↓
[Laravel admin click "Forecast"]
    ↓
INSERT INTO core.recompute_jobs (job_type='campaign_estimate', payload={...})
    ↓
[Cron /1min drain]  ← existing worker
    ↓
RecomputeRunner._process_campaign_job
    ↓
CampaignPlannerService.estimate_and_persist(screen_ids, duration_days, ...)
    ├── SQL aggregate: ST_GeoHash(precision 7) + SUM estimated_daily_impressions
    ├── reach = unique_cells × 500 × log_saturation(duration_days)
    ├── frequency = total_impr / reach
    └── INSERT INTO output.campaign_estimates
    ↓
payload.result = {id, total_impressions_for_duration, estimated_unique_reach, ...}
    ↓
Laravel poll → render histogram + campaign_id để bookmark
```

---

## 4. API cho Laravel — 2 paths

### 4.1. Path A — Direct CLI (ops / admin one-off)

```bash
.venv/bin/python -m app.cli estimate-campaign \
    --screens 1,2,3,42,58 \
    --duration 30 \
    --name "Tết 2026 Hanoi" \
    --budget 50000000
```

Blocking ~1-2s (tùy số screens). Output JSON full row. **Không** phù hợp Laravel UI vì blocking HTTP request.

### 4.2. Path B — Async job queue (Laravel UI, **khuyến nghị**)

```sql
-- Laravel INSERT (dùng role oohx_control)
INSERT INTO core.recompute_jobs (job_type, payload, priority)
VALUES (
    'campaign_estimate',
    jsonb_build_object(
        'screen_ids',    ARRAY[1, 2, 3, 42, 58]::bigint[],
        'duration_days', 30,
        'campaign_name', 'Tết 2026 Hanoi',
        'total_budget',  50000000,
        'notes',         'Q1 focus',
        '_actor',        jsonb_build_object(
            'enqueued', jsonb_build_object(
                'by', :user_email,
                'at', to_jsonb(NOW()::text)
            )
        )
    ),
    110  -- priority, ngang giữa preview (120) và bulk (150)
)
RETURNING id;
```

**Poll** (giống preview):

```sql
SELECT
    id, status, retry_count, error_message,
    requested_at, started_at, finished_at,
    payload->'result' AS result
FROM core.recompute_jobs
WHERE id = :job_id;
```

Khi `status = 'done'` → `payload.result` chứa full row. Bao gồm `id` = campaign_id để navigate sang campaign detail.

### 4.3. Laravel Eloquent pattern

```php
// App\Services\DataEngine\CampaignPlannerJobService.php
public function enqueue(array $screenIds, int $duration, ?string $name = null,
                       ?float $budget = null, ?string $actor = null): int
{
    return DB::connection('data_engine')
        ->table('core.recompute_jobs')
        ->insertGetId([
            'job_type' => 'campaign_estimate',
            'payload'  => DB::raw(sprintf(
                "jsonb_build_object(
                    'screen_ids', ARRAY[%s]::bigint[],
                    'duration_days', %d,
                    'campaign_name', %s,
                    'total_budget', %s,
                    '_actor', jsonb_build_object('enqueued', jsonb_build_object('by', %s))
                )",
                implode(',', array_map('intval', $screenIds)),
                $duration,
                $name ? "'".addslashes($name)."'" : 'null',
                $budget !== null ? (float)$budget : 'null',
                "'".addslashes($actor ?? 'unknown')."'",
            )),
            'priority' => 110,
        ]);
}
```

Poll pattern: 2s trong 30s đầu, sau 5s, timeout 60s → show "Job running, refresh later".

---

## 5. Result JSON schema

Khi `status='done'`, `payload.result`:

```json
{
  "id": 42,
  "screen_ids": [1, 2, 3, 42, 58],
  "duration_days": 30,

  "screens_with_estimate":    5,
  "screens_missing_estimate": 0,

  "total_daily_impressions":        125000.0,
  "total_impressions_for_duration": 3750000.0,

  "unique_geohash_cells":     4,
  "estimated_unique_reach":   3960.0,
  "estimated_frequency":      946.9,

  "total_budget":             50000000.0,
  "estimated_cpm":            13333.33,

  "avg_confidence":           0.62,
  "formula_version_id":       42,

  "campaign_name":            "Tết 2026 Hanoi",
  "computed_at":              "2026-04-21T10:00:00+00:00"
}
```

**Stable contract**: tất cả field root. Semver — thêm field OK, rename/remove = breaking → ping trước.

---

## 6. UI suggestions

### 6.1. Campaign builder flow

```
[Step 1 — chọn screens]
  ┌─ Map với 200 biển marker ─┐
  │ Click để thêm vào giỏ      │
  │ Filter: city, type,...     │
  └────────────────────────────┘
  Giỏ: 5 biển | [Next →]

[Step 2 — duration + budget]
  Duration:  [30 days ▼]
  Budget:    [_________] VND (optional)

[Step 3 — review]
  Click "Forecast" → enqueue job → loading spinner
  
[Step 4 — result]
  ┌─────────────────────────────────────────┐
  │ 📊 Campaign Forecast                    │
  │                                          │
  │ Impressions (total):      3.75M         │
  │ Unique Reach:             3,960 ⚠       │
  │ Frequency:                946×          │
  │ CPM:                      13,333 VND    │
  │                                          │
  │ 5 screens (5/5 có data ✓)               │
  │ 4 distinct areas                        │
  │                                          │
  │ [Save campaign] [Edit] [New]            │
  └─────────────────────────────────────────┘
```

### 6.2. Warning indicators

- **⚠ Reach thấp bất thường**: `frequency > 100` → advertiser có thể over-saturated (same people seeing ad nhiều lần). Suggest thêm screens ở area khác.
- **⚠ Missing screens**: `screens_missing_estimate > 0` → hiện list. Laravel warn advertiser screen đó data chưa có, kết quả không đầy đủ.
- **🔴 avg_confidence < 0.5**: quality data yếu → warning "Estimate có mức confidence thấp"

### 6.3. Campaign history page

```
SELECT id, campaign_name, screen_count, duration_days,
       total_impressions_for_duration, estimated_unique_reach, computed_at
FROM output.campaign_estimates
ORDER BY computed_at DESC
LIMIT 50;
```

Laravel render bảng — click row → detail page với full breakdown.

---

## 7. Reach model limitations (Laravel team cần biết)

### 7.1. Current formula

```
unique_reach = unique_geohash_cells × 500 × saturation(duration_days)
where saturation = min(2.0, 1 + log(duration_days) × 0.3)
frequency = total_impressions_for_duration / unique_reach
```

### 7.2. Assumptions

| Assumption | Reality check |
|---|---|
| 500 unique viewers/day/150m² cell | Guess dựa trên urban VN baseline. Chưa calibrate với real footfall data. |
| Log saturation curve capped 2× | Industry heuristic — 30 ngày không mang 30× người mới. Chính xác hơn cần time-series data. |
| Screens cùng geohash cell = cùng viewer | Approximation — thực tế 2 biển cách 100m có thể cùng eyeballs nhưng khác angle. |
| Không phân biệt indoor vs outdoor cho reach | Indoor mall screens thường có unique audience so với outdoor highway. Phase 4.2+ sẽ refine. |

### 7.3. Sẽ improve khi có

1. **GHSL population data** (Phase 4.2.1) → thay constant 500 bằng actual density per cell
2. **Real traffic samples** ≥ 30 → auto-calibration base_per_cell theo city
3. **Phase 5** — time-series reach curves từ rolling 90-day estimate history

### 7.4. Cho tới lúc đó

Laravel UI nên hiển thị **disclaimer** dưới reach/frequency numbers:
> *Reach dựa trên mô hình spatial dedup simple. Numbers là directional estimate, không phải measured reality. Sẽ refine dần khi có traffic calibration data.*

---

## 8. Phase 4.2 — Data Quality (design preview)

**Mục tiêu**: fix limitations §7 của Phase 4.1 + improve per-screen estimate accuracy.

### 8.1. Scope (3-4 tuần total)

Thứ tự ROI đã review Phase 3:

| Step | Module | Effort |
|---|---|---|
| 4.2.1 | GHSL/Kontur population grid ingestion + enrichment wiring | 1 tuần |
| 4.2.2 | Venue footfall automation (Google Places API) | 1 tuần |
| 4.2.3 | Sample ingestion UX (Laravel CSV upload + validation) | 3 ngày |
| 4.2.4 | Auto-calibration aggregate + CLI + cron | 3 ngày |
| 4.2.5 | Formula v3 publish (bundle all improvements) | 3 ngày |

### 8.2. Phase 4.2.1 detailed spec (ship first)

**Schema**: `sql/012_population_grid.sql`
```sql
CREATE TABLE IF NOT EXISTS source.population_grid (
    id          BIGSERIAL PRIMARY KEY,
    source_id   TEXT NOT NULL,       -- hex cell id hoặc H3 index
    resolution  INTEGER,              -- Kontur H3 res (8, 9...)
    population  DOUBLE PRECISION NOT NULL,
    year        INTEGER,
    geom        geography(Polygon, 4326) NOT NULL,
    centroid    geography(Point, 4326) GENERATED ALWAYS AS (geom::geography) STORED,
    source_name TEXT,                 -- 'kontur', 'ghsl', 'worldpop'
    ingested_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (source_name, source_id)
);
CREATE INDEX idx_pop_grid_geom ON source.population_grid USING GIST (geom);
```

**Ingest CLI**: `ingest-population-grid --file vietnam_hex9.csv --source kontur --year 2023`
- CSV schema: `source_id, resolution, population, year, geom_wkt`
- Bulk INSERT via psycopg `COPY FROM STDIN`
- User tự download raw data từ https://data.humdata.org/dataset/kontur-population-vietnam

**Enrichment wiring** ([app/services/enrichment.py](../../python-data-engine/app/services/enrichment.py) update):
```python
# Thay 300m hardcode density=0 bằng real query:
SELECT SUM(p.population) / (3.14159 * 0.3 * 0.3)   -- /km²
FROM source.population_grid p, pt
WHERE ST_DWithin(p.geom, pt.g, 300);
```

**Traffic estimation wiring** ([app/services/traffic_estimation.py](../../python-data-engine/app/services/traffic_estimation.py) update):
```python
# Phase 4.2.1 — replace hardcoded population_factor=1.0
density = ctx.get("population_density_300m") or 20000.0  # fallback urban baseline
population_factor = _clip(density / 20000.0, 0.3, 2.0)
passby = base * road_mult * ... * population_factor * ...
```

**Campaign reach upgrade** ([app/services/campaign_planner.py](../../python-data-engine/app/services/campaign_planner.py) update):
```python
# Thay constant 500 bằng per-cell actual population
reach = SUM(cell.population * saturation_factor)
```

### 8.3. Phase 4.2.2 — Venue footfall

**Google Places API Details** → `user_ratings_total` làm proxy cho footfall:
- Free tier 100 req/day đủ cho 200 screens × 4 weeks = 200/month ≪ cap
- Schema: add `footfall_proxy_source` column vào `source.venues`
- CLI: `refresh-venue-footfall` cron weekly
- Mapping: rating_total × multiplier → daily_footfall

### 8.4. Phase 4.2.3 — Sample ingestion UX

Laravel build admin UI:
```
[CSV upload form]
  - Validate: required columns (lat, lon, observed_passby, observed_at)
  - Preview first 5 rows
  - Submit → INSERT via oohx_control
  - Response: rows_ingested + sample IDs
```

DE side: `app/repositories/traffic_samples.py` đã có `bulk_ingest_csv`. Chỉ cần Laravel expose UI.

### 8.5. Phase 4.2.4 — Auto-calibration

`calibrate-from-samples --city [Hanoi]` (aggregate-level):
- Query `source.traffic_samples` trong 90 ngày
- Compute median ratio `observed/predicted` per `(road_class, city)`
- UPSERT vào `config.calibration_factors` (new table)
- Enrichment đọc calibration_factor per screen dựa trên nearest road_class

---

## 9. Deploy checklist (ops)

### 9.1. Apply migration 011

```bash
cd /home/oohx/apps/oohx-matrix/python-data-engine
source .venv/bin/activate
python -m app.cli init-db --sql-dir sql
```

Verify:
```sql
\d output.campaign_estimates
SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname='recompute_jobs_job_type_check';
-- Phải có 'campaign_estimate' trong list
```

### 9.2. Run tests

```bash
.venv/bin/pytest tests/unit/test_campaign_planner.py -v
.venv/bin/pytest tests/integration/test_campaign_planner.py -v -m integration
```

Expect: 25 unit + 4 integration pass.

### 9.3. Smoke test CLI

```bash
# Pick vài screen_id từ prod
.venv/bin/python -m app.cli estimate-campaign \
    --screens 1,2,3,5,8 --duration 30 --name "smoke-4.1" --no-persist

# Full persist + queue path
.venv/bin/python -m app.cli enqueue-campaign \
    --screens 1,2,3,5,8 --duration 30 --name "smoke-queue"
# note job_id từ output

.venv/bin/python -m app.cli recompute-pending-jobs --max 5
.venv/bin/python -m app.cli show-job --id <job_id>
# Expect status=done + payload.result.id > 0

.venv/bin/python -m app.cli list-campaigns --limit 5
```

---

## 10. Laravel test plan

### 10.1. Happy path

- [ ] Admin chọn 3 screens → enqueue campaign → poll 10s → status=done
- [ ] Render result: total_impressions > 0, reach > 0, frequency > 0
- [ ] CPM = budget / (impressions/1000) đúng
- [ ] Save campaign_id → navigate sang campaign detail page

### 10.2. Edge cases

- [ ] Campaign với 1 screen → compute success, reach khoảng 500 × saturation
- [ ] Campaign 50+ screens → aggregate trong ≤ 5s
- [ ] Screen chưa enrich → `screens_missing_estimate > 0`, warning shown
- [ ] Duration 1 day vs 30 days vs 180 days → reach saturation đúng curve
- [ ] Budget = 0 → CPM = null

### 10.3. Reproducibility

- [ ] Enqueue 2 lần cùng screens + duration → 2 campaign_id khác (expected) nhưng numbers giống
- [ ] `list-campaigns` hiển thị cả 2

### 10.4. Validation

- [ ] screen_ids empty → job status=failed với error "requires ≥ 1 screen"
- [ ] duration=0 hoặc 500 → status=failed
- [ ] Non-existent screen_id → graceful skip (count vào missing), không crash

---

## 11. Open questions

1. **Reach constant 500/cell** — cần calibrate. Đề xuất chờ Phase 4.2.1 + 4.2.4 trước khi promise accuracy cho sales team.
2. **Campaign cleanup cadence** — `output.campaign_estimates` có cleanup như preview jobs không? Đề xuất giữ 180 ngày (vs preview 7 ngày) vì campaign history có business value.
3. **Concurrent bidding** — nếu 2 advertiser query cùng screens đồng thời, có cần lock? Không — pure read, aggregate stable.
4. **CPM formula** — hiện là `budget / (impressions/1000)`. Có nên include `platform_fee` separately? Defer tới Phase 4.2 nếu finance cần.
5. **Multi-currency** — MVP assume VND. Nếu expand Thailand/Indonesia → cần currency column trong `campaign_estimates`. Phase 5.

---

## 12. Changelog

| Ngày | Version | Change |
|---|---|---|
| 2026-04-21 | 4.1.1 | Phase 4.1 shipped: campaign_estimates table, CampaignPlannerService, 4 CLI, 29 tests. Laravel UI task open. Phase 4.2 design §8. |

---

*Handoff owner: Data Engine tech lead. Ship Phase 4.2.1 (population grid) sau khi Laravel feedback Phase 4.1 UI.*
