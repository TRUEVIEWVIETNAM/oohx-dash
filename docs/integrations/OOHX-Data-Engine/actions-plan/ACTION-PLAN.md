# OOHX Data Engine — Action Plan (Laravel side)

> **Role of this document**: action plan cho team Laravel triển khai Phase 2 của OOHX Data Engine integration. Mỗi task có owner, prerequisite, deliverable, acceptance criteria rõ để builder pick up không cần re-read toàn bộ proposal.
>
> **Ordering rationale**: theo đề xuất architect, phase ship được độc lập, parallel tracks khi có thể. Không chờ tất cả Python side xong mới build Laravel.
>
> **Reference docs** (đọc khi cần):
> - [OOHX-DATA-ENGINE-ARCHITECTURE.md](../OOHX-DATA-ENGINE-ARCHITECTURE.md) — kiến trúc hiện tại
> - [OOHX-UPGRADE-PROPOSAL.md](../OOHX-UPGRADE-PROPOSAL.md) — proposal Phase 2 đầy đủ
> - [oohx-matrix-integration.md](../oohx-matrix-integration.md) — Phase 1 integration đã xong
> - [laravel-integration/01..05](../laravel-integration/) — 5 guide chi tiết per feature

---

## 0. Status hiện tại (2026-04-20)

Phase 1 **đã xong end-to-end**:
- ✅ Laravel outbound (`oohx:sync-to-engine` + scheduler 30m)
- ✅ Data Engine ingest + enrich + estimate (83 screens Hanoi/HCMC)
- ✅ Cron recompute mỗi 10 phút + nightly city backfill
- ✅ Laravel inbound read qua SSH tunnel, role `oohx_readonly`
- ✅ Filament admin: Data Engine control page + Traffic Estimates Resource + Screen detail estimate section
- ✅ Frontpage + admin Insights layered (DE > owner → fallback Phase 1 AI)

Phase 2 **chưa build**. Action plan dưới đây là cho Phase 2.

---

## 1. Phase ordering (executive)

```
┌──── Week 0 ────┐    ┌────── Week 1-2 ──────┐    ┌─── Week 3 ───┐    ┌── Week 4-5 ──┐    ┌── Week 6-7 ──┐
│  Prep & Ops    │ => │ Track A: Guide 01    │ => │  Guide 02    │ => │  Guide 03    │ => │  Guide 03 pt2│
│  (ops-led)     │    │ Track B: Guides 04,05│    │  (Jobs)      │    │  (Collectors)│    │  + calib     │
│                │    │ (parallel, 2 devs)   │    │              │    │ pt1: POI+wx  │    │              │
└────────────────┘    └──────────────────────┘    └──────────────┘    └──────────────┘    └──────────────┘
```

**Lý do ordering này**:
- **Week 0**: role `oohx_control` + migration SQL là BLOCKER cho mọi thứ write — làm sớm.
- **Week 1-2 parallel**: Guide 01 cần write trên `config.*` → cần `oohx_control`. Guides 04+05 chỉ read → không cần, ship trước được khi có 2 dev. Nếu 1 dev → làm 01 trước, 04+05 sau.
- **Week 3**: Guide 02 (Jobs) cần `oohx_control` nhưng depend 01 hoàn tất để test published version trigger recompute.
- **Week 4-5**: Guide 03 (Collectors UI) phụ thuộc Python team ship `Collector` base class + 2 collector đầu (POI + weather).
- **Week 6-7**: hoàn thiện 4 collector còn lại + calibration — Python-heavy, Laravel chủ yếu hiển thị.

**Tổng effort Laravel**: ~4-5 tuần (1 dev full-time) hoặc ~3 tuần (2 dev parallel).

---

## 2. Phase 0 — Prep & Ops (ngoài Laravel, 2-3 ngày)

**Owner**: Data Engine ops (Python team) + DBA.
**Không có task Laravel trong phase này, nhưng phải xong trước khi Laravel bắt đầu Week 1**.

### TASK-0.1: Run SQL migrations trên Data Engine VPS
- **Owner**: Data Engine ops
- **Prerequisite**: Access SSH + superuser postgres trên Data Engine VPS
- **Deliverable**:
  - Apply `sql/006_config_tables.sql` (6 tables schema `config.*`)
  - Apply `sql/007_collectors_tables.sql` (2 tables schema `collectors.*`)
  - `ALTER source.*` thêm `weather_snapshots`, `population_grid`, `traffic_samples`
  - `ALTER metrics.screen_context_metrics` thêm `weather_factor`, `seasonality_factor`, `calibration_factor`
  - `ALTER output.screen_traffic_estimates` thêm `formula_version_id BIGINT`
- **Acceptance**:
  - `\dt config.*` và `\dt collectors.*` list đúng các bảng
  - CHECK constraints fire khi insert giá trị invalid (vd `road_class_multipliers.multiplier = 10` phải fail)
- **Complexity**: S (1-2 giờ)

### TASK-0.2: Tạo role `oohx_control` + grant
- **Owner**: DBA / Data Engine ops
- **Deliverable**: SQL chạy bên VPS:
  ```sql
  CREATE ROLE oohx_control LOGIN PASSWORD '<strong-pwd>';
  GRANT USAGE ON SCHEMA core, config, collectors, output, metrics, source TO oohx_control;
  GRANT SELECT ON ALL TABLES IN SCHEMA core, output, metrics, source, collectors, config TO oohx_control;
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
  GRANT USAGE ON ALL SEQUENCES IN SCHEMA core, collectors, config TO oohx_control;
  ALTER DEFAULT PRIVILEGES IN SCHEMA core, collectors, config
      GRANT USAGE ON SEQUENCES TO oohx_control;
  ```
- **Acceptance**:
  - `psql -h 127.0.0.1 -p 5433 -U oohx_control -d oohx_data -c "SELECT 1"` qua tunnel OK
  - `oohx_control` có thể INSERT vào `core.recompute_jobs` nhưng KHÔNG DELETE `output.*`
- **Deliver**: Password gửi Laravel team qua kênh bảo mật (password manager)
- **Complexity**: S (30 phút)

### TASK-0.3: Python side — `config_loader.py` + `seed-config-from-code`
- **Owner**: Data Engine dev (Python)
- **Prerequisite**: TASK-0.1 done
- **Deliverable**:
  - `app/config_loader.py` — load active `TrafficConfig` từ DB, fallback về hardcode nếu DB rỗng
  - `app/repositories/config.py` — CRUD repository cho 6 bảng `config.*`
  - CLI `python -m app.cli seed-config-from-code` — one-shot backfill DB từ `app/config.py` hardcode hiện tại
  - Update `TrafficEstimationService` đọc từ `get_traffic_config()` (wrapper mới, cache TTL 5 phút)
- **Acceptance**:
  - Chạy `seed-config-from-code` trên VPS rỗng → 6 bảng `config.*` có data khớp hardcode
  - Đổi `config.road_class_multipliers.multiplier` cho `highway` từ 2.5 → 2.7, chạy `recompute-screen`, verify estimate thay đổi
- **Complexity**: M (3-4 ngày)
- **BLOCKER note**: Laravel Guide 01 phụ thuộc task này. Python phải seed DB xong trước khi Laravel UI vào edit.

---

## 3. Phase 2.A — Formula Config Management (Guide 01)

**Week 1-2 Track A**, owner **Laravel team**, ~3-5 ngày.
**Ref doc**: [laravel-integration/01-formula-config-management.md](../laravel-integration/01-formula-config-management.md)

### TASK-01.1: Add `oohx_control` DB connection
- **Prerequisite**: TASK-0.2 (password available)
- **Deliverable**:
  - `.env`: thêm `DB_OOHX_CONTROL_USERNAME`, `DB_OOHX_CONTROL_PASSWORD`
  - `config/database.php`: connection `oohx_control` (pgsql, cùng host/port `oohx`, khác user, `search_path: config,core,collectors,output,public`)
  - Test connect: `php artisan tinker --execute='DB::connection("oohx_control")->selectOne("SELECT 1 as ok");'`
- **Acceptance**: Select OK, không throw driver/permission error
- **Complexity**: S (30 phút)

### TASK-01.2: Eloquent models cho `config.*`
- **Path**: `app/Models/Oohx/Config/`
- **Deliverable**: 6 models
  - `BaseCityTraffic` (PK `city`, string, no timestamps — cột `updated_at` tự PostgreSQL set)
  - `RoadClassMultiplier` (PK `road_class`)
  - `ZoneFactor` (PK `zone_type`)
  - `DeliveryDefault` (PK `key`)
  - `FormulaVersion` (auto-increment id, cast `snapshot JSON`, `is_active boolean`)
  - `AuditLog` (append-only; provide static `log(actor, action, target, old, new, note)` method)
- **Pattern**: tất cả `protected $connection = 'oohx_control';`, `$table = 'config.base_city_traffic'`...
- **Acceptance**: `BaseCityTraffic::all()` return collection từ `config.base_city_traffic`
- **Complexity**: S (1 giờ)

### TASK-01.3: `ConfigManagerService`
- **Path**: `app/Services/Oohx/ConfigManagerService.php`
- **Methods**:
  - `listCoefficients(): array` — return 4 nhóm keyed by group name
  - `updateCoefficient(string $group, string $key, array $data, User $actor): void` — validate range + audit log + DB update (1 transaction)
  - `publishVersion(string $tag, string $description, User $actor, bool $activate = false): FormulaVersion` — snapshot tất cả config.* thành JSONB, insert `formula_versions`, optional activate
  - `activateVersion(FormulaVersion $version, User $actor): void` — atomic switch: UPDATE cũ `is_active=false`, UPDATE mới `is_active=true` (có unique index đảm bảo max 1 active)
  - `diffVersions(FormulaVersion $a, FormulaVersion $b): array` — return key-by-key diff
- **Acceptance**:
  - Update coefficient khớp CHECK constraint (multiplier 0 < x <= 5)
  - Publish version 2 lần với cùng tag → throw (unique violation)
  - Activate version mới → cũ deactivated tự động
  - Mọi UPDATE tạo row trong `config.audit_log`
- **Complexity**: M (1-2 ngày)

### TASK-01.4: Filament Resources
- **Filament-native**: tạo 4 Resources + 1 Page
  - `OohxConfig/BaseCityTrafficResource` — list + edit inline (no create modal — chỉ seed được mới hiện)
  - `OohxConfig/RoadClassMultiplierResource` — tương tự
  - `OohxConfig/ZoneFactorResource`
  - `OohxConfig/DeliveryDefaultResource`
  - `OohxConfig/FormulaVersionResource` — list + View (show snapshot JSON + diff) + Action "Publish new version" (form tag+description+activate checkbox) + Action "Activate"
- **Navigation group**: "OOHX · Data Engine" (nhóm riêng, không lẫn System Settings)
- **Pattern**: extend existing read-only Resource pattern (OohxEstimateResource) cho các Resource read-heavy; thêm `canEdit=true` cho 4 coefficient tables
- **Validation**: `->rules(['numeric', 'min:0', 'max:5'])` trên multiplier form field — khớp DB CHECK
- **Acceptance**:
  - Ops vào UI edit `highway = 2.7`, save → DB cập nhật + audit log entry
  - Publish version v-2026-05-15 + activate → cũ deactivated, `output.*` lần recompute tiếp theo ghi `formula_version_id` mới
- **Complexity**: M (2-3 ngày)

### TASK-01.5: Audit log viewer
- **Filament Resource read-only**: `OohxConfig/AuditLogResource`
- **Table columns**: actor, action (badge), target, old_value (truncated), new_value (truncated), note, created_at (since)
- **Filters**: actor (Select), action (Select), date range
- **View page**: full JSON diff highlight (có thể dùng `<pre>` trong ViewEntry)
- **No create/edit** — append-only
- **Acceptance**: Thấy tất cả entries từ TASK-01.3 updates
- **Complexity**: S (2-3 giờ)

---

## 4. Phase 2.A — Track B (parallel, nếu có 2 devs)

Nếu chỉ 1 dev, **skip track B tuần 1-2, làm sau Guide 01**. Nếu có 2 dev, làm parallel.

### Guide 05 — Monitoring Dashboard (S, 1-2 ngày)
**Ref**: [laravel-integration/05-monitoring-dashboard.md](../laravel-integration/05-monitoring-dashboard.md)

### TASK-05.1: `MonitoringDashboardService`
- **Path**: `app/Services/Oohx/MonitoringDashboardService.php`
- **Method**: `snapshot(): array` aggregating 5 query sets:
  - Freshness: `COUNT(*) screens / COUNT(estimates) / avg age of last_calculated_at`
  - Jobs: `GROUP BY status` counts, failed 7 ngày qua
  - Collectors: latest run per collector, staleness vs cadence config
  - Formula: active version, coverage % of screens using active version
  - Alerts: derived list với severity (never_estimated, stale >24h, pending >100, fail_rate >5%, collector overdue)
- **Cache**: `Cache::remember('oohx:dashboard:snapshot', 60, ...)` — 60s TTL, không realtime
- **Acceptance**: Return dict có đủ 5 nhóm, `computeAlerts()` return array sorted by severity
- **Complexity**: S (4-6 giờ)

### TASK-05.2: Filament Page `OohxMonitoring`
- **Path**: `app/Filament/Pages/OohxMonitoring.php`
- **Pattern**: tương tự `OohxDataEngine` page hiện có
- **Layout**:
  - Alert banner trên cùng (nếu có alerts, severity-coded)
  - 4 metric card groups: freshness/coverage, job queue, collector status, formula coverage
  - Auto-refresh 60s (Filament poll hoặc Livewire `wire:poll.60s`)
- **Color thresholds**: coverage ≥99% green, 90-99% yellow, <90% red; fail_rate <1% green, 1-5% yellow, >5% red
- **Acceptance**:
  - Load page hiện stats đúng với DB state
  - Tạo 1 failed job → banner alert xuất hiện trong 60s
- **Complexity**: S (4-6 giờ)

### Guide 04 — Screen Context Inspector (S, 1-2 ngày)
**Ref**: [laravel-integration/04-screen-context-inspector.md](../laravel-integration/04-screen-context-inspector.md)

### TASK-04.1: `ScreenContextMetrics` model
- **Path**: `app/Models/Oohx/ScreenContextMetrics.php`
- **Connection**: `oohx` (readonly), table `metrics.screen_context_metrics`, PK `screen_id`
- **Casts**: `context_tags JSON`, numeric factors as float
- **Relationship**: `screen()` → `Oohx\Screen`
- **Complexity**: S (30 phút)

### TASK-04.2: `ScreenInspectorService`
- **Path**: `app/Services/Oohx/ScreenInspectorService.php`
- **Method**: `inspect(string $externalId): array` — 1 query load screen+metrics+estimate+weather snapshot+active formula version
- **Method**: `buildBreakdown(array $context): array` — mirror Python formula logic
  - Outdoor: 11 steps (base × road × lane × intersection × poi × population → passby; × vis × dir → OTS; × sov × dwell → impressions)
  - Indoor: 7 steps (venue_footfall × zone → flow; × vis × dir → OTS; × sov × dwell → impressions)
- **Acceptance**: Output có đủ các step, formula math verify đúng với DB values
- **Complexity**: S (1 ngày)

### TASK-04.3: Add Inspector view page vào existing `OohxEstimateResource`
- **Không tạo Resource mới** — tái sử dụng `OohxEstimateResource`, thêm Action "Inspect context" ở view page trigger route riêng
- **Route**: `/admin/oohx-estimates/{externalId}/inspect` (custom route via Resource page hoặc Filament custom Page)
- **View**: 7 panels — estimate summary, breakdown steps, road, POI counts, venue, weather, context tags
- **Action "Re-enqueue"**: gọi `JobOrchestrator::enqueueScreen()` (sẽ build ở Guide 02)
- **Acceptance**:
  - Load <100ms với screen có data đầy đủ
  - Warning banner nếu `last_calculated_at` > 24h hoặc `formula_version_id` != active
- **Complexity**: S (1 ngày)

---

## 5. Phase 2.B — Jobs Orchestration (Guide 02)

**Week 3**, owner **Laravel team**, ~3-5 ngày.
**Ref doc**: [laravel-integration/02-jobs-orchestration.md](../laravel-integration/02-jobs-orchestration.md)

### TASK-02.1: `RecomputeJob` model
- **Path**: `app/Models/Oohx/RecomputeJob.php`
- **Connection**: `oohx_control`, table `core.recompute_jobs`
- **Scopes**: `pending()`, `processing()`, `failed()`, `done()`
- **Casts**: `payload JSON`, timestamps
- **Helper**: `getDurationSecondsAttribute()` — diff started_at/finished_at
- **Complexity**: S (30 phút)

### TASK-02.2: `JobOrchestrator` service
- **Path**: `app/Services/Oohx/JobOrchestrator.php`
- **Methods**:
  - `enqueueScreen(string $screenId, int $priority = 100, User $actor): RecomputeJob`
  - `enqueueCity(string $city, int $priority = 200, User $actor): RecomputeJob`
  - `enqueueBulk(array $payload, User $actor): RecomputeJob` — `job_type='bulk'`, `payload` free-form JSON
  - `retry(RecomputeJob $job, User $actor): void` — set `status='pending'`, `retry_count = 0`, `error_message = null`
  - `cancel(RecomputeJob $job, User $actor): void` — chỉ cho cancel status `pending`, set `status='cancelled'`
  - `countsByStatus(): array` — quick query
- **Transactional**: mọi write trong transaction; audit log (reuse AuditLog model từ Guide 01)
- **Acceptance**:
  - Enqueue screen → row xuất hiện với `status=pending`; sau ≤ 10 phút, Python cron drain → `status=done`
  - Retry failed → row `status=pending` lại
  - Cancel pending → row `status=cancelled`, không bị Python pick nữa
- **Complexity**: M (1 ngày)

### TASK-02.3: Filament Resource `OohxRecomputeJob`
- **Path**: `app/Filament/Resources/OohxRecomputeJobResource.php`
- **Features**:
  - Table: id, job_type (badge), screen_id/city (display based on type), status (color badge), priority, retry_count, requested_at (since), duration
  - Filters: status (Select), job_type, date range, retry_count > 0
  - Actions per row:
    - View detail (error_message, payload, timestamps)
    - "Retry" (cho failed, confirm modal)
    - "Cancel" (cho pending, confirm modal)
  - Bulk actions: Retry selected failed, Cancel selected pending
- **Navigation**: "OOHX · Data Engine" group
- **Badge**: show pending count (từ `countsByStatus`)
- **Auto-refresh**: Livewire `wire:poll.10s` cho header counter, 30s cho table
- **Acceptance**:
  - Enqueue từ Inspector (Guide 04 re-enqueue button) → job xuất hiện ở Jobs list
  - Retry failed job → sau 10 phút status chuyển done
- **Complexity**: M (1-2 ngày)

### TASK-02.4: Integration với Guide 01 Publish flow
- **Sau khi TASK-01.4 "Activate version"** → optionally enqueue `recompute-all` job để apply formula mới cho toàn bộ screens
- **UX**: modal confirm "Activate v2 và recompute X screens?" với option "Just activate, recompute manually later"
- **Acceptance**: Activate + recompute chọn → 1 job type `bulk` với payload `{action: "recompute_all", formula_version_id: N}` xuất hiện
- **Complexity**: S (2 giờ)

---

## 6. Phase 2.C — Collectors Admin (Guide 03)

**Week 4-5**, owner **Laravel team** (cần Python team ship trước), ~2-3 ngày Laravel work.
**Ref doc**: [laravel-integration/03-collectors-admin.md](../laravel-integration/03-collectors-admin.md)

### TASK-0-python-collectors: Python side ship Collector base + 2 collectors
- **Owner**: Data Engine dev (Python)
- **BLOCKER cho Laravel Guide 03**
- **Deliverable**:
  - `app/collectors/base.py` — abstract `Collector` class với 3 hook: `plan()`, `fetch()`, `persist()`
  - `app/collectors/overpass_poi.py` — fetch POI qua Overpass API, upsert `source.pois`
  - `app/collectors/open_meteo_weather.py` — fetch weather, insert `source.weather_snapshots`
  - `app/collectors/runner.py` — drain `collectors.collector_runs` với `SKIP LOCKED`
  - CLI: `collect-poi`, `collect-weather`, `run-pending-collectors`
  - Cron: `*/15 * * * * run-pending-collectors --max 5`
- **Acceptance**:
  - `collect-poi --city Hanoi` chạy → `source.pois` tăng rows, `collectors.collector_runs` ghi stats
  - Laravel INSERT `{collector_name:"overpass_poi", city:"Hanoi", status:"pending"}` → ≤15 phút sau status `done`
- **Complexity**: M (5-7 ngày Python)

### TASK-03.1: `CollectorRun` model + config metadata
- **Path**: `app/Models/Oohx/CollectorRun.php` (connection `oohx_control`)
- **Config file**: `config/oohx_collectors.php` — hardcoded metadata cho 4 collector:
  ```php
  return [
      'overpass_poi' => [
          'display_name' => 'OpenStreetMap POI (Overpass)',
          'cadence_hours' => 168, // weekly
          'supports_city' => true,
          'supports_bbox' => true,
          'rate_limit' => '10k queries/day',
          'cost' => 'free',
      ],
      // open_meteo_weather, osm_roads, worldpop_population...
  ];
  ```
- **Acceptance**: `config('oohx_collectors')` return 4 entries
- **Complexity**: S (30 phút)

### TASK-03.2: `CollectorManager` service
- **Path**: `app/Services/Oohx/CollectorManager.php`
- **Methods**:
  - `listCollectors(): array` — merge config metadata + latest run per collector
  - `trigger(string $collector, ?string $city, ?array $bbox, User $actor): CollectorRun` — INSERT pending row
  - `cancel(CollectorRun $run, User $actor): void` — pending only
  - `latestByCollector(): array` — keyed by collector_name, per city breakdown
- **Acceptance**: Trigger insert row, cancel fails nếu run đã started
- **Complexity**: S (4 giờ)

### TASK-03.3: Filament Page + Resource
- **Page `OohxCollectors`**: overview grid hiện 4 collector, mỗi card show latest run per city + cadence staleness indicator
- **Resource `OohxCollectorRunResource`** (read mostly): history list với filters collector/city/status, detail view
- **Actions**:
  - "Trigger run" modal: collector select + city (optional) + bbox (optional)
  - "Cancel" cho pending runs
- **Acceptance**: Trigger POI Hanoi → sau ≤15 phút hiện `status=done` + `rows_ingested > 0`
- **Complexity**: M (1 ngày)

---

## 7. Phase 2.D — Remaining collectors + calibration

**Week 6-7**, mostly Python work. Laravel side minor updates.

### TASK-0-python-collectors-pt2: Python side còn lại
- **Owner**: Data Engine dev
- **Deliverable**:
  - `osm_roads` collector (shell-out osm2pgsql được)
  - `worldpop_population` collector
  - Formula mới integrate `weather_factor`, `seasonality_factor`, `calibration_factor` vào `_estimate_outdoor`
  - CLI `traffic-samples-ingest --file ...`
- **Complexity**: M (5-7 ngày Python)

### TASK-04.4: Update Inspector hiển thị factors mới
- **Update**: `ScreenInspectorService::buildBreakdown()` thêm các step `× weather_factor × seasonality_factor × calibration_factor`
- **UI**: thêm 3 step vào breakdown panel
- **Complexity**: S (2 giờ)

### TASK-05.3: Update Dashboard với weather/calibration freshness
- **Update**: `MonitoringDashboardService::snapshot()` thêm nhóm "Data sources freshness" — weather last fetch per city, traffic_samples count, population year
- **Complexity**: S (2-3 giờ)

---

## 8. Cross-cutting checklist

Builder **phải làm đầy đủ** cho tất cả phases:

### Code quality
- [ ] Tất cả Models đặt trong `app/Models/Oohx/` (namespace riêng tách với MySQL models)
- [ ] Services đặt trong `app/Services/Oohx/`
- [ ] Dùng connection `oohx_control` cho write, `oohx` cho read-only — **không bao giờ mix trong cùng query**
- [ ] Mọi write lên `config.*` + `core.recompute_jobs` + `collectors.collector_runs` phải có audit log entry (AuditLog model từ Guide 01)

### Filament compliance (theo CLAUDE.md)
- [ ] Dùng Filament Resource + Page pattern, không custom Livewire
- [ ] Actions dùng `requiresConfirmation()`, không `wire:confirm`/browser confirm
- [ ] Navigation group: "OOHX · Data Engine" (gộp tất cả features)
- [ ] Badge count ở navigation cho relevant Resources (pending jobs, failed runs, stale estimates)

### Security
- [ ] Validation range khớp DB CHECK constraint (vd multiplier 0 < x <= 5) — double-check fail-safe
- [ ] Escape shell args khi trigger remote command (đã làm với `escapeshellarg()` cho recompute city)
- [ ] SSH key permissions 600, file path trong `storage/app/oohx-ssh/` (đã tránh `/root/` open_basedir)
- [ ] Password `oohx_control` chỉ super_admin role xem được UI edit config — thêm policy check

### Testing
- [ ] Migration test: `php artisan migrate:fresh` không fail (Laravel migrations không đụng `oohx_*` schemas)
- [ ] Smoke test end-to-end mỗi phase: ops đổi coefficient → enqueue recompute → estimate mới
- [ ] Rollback test: activate version cũ → recompute → estimate revert đúng giá trị cũ

### Documentation
- [ ] Cập nhật `CLAUDE.md` nếu thêm convention mới (vd "OOHX Data Engine configs luôn qua oohx_control connection")
- [ ] README cho Filament nav group giải thích phân biệt các Resources OOHX

---

## 9. Open questions phải chốt trước kick-off

Từ proposal §8 + issues phát sinh trong Phase 1:

1. **Password distribution cho `oohx_control`** — ai lưu, lưu đâu (1Password/Bitwarden team vault)?
2. **Preview impact của version mới trước activate** — có cần dry-run trên 100 screens mẫu không? (Phase 2.A cuối có thể add)
3. **Weather granularity** — per-city 4 điểm/ngày OK? Hay cần per-screen?
4. **Publish permission** — `super_admin` + `data_ops` role only? Cần define role `data_ops` mới?
5. **Recompute-all job latency** — khi publish version + activate + recompute-all 83 screens, user có đợi progress realtime không? Hay chỉ cần "queued, check Jobs page"?
6. **Navigation group name** — "OOHX · Data Engine" hay "Data Engine" ngắn gọn? Theo existing "System Settings" pattern hay tách riêng?

---

## 10. Success criteria Phase 2 (full)

Kết thúc toàn bộ Phase 2.A + 2.B + 2.C + 2.D:

- [ ] Ops thay `highway = 2.5 → 2.7` qua Filament UI, click "Activate + Recompute All", thấy estimate mới trong ≤15 phút
- [ ] Publish formula version mới + activate, xem audit log ai đổi gì
- [ ] Trigger `collect-poi` Hanoi từ Filament UI, xem progress, confirm `source.pois` có ≥ 5000 rows
- [ ] Inspector 1 screen hiện: nearest road, 7 POI count theo category, venue (nếu indoor), factor resolved từng bước, công thức breakdown
- [ ] Monitoring dashboard hiện: coverage %, avg age, failed jobs 24h, collector staleness
- [ ] Không tăng RAM peak Data Engine VPS quá 800MB
- [ ] Không phát sinh FastAPI/Celery/Redis
- [ ] Laravel test suite pass (100% migration tests)
- [ ] Filament UI không browser confirm, tất cả Resources native

---

## 11. Kickoff checklist cho Builder

Trước khi bắt đầu bất kỳ task nào, builder confirm:

- [ ] Đã đọc [oohx-matrix-integration.md](../oohx-matrix-integration.md) (Phase 1) — hiểu connection `oohx` đã có
- [ ] Đã đọc [OOHX-UPGRADE-PROPOSAL.md](../OOHX-UPGRADE-PROPOSAL.md) §1-5 — hiểu big picture Phase 2
- [ ] Đã đọc guide chi tiết của task đang làm (01/02/03/04/05)
- [ ] Confirm TASK-0.1 + TASK-0.2 + TASK-0.3 đã xong (nếu làm phase 2.A-D)
- [ ] Có password `oohx_control` trong hand
- [ ] `.env` local có `DB_OOHX_CONTROL_*` set (hoặc đã skip tasks cần write)

---

## 12. Version history của action plan này

| Ngày | Version | Thay đổi |
|---|---|---|
| 2026-04-20 | 1.0 | Initial draft, architect proposal dựa trên UPGRADE-PROPOSAL + 5 laravel-integration guides |

---

*Document owner: architect team. Update khi có change trong scope hoặc ordering.*
