# Phase 2.A — Data Engine Handoff for Laravel Team

> **Role of this document**: handoff bàn giao phần **Data Engine đã ship** cho team Laravel bắt đầu Phase 2.A (Formula & Config Management UI).
> **Status**: Phase 0 (prep migrations + role) + Phase 2.A backend Python đã xong và tested. Laravel team có thể start ngay.
> **Reference docs**:
> - [01-formula-config-management.md](01-formula-config-management.md) — spec gốc Laravel side
> - [DATA-ENGINE-ACTION-PLAN.md](../DATA-ENGINE-ACTION-PLAN.md) — plan DE side (task breakdown)
> - [OOHX-UPGRADE-PROPOSAL.md](../OOHX-UPGRADE-PROPOSAL.md) — proposal lớn

---

## 1. What's shipped on Data Engine side

### 1.1. SQL migrations (applied on Data Engine VPS)

| File | Nội dung |
|---|---|
| [`python-data-engine/sql/006_config_tables.sql`](../../python-data-engine/sql/006_config_tables.sql) | Schema `config.*` — 6 bảng: `base_city_traffic`, `road_class_multipliers`, `zone_factors`, `delivery_defaults`, `formula_versions`, `audit_log` + partial unique index `(is_active)` + triggers `updated_at` |
| [`python-data-engine/sql/007_collectors_and_sources.sql`](../../python-data-engine/sql/007_collectors_and_sources.sql) | Schema `collectors.*` + extensions vào `source/metrics/output` + status `'cancelled'` cho `recompute_jobs` |

Laravel team **không cần** chạy lại migration — ops Data Engine đã apply.

### 1.2. Python backend

Toàn bộ code Python đã update để đọc coefficient từ DB thay vì hardcode:

- **[`app/config.py`](../../python-data-engine/app/config.py)** — `TrafficConfig` có thêm 3 metadata field: `version_id`, `version_tag`, `source` (`"active_version"` | `"tables"` | `"hardcoded"`)
- **[`app/config_loader.py`](../../python-data-engine/app/config_loader.py)** — loader với TTL cache 5 phút + fallback chain 3 tier
- **[`app/repositories/config.py`](../../python-data-engine/app/repositories/config.py)** — CRUD cho `config.*` tables (INSERT/UPDATE/UPSERT/audit)
- **[`app/services/traffic_estimation.py`](../../python-data-engine/app/services/traffic_estimation.py)** — estimate giờ ghi `model_version` = tag + `formula_version_id` vào `output.*`

### 1.3. New CLI commands

| Command | Purpose |
|---|---|
| `python -m app.cli seed-config-from-code [--force] [--tag <tag>]` | One-shot: backfill `config.*` từ hardcoded defaults + publish version active |
| `python -m app.cli show-active-config` | In ra TrafficConfig đang được Python dùng (source + version) |
| `python -m app.cli publish-config-version --tag <tag> [--description "..."] [--activate]` | Snapshot `config.*` → insert `formula_versions` row |
| `python -m app.cli activate-config-version --tag <tag>` | Switch active version atomically |
| `python -m app.cli list-config-versions [--limit N]` | List N versions mới nhất |
| `python -m app.cli show-config-version --tag <tag>` | Dump snapshot JSON của 1 version |
| `python -m app.cli audit-log-tail [--limit N]` | Tail audit log |

### 1.4. Role `oohx_control`

Ops đã chạy [`scripts/create_oohx_control_role.sh`](../../python-data-engine/scripts/create_oohx_control_role.sh), role có quyền:

| Schema / Table | Quyền |
|---|---|
| `config.*` (tất cả bảng ngoại trừ `audit_log`) | SELECT, INSERT, UPDATE, DELETE |
| `config.audit_log` | SELECT, INSERT (**không UPDATE/DELETE** — append-only) |
| `core.recompute_jobs` | SELECT, INSERT, UPDATE |
| `collectors.collector_runs` | SELECT, INSERT, UPDATE |
| Các bảng khác (`output.*`, `core.screens`, `metrics.*`, `source.*`) | SELECT |
| `output.*`, `metrics.*`, `source.*` | **KHÔNG có INSERT/UPDATE/DELETE** — Laravel tuyệt đối không ghi vào đây |

**Password** `oohx_control` đã được share qua 1Password team vault với key `OOHX / oohx_control PG pwd`. Nếu không tìm thấy → báo Data Engine ops.

---

## 2. Connection setup (Laravel side — Laravel team phải làm)

### 2.1. `.env`

Thêm 2 dòng mới vào Laravel `.env` (connection `oohx` read-only hiện tại giữ nguyên):

```env
DB_OOHX_CONTROL_USERNAME=oohx_control
DB_OOHX_CONTROL_PASSWORD=<paste-từ-1Password>
```

SSH tunnel hiện tại tái dùng (cùng port 5433).

### 2.2. `config/database.php`

Thêm connection `oohx_control` bên cạnh `oohx`:

```php
'oohx_control' => [
    'driver'      => 'pgsql',
    'host'        => env('DB_OOHX_HOST', '127.0.0.1'),
    'port'        => env('DB_OOHX_PORT', 5433),
    'database'    => env('DB_OOHX_DATABASE', 'oohx_data'),
    'username'    => env('DB_OOHX_CONTROL_USERNAME', 'oohx_control'),
    'password'    => env('DB_OOHX_CONTROL_PASSWORD', ''),
    'charset'     => 'utf8',
    'prefix'      => '',
    'schema'      => 'config',
    'sslmode'     => 'prefer',
    'search_path' => 'config,core,collectors,output,public',
],
```

### 2.3. Verify connection (bắt buộc làm TRƯỚC khi build code)

```bash
cd /path/to/laravel-app
php artisan config:clear

php artisan tinker
>>> use Illuminate\Support\Facades\DB;
>>> DB::connection('oohx_control')->selectOne('SELECT current_user, current_database()');
// Expect: { current_user: 'oohx_control', current_database: 'oohx_data' }

>>> DB::connection('oohx_control')->selectOne('SELECT COUNT(*) AS n FROM config.road_class_multipliers');
// Expect: { n: 10 }  (10 road classes đã được seed)
```

Nếu bất kỳ query nào fail, **stop** và báo ops. Không debug mò.

---

## 3. DB state Laravel team sẽ làm việc với

### 3.1. `config.*` đã được seed

Data Engine ops đã chạy:
```bash
python -m app.cli seed-config-from-code --tag mvp-1.0
```

Expected state:

```sql
SELECT * FROM config.base_city_traffic;
-- 8 rows: Hanoi=8000, HCMC=10000, Da Nang=5000, default=6000, ...

SELECT * FROM config.road_class_multipliers;
-- 10 rows: highway=2.5, primary=2.0, secondary=1.5, ...

SELECT * FROM config.zone_factors;
-- 8 rows: entrance=1.00, escalator=0.85, ...

SELECT * FROM config.delivery_defaults;
-- 11 rows: visibility_outdoor=0.25, share_of_voice=0.125, ...

SELECT id, tag, is_active, created_by FROM config.formula_versions;
-- 1 row: id=1, tag='mvp-1.0', is_active=true, created_by='cli:<ops>'
```

### 3.2. Existing `output.screen_traffic_estimates` đã có `formula_version_id`

Cột mới `formula_version_id BIGINT REFERENCES config.formula_versions(id)`:
- Rows cũ (từ Phase 1) = `NULL` → Laravel hiển thị "legacy (unknown version)" ở Inspector
- Rows mới (sau khi recompute) = `1` (pointing to mvp-1.0)

Tuỳ chọn: ops có thể chạy `python -m app.cli recompute-city --city Hanoi` (và HCMC) để backfill `formula_version_id` cho tất cả existing rows.

---

## 4. Conceptual model — làm sao Laravel và Python "đồng bộ"

```
┌────────────────────────┐     UPSERT       ┌────────────────────────┐
│ Laravel admin          │ ───── (write) ─► │ config.road_class_mult.│
│ edit highway 2.5→2.7   │ INSERT audit_log │ → trigger updated_at   │
└────────────────────────┘                  └────────────────────────┘
                                                       │
                                              [5 phút TTL cache]
                                                       │
                                                       ▼
┌────────────────────────┐   on next call  ┌────────────────────────┐
│ Python service         │ reload config   │ config_loader reads    │
│ (cron or on-demand)    │ ◄────────────── │ active version or tables│
└────────────────────────┘                 └────────────────────────┘
        │
        ▼
output.screen_traffic_estimates
  formula_version_id = <current active id>
```

Ý nghĩa cho Laravel UI:
- **Thay coefficient** → update 1 row trong `config.*` → Python dùng giá trị mới trong ≤ 5 phút.
- **Publish version mới** → INSERT vào `config.formula_versions` → activate → Python dùng snapshot mới trong ≤ 5 phút.
- **Rollback** = activate version cũ → Python tự revert trong ≤ 5 phút.
- **Xem ai đổi gì** → SELECT `config.audit_log ORDER BY created_at DESC`.
- **Xem coverage** = `output.* WHERE formula_version_id = <id>` so với total screens.

---

## 5. Laravel team start ngay được

### 5.1. Scope Phase 2.A (theo [01-formula-config-management.md](01-formula-config-management.md))

| Task | Deliverable | Estimated effort |
|---|---|---|
| TASK-01.1 | `oohx_control` connection + verify | 30m |
| TASK-01.2 | 6 Eloquent models | 1h |
| TASK-01.3 | `ConfigManagerService` | 1–2d |
| TASK-01.4 | 4 coefficient Resources + FormulaVersion Resource trong Filament | 2–3d |
| TASK-01.5 | `AuditLogResource` (read-only) | 2–3h |

Mọi task đã có ví dụ code trong [01-formula-config-management.md](01-formula-config-management.md) — paste sang làm baseline.

### 5.2. Validation rules cần khớp DB CHECK

Laravel form validation **phải khớp** CHECK constraints phía DB để user không gặp generic error:

| Field | Laravel rule | DB CHECK |
|---|---|---|
| `base_city_traffic.baseline_passby` | `numeric\|min:0\|max:1000000` | `>= 0 AND <= 1000000` |
| `road_class_multipliers.multiplier` | `numeric\|min:0.01\|max:5` | `> 0 AND <= 5` |
| `zone_factors.factor` | `numeric\|min:0.01\|max:2` | `> 0 AND <= 2` |
| `delivery_defaults.value` | `numeric\|min:0\|max:100` | `>= 0 AND <= 100` |

### 5.3. Audit actor convention

Khi Laravel INSERT vào `config.audit_log`, dùng `actor = auth()->user()->email` (hoặc `'web:<id>'` nếu không có email). Python CLI dùng `cli:<unix_user>`. Khác format để trace nguồn.

---

## 6. Test steps Laravel team sẽ làm

### Test 1 — Read OK

```php
// tinker
use Illuminate\Support\Facades\DB;

DB::connection('oohx_control')
    ->table('config.road_class_multipliers')
    ->orderBy('road_class')
    ->get();
// Expect 10 rows
```

### Test 2 — Update coefficient

```php
DB::connection('oohx_control')
    ->table('config.road_class_multipliers')
    ->where('road_class', 'highway')
    ->update([
        'multiplier' => 2.7,
        'note'       => 'Laravel UI test',
        'updated_by' => 'test@laravel.local',
    ]);
// 1 row affected
```

Verify audit log:
```php
DB::connection('oohx_control')->table('config.audit_log')
    ->orderByDesc('created_at')
    ->first();
// null — because UPDATE qua Laravel không tự ghi audit.
//        Laravel team phải ghi audit thủ công trong ConfigManagerService::updateCoefficient().
```

> **Lưu ý quan trọng**: không có DB trigger ghi audit tự động. Laravel service **phải** gọi thêm 1 INSERT vào `config.audit_log` trong cùng transaction. Xem pattern ở [01-formula-config-management.md §6 `ConfigManagerService`](01-formula-config-management.md#6-service-layer).

### Test 3 — Verify Python pickup (end-to-end)

Sau khi update coefficient qua Laravel, đợi ≤ 5 phút rồi SSH Data Engine:

```bash
ssh oohx@139.162.20.95
cd python-data-engine
.venv/bin/python -m app.cli show-active-config
# "road_class_multipliers": { "highway": 2.7, ... }
```

Hoặc ép reload ngay:
```bash
.venv/bin/python -m app.cli recompute-screen --screen-id 1
# Log sẽ hiện version=mvp-1.0, passby với highway=2.7 thay vì 2.5
```

### Test 4 — Publish + activate version từ Laravel

```php
use App\Services\Oohx\ConfigManagerService;

$svc = app(ConfigManagerService::class);
$v = $svc->publishVersion('v-laravel-test-1', 'First version from Laravel UI');
$svc->activateVersion('v-laravel-test-1');
```

Verify:
```bash
# Trên Data Engine VPS
.venv/bin/python -m app.cli show-active-config
# "version_tag": "v-laravel-test-1", "source": "active_version"
```

---

## 7. Things Laravel team **KHÔNG** được làm

1. **KHÔNG** chạy `php artisan migrate` đụng vào schema `oohx_*` — Laravel migration chỉ MySQL app DB.
2. **KHÔNG** DELETE từ `config.audit_log` (đã REVOKE permission — sẽ lỗi). Audit log append-only.
3. **KHÔNG** UPDATE cột `created_at`, `activated_at`, `is_active` trực tiếp — luôn qua Service method (xử lý transactional + audit).
4. **KHÔNG** hardcode password vào code — luôn `env('DB_OOHX_CONTROL_PASSWORD')`.
5. **KHÔNG** query schema `metrics.*`, `source.*` qua `oohx_control` để UPDATE — role không có quyền. Nếu cần read → OK.
6. **KHÔNG** viết hai bản config (DB vs Laravel config file). DB là source of truth.

---

## 8. Fallback semantics — quan trọng khi debug

Python loader có 3 tier fallback (đọc từ trên xuống):

| Tier | Source | Triggered khi |
|---|---|---|
| 1 | `config.formula_versions WHERE is_active=TRUE` | Có version active (case bình thường) |
| 2 | 4 bảng `config.*` trực tiếp | Có data coefficient nhưng chưa publish version |
| 3 | Hardcoded TrafficConfig() trong `app/config.py` | DB unreachable HOẶC tất cả rỗng |

Laravel UI nên expose indicator:
- `show-active-config.source == "hardcoded"` → **BANNER ĐỎ** "⚠ Data Engine đang dùng fallback. DB config chưa được seed hoặc unreachable."
- `source == "tables"` → **BANNER VÀNG** "⚠ Chưa có active formula version. Publish 1 version để seal các giá trị hiện tại."
- `source == "active_version"` → OK, hiển thị `version_tag`.

Endpoint để lấy source: có thể query trực tiếp
```sql
SELECT tag, created_at, activated_at
FROM config.formula_versions
WHERE is_active = TRUE
LIMIT 1;
```
Nếu NULL → Python đang dùng tier 2 hoặc 3.

---

## 9. Open questions Laravel team cần trả lời ngược lại DE

Trả lời trong sprint planning meeting hoặc reply qua Slack `#oohx-dataengine`:

1. **Preview impact trước khi activate** — Laravel có UI "dry-run" không? Nếu có, DE cần ship thêm CLI `dry-run-version` (effort 1d). Nếu không, skip.
2. **Recompute-all sau activate** — button "Activate + Recompute All" redirect sang Jobs page hay chạy sync (đợi ~5 phút)? Đề xuất async (enqueue bulk job, redirect).
3. **Preview diff giữa 2 version** — Laravel có UI diff không (compare snapshot JSON từng field)? DE có thể support nếu cần.
4. **Audit log export** — có cần export CSV không, hay chỉ filter/view trong UI?
5. **Multi-tenant** — sau này nhiều city hoặc country, có tách role `oohx_control_hanoi`, `oohx_control_hcmc` không? MVP = 1 role duy nhất, chấp nhận.

---

## 10. Support contacts

- **Data Engine ops**: Slack `#oohx-dataengine` — ping khi:
  - `oohx_control` password thất lạc
  - Tunnel drop
  - Muốn recompute manually để test
  - DB schema mismatch (ví dụ cột thiếu)
- **Escalate**: Data Engine tech lead trực tiếp nếu critical (prod blocker)

---

## 11. Next sprint — Phase 2.B chuẩn bị gì

Sau Phase 2.A (dự kiến 1-2 tuần):
- DE team bắt tay Phase 2.C (collectors framework) — Laravel Guide 03 chờ sau ~2 tuần
- Laravel team có thể parallel Guide 02 (Jobs) và Guide 04, 05 (Inspector + Monitoring) — chỉ cần `oohx_control` + `oohx` connection đã có

---

## 12. Changelog Data Engine side

| Ngày | Change |
|---|---|
| 2026-04-20 | Migrations 006, 007 applied. `oohx_control` role created. `seed-config-from-code` executed — `mvp-1.0` active. CLI commands ship. |

---

*Handoff owner: Data Engine tech lead. Update mỗi khi có breaking change shape DB hoặc semantics.*
