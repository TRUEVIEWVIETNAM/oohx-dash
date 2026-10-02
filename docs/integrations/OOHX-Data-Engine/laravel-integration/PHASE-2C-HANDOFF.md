# Phase 2.C — Data Engine Handoff for Laravel Team

> **Role of this document**: handoff Collectors framework (POI + Weather) đã ship cho team Laravel bắt đầu Guide 03 (Collectors Admin UI).
> **Status**: base framework + 2 collectors production-ready + tested shape. Laravel có thể build UI ngay.
> **Reference**:
> - [03-collectors-admin.md](03-collectors-admin.md) — spec gốc Laravel side
> - [PHASE-2B-HANDOFF.md](PHASE-2B-HANDOFF.md) — jobs orchestration
> - [DATA-ENGINE-ACTION-PLAN.md](../DATA-ENGINE-ACTION-PLAN.md)

---

## 1. What's shipped on Data Engine side (Phase 2.C)

### 1.1. New SQL migration

- [`sql/008_collector_prep.sql`](../../python-data-engine/sql/008_collector_prep.sql) — partial unique index trên `source.pois.osm_id` và `source.roads.osm_id` (để Overpass/osm2pgsql ON CONFLICT UPSERT), partial unique `(city, observed_at)` trên `source.weather_snapshots` để dedupe khi 2 cron fire sát nhau.

Đã apply trên Data Engine VPS.

### 1.2. Code files

```
python-data-engine/
├── app/
│   ├── collectors/
│   │   ├── __init__.py
│   │   ├── base.py                  # Collector ABC + BBox + Context/Result
│   │   ├── _http.py                 # requests + retry + rate-limit aware
│   │   ├── overpass_poi.py          # OSM POI → source.pois (cache 24h)
│   │   ├── open_meteo_weather.py    # Open-Meteo → source.weather_snapshots
│   │   └── runner.py                # Registry + drain queue (SKIP LOCKED)
│   └── repositories/
│       └── collectors.py            # CollectorRunRepository + CollectorCacheRepository
└── requirements.txt                 # + requests>=2.31
```

### 1.3. New CLI commands

| Command | Purpose |
|---|---|
| `list-collectors` | List registered collectors (name + metadata) |
| `enqueue-collector --name X --city Y [--priority N] [--bbox JSON]` | Queue 1 run |
| `run-pending-collectors --max 5` | Drain queue (SKIP LOCKED) |
| `collect-poi --city X` | Sync shortcut: enqueue + drain 1 POI run |
| `collect-weather --city X` | Sync shortcut: enqueue + drain 1 weather run |
| `list-collector-runs [--collector X] [--city Y] [--status Z] [--limit N]` | List recent runs |
| `show-collector-run --id N` | Detail 1 run |
| `collectors-status` | Counts + latest per (collector, city) |
| `purge-collector-cache` | Remove expired cache rows (cron weekly) |

---

## 2. Contracts cho Laravel

### 2.1. Enqueue: chỉ cần INSERT

Role `oohx_control` đã có INSERT + UPDATE trên `collectors.collector_runs`. Laravel enqueue bằng Eloquent:

```php
use App\Models\Oohx\CollectorRun;

CollectorRun::create([
    'collector_name' => 'overpass_poi',   // hoặc 'open_meteo_weather'
    'city'           => 'Hanoi',
    'params'         => [                 // JSONB
        'bbox' => [                       // optional — override auto-bbox
            'min_lon' => 105.70, 'min_lat' => 20.90,
            'max_lon' => 106.00, 'max_lat' => 21.15,
        ],
    ],
    'priority'     => 100,
    'status'       => 'pending',
    'requested_by' => auth()->user()?->email ?? 'laravel-ui',
    'requested_at' => now(),
]);
```

Cron Python worker (mỗi 15 phút) sẽ pick lên + execute.

### 2.2. Status lifecycle

```
pending  ──(cron pick)──►  running  ──(success)──►  done
                                 │
                                 ├──(task fails)──►  failed (retry ≤ 3 lần)
                                 │
                                 └──(Laravel cancels)──►  cancelled
```

`pending` → `cancelled`: Laravel set trực tiếp; Python không pick lên.

`running` cancel: không support cooperative cancel ở Phase 2.C. Collector chạy task trong < 3 phút, cancel không đáng.

### 2.3. Result fields

Khi collector xong, các cột sau được populate:

| Field | Type | Example |
|---|---|---|
| `status` | text | `done` |
| `rows_ingested` | int | `3472` (POI inserted + updated) |
| `bytes_fetched` | bigint | `620483` |
| `stats` | jsonb | `{"inserted": 1200, "updated": 2272, "skipped": 45, "api_calls": 1, "parsed": 3517, "tasks_planned": 1, "tasks_completed": 1}` |
| `error_message` | text | null khi done; có text khi failed |
| `started_at`, `finished_at` | timestamptz | |

Laravel hiển thị:
- `rows_ingested` làm headline metric
- `bytes_fetched` → format "MB/KB" cho info
- `stats.skipped` → cảnh báo nếu > 10% của parsed
- `stats.api_calls` → cost awareness (Overpass rate limit)

### 2.4. Collectors đã ship + metadata cho `config/oohx_collectors.php`

Laravel cần tạo config file theo guide 03. Dưới đây là values cho 2 collector đã ship:

```php
<?php

return [
    'overpass_poi' => [
        'display_name'  => 'OpenStreetMap POI (Overpass API)',
        'description'   => 'Fetch POI nodes by category within city bbox. '
                         . 'Upsert into source.pois by osm_id.',
        'provider'      => 'Overpass API',
        'cost'          => 'free',
        'rate_limit'    => '10,000 queries/day per IP',
        'cadence_hours' => 168,            // weekly
        'supports_city' => true,
        'supports_bbox' => true,
        'cache_ttl_hours' => 24,
        'expected_runtime_seconds' => 90,   // for Hanoi bbox
    ],
    'open_meteo_weather' => [
        'display_name'  => 'Weather snapshot (Open-Meteo)',
        'description'   => 'Current + hourly weather for city centroid. '
                         . 'Insert into source.weather_snapshots.',
        'provider'      => 'Open-Meteo',
        'cost'          => 'free (no API key)',
        'rate_limit'    => '10,000 calls/day',
        'cadence_hours' => 6,
        'supports_city' => true,
        'supports_bbox' => false,
        'cache_ttl_hours' => 0,            // never cache weather
        'expected_runtime_seconds' => 3,
    ],

    // Placeholder cho Phase 2.D:
    // 'osm_roads'          => [...],
    // 'worldpop_population'=> [...],
];
```

### 2.5. Supported cities (Phase 2.C built-in centroids)

Overpass POI + Open-Meteo đều có default bbox/centroid cho:
- `Hanoi`, `Ha Noi`
- `HCMC`, `Ho Chi Minh`, `Ho Chi Minh City`
- `Da Nang`, `Danang`
- `Hai Phong`, `Hải Phòng`
- `Can Tho`
- `Ninh Bình`

City khác → fallback: Python auto-derive bbox/centroid từ `core.screens` (AVG lat/lon với pad ±0.05°). Nếu city không có screen active nào → collector sẽ fail với `ValueError`. Laravel UI nên validate trước.

### 2.6. Params schema (JSONB)

Laravel gửi qua `collector_runs.params`. Schema cho từng collector:

#### `overpass_poi.params`

```jsonc
{
  "bbox": {                       // optional — override CITY_DEFAULT_BBOX
    "min_lon": 105.70,
    "min_lat": 20.90,
    "max_lon": 106.00,
    "max_lat": 21.15
  }
}
```

#### `open_meteo_weather.params`

```jsonc
{
  "forecast_hours": 24            // optional — thêm forecast hourly block
}
```

---

## 3. Laravel UI cần build (tham chiếu Guide 03)

### 3.1. Overview page `/admin/oohx/collectors`

```
Collectors — counts: pending=2 · running=0 · done=5 · failed=0

📍 OpenStreetMap POI (Overpass API)
   Cost: free · Rate: 10k/day · Cadence: weekly · Cache: 24h
   Latest — Hanoi: ✅ done 2d ago (3,472 rows, 620 KB)
   Latest — HCMC:  ⚠️ never
   [Trigger: Hanoi] [Trigger: HCMC] [Custom bbox...]

🌤  Weather snapshot (Open-Meteo)
   Cost: free · Rate: 10k/day · Cadence: every 6h
   Latest — Hanoi: ✅ done 1h ago (1 row)
   Latest — HCMC:  ✅ done 1h ago (1 row)
   [Trigger: Hanoi] [Trigger: HCMC]

[View all runs →]
```

Staleness indicator (cadence comparison):
- age < cadence         → green ✅
- cadence ≤ age < 2×cadence → yellow ⚠️
- age > 2×cadence       → red 🔴 "overdue"

### 3.2. Trigger modal

```
Trigger overpass_poi?

City: [Hanoi ▼]
Priority: [100]

☐ Custom bbox (advanced)
   min_lon: [...]  min_lat: [...]
   max_lon: [...]  max_lat: [...]

⚠ This will consume 1 Overpass API call (~2 min runtime).
  Last run: 2 days ago.

[Cancel]  [Enqueue]
```

### 3.3. Run detail `/admin/oohx/collectors/runs/{id}`

```
Collector run #42
────────────────

Collector:    overpass_poi
City:         Hanoi
Status:       🟢 done
Priority:     100
Retry count:  0

Result:
  Rows ingested:  3,472  (inserted: 1,200 · updated: 2,272)
  Bytes fetched:  620 KB
  Skipped:        45     (POI không thuộc category ta track)
  Parsed:         3,517
  API calls:      1

Timing:
  Requested:   2026-04-20 10:00:00 by admin@oohx.vn
  Started:     2026-04-20 10:00:05
  Finished:    2026-04-20 10:01:38
  Duration:    1m 33s

Params:
  { "bbox": null }   // null nghĩa là dùng default city bbox

Error:        —

[← Back to list]
```

---

## 4. Ops responsibilities (Data Engine side)

Cron đã thêm để auto-trigger:

```cron
# Drain collector queue mỗi 15 phút
*/15 * * * *  cd /home/oohx/apps/oohx-matrix/python-data-engine && \
    .venv/bin/python -m app.cli run-pending-collectors --max 5 \
    >> /home/oohx/logs/collectors.log 2>&1

# Weather refresh mỗi 6h (cho Hanoi + HCMC)
0 */6 * * *   cd /home/oohx/apps/oohx-matrix/python-data-engine && \
    .venv/bin/python -m app.cli collect-weather --city Hanoi \
    >> /home/oohx/logs/weather.log 2>&1
5 */6 * * *   cd /home/oohx/apps/oohx-matrix/python-data-engine && \
    .venv/bin/python -m app.cli collect-weather --city HCMC \
    >> /home/oohx/logs/weather.log 2>&1

# POI refresh weekly (Sunday 3-4am)
0 3 * * 0     cd /home/oohx/apps/oohx-matrix/python-data-engine && \
    .venv/bin/python -m app.cli collect-poi --city Hanoi \
    >> /home/oohx/logs/poi.log 2>&1
0 4 * * 0     cd /home/oohx/apps/oohx-matrix/python-data-engine && \
    .venv/bin/python -m app.cli collect-poi --city HCMC \
    >> /home/oohx/logs/poi.log 2>&1

# Cache cleanup weekly
30 4 * * 0    cd /home/oohx/apps/oohx-matrix/python-data-engine && \
    .venv/bin/python -m app.cli purge-collector-cache \
    >> /home/oohx/logs/collectors.log 2>&1
```

(DE ops sẽ setup sau khi verify collectors trên staging.)

---

## 5. Round-trip test (bạn có thể chạy ngay)

Trên Data Engine VPS:

```bash
cd ~/apps/oohx-matrix/python-data-engine
source .venv/bin/activate

# 1. Verify migration 008 applied
python -m app.cli init-db --sql-dir sql
psql -h 127.0.0.1 -U oohx -d oohx_data -c "\d source.pois"
# → phải thấy index uq_pois_osm_id

# 2. Install new dep
pip install -r requirements.txt

# 3. List collectors
python -m app.cli list-collectors

# 4. Sync weather Hanoi (quick, ~3s)
python -m app.cli collect-weather --city Hanoi
# Kỳ vọng: JSON với run_id, stats.inserted=1

# 5. Verify data
psql -h 127.0.0.1 -U oohx -d oohx_data -c "
SELECT city, observed_at, temperature_c, precipitation_mm, weather_code
FROM source.weather_snapshots
ORDER BY observed_at DESC LIMIT 5;"

# 6. (optional, slow) Sync POI Hanoi (~1-2 phút)
python -m app.cli collect-poi --city Hanoi
# Kỳ vọng: inserted + updated ≈ 3000-6000 rows

# 7. Verify
psql -h 127.0.0.1 -U oohx -d oohx_data -c "
SELECT category, COUNT(*)
FROM source.pois
WHERE source_name = 'overpass'
GROUP BY category
ORDER BY 2 DESC;"

# 8. Xem collectors status tổng
python -m app.cli collectors-status
```

---

## 6. Things Laravel team KHÔNG làm

- ❌ Không gọi Overpass / Open-Meteo trực tiếp từ Laravel — tốn API quota 2 lần, khó track.
- ❌ Không INSERT vào `source.pois` / `source.weather_snapshots` / `source.roads` — role `oohx_control` không có quyền, sẽ 403. Chỉ Python collector ghi được.
- ❌ Không UPDATE `collector_runs.stats` hoặc `status='done'` thủ công — worker own fields đó.
- ❌ Không retry job `failed` bằng cách duplicate row — set `retry_count=0, status='pending'` nếu muốn retry (hoặc tạo row mới).

---

## 7. Next — Phase 2.D preview

Sẽ ship sau:
- `osm_roads` collector (shell-out `osm2pgsql`, refresh monthly) — populate `source.roads` cho nearest-road enrichment với ~100k roads VN thay vì 5 rows sample hiện tại.
- `worldpop_population` collector (raster → H3 grid) — populate `source.population_grid` cho `population_factor`.
- Formula update: `weather_factor`, `seasonality_factor`, `calibration_factor` integrate vào `_estimate_outdoor` + `_estimate_indoor`.

Laravel guide 03 Phase 2.D chỉ cần **thêm 2 entry** vào `config/oohx_collectors.php` (metadata) — UI tự generate, không code mới.

---

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `overpass_poi` fails `HTTP 429` | rate-limited | Tự retry 3 lần với backoff. Nếu vẫn fail → đợi 10 phút rồi retry job. |
| `overpass_poi` fails `cannot determine bbox for city=X` | City không có default bbox + không có screen active ở city | Laravel UI validate trước: chỉ cho trigger nếu city có ≥ 1 screen hoặc có bbox override. |
| `open_meteo_weather` có data nhưng `temperature_c = NULL` | API trả `current.temperature_2m = null` (rare) | Tự handle — không crash. UI hiển thị "—". |
| rows_ingested = 0 nhưng status=done | bbox đúng nhưng region thật sự không có POI quan tâm | Rare — double check bbox bằng `leafletjs.com/examples/osm` để sanity. |
| run đứng ở `running` mãi | worker crash giữa chừng | Orphan detection: sau 30 phút, ops restart service hoặc `UPDATE collector_runs SET status='failed' WHERE status='running' AND started_at < NOW() - INTERVAL '30m';` |

---

## 9. Production metrics (2026-04-20 Hanoi smoke test)

Verified trên VPS:

| Collector | City | Endpoints | Tiles | Duration | Rows | Bytes |
|---|---|---|---|---|---|---|
| `open_meteo_weather` | Hanoi | 1 | — | **1.0s** | 1 | 517 B |
| `overpass_poi` | Hanoi | kumi.systems (primary) | 9 (0.10°) | **2.5s** | 12,564 POIs | 4.7 MB |

Impact lên estimate (outdoor screens dense area):
- OD-001 Hoàn Kiếm: POI 5 → 72, `daily_impr` 882 → 1,445 (+64%)
- OD-002 Ba Trieu: POI 9 → 314 (capped 1.8), `daily_impr` 916 → 1,512 (+65%)
- Indoor unchanged (công thức indoor không dùng POI count, chỉ venue_footfall)

Overpass endpoint strategy:
- Default `overpass.kumi.systems` — nhanh hơn `overpass-api.de` ~50x cho Hanoi bbox
- Auto-fallback 3 mirrors nếu primary fail
- Cache 24h → re-run cùng tile trong ngày = 0 API call

## 10. Changelog

| Ngày | Change |
|---|---|
| 2026-04-20 | Phase 2.C backend shipped: migration 008, collectors/ module, 2 collectors (overpass_poi, open_meteo_weather), CollectorRunner + CLI. Handoff doc này. |
| 2026-04-20 | Hotfix: auto tile-split 0.10°, multi-endpoint fallback (kumi.systems default), server timeout 90s, client timeout 240s. Smoke test pass 12,564 POIs trong 2.5s. |

---

*Handoff owner: Data Engine tech lead. Slack `#oohx-dataengine` khi start build.*
