# OOHX Data Engine — Upgrade Proposal

> **Role of this document**: architectural proposal for Phase 2 of OOHX Data Engine.
> Tài liệu chị em:
> - [`OOHX-DATA-ENGINE-ARCHITECTURE.md`](OOHX-DATA-ENGINE-ARCHITECTURE.md) — kiến trúc hiện tại (MVP v1).
> - [`oohx-matrix-integration.md`](oohx-matrix-integration.md) — tích hợp Laravel read-only hiện tại.
> - [`docs/laravel-integration/*.md`](docs/laravel-integration/) — **5 integration guide** chi tiết cho team Laravel (đi kèm proposal này).
>
> **Author stance**: senior data + GIS architect. Đề xuất thực dụng, bám sát ràng buộc VPS 4GB RAM. Giữ nguyên nguyên tắc đã chốt: batch, rule-based, deterministic, no realtime.

---

## 1. Executive Summary

Phase 1 (đã xong) trả lời: "cho lat/lon + metadata tối thiểu, ước lượng
impressions được không?" Có, deterministically, cheaply.

Phase 2 (đề xuất) giải quyết 3 câu hỏi tiếp theo:

| # | Câu hỏi | Trả lời bằng |
|---|---|---|
| 1 | Làm sao ops/sales chỉnh được formula mà không phải deploy Python? | Di chuyển toàn bộ coefficient ra **`config.*` schema** trong Postgres; Laravel có admin UI chỉnh trực tiếp, versioned. |
| 2 | Làm sao Laravel trigger/monitor được Data Engine (recompute, collectors)? | Mở **2 write surface** rất hẹp qua DB (`oohx_control` role): `core.recompute_jobs`, `collectors.collector_runs`, `config.*`. Không thêm HTTP API ở phase này. |
| 3 | Làm sao enrichment có dữ liệu thực thay vì seed tay? | Build **collectors framework**: POI (Overpass), roads (osm2pgsql), weather (Open-Meteo), population (WorldPop). Mỗi collector là 1 CLI + 1 row state trong `collectors.collector_runs`. |

**Nguyên tắc không đổi:**
- Laravel vẫn **không** đọc MySQL của Data Engine (không có MySQL ở Data Engine).
- Data Engine vẫn **không** trực tiếp query Laravel DB.
- Mọi thứ liên kết qua PostgreSQL `oohx_data` qua SSH tunnel.
- Batch + cron. Không realtime. Không Kafka, Celery, Redis.

---

## 2. Ba mục tiêu + phương án kiến trúc

### 2.1. Mục tiêu 1 — Laravel quản lý Data Engine

**Phạm vi "quản lý":**
1. Trigger recompute (per screen / per city / bulk) từ Laravel admin UI.
2. Xem queue health, retry job failed, cancel job pending.
3. Inspect 1 screen: xem road/POI/venue nào đã enrich, factor từng bước, estimate breakdown.
4. Dashboard freshness: bao nhiêu screen chưa có estimate, bao nhiêu cũ > 24h, jobs status.

**Phương án kiến trúc:**

> **Database là control plane. Không thêm HTTP API ở phase 2.**

Tại sao không HTTP API ở phase này:
- Thêm 1 service Python (uvicorn + FastAPI) = thêm tầng, thêm điểm fail, thêm auth/rate-limit.
- Pattern hiện tại (INSERT vào `recompute_jobs`, Python cron drain) đã đủ: latency 10 phút cho các action không critical-realtime.
- Nếu cần latency ngắn hơn, thêm `NOTIFY` PostgreSQL + 1 listener Python simple — vẫn rẻ hơn FastAPI.

Write surface mới (được cấp cho Laravel qua role `oohx_control`):

| Target | Quyền | Dùng để |
|---|---|---|
| `core.recompute_jobs` | `INSERT`, `SELECT`, `UPDATE` (status='cancelled' only) | Enqueue + xem + cancel jobs |
| `collectors.collector_runs` | `INSERT`, `SELECT` | Trigger collector run (Python cron pick up) |
| `config.*` (tất cả bảng) | `SELECT`, `INSERT`, `UPDATE` | Edit formula coefficients + activate version |
| `output.*`, `core.screens`, `metrics.*` | `SELECT` | Inspect + monitoring |

Chi tiết: [docs/laravel-integration/02-jobs-orchestration.md](docs/laravel-integration/02-jobs-orchestration.md),
[04-screen-context-inspector.md](docs/laravel-integration/04-screen-context-inspector.md),
[05-monitoring-dashboard.md](docs/laravel-integration/05-monitoring-dashboard.md).

### 2.2. Mục tiêu 2 — Dynamic formulas

**Phạm vi "dynamic":**

3 cấp độ "dynamic" lý thuyết:

| Level | Ví dụ | Độ rủi ro |
|---|---|---|
| **L1**: coefficient dynamic, formula shape cố định | thay `highway = 2.5` → `2.7` mà không deploy Python | thấp — validate range là đủ |
| **L2**: formula expression lưu ở DB, Python eval | `"base * road * lane * poi"` → đổi thành `"base * road * lane * poi * weather"` | cao — eval injection, khó debug |
| **L3**: pluggable strategy (multiple model versions coexist) | `rule_based_v1`, `rule_based_v2_weather_aware` chạy song song | trung bình — cần test harness |

**Đề xuất phase 2: chỉ làm L1 + L3 shell.**

L2 **không** làm (giữ an toàn; eval string là anti-pattern cho batch job).

Specifically:

- Coefficient di chuyển từ `app/config.py` → bảng `config.*`:
  - `config.base_city_traffic` (city → baseline_passby)
  - `config.road_class_multipliers` (road_class → multiplier)
  - `config.zone_factors` (zone_type → factor)
  - `config.delivery_defaults` (visibility_outdoor / indoor, direction_*, SOV, dwell, caps)
- Formula shape vẫn ở Python (`services/traffic_estimation.py`), chọn theo `estimation_method` field (chuẩn bị sẵn cho L3).
- Mỗi lần Laravel publish version mới = snapshot toàn bộ `config.*` vào 1 row `config.formula_versions` (JSONB), đánh dấu `is_active=true`, old version set `false`.
- Python đọc active version ở đầu mỗi job.
- Output `estimated.*` ghi `model_version = formula_versions.tag`.

**Rollback** = activate version cũ + recompute.

Chi tiết: [docs/laravel-integration/01-formula-config-management.md](docs/laravel-integration/01-formula-config-management.md).

### 2.3. Mục tiêu 3 — Contextual data collectors

**Phạm vi "contextual data":**

| Source | Provider | Cost | Cadence | Priority |
|---|---|---|---|---|
| POI | **Overpass API (OSM)** | free | weekly per city | P0 |
| Roads + lanes | **osm2pgsql + Overpass** | free | monthly | P0 |
| Population density | **WorldPop 100m raster** | free | yearly (dataset) | P1 |
| Weather current/forecast | **Open-Meteo** | free (no API key) | daily per city | P1 |
| Seasonality | derived from weather history | — | quarterly | P2 |
| Traffic counts (calibration) | manual CSV / partner | — | ad-hoc | P2 |
| Real-time traffic | TomTom/HERE | $$$ | realtime | P3 (skip phase 2) |

**Kiến trúc collector framework:**

```
┌─────────────────────────┐         ┌─────────────────────────┐
│ Laravel admin           │ enqueue │ PostgreSQL              │
│ "Collect POI Hanoi now" │ ──────► │ collectors.collector_runs│
└─────────────────────────┘  INSERT │   (status=pending)      │
                                    └───────────┬─────────────┘
                                                │ cron 15m
                                    ┌───────────▼─────────────┐
                                    │ python -m app.cli       │
                                    │ run-pending-collectors  │
                                    └───────────┬─────────────┘
                                                │
                          ┌─────────────────────┼─────────────────────┐
                          ▼                     ▼                     ▼
               OverpassPoiCollector  OpenMeteoWeatherCollector  WorldPopPopulationCollector
                          │                     │                     │
                          ▼                     ▼                     ▼
                    source.pois        source.weather_snapshots  source.population_grid
```

Mỗi collector:
- Subclass của `Collector` với 3 hook: `plan()`, `fetch()`, `persist()`.
- **Idempotent** — re-run không duplicate; UPSERT trên (osm_id, city) hoặc (lat, lon, observed_at).
- **Bounded** — 1 city/bbox 1 run; tránh load mọi thứ lên RAM.
- **Cacheable** — response raw lưu `collectors.collector_cache` (TTL) để debug + retry không tốn API call.
- **Stateful** — stats (rows ingested, bytes fetched, duration) ghi `collector_runs.stats`.

Chi tiết: [docs/laravel-integration/03-collectors-admin.md](docs/laravel-integration/03-collectors-admin.md).

---

## 3. Schema proposal (SQL migrations mới)

### 3.1. `sql/006_config_tables.sql`

```sql
CREATE SCHEMA IF NOT EXISTS config;

CREATE TABLE config.base_city_traffic (
    city              TEXT PRIMARY KEY,
    baseline_passby   NUMERIC NOT NULL CHECK (baseline_passby >= 0),
    note              TEXT,
    updated_by        TEXT,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE config.road_class_multipliers (
    road_class        TEXT PRIMARY KEY,
    multiplier        NUMERIC NOT NULL CHECK (multiplier > 0 AND multiplier <= 5),
    note              TEXT,
    updated_by        TEXT,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE config.zone_factors (
    zone_type         TEXT PRIMARY KEY,
    factor            NUMERIC NOT NULL CHECK (factor > 0 AND factor <= 2),
    note              TEXT,
    updated_by        TEXT,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE config.delivery_defaults (
    key               TEXT PRIMARY KEY,    -- visibility_outdoor, share_of_voice, ...
    value             NUMERIC NOT NULL,
    description       TEXT,
    updated_by        TEXT,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Formula version snapshots
CREATE TABLE config.formula_versions (
    id                BIGSERIAL PRIMARY KEY,
    tag               TEXT NOT NULL UNIQUE,              -- 'v-2026-05-15', 'mvp-1.0'
    description       TEXT,
    snapshot          JSONB NOT NULL,                    -- frozen copy of config.*
    is_active         BOOLEAN NOT NULL DEFAULT FALSE,
    activated_at      TIMESTAMPTZ,
    created_by        TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- Only one active at a time (partial unique index)
CREATE UNIQUE INDEX idx_formula_versions_one_active
    ON config.formula_versions (is_active) WHERE is_active = TRUE;

-- Audit log
CREATE TABLE config.audit_log (
    id                BIGSERIAL PRIMARY KEY,
    actor             TEXT NOT NULL,
    action            TEXT NOT NULL,
    target            TEXT,
    old_value         JSONB,
    new_value         JSONB,
    note              TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### 3.2. `sql/007_collectors_tables.sql`

```sql
CREATE SCHEMA IF NOT EXISTS collectors;

CREATE TABLE collectors.collector_runs (
    id                BIGSERIAL PRIMARY KEY,
    collector_name    TEXT NOT NULL,     -- 'overpass_poi', 'open_meteo_weather', ...
    city              TEXT,
    bbox              geography(Polygon, 4326),
    params            JSONB NOT NULL DEFAULT '{}'::jsonb,
    status            TEXT NOT NULL DEFAULT 'pending'
                      CHECK (status IN ('pending','running','done','failed','cancelled')),
    priority          INTEGER NOT NULL DEFAULT 100,
    retry_count       INTEGER NOT NULL DEFAULT 0,
    rows_ingested     INTEGER NOT NULL DEFAULT 0,
    bytes_fetched     BIGINT NOT NULL DEFAULT 0,
    stats             JSONB NOT NULL DEFAULT '{}'::jsonb,
    error_message     TEXT,
    requested_by      TEXT,
    requested_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at        TIMESTAMPTZ,
    finished_at       TIMESTAMPTZ
);

CREATE INDEX idx_collector_runs_status ON collectors.collector_runs (status, priority, requested_at);
CREATE INDEX idx_collector_runs_name   ON collectors.collector_runs (collector_name, finished_at DESC);

-- Cache cho raw API responses (tránh lặp call)
CREATE TABLE collectors.collector_cache (
    id                BIGSERIAL PRIMARY KEY,
    collector_name    TEXT NOT NULL,
    cache_key         TEXT NOT NULL,
    payload           BYTEA,             -- compressed JSON/XML raw
    content_type      TEXT,
    fetched_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at        TIMESTAMPTZ,
    size_bytes        INTEGER,
    UNIQUE (collector_name, cache_key)
);

CREATE INDEX idx_collector_cache_expire ON collectors.collector_cache (expires_at);
```

### 3.3. Bổ sung `source.*`

```sql
-- Weather snapshot (gắn vào grid city 10km mỗi ngày, hoặc per-screen nếu cần)
CREATE TABLE source.weather_snapshots (
    id                BIGSERIAL PRIMARY KEY,
    city              TEXT,
    geom              geography(Point, 4326),
    observed_at       TIMESTAMPTZ NOT NULL,
    temperature_c     NUMERIC,
    precipitation_mm  NUMERIC,
    wind_kmh          NUMERIC,
    weather_code      INTEGER,           -- WMO weather code
    raw               JSONB,
    collector_run_id  BIGINT REFERENCES collectors.collector_runs(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_weather_geom    ON source.weather_snapshots USING GIST (geom);
CREATE INDEX idx_weather_city_ts ON source.weather_snapshots (city, observed_at DESC);

-- Population density (h3 hex tiles or 100m grid)
CREATE TABLE source.population_grid (
    id                BIGSERIAL PRIMARY KEY,
    cell_key          TEXT NOT NULL,                         -- h3 hex id or WorldPop tile id
    geom              geography(Polygon, 4326) NOT NULL,
    centroid          geography(Point, 4326) NOT NULL,
    population        NUMERIC NOT NULL,                      -- estimated inhabitants
    year              INTEGER NOT NULL,
    source_name       TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (cell_key, year)
);

CREATE INDEX idx_pop_geom     ON source.population_grid USING GIST (geom);
CREATE INDEX idx_pop_centroid ON source.population_grid USING GIST (centroid);

-- Traffic samples — for calibration (REAL count từ partner/manual)
CREATE TABLE source.traffic_samples (
    id                BIGSERIAL PRIMARY KEY,
    city              TEXT,
    road_id           BIGINT REFERENCES source.roads(id),
    geom              geography(Point, 4326),
    observed_passby   NUMERIC NOT NULL,      -- vehicles/day or pedestrians/day
    observation_type  TEXT CHECK (observation_type IN ('vehicle','pedestrian','both')),
    observed_at       TIMESTAMPTZ NOT NULL,
    duration_hours    NUMERIC,
    source_name       TEXT,
    notes             TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_traffic_samples_city ON source.traffic_samples (city, observed_at DESC);
CREATE INDEX idx_traffic_samples_geom ON source.traffic_samples USING GIST (geom);
```

### 3.4. Bổ sung `metrics.*` + `output.*`

Thêm cột vào `metrics.screen_context_metrics`:

```sql
ALTER TABLE metrics.screen_context_metrics
    ADD COLUMN weather_factor NUMERIC,
    ADD COLUMN seasonality_factor NUMERIC,
    ADD COLUMN calibration_factor NUMERIC;   -- từ traffic_samples gần nhất
```

Thêm cột vào `output.screen_traffic_estimates`:

```sql
ALTER TABLE output.screen_traffic_estimates
    ADD COLUMN formula_version_id BIGINT REFERENCES config.formula_versions(id);
```

Backward compatible: cột cũ vẫn có, Laravel không cần update ngay.

### 3.5. Role `oohx_control`

```sql
CREATE ROLE oohx_control LOGIN PASSWORD '<pwd>';

GRANT USAGE ON SCHEMA core, config, collectors, output, metrics, source TO oohx_control;

-- Read everywhere
GRANT SELECT ON ALL TABLES IN SCHEMA core, output, metrics, source, collectors, config TO oohx_control;

-- Write on control surface only
GRANT INSERT, UPDATE ON core.recompute_jobs TO oohx_control;
GRANT INSERT, UPDATE ON collectors.collector_runs TO oohx_control;
GRANT INSERT, UPDATE, DELETE ON
    config.base_city_traffic,
    config.road_class_multipliers,
    config.zone_factors,
    config.delivery_defaults,
    config.formula_versions,
    config.audit_log
TO oohx_control;

-- Sequences
GRANT USAGE ON ALL SEQUENCES IN SCHEMA core, collectors, config TO oohx_control;
ALTER DEFAULT PRIVILEGES IN SCHEMA core, collectors, config
    GRANT USAGE ON SEQUENCES TO oohx_control;
```

Hai tunnel connections (Laravel `.env`):

```env
# Read-only (hiện tại)
DB_OOHX_HOST=127.0.0.1
DB_OOHX_PORT=5433
DB_OOHX_USERNAME=oohx_readonly
DB_OOHX_PASSWORD=<pwd>

# Control (mới)
DB_OOHX_CONTROL_HOST=127.0.0.1
DB_OOHX_CONTROL_PORT=5433           # same tunnel, same port
DB_OOHX_CONTROL_USERNAME=oohx_control
DB_OOHX_CONTROL_PASSWORD=<pwd>
```

Không cần SSH tunnel thứ 2 — cùng tunnel, khác user.

---

## 4. Python side changes summary

### 4.1. Module mới

```
app/
├── config_loader.py             # NEW: load config từ DB, fallback về hardcode
├── collectors/
│   ├── __init__.py              # NEW
│   ├── base.py                  # NEW: Collector abstract class
│   ├── overpass_poi.py          # NEW
│   ├── osm_roads.py             # NEW (hoặc shell-out osm2pgsql)
│   ├── open_meteo_weather.py    # NEW
│   ├── worldpop_population.py   # NEW
│   └── runner.py                # NEW: drain collectors.collector_runs queue
├── repositories/
│   ├── config.py                # NEW: repository cho config.* tables
│   ├── collectors.py            # NEW
│   └── ... (existing)
└── cli.py                       # EXTEND: collect-*, publish-config-version, ...
```

### 4.2. `TrafficConfig` trở thành DB-backed

**Trước (phase 1):**
```python
@dataclass(frozen=True)
class TrafficConfig:
    road_class_multiplier: Dict[str, float] = field(default_factory=lambda: {
        "highway": 2.5, ...
    })
```

**Sau (phase 2):**
```python
def load_active_config() -> TrafficConfig:
    with get_conn() as conn:
        active = conn.execute(
            "SELECT id, tag, snapshot FROM config.formula_versions WHERE is_active LIMIT 1"
        ).fetchone()
        if active:
            return TrafficConfig.from_snapshot(active[2], version_id=active[0], tag=active[1])
        # Fallback: load từng bảng
        return TrafficConfig.from_tables(conn)
```

Services gọi `get_traffic_config()` vẫn không đổi — tiện migration.

Cache: `lru_cache` với TTL ngắn (5 phút) để không hit DB mỗi lần; cron job 10 phút sẽ tự refresh.

### 4.3. CLI commands mới

```
python -m app.cli publish-config-version --tag v-2026-05-15 --activate
python -m app.cli list-config-versions
python -m app.cli activate-config-version --tag v-2026-05-15
python -m app.cli collect-poi            --city Hanoi
python -m app.cli collect-weather        --city Hanoi
python -m app.cli collect-population     --city Hanoi
python -m app.cli collect-roads          --city Hanoi
python -m app.cli run-pending-collectors --max 5
python -m app.cli seed-config-from-code       # one-shot: backfill config.* từ hardcode hiện tại
```

### 4.4. Cron mới

```cron
# Phase 1 (giữ nguyên)
*/10 * * * *  python -m app.cli recompute-pending-jobs --max 200
30 2 * * *    python -m app.cli recompute-city --city Hanoi
45 2 * * *    python -m app.cli recompute-city --city HCMC

# Phase 2 (mới)
*/15 * * * *  python -m app.cli run-pending-collectors --max 5
0   */6 * * * python -m app.cli collect-weather --city Hanoi
0   */6 * * * python -m app.cli collect-weather --city HCMC
0   3 * * 0   python -m app.cli collect-poi    --city Hanoi       # weekly POI refresh
0   4 * * 0   python -m app.cli collect-poi    --city HCMC
```

---

## 5. Laravel side changes summary

### 5.1. Ranh giới rõ ràng

| Laravel làm | Laravel **không** làm |
|---|---|
| Admin UI edit config, publish version | Viết Python, deploy Data Engine |
| Enqueue recompute job | Chạy recompute trực tiếp |
| Trigger collector run | Fetch Overpass/WorldPop trực tiếp |
| Query output + metrics | Viết output |
| Audit UI thay đổi config | Truy cập `source.*` để edit raw data |

### 5.2. Deliverables Laravel side

| # | Feature | Guide |
|---|---|---|
| 01 | Formula & config management (CRUD + version publish) | [01-formula-config-management.md](docs/laravel-integration/01-formula-config-management.md) |
| 02 | Jobs orchestration (enqueue + monitor + retry) | [02-jobs-orchestration.md](docs/laravel-integration/02-jobs-orchestration.md) |
| 03 | Collectors admin (trigger + history + cost tracking) | [03-collectors-admin.md](docs/laravel-integration/03-collectors-admin.md) |
| 04 | Screen context inspector (explain the estimate) | [04-screen-context-inspector.md](docs/laravel-integration/04-screen-context-inspector.md) |
| 05 | Monitoring dashboard (freshness + health + alerts) | [05-monitoring-dashboard.md](docs/laravel-integration/05-monitoring-dashboard.md) |

Mỗi guide là self-contained: có mục tiêu, schema reference, Laravel models/services/routes, UI wireframe mức văn bản, test steps, assumptions phải confirm.

---

## 6. Migration plan (phased)

### Phase 2.A — Dynamic config (≈ 2 tuần)

| Bước | Ai | Làm gì |
|---|---|---|
| A1 | Data Engine ops | Chạy migration `sql/006_config_tables.sql` |
| A2 | Data Engine dev | Viết `repositories/config.py` + `config_loader.py`, services đọc config từ DB |
| A3 | Data Engine ops | `python -m app.cli seed-config-from-code` — seed DB từ hardcode hiện tại |
| A4 | Laravel team | Guide 01: CRUD + version publish UI |
| A5 | Data Engine ops | Tạo role `oohx_control`, cấp Laravel |
| A6 | QA | Đổi 1 coefficient qua Laravel UI → confirm recompute dùng giá trị mới |

### Phase 2.B — Jobs + screen inspector + monitoring UX (≈ 1 tuần)

| Bước | Ai | Làm gì |
|---|---|---|
| B1 | Laravel team | Guide 02: jobs table + enqueue + retry UI |
| B2 | Laravel team | Guide 04: screen inspector page |
| B3 | Laravel team | Guide 05: monitoring dashboard |

### Phase 2.C — Collectors framework (≈ 2 tuần)

| Bước | Ai | Làm gì |
|---|---|---|
| C1 | Data Engine ops | Chạy migration `sql/007_collectors_tables.sql` + `ALTER source.*` |
| C2 | Data Engine dev | Implement `Collector` base + 2 collector: `overpass_poi`, `open_meteo_weather` |
| C3 | Data Engine dev | `run-pending-collectors` CLI + cron |
| C4 | Laravel team | Guide 03: collectors admin UI |
| C5 | QA | Trigger collect-poi Hanoi từ Laravel → confirm `source.pois` có data |

### Phase 2.D — Remaining collectors + calibration (≈ 2 tuần)

| Bước | Ai | Làm gì |
|---|---|---|
| D1 | Data Engine dev | Implement `osm_roads` collector (có thể shell-out `osm2pgsql`) |
| D2 | Data Engine dev | Implement `worldpop_population` collector |
| D3 | Data Engine dev | Thêm `weather_factor`, `seasonality_factor`, `calibration_factor` vào formula |
| D4 | Laravel team | Hiện `weather_factor`, `seasonality_factor` ở inspector (guide 04 update) |
| D5 | Data Engine dev | `traffic_samples` ingest + calibration script |

**Tổng ≈ 7 tuần**, chia thành 4 phase độc lập, mỗi phase ship được riêng.

---

## 7. Risks & mitigations

| Rủi ro | Impact | Mitigation |
|---|---|---|
| Laravel UPDATE sai coefficient → estimate sai loạt | Medium | 1/ Validate range trong CHECK constraint. 2/ Audit log đầy đủ. 3/ Version publish yêu cầu tag + description — không edit inplace silent. |
| Formula version active bị rollback giữa job đang chạy | Low | Config load ở đầu mỗi `run_screen`, không reload giữa chừng. Job nào dùng version cũ vẫn chạy xong với version cũ. |
| Collector API rate-limit (Overpass) | Medium | `collector_cache` + exponential backoff. 1 city = 1 run; chia nhỏ bbox nếu lớn. |
| Collector tải nặng làm Postgres spike | Medium | Batch INSERT trong transaction ≤ 1000 rows. `work_mem` đã tune. |
| `config.formula_versions.snapshot` JSONB size blow up | Very low | Snapshot mỗi version ≈ 5KB. 1 năm 52 version = < 1MB. |
| Laravel viết trực tiếp config → drift với file `app/config.py` default | Low | Sau `seed-config-from-code`, hardcode chỉ còn là **fallback** — nếu DB rỗng thì dùng. Không còn là source of truth. |
| Audit log phình to | Low | Partition theo tháng nếu cần; MVP chưa cần. |

---

## 8. Open questions

Những câu hỏi kỹ thuật / sản phẩm cần chốt trước khi bắt đầu Phase 2.A:

1. **Ai được phép publish formula version?** Đề xuất: role Laravel `admin` + `data_ops`. Regular `seller`/`advertiser` không thấy admin UI.

2. **Có cần preview impact của version mới trước khi activate không?** Tức là "chạy thử trên 100 screen mẫu, so sánh daily_impressions v1 vs v2". Đề xuất làm ở Phase 2.A cuối — dùng cột `formula_version_id` ở `output.screen_traffic_estimates` để chạy song song.

3. **Weather data granularity?** Per-city (1 điểm) hay per-screen? Đề xuất: per-city 4 điểm/ngày (mỗi 6h). Rẻ + đủ resolution cho OOH.

4. **WorldPop year?** Dataset mới nhất 2020. Có dùng 2020 hay cần đợi 2024? Phase 2 dùng 2020 OK.

5. **Real-time traffic (TomTom/HERE)?** Ngân sách có cho phép? Đề xuất: SKIP ở phase 2, giữ sampling thủ công từ partner.

6. **SSH tunnel thứ hai cho `oohx_control` hay dùng chung?** Đề xuất: dùng chung. Giảm surface area, dễ vận hành.

---

## 9. Success criteria cho Phase 2

Kết thúc Phase 2, hệ thống phải đạt:

- [ ] Ops/admin thay `highway = 2.5 → 2.7` qua Laravel UI, trigger recompute, kết quả mới xuất hiện trong `output.*` trong ≤ 15 phút.
- [ ] Publish formula version mới + activate, xem audit log ai đổi gì.
- [ ] Trigger `collect-poi` Hanoi từ Laravel UI, xem progress, confirm `source.pois` có ≥ 5000 rows (mục tiêu Hanoi).
- [ ] Inspector 1 screen cho thấy: nearest road, 7 POI count theo category, venue (nếu indoor), factor resolved từng bước, công thức bung ra thành các phép nhân.
- [ ] Dashboard hiện: số screen active, số đã estimate, tuổi trung bình estimate, failed jobs 24h qua.
- [ ] Không tăng RAM peak của Data Engine VPS quá 800MB (giới hạn 4GB VPS).
- [ ] Không có FastAPI/Celery/Redis phát sinh. Cron + PostgreSQL là control plane.

---

## 10. Next steps

1. Review proposal này với team (1 tuần).
2. Chốt answers của §8 open questions.
3. Kick off Phase 2.A: migration + Python config_loader + Laravel guide 01.
4. Sau 2 tuần review kết quả Phase 2.A, quyết định Phase 2.B / 2.C thứ tự.

---

## Tài liệu đi kèm

- [docs/laravel-integration/01-formula-config-management.md](docs/laravel-integration/01-formula-config-management.md) — CRUD coefficient + version publish + audit log
- [docs/laravel-integration/02-jobs-orchestration.md](docs/laravel-integration/02-jobs-orchestration.md) — enqueue + monitor + retry recompute jobs
- [docs/laravel-integration/03-collectors-admin.md](docs/laravel-integration/03-collectors-admin.md) — trigger + history + quotas cho 4 collector
- [docs/laravel-integration/04-screen-context-inspector.md](docs/laravel-integration/04-screen-context-inspector.md) — explain-the-estimate page
- [docs/laravel-integration/05-monitoring-dashboard.md](docs/laravel-integration/05-monitoring-dashboard.md) — freshness / queue health / alerts

*Updated: 2026-04-20. Proposal version: 2.0.*
