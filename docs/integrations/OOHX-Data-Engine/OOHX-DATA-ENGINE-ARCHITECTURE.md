# OOHX Data Engine — Technical Architecture

> Tài liệu kỹ thuật mô tả toàn bộ dự án **OOHX Data Engine** (Python + PostGIS).
> Đối tượng đọc: lập trình viên mới tiếp nhận dự án, cần hiểu kiến trúc,
> quy ước triển khai, và các điểm mở rộng trước khi viết code tiếp.
>
> Tài liệu chị em:
> - [`oohx-matrix-integration.md`](oohx-matrix-integration.md) — chi tiết integration phía **Laravel** (không thay đổi code Data Engine).
> - [`python-data-engine/README.md`](python-data-engine/README.md) — runbook ngắn cho ops.

---

## 1. Mục tiêu & phạm vi

OOHX Data Engine là một **batch spatial engine** tách biệt hẳn khỏi Laravel
marketplace, chạy trên VPS riêng (2 GB RAM). Nhiệm vụ duy nhất:

> Từ **toạ độ screen** + **metadata cơ bản**, tính ra ước lượng
> `daily_passby / daily_ots / daily_impressions / confidence_score` để Laravel
> hiển thị cho advertiser & planner.

Nguyên tắc thiết kế (đã chốt ở MVP):

| Nguyên tắc | Lý do |
|---|---|
| **Không realtime** — mọi thứ precomputed | VPS nhỏ; Laravel chỉ cần SELECT precomputed values |
| **Không web framework** — chỉ CLI + cron | Bớt một tầng phức tạp; `click` + `psycopg` là đủ |
| **Không ORM, không GeoPandas** | Toàn bộ spatial math chạy trong PostGIS; Python không bao giờ load geometry vào RAM |
| **Rule-based model, deterministic** | Output giải thích được cho sales/planner; không phụ thuộc model training |
| **4 schema tách namespace rõ** | `core` / `source` / `metrics` / `output` — phân tầng độc lập, dễ mở rộng |
| **One worker, optional scale-out** | `FOR UPDATE SKIP LOCKED` đảm bảo đa worker an toàn khi cần |

**Không làm ở MVP:**
- HTTP API (sẽ thêm FastAPI ở giai đoạn sau nếu cần push-style ingest).
- ML model (giữ chỗ bằng `model_version` + `estimation_method`, thay thế sau).
- Reach/frequency, CPM (cột có sẵn trong `output` nhưng để `NULL`).

---

## 2. Hình dung hệ thống

### 2.1. Hai VPS, một chiều dữ liệu

```
┌──────────────────────────┐     30m rsync     ┌──────────────────────────┐
│ LARAVEL VPS              │ ────screens.json─►│ DATA ENGINE VPS          │
│ 172.104.188.62           │                   │ 139.162.20.95            │
│                          │ ◄── SSH tunnel ── │                          │
│ - Laravel app (MySQL)    │   :5433 → :5432   │ - PostgreSQL 16 + PostGIS│
│ - oohx:sync-to-engine    │   oohx_readonly   │ - Python Data Engine     │
│   (Artisan, every 30m)   │                   │ - cron(10m) + cron nightly│
└──────────────────────────┘                   └──────────────────────────┘
```

- Laravel **chỉ đọc** `output.screen_traffic_estimates` (+ `core.screens` để
  JOIN qua `external_id`). Không có quyền ghi.
- Data Engine **chỉ nhận** screens qua file drop; không bao giờ kết nối vào
  MySQL của Laravel.
- Mọi thứ qua SSH: port 5432 của PostgreSQL **không** public ra ngoài.

### 2.2. Pipeline nội bộ

```
screens.json ──► ingest-screens ──► core.screens (upsert by external_id)
                                         │
         enqueue-screen/city ──► core.recompute_jobs (pending)
                                         │
      cron */10 * * * * recompute-pending-jobs
                                         │
                                         ▼
                          ┌── RecomputeRunner.run_screen ──┐
                          │                                │
                          ▼                                ▼
                EnrichmentService                TrafficEstimationService
                (PostGIS queries)                (deterministic math)
                          │                                │
                          ▼                                ▼
              metrics.screen_context_metrics    output.screen_traffic_estimates
                                                           │
                                                           ▼
                                         Laravel SELECT qua SSH tunnel
```

Mỗi screen = 1 row ở `metrics.*` và 1 row ở `output.*` (upsert theo `screen_id`).
Tái chạy = ghi đè, không version hoá — `enrich_version` / `model_version` chỉ
để trace.

---

## 3. Tech stack & quy ước

### 3.1. Stack chính

| Layer | Chọn | Ghi chú |
|---|---|---|
| Language | Python 3.10+ | type hints + `from __future__ import annotations` ở mọi module |
| DB | PostgreSQL 15/16 + PostGIS 3 | GiST indexes trên mọi cột `geography` |
| DB driver | `psycopg[binary]` v3 + `psycopg_pool` | autocommit=False, explicit `transaction()` context manager |
| CLI | `click` 8.x | mọi entry-point đi qua `python -m app.cli` |
| Env | `python-dotenv` | load `.env` nếu có; fallback sang `os.getenv` |
| Logging | stdlib `logging` | level qua `OOHX_LOG_LEVEL`, format có timestamp + logger name |

**Không dùng:** SQLAlchemy, GeoPandas, pandas, alembic, FastAPI, celery, redis.
Thêm bất kỳ dependency mới nào cần cân nhắc cost/benefit trên VPS 2 GB.

### 3.2. Quy ước code

- **Repository pattern**: mọi truy cập DB đi qua class trong `app/repositories/`.
  Service **không** viết SQL thẳng (trừ các SQL spatial phức tạp trong
  `enrichment.py` — SQL template đặt cạnh class, vẫn gọi cursor trực tiếp từ
  service để tránh biến `metrics` thành struct cứng).
- **Service pattern**: logic tính toán / orchestration sống trong
  `app/services/` và `app/jobs/`. Không import `click` hay đọc `os.environ`.
- **Dataclass config**: `TrafficConfig` là `frozen dataclass` với
  `@lru_cache(maxsize=1)` — singleton thread-safe, mutation tuyệt đối cấm.
- **Dict-in, dict-out**: repositories trả `dict` (qua `dict_row`) chứ không
  dựng Pydantic model. Lý do: payload linh hoạt, không cần validation nặng
  trong batch job.
- **Errors**: service/repo ném `ValueError` cho dữ liệu sai; CLI bọc thành
  `click.ClickException`. Job runner **không** rethrow — bắt để update
  `core.recompute_jobs.error_message` và quyết định retry/fail.

### 3.3. Layout thư mục

```
python-data-engine/
├── app/
│   ├── config.py                # DBConfig + TrafficConfig + POI_CATEGORY_MAP
│   ├── db.py                    # pool, get_conn(), transaction(), ping()
│   ├── cli.py                   # click commands → services
│   ├── repositories/
│   │   ├── screens.py           # core.screens + core.screen_delivery_settings
│   │   ├── jobs.py              # core.recompute_jobs (SKIP LOCKED)
│   │   ├── metrics.py           # metrics.screen_context_metrics
│   │   └── estimates.py         # output.screen_traffic_estimates
│   ├── services/
│   │   ├── enrichment.py        # PostGIS: nearest road, POI counts, venue
│   │   └── traffic_estimation.py# rule-based formula per REQ-IV
│   └── jobs/
│       └── recompute.py         # RecomputeRunner (screen/city/queue drain)
├── sql/                         # DDL migrations, numbered lexically
├── sample_data/                 # seed data cho smoke test
├── scripts/bootstrap_db.sh      # superuser bootstrap (role + db + init-db)
├── requirements.txt
├── .env.example
└── README.md                    # runbook operator
```

---

## 4. Data model

4 schema PostgreSQL với trách nhiệm tách biệt rõ ràng:

### 4.1. Schema `core` — trạng thái nội bộ

| Table | Mô tả |
|---|---|
| `core.screens` | Bảng chính của Data Engine. Upsert key = `external_id` (string tham chiếu tới Laravel). Mỗi screen có `lat/lon` + `geom` (PostGIS geography Point 4326). CHECK: `indoor_outdoor ∈ {'indoor','outdoor'}`. |
| `core.screen_delivery_settings` | 1-1 với screen. Chứa `ad_duration`, `loop_duration`, `share_of_voice`, `dwell_factor`, và override cho `visibility_factor` / `direction_factor`. Auto-insert default khi screen mới được ingest. |
| `core.recompute_jobs` | Queue. `job_type ∈ {'screen','city','bulk'}`, `status ∈ {'pending','processing','done','failed'}`. Payload `JSONB` free-form cho bulk. |

### 4.2. Schema `source` — dữ liệu không gian ngoài

| Table | Geometry | Nguồn điển hình |
|---|---|---|
| `source.roads` | `geography(LineString, 4326)` | OSM extract — road_class, lane_count, oneway |
| `source.pois` | `geography(Point, 4326)` | OSM POI — category/subcategory, brand |
| `source.venues` | `geography(MultiPolygon, 4326)` + centroid | Nội bộ — daily/weekly/monthly footfall, confidence |

Schema này **độc lập với Laravel**; refresh riêng (import OSM hoặc update
footfall từ ops). Không có FK ngược lên `core.*`.

### 4.3. Schema `metrics` — snapshot enrichment

`metrics.screen_context_metrics` (1-1 với screen, PK = `screen_id`):

- Cột context thô: `nearest_road_class`, `lane_count`, `poi_count_300m`,
  `venue_footfall`, v.v.
- Cột factor đã resolved: `zone_factor`, `visibility_factor`, `direction_factor`.
- Cột score normalized 0..1: `road_score`, `poi_score`, `venue_score`,
  `population_score` (MVP placeholder).
- `context_tags JSONB` — metadata tuỳ ý, để debug (`has_nearest_road`,
  `venue_matched`, `oneway`, …).
- `enrich_version` — tag version thuật toán enrichment (hiện `"mvp-1.0"`).

### 4.4. Schema `output` — consumer contract với Laravel

`output.screen_traffic_estimates` (1-1 với screen):

| Cột | Công thức |
|---|---|
| `estimated_daily_passby` | (chỉ outdoor) `base × road × lane × intersection × poi × population` |
| `estimated_daily_screen_flow` | (chỉ indoor) `venue_footfall × zone_factor` |
| `estimated_daily_ots` | `passby/flow × visibility × direction` |
| `estimated_daily_impressions` | `ots × share_of_voice × dwell_factor` |
| `estimated_weekly_impressions` | `daily × 7` |
| `estimated_monthly_impressions` | `daily × 30` |
| `impression_multiplier` | `visibility × direction × sov × dwell` (scalar giải thích chênh giữa OTS & impressions) |
| `confidence_score` | 0.10 – 0.95, từ heuristic theo `_outdoor_confidence` / `_indoor_confidence` |
| `estimation_method` | `"rule_based_outdoor"` / `"rule_based_indoor"` |
| `model_version` | `"mvp-1.0"` (bump khi đổi công thức) |
| `reach/frequency/cpm` | **NULL** ở MVP — giữ cột để mở rộng |

**Bất biến quan trọng:** Laravel chỉ đọc `output.*` + join `core.screens`.
Đừng bao giờ để logic business của Laravel phụ thuộc vào `metrics.*` hay
`source.*` — chúng có thể thay hình dáng bất cứ lúc nào.

### 4.5. Indexes & triggers

- **Spatial (GiST)**: `core.screens.geom`, `source.roads.geom`,
  `source.pois.geom`, `source.venues.geom`, `source.venues.centroid`.
- **Attribute (B-tree)**: `screens (city, status, indoor_outdoor)`,
  `roads (city, road_class)`, `pois (city, category)`, `venues (city)`.
- **Queue**: `recompute_jobs (status, priority, requested_at)` + partial
  index `(screen_id) WHERE screen_id IS NOT NULL`.
- **Output**: `output.screen_traffic_estimates (last_calculated_at)` — để
  Laravel filter "mới hơn N giờ".
- **Trigger**: `core.set_updated_at()` gắn vào 5 bảng có cột `updated_at`.

---

## 5. Pipeline chi tiết

### 5.1. Ingest (file drop)

Input: file `.json` (array) hoặc `.csv` ở `/home/oohx/inbox/screens.json`.

```bash
.venv/bin/python -m app.cli ingest-screens --file ~/inbox/screens.json
```

Tại [`app/cli.py:114-135`](python-data-engine/app/cli.py#L114-L135):

1. Đọc file, parse thành list dict.
2. Với mỗi row:
   - `ScreenRepository.upsert()` — `INSERT ... ON CONFLICT (external_id) DO UPDATE`,
     trigger build `geom` từ `lat/lon`.
   - `ScreenRepository.set_delivery_settings_defaults_if_missing()` — đảm bảo
     có row trong `core.screen_delivery_settings` để `TrafficEstimationService`
     resolve được SOV/dwell.
3. Trả summary: `{file, ok, fail, total}`.

**Upsert là idempotent**: chạy lại cùng file không sinh duplicate, nhưng sẽ
ghi đè tất cả cột trừ `created_at`. `synced_at` luôn set `NOW()`.

**Không auto-enqueue.** Sau ingest, screen mới vẫn chưa có estimate; cần một
trong hai:
- `enqueue-screen --screen-id N` rồi để cron 10m drain, hoặc
- `recompute-city --city Hanoi` cho backfill trực tiếp.

### 5.2. Enrichment

[`services/enrichment.py`](python-data-engine/app/services/enrichment.py)
— một PostGIS query cho mỗi phép đo, không bao giờ load geometry lên Python.

**Outdoor** (`_enrich_outdoor`):

1. `_SQL_NEAREST_ROAD` — road gần nhất trong bán kính 200 m, `ORDER BY r.geom <-> pt`.
2. `_SQL_NEAREST_MAIN_ROAD` — khoảng cách tới đường trục gần nhất (≤ 2 km) — dùng cho context tag.
3. `_SQL_POI_COUNTS` — một query với `FILTER (WHERE ...)` đếm POI trong 100/300/500 m + 7 category (shopping/food/office/…).
4. Resolve `zone_factor` (default outdoor = `roadside`), `visibility_factor = 0.25`,
   `direction_factor = 0.60` nếu `oneway` else `1.00`.
5. Tính `road_score = road_class_multiplier[class]`, `poi_score = min(1 + 0.01·poi_300, 1.8)`.

**Indoor** (`_enrich_indoor`):

1. `_SQL_VENUE_LOOKUP` — venue bao quanh (hoặc trong 150 m) bằng `ST_DWithin`.
2. Fallback `_SQL_VENUE_BY_NAME` nếu geo miss nhưng screen có `venue_name`
   (trường hợp coordinates sai lệch nhẹ trong mall).
3. POI counts giống outdoor (để báo cáo, không tham gia công thức indoor).
4. Default factors: `zone_factor` = `entrance` nếu screen không khai; `visibility = 0.55`; `direction = 1.00`.
5. `venue_score = min(daily_footfall / 100_000, 1.0)`.

Output của enrichment = dict ~30 field, được `MetricsRepository.upsert` ghi
atomic vào `metrics.screen_context_metrics`.

### 5.3. Estimation

[`services/traffic_estimation.py`](python-data-engine/app/services/traffic_estimation.py)
— công thức thuần Python, đọc từ metrics + delivery_settings đã có sẵn.

**Outdoor** (REQ IV.A):

```
base         = base_city_traffic[city]                        # 8000 Hanoi, 10000 HCMC, 6000 default
road_mult    = road_class_multiplier[road_class]              # 2.5 highway → 0.5 service
lane_factor  = clamp(0.6, 1.0 + 0.2·(lanes − 2), 2.0)
poi_factor   = min(1 + 0.01·poi_300m, 1.8)
intersection = 1.0       # MVP placeholder
population   = 1.0       # MVP placeholder

passby       = base × road × lane × intersection × poi × population
ots          = passby × visibility × direction
daily_impr   = ots × share_of_voice × dwell_factor
```

Resolver ưu tiên: `context metrics → delivery override → cfg default`
(hàm `_resolve_factor`).

**Indoor** (REQ IV.B):

```
screen_flow  = venue_footfall × zone_factor
ots          = screen_flow × visibility × direction
daily_impr   = ots × share_of_voice × dwell_factor
```

**Confidence**:

- Outdoor baseline 0.55; penalty nếu không tìm thấy road hoặc road là
  residential/service; bonus nhẹ nếu có POI.
- Indoor: 0.75 nếu venue match + có footfall; 0.60 nếu có footfall nhưng
  không match venue; 0.30 nếu không có footfall (ước lượng sẽ ~0 — báo
  downstream biết không đáng tin).

Kết quả = dict ~15 field; `EstimateRepository.upsert` ghi vào
`output.screen_traffic_estimates`, `last_calculated_at = NOW()`.

### 5.4. RecomputeRunner — entry point orchestration

[`app/jobs/recompute.py`](python-data-engine/app/jobs/recompute.py):

| Method | Dùng khi |
|---|---|
| `run_screen(screen_id)` | End-to-end cho 1 screen: ensure delivery settings → enrich → estimate |
| `run_city(city)` | Loop tuần tự mọi screen active của city, mỗi screen 1 transaction độc lập (1 lỗi không chặn phần còn lại) |
| `process_pending_jobs(max_jobs, max_retry)` | Drain queue — claim job bằng `SKIP LOCKED`, dispatch theo `job_type`, update status + retry |

**Job retry logic** (`JobRepository.mark_failed`):
- `retry_count` tăng sau mỗi fail.
- Nếu `retry_count < max_retry` → set `status='pending'` lại (queue tự pick).
- Nếu đạt `max_retry` → `status='failed'` cố định, `error_message` lưu 4000
  ký tự đầu.

---

## 6. Triển khai & vận hành

### 6.1. First-time setup trên VPS trắng

```bash
# 1. System packages (Ubuntu 22.04)
sudo apt update && sudo apt install -y \
    postgresql postgresql-contrib postgresql-15-postgis-3 \
    python3 python3-venv python3-pip build-essential

# 2. App checkout + venv
cd /opt && sudo git clone <repo> oohx && sudo chown -R oohx:oohx oohx
cd oohx/python-data-engine
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

# 3. Environment
cp .env.example .env && vi .env      # set PG_PASSWORD

# 4. DB bootstrap (superuser)
PG_SUPERUSER=postgres PG_DB=oohx_data PG_USER=oohx \
  PG_PASSWORD=<pwd> ./scripts/bootstrap_db.sh

# 5. Smoke
python -m app.cli ping
python -m app.cli ingest-screens --file sample_data/screens.json
psql -U oohx -d oohx_data -f sample_data/load_sources.sql
python -m app.cli recompute-city --city Hanoi
psql -U oohx -d oohx_data \
  -c "SELECT external_id, estimated_daily_impressions, confidence_score
      FROM output.screen_traffic_estimates e
      JOIN core.screens s ON s.id = e.screen_id;"
```

### 6.2. Cron plan chuẩn

```cron
# Drain recompute queue (10 phút)
*/10 * * * *  cd /opt/oohx/python-data-engine && \
    .venv/bin/python -m app.cli recompute-pending-jobs --max 200 \
    >> /var/log/oohx/worker.log 2>&1

# Nightly full backfill theo thành phố
30 2 * * *    cd /opt/oohx/python-data-engine && \
    .venv/bin/python -m app.cli recompute-city --city Hanoi \
    >> /var/log/oohx/recompute.log 2>&1

45 2 * * *    cd /opt/oohx/python-data-engine && \
    .venv/bin/python -m app.cli recompute-city --city HCMC \
    >> /var/log/oohx/recompute.log 2>&1
```

Nếu đổi phía Laravel sang enqueue theo screen (push-model), chỉ cần giữ
cron 10 phút.

### 6.3. PostgreSQL tuning (2 GB VPS)

```
shared_buffers = 384MB
work_mem = 16MB
maintenance_work_mem = 128MB
effective_cache_size = 1GB
```

Với 4-connection pool (`PG_MAX_CONN=4`), peak memory Python + libpq ≪ 300 MB.

### 6.4. Role phân quyền

| Role | Quyền | Dùng bởi |
|---|---|---|
| `oohx` | OWNER DB — DDL + DML mọi schema | Data Engine CLI |
| `oohx_readonly` | `USAGE` + `SELECT` trên `output`, `core.screens` | Laravel (qua SSH tunnel :5433) |

Tạo `oohx_readonly`:

```sql
CREATE ROLE oohx_readonly LOGIN PASSWORD '<pwd>';
GRANT USAGE ON SCHEMA output, core TO oohx_readonly;
GRANT SELECT ON output.screen_traffic_estimates TO oohx_readonly;
GRANT SELECT ON core.screens TO oohx_readonly;
ALTER DEFAULT PRIVILEGES IN SCHEMA output
    GRANT SELECT ON TABLES TO oohx_readonly;
```

### 6.5. Observability

MVP không có metrics push. Điều tra theo thứ tự:

```bash
# 1. DB reachable?
python -m app.cli ping

# 2. Queue health
python -m app.cli jobs-status
# → {"pending": 0, "processing": 0, "done": 1234, "failed": 2}

# 3. Estimate freshness
psql -U oohx -d oohx_data -c "
SELECT
    COUNT(*)                                      AS screens,
    COUNT(e.screen_id)                            AS estimated,
    MIN(e.last_calculated_at)                     AS oldest,
    MAX(e.last_calculated_at)                     AS newest
FROM core.screens s
LEFT JOIN output.screen_traffic_estimates e ON e.screen_id = s.id
WHERE s.status = 'active';"

# 4. Failed jobs chi tiết
psql -U oohx -d oohx_data -c "
SELECT id, job_type, screen_id, city, retry_count, error_message
FROM core.recompute_jobs
WHERE status = 'failed'
ORDER BY finished_at DESC LIMIT 20;"
```

Log files:
- `/var/log/oohx/worker.log` — drain queue (cron 10 phút).
- `/var/log/oohx/recompute.log` — nightly backfill.
- `journalctl -u postgresql` — DB system log.

---

## 7. CLI reference

Mọi command: `python -m app.cli <command> --help`.

| Command | Input | Output | Tác dụng |
|---|---|---|---|
| `ping` | — | `ok` / `FAIL` | DB healthcheck |
| `init-db` | `--sql-dir sql` | log files applied | Chạy toàn bộ `sql/*.sql` trong 1 transaction |
| `ingest-screens` | `--file path` | `{file, ok, fail, total}` | Upsert screens từ JSON/CSV |
| `enrich-screen` | `--screen-id N` | context dict | Chỉ enrich, không estimate |
| `estimate-screen` | `--screen-id N` | estimate dict | Chỉ estimate (cần metrics sẵn) |
| `recompute-screen` | `--screen-id N` | summary | Enrich + estimate end-to-end |
| `recompute-city` | `--city Hanoi` | `{city, total, ok, fail}` | Loop mọi screen active của city |
| `enqueue-screen` | `--screen-id N` `--priority 100` | `{job_id}` | Đẩy vào queue |
| `enqueue-city` | `--city Hanoi` `--priority 200` | `{job_id}` | Đẩy job city vào queue |
| `recompute-pending-jobs` | `--max 200` `--max-retry 3` | `{processed, failed}` | Worker drain |
| `jobs-status` | — | dict count theo status | Health snapshot |

Exit code: 0 thành công, khác 0 nếu `click.ClickException` — phù hợp để cron
gắn `MAILTO` hoặc alert.

---

## 8. Hợp đồng với Laravel

### 8.1. Schema contract (read path)

Laravel connect read-only qua SSH tunnel và SELECT:

```sql
SELECT s.external_id, s.name, s.indoor_outdoor, s.city, s.zone_type,
       e.estimated_daily_impressions,
       e.estimated_monthly_impressions,
       e.confidence_score,
       e.estimation_method,
       e.model_version,
       e.last_calculated_at
FROM output.screen_traffic_estimates e
JOIN core.screens s ON s.id = e.screen_id
WHERE s.external_id = :externalId;
```

**Hợp đồng ổn định của Data Engine với Laravel:**
1. `core.screens.external_id` là khoá nối duy nhất.
2. Các cột trong [§4.4](#44-schema-output--consumer-contract-với-laravel)
   không bị rename / drop (chỉ bị `NULL` nếu chưa có data).
3. `last_calculated_at` luôn monotonic tăng.
4. Không thêm cột NOT NULL mới — nếu cần thêm, phải có default.

### 8.2. Ingest contract (write path)

Payload bắt buộc (sẽ raise `ValueError` nếu thiếu):

| Field | Type | Constraint |
|---|---|---|
| `external_id` | string | unique, không đổi |
| `indoor_outdoor` | `'indoor'` \| `'outdoor'` | enum bắt buộc |
| `lat` | float | WGS84 |
| `lon` | float | WGS84 |

Optional (nên đẩy càng đầy đủ càng tốt):
`name`, `media_owner_name`, `venue_name` (nên có nếu indoor), `venue_type`,
`zone_type` (enum dưới), `screen_type`, `screen_size`, `orientation`,
`city`, `district`, `ward`, `address`, `status` (default `active`),
`source_updated_at`.

**`zone_type` enum recognised**:
`entrance`, `checkout`, `escalator`, `food_court`, `cinema_corridor`,
`inside_aisle`, `roadside`, `facade`. Giá trị khác fallback về default theo
`indoor_outdoor`.

**`city` whitelist** (có trong `base_city_traffic`):
`Hanoi`, `Ha Noi`, `HCMC`, `Ho Chi Minh`, `Ho Chi Minh City`, `Da Nang`,
`Danang`. Các city khác dùng `default = 6000`. Thêm city → sửa
[`app/config.py:60-69`](python-data-engine/app/config.py#L60-L69).

Chi tiết integration phía Laravel: xem
[`oohx-matrix-integration.md`](oohx-matrix-integration.md).

---

## 9. Các điểm mở rộng

Thứ tự ưu tiên gợi ý khi phát triển tiếp:

### 9.1. Dữ liệu không gian thực

- **Import OSM roads + POIs** cho Hanoi, HCMC, Da Nang. Dùng `osm2pgsql` hoặc
  `imposm3`, xuất vào `source.roads` và `source.pois`. Cần viết importer
  script idempotent (TRUNCATE + INSERT trong transaction).
- **Venue catalog**: DemoMedia hoặc đối tác cung cấp CSV với
  `venue_name, type, chain, city, daily_footfall, centroid/polygon`. Viết
  `python -m app.cli import-venues --file venues.csv`.
- Khi có data thật, `confidence_score` tự nhích lên vì `has_main_road_within_2km`
  và `venue_matched` sẽ true thường xuyên hơn.

### 9.2. Intersection factor & population density

Cột đã có sẵn, tính toán đang hardcode = 1.0.

- **Intersection**: đếm giao lộ OSM trong 100/200/500 m — query PostGIS với
  `ST_Intersection` hoặc bảng `source.intersections` riêng.
- **Population density**: load raster dân số (WorldPop, GHSL) vào
  `source.population_grid`, query bằng `ST_SummaryStats`.

Khi thêm, nhớ bump `TrafficConfig.enrich_version` và `model_version`, và cập
nhật formula trong `_estimate_outdoor`.

### 9.3. Reach, frequency, CPM

Các cột ở `output.*` đang `NULL`. Reach cần:
- Audience turnover model (unique viewers ≠ impressions).
- CPM cần bảng giá theo media_owner × city × screen_type.

Đặt logic mới trong `TrafficEstimationService`, giữ shape của
`output.screen_traffic_estimates` không đổi.

### 9.4. Push ingest qua HTTP (khi Laravel cần)

Thêm một FastAPI thin (≈100 LOC) chạy uvicorn, endpoint
`POST /ingest/screens` gọi thẳng `ScreenRepository.upsert_many`. Giữ CLI làm
primary path; HTTP chỉ là adapter.

Không thêm authentication phức tạp — bảo vệ bằng firewall + Basic Auth là đủ
cho scope nội bộ.

### 9.5. Model v2 (ML-assisted)

Giữ nguyên `rule_based_*` làm fallback. Thêm:
- `metrics.screen_context_metrics` cột `embedding vector(N)` (`pgvector`).
- Service mới `MLEstimationService` đọc model từ `.pkl`/`onnx`, chạy song
  song với `TrafficEstimationService`. `estimation_method = 'hybrid_v2'`.

Output schema không đổi → Laravel không cần sửa.

---

## 10. Anti-patterns cần tránh

Những lỗi dễ mắc cho developer mới:

1. **Dùng Eloquent/ORM viết vào Data Engine** — đừng. Mọi write phải đi qua
   repository (vì có upsert logic, validation, jsonb cast).
2. **Thêm `psql` call trong Python bằng subprocess** — đã có `transaction()`
   context; migration và one-off SQL đặt vào `sql/*.sql` + `init-db`.
3. **Load geometry lên Python rồi tính khoảng cách** — VPS sẽ chết. Tất cả
   spatial query phải `ST_*` phía PostGIS, Python chỉ nhận scalar.
4. **Tạo SQLAlchemy session** — đã chốt dùng `psycopg` thô; thêm ORM = chi
   phí maintain + memory overhead vô ích.
5. **Silent swallow exception trong RecomputeRunner** — job runner **phải**
   rethrow để `mark_failed` ghi `error_message`. Bắt `except Exception: pass`
   làm queue chết im lặng.
6. **Hard-code city name / zone_type trong service** — thêm vào
   `TrafficConfig` để mọi override đi qua một chỗ.
7. **Bỏ qua `set_delivery_settings_defaults_if_missing`** — nếu
   `core.screen_delivery_settings` không có row, `_delivery_params` fallback
   về config default nên vẫn chạy, nhưng lần đầu screen được ingest mà ops
   muốn override SOV sẽ không có nơi để override. Luôn gọi hàm này sau upsert
   screen.

---

## 11. Version & roadmap tracking

- Công thức hiện tại: `model_version = "mvp-1.0"`, `enrich_version = "mvp-1.0"`
  (ở [`config.py:55-56`](python-data-engine/app/config.py#L55-L56)).
- Bump version khi:
  - Đổi công thức estimate → `model_version`.
  - Thêm/đổi dimension ở context → `enrich_version`.
- Laravel có thể filter `WHERE model_version = 'mvp-1.0'` nếu muốn xem
  precomputed theo version (hiện không filter).

---

## 12. Tài liệu liên quan & liên hệ

| Tài liệu | Nội dung |
|---|---|
| [`python-data-engine/README.md`](python-data-engine/README.md) | Runbook ngắn cho ops |
| [`oohx-matrix-integration.md`](oohx-matrix-integration.md) | Laravel-side integration (connection, models, Artisan commands, scheduler) |
| `sql/003_create_tables.sql` | Định nghĩa schema chính thức |
| `sample_data/*` | Seed cho smoke test, dùng làm fixture khi viết test |

Khi cần:
- Thêm city / tune factor defaults → sửa `TrafficConfig`, bump version.
- Thêm bảng output mới → tạo migration `sql/006_*.sql`, thêm
  repository + service, Laravel cần deploy thêm model mới.
- Tái tổ chức schema `source.*` → free hand, không ảnh hưởng Laravel.

---

*Cập nhật cuối: 2026-04-20. Version tài liệu: 1.0.*
