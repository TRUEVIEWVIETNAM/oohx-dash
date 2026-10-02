# Phase 3.A — Data Engine Handoff for Laravel Team

> **Role of this document**: handoff Phase 3.A Part 1 (dry-run preview) cho team Laravel. Safety net bắt buộc trước mỗi lần activate formula version production.
>
> **Status**: backend shipped + syntax-validated. Migration 010 chưa apply trên VPS — xem §6 Deploy checklist.
>
> **Scope Phase 3.A Part 1** (đã ship hôm nay):
> - Refactor `TrafficEstimationService` — tách `compute()` khỏi `estimate()`
> - `TrafficConfig.from_snapshot()` classmethod
> - `PreviewService` — dry-run so baseline vs target cfg
> - Migration 010 — mở `job_type='preview'`
> - `RecomputeRunner` dispatch `preview` jobs
> - CLI `preview-version` (direct) + `enqueue-preview` (queue)
>
> **Ngoài scope** (Phase 3.A Part 2+ sẽ ship sau):
> - HCMC data parity (ops task, chạy collectors)
> - `health-check` CLI + monitoring cron
> - Backup runbook
>
> **Reference**:
> - [01-formula-config-management.md](01-formula-config-management.md) — Guide 01 (Formula version UI)
> - [02-jobs-orchestration.md](02-jobs-orchestration.md) — Guide 02 (Jobs UI pattern)
> - [PHASE-2B-HANDOFF.md](PHASE-2B-HANDOFF.md) — job queue integration tiền lệ
> - [DATA-ENGINE-ACTION-PLAN.md §7](../DATA-ENGINE-ACTION-PLAN.md) — plan đầy đủ

---

## 1. What's shipped

### 1.1. SQL migration

| File | Change |
|---|---|
| [`sql/010_preview_jobs.sql`](../../python-data-engine/sql/010_preview_jobs.sql) | Mở `core.recompute_jobs.job_type` CHECK thêm `'preview'`. Idempotent (DROP IF EXISTS). |

**Laravel impact**: **không ALTER gì từ Laravel**. DE ops sẽ apply trên VPS.

### 1.2. Python changes (DE internal — Laravel không cần quan tâm chi tiết)

| File | Change |
|---|---|
| [`app/config.py`](../../python-data-engine/app/config.py) | Classmethod `TrafficConfig.from_snapshot(snapshot, id, tag, source)` |
| [`app/config_loader.py`](../../python-data-engine/app/config_loader.py) | `_from_snapshot` giờ delegate sang classmethod |
| [`app/services/traffic_estimation.py`](../../python-data-engine/app/services/traffic_estimation.py) | Tách `compute(screen_id, cfg_override=None)` ra khỏi `estimate()`. Signature `estimate()` giữ nguyên — không breaking change cho caller hiện tại. |
| [`app/services/preview.py`](../../python-data-engine/app/services/preview.py) | **NEW** `PreviewService.preview_version(tag, sample_size, city, seed)` |
| [`app/repositories/jobs.py`](../../python-data-engine/app/repositories/jobs.py) | `enqueue_preview(...)`, `update_payload_key(key, value)` |
| [`app/jobs/recompute.py`](../../python-data-engine/app/jobs/recompute.py) | Dispatch `job_type='preview'` → `_process_preview_job` |
| [`app/cli.py`](../../python-data-engine/app/cli.py) | 2 command mới: `preview-version`, `enqueue-preview` |

---

## 2. Preview API — 2 paths

Laravel có **2 cách** gọi preview. Chọn theo use case:

### Path A — Synchronous CLI (admin one-off)

Dùng khi ops/admin cần kết quả ngay tại dòng lệnh. **Không** phù hợp cho Laravel UI (blocking).

```bash
cd /home/oohx/apps/oohx-matrix/python-data-engine
.venv/bin/python -m app.cli preview-version \
    --tag v2-2026-Q2 \
    --sample 100 \
    --city Hanoi \
    --seed 42
```

Chạy ~5-15s cho 100 screens → in JSON ra stdout.

### Path B — Async queue (Laravel UI — **khuyến nghị**)

Flow chuẩn:

```
[Admin click Preview button]
    ↓
Laravel INSERT INTO core.recompute_jobs
    (job_type='preview', payload={tag, sample_size, city?, seed?}, status='pending')
    RETURNING id
    ↓
Laravel redirect admin tới "Preview result" page (loading spinner, polling job_id)
    ↓
[Cron worker /1min drain pending jobs]
    ↓
Worker process_preview_job → ghi result vào payload.result
    ↓
Laravel poll SELECT status, payload->'result' WHERE id=?
    ↓ status='done' + result có đủ data
[Render histogram + table top deltas]
```

**Tại sao Path B**:
- UI không block, admin có thể làm việc khác
- Kết quả persist trong DB → refresh page vẫn thấy
- Retry logic đã sẵn trong RecomputeRunner (max 3 lần)

---

## 3. Enqueue preview job — SQL spec Laravel

### 3.1. INSERT statement

Laravel dùng role `oohx_control` (đã GRANT INSERT từ Phase 0).

```sql
INSERT INTO core.recompute_jobs (job_type, payload, priority)
VALUES (
    'preview',
    jsonb_build_object(
        'tag',         :tag,
        'sample_size', :sample_size,
        'city',        :city,        -- NULL OK
        'seed',        :seed,        -- NULL OK
        '_actor',      jsonb_build_object(
            'enqueued', jsonb_build_object(
                'by', :user_email,
                'at', to_jsonb(NOW()::text)
            )
        )
    ),
    120   -- default priority, giữa 'city' (200) và 'bulk' (150)
)
RETURNING id;
```

### 3.2. Eloquent / Laravel example

```php
// app/Services/DataEngine/PreviewJobService.php
public function enqueuePreview(array $params, string $actorEmail): int
{
    return DB::connection('data_engine')
        ->table('core.recompute_jobs')
        ->insertGetId([
            'job_type' => 'preview',
            'payload'  => DB::raw(sprintf(
                "jsonb_build_object(
                    'tag', %s,
                    'sample_size', %s,
                    'city', %s,
                    'seed', %s,
                    '_actor', jsonb_build_object(
                        'enqueued', jsonb_build_object('by', %s, 'at', to_jsonb(NOW()::text))
                    )
                )",
                // Sử dụng parameter binding — ví dụ trên rút gọn
            )),
            'priority' => 120,
        ]);
}
```

> **Payload validation** (Laravel side trước INSERT):
> - `tag`: string, max 100, exists trong `config.formula_versions.tag`
> - `sample_size`: int, 1..2000 (DE cap `_MAX_SAMPLE_SIZE=2000`)
> - `city`: string|null, optional
> - `seed`: int|null, optional. Dùng cùng seed → cùng sample screens (reproducible)
> - `priority`: 100..200 hợp lý; không bắt buộc

### 3.3. Poll status + result

```sql
SELECT
    id,
    status,                                   -- pending/processing/done/failed/cancelled
    retry_count,
    error_message,
    requested_at,
    started_at,
    finished_at,
    payload->>'tag'         AS tag,
    payload->>'city'        AS city,
    payload->'result'       AS result,        -- NULL khi chưa xong
    payload->>'_actor'      AS actor_raw
FROM core.recompute_jobs
WHERE id = :job_id;
```

Poll pattern đề xuất (giống pattern Jobs UI đã dùng):
- Interval: **2s** trong 60s đầu, sau đó **10s**
- Timeout UI: 120s → show "Job still running, refresh page later"
- Terminal states: `done | failed | cancelled` → dừng poll

---

## 4. Result JSON schema

Khi `status='done'`, `payload->'result'` chứa:

```json
{
  "baseline_version_tag": "v1-mvp",
  "baseline_version_id":  17,
  "target_version_tag":   "v2-2026-Q2",
  "target_version_id":    42,

  "sample_size_requested": 100,
  "sample_size_computed":  97,
  "skipped":               3,
  "skipped_examples": [
    {"screen_id": 153, "reason": "Screen 153 has no context metrics — run enrichment first"}
  ],

  "city": "Hanoi",
  "seed": 42,

  "metrics": {
    "estimated_daily_impressions": {
      "baseline_sum":       15234567.0,
      "target_sum":         16890123.0,
      "screens_increased":  78,
      "screens_decreased":  12,
      "screens_unchanged":  7,
      "screens_undefined":  0,
      "delta_pct_p50":      0.08,
      "delta_pct_p90":      0.23,
      "delta_pct_p99":      0.41,
      "delta_pct_min":      -0.12,
      "delta_pct_max":      0.55,
      "delta_pct_mean":     0.094
    },
    "estimated_daily_ots":  { /* same shape */ },
    "confidence_score":     { /* same shape */ }
  },

  "top_deltas": [
    {
      "screen_id": 142,
      "baseline":  45123.5,
      "target":    71234.2,
      "delta_pct": 0.5784
    }
    /* ... 10 biggest by abs(delta_pct) ... */
  ]
}
```

### 4.1. Schema contract

- **Stable fields** (Laravel dựa vào): tất cả key ở root + `metrics.{key}.delta_pct_*`, `top_deltas[].{screen_id, baseline, target, delta_pct}`
- **Semver promise**: thêm field mới = minor version. Đổi tên/kiểu = breaking → sẽ bump handoff doc và ping Laravel trước
- Tất cả `delta_pct_*` ở **decimal form** (0.08 = 8%) — Laravel format × 100 khi render

### 4.2. Semantic của counts

| Counter | Nghĩa |
|---|---|
| `sample_size_computed` | N screens có cả baseline + target tính được |
| `skipped` | Screen chưa enrich, hoặc không exist → không count |
| `screens_increased` | delta > 0 |
| `screens_decreased` | delta < 0 |
| `screens_unchanged` | baseline == target (exact) |
| `screens_undefined` | baseline == 0 nhưng target != 0 → ratio undefined; không count vào delta percentiles |

`screens_increased + decreased + unchanged + undefined = sample_size_computed`.

---

## 5. UI suggestions

### 5.1. Preview button placement

Trong Formula version detail page (Guide 01), thêm button "Preview impact" **kế bên** "Activate":

```
┌ Version v2-2026-Q2 (draft) ────────────────────────────┐
│  Description: Q2 weather-aware formula                │
│  Created by: admin@oohx.com at 2026-04-20             │
│                                                        │
│  [ Preview impact ]   [ Activate this version ]       │
└────────────────────────────────────────────────────────┘
```

Rule: "Activate" button **disabled** cho đến khi admin click "Preview impact" ít nhất 1 lần cho version đó trong session (tuỳ Laravel quyết định enforce hay soft warning).

### 5.2. Preview form modal

```
┌ Preview: v2-2026-Q2 vs current ──────────────────┐
│  City filter:  [All cities ▼]                   │
│  Sample size:  [100      ]  (max 2000)          │
│  Seed:         [         ]  (optional, repeat)  │
│                                                  │
│                  [ Cancel ]  [ Run preview ]     │
└──────────────────────────────────────────────────┘
```

Submit → enqueue job → redirect tới `/formula-versions/{id}/preview/{job_id}`.

### 5.3. Preview result page

```
┌ Preview v2-2026-Q2 vs v1-mvp ─────────────────────────┐
│  Sample: 97/100 screens (3 skipped, see details)     │
│  City: Hanoi | Seed: 42 | Job: #1234 (2s ago)        │
│                                                        │
│  Daily Impressions                                     │
│   ┌──────────────────────────────────────────┐        │
│   │ ▲ Increased: 78 screens                  │        │
│   │ ▼ Decreased: 12                          │        │
│   │ ● Unchanged:  7                          │        │
│   └──────────────────────────────────────────┘        │
│                                                        │
│   Delta distribution:                                  │
│   p50: +8%    p90: +23%    p99: +41%                  │
│   range: -12% ... +55%      mean: +9.4%               │
│                                                        │
│   [bar chart hoặc simple percentile lines]            │
│                                                        │
│  ⚠️  Warning: p99 > 40% — review trước khi activate    │
│                                                        │
│  Top changes (click để xem screen detail):             │
│  │ Screen  │ Baseline │ Target   │ Δ%      │          │
│  │ 142     │ 45,123   │ 71,234   │ +57.8%  │          │
│  │ 98      │ 12,000   │ 17,890   │ +49.1%  │          │
│  │ ...                                                 │
│                                                        │
│  [ Go back ]    [ Activate this version ]             │
└────────────────────────────────────────────────────────┘
```

### 5.4. Warning thresholds (đề xuất)

Render badge mức rủi ro dựa trên `delta_pct_p99`:

| p99 | Badge | Message |
|---|---|---|
| < 10% | 🟢 Low impact | Safe to activate |
| 10-30% | 🟡 Medium | Review top changes |
| 30-50% | 🟠 High | Check top_deltas carefully |
| > 50% | 🔴 Critical | Confirm với product team trước khi activate |

Laravel có thể hardcode threshold hoặc cho admin setting override.

---

## 6. Deploy checklist (ops)

### 6.1. Apply migration 010

```bash
# Trên Data Engine VPS (139.162.20.95)
cd /home/oohx/apps/oohx-matrix/python-data-engine
source .venv/bin/activate
python -m app.cli init-db --sql-dir sql
```

Verify:

```sql
-- Phải thấy constraint mới có 'preview'
SELECT consrc FROM pg_constraint WHERE conname = 'recompute_jobs_job_type_check';
-- hoặc PostgreSQL 16:
SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname='recompute_jobs_job_type_check';
-- Expected: CHECK (job_type IN ('screen','city','bulk','preview'))
```

### 6.2. Verify CLI

```bash
# Liệt kê commands — expect có 'preview-version' và 'enqueue-preview'
.venv/bin/python -m app.cli --help | grep -E "preview-version|enqueue-preview"
```

### 6.3. Smoke test DE-side

```bash
# 1. Publish 1 test version với highway multiplier tăng
.venv/bin/python -m app.cli publish-config-version \
    --tag v-preview-smoke --description "Smoke test preview only" \
    # KHÔNG --activate

# (Optional) modify coefficients trong config.* trước publish nếu muốn thấy delta rõ hơn

# 2. Dry-run CLI
.venv/bin/python -m app.cli preview-version \
    --tag v-preview-smoke --sample 20 --seed 1

# 3. Queue-based
.venv/bin/python -m app.cli enqueue-preview \
    --tag v-preview-smoke --sample 20 --seed 1
# note job_id từ output

# Drain queue — sẽ pick preview job vừa enqueue
.venv/bin/python -m app.cli recompute-pending-jobs --max 5

# Verify result
.venv/bin/python -m app.cli show-job --id <job_id>
# Expect status='done', payload->'result' có schema đúng
```

### 6.4. Verify output.* không bị touch

```sql
-- Trước preview: note last_calculated_at
SELECT MAX(last_calculated_at) FROM output.screen_traffic_estimates;
-- Sau preview (cả CLI + queue): value KHÔNG đổi
SELECT MAX(last_calculated_at) FROM output.screen_traffic_estimates;
```

---

## 7. Laravel test plan (end-to-end)

Test cases Laravel team phải chạy trước khi release UI:

### 7.1. Happy path

- [ ] Login admin → Formula version list → chọn version `draft`
- [ ] Click "Preview impact" → modal hiện
- [ ] Submit sample=50, seed=1 → redirect page preview
- [ ] Page polling cho tới khi `status='done'` (≤ 2 phút)
- [ ] Verify histogram + top_deltas render đúng
- [ ] Verify: `SELECT COUNT(*) FROM output.screen_traffic_estimates` không đổi trước/sau

### 7.2. Reproducibility

- [ ] Run preview 2 lần cùng `--tag --sample --seed` → `top_deltas[0].screen_id` giống nhau
- [ ] Đổi seed → top_deltas khác (different sample)

### 7.3. Error cases

- [ ] Enqueue với `tag='nonexistent-version'` → sau cron drain: `status='failed'`, `error_message LIKE 'preview failed: Formula version tag%not found%'`
- [ ] Enqueue với `sample_size=3000` → `status='failed'`, error chứa `sample_size vượt cap 2000`
- [ ] Enqueue với payload thiếu `tag` → `status='failed'`, error chứa `requires payload.tag`

### 7.4. Active version preview (sanity)

- [ ] Preview với `tag = <current active version>` → tất cả `delta_pct_*` ≈ 0 (≤ 0.001 do floating-point)
- [ ] `metrics.estimated_daily_impressions.baseline_sum ≈ target_sum`

### 7.5. City filter

- [ ] Preview `--city Hanoi` → `top_deltas[].screen_id` toàn Hanoi
- [ ] Preview `--city XYZ` (không có screen) → `sample_size_computed=0`, không crash

### 7.6. Concurrent preview jobs

- [ ] Enqueue 3 preview jobs đồng thời cho cùng 1 tag → cả 3 `status='done'` (SKIP LOCKED safe)
- [ ] Không có deadlock trong postgres log

### 7.7. Cancellation

- [ ] Enqueue preview → ngay lập tức UPDATE status='cancelled' trước khi cron chạy
- [ ] Worker không pick up (vì `WHERE status='pending'`)
- [ ] UI hiện "Cancelled", không render result

---

## 8. Known limitations / Phase 3.A Part 2 todo

| Gap | Workaround hiện tại | Sẽ xử lý ở |
|---|---|---|
| HCMC POI + roads sparse — preview HCMC có thể skip nhiều screens | Laravel filter `city=Hanoi` mặc định | Phase 3.A Part 2 (ops run collectors) |
| Preview chưa persist history — mỗi lần gọi là 1 job mới | Giữ job_id trong URL để bookmark được | Phase 3.B (archive job sau N ngày) |
| Không thể preview 2 version song song (baseline luôn = active) | Activate trung gian là khả thi nhưng nguy hiểm; skip cho Part 1 | Phase 3.B nếu có request |
| Warning threshold hardcode ở Laravel UI | OK cho MVP | Phase 3.B: lưu thresholds trong `config.delivery_defaults` |

---

## 9. Open questions cần Laravel team chốt

1. **Preview gate activation?** — Enforce "phải preview ≥ 1 lần mới được activate" hay chỉ soft warning?
   → DE vote: soft warning + log audit "activated without preview" cho ops trace.
2. **Default sample_size** cho UI? → Đề xuất `100` cho main market (Hanoi), `30` cho city nhỏ.
3. **City filter required hay optional?** → DE vote: optional, default "all cities" khi sample đủ lớn (≥ 100).
4. **Warning p99 thresholds** — dùng 10/30/50% như đề xuất §5.4 hay khác?
5. **Job retention** — giữ preview jobs bao lâu? (Khác với recompute jobs vì result payload bự ~50KB)
   → Đề xuất: TTL 30 ngày, cron cleanup.

---

## 10. Contact + Changelog

**DE owner**: Data Engine tech lead (vnanswer@gmail.com)

Paste vào ticket Laravel khi có câu hỏi hoặc chặn.

### Changelog

| Ngày | Version | Change |
|---|---|---|
| 2026-04-21 | 3.A.1 | Phase 3.A Part 1 shipped: migration 010, preview service, CLI, job dispatch. Laravel UI task open. |

---

*Handoff owner: Data Engine tech lead. Update khi Laravel ship UI hoặc phát hiện gap.*
