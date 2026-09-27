# Phase 2.B — Data Engine Handoff for Laravel Team

> **Role of this document**: handoff phần **Jobs orchestration backend** đã ship cho team Laravel bắt đầu Phase 2.B (Jobs UI).
> **Status**: backend complete + tested. Laravel team có thể build UI ngay.
> **Reference docs**:
> - [02-jobs-orchestration.md](02-jobs-orchestration.md) — spec gốc Laravel side
> - [PHASE-2A-HANDOFF.md](PHASE-2A-HANDOFF.md) — connection setup đã mô tả ở đây
> - [DATA-ENGINE-ACTION-PLAN.md](../DATA-ENGINE-ACTION-PLAN.md) — plan DE side

---

## 1. What's shipped on Data Engine side (Phase 2.B)

### 1.1. Code changes

| File | Change |
|---|---|
| [`app/repositories/jobs.py`](../../python-data-engine/app/repositories/jobs.py) | + `enqueue_bulk_action()` với action dispatch · + `update_progress()` (merge JSONB) · + `get_status()` / `mark_cancelled()` · + `get_job()` · + `list_recent()` |
| [`app/jobs/recompute.py`](../../python-data-engine/app/jobs/recompute.py) | Bulk dispatcher hỗ trợ `recompute_all` / `recompute_stale` / `recompute_by_city` · progress flush mỗi 25 screens · cooperative cancellation · `_BulkCancelled` sentinel |
| [`app/cli.py`](../../python-data-engine/app/cli.py) | + `enqueue-recompute-all` · `enqueue-recompute-stale` · `enqueue-recompute-by-city` · `cancel-job` · `show-job` · `list-recent-jobs` |

### 1.2. Contract: bulk job payload schema

Laravel có 3 cách enqueue bulk job tuỳ use case:

#### Case A — Recompute **toàn bộ** active screens (dùng sau khi activate formula version mới)

```sql
INSERT INTO core.recompute_jobs (job_type, priority, payload)
VALUES (
    'bulk',
    50,
    jsonb_build_object(
        'action', 'recompute_all',
        '_actor', jsonb_build_object('enqueued', jsonb_build_object(
            'by', 'admin@oohx.vn', 'at', NOW()::text
        ))
    )
);
```

#### Case B — Recompute chỉ screens **chưa sync** với active version (hiệu quả hơn Case A)

```sql
INSERT INTO core.recompute_jobs (job_type, priority, payload)
VALUES (
    'bulk',
    50,
    jsonb_build_object(
        'action', 'recompute_stale',
        '_actor', ...
    )
);
```

"Stale" = screen có `formula_version_id IS NULL` HOẶC khác id của active version.

#### Case C — Recompute theo city async (alternative cho `enqueue-city`)

```sql
INSERT INTO core.recompute_jobs (job_type, priority, payload)
VALUES (
    'bulk',
    100,
    jsonb_build_object(
        'action', 'recompute_by_city',
        'city',   'Hanoi',
        '_actor', ...
    )
);
```

### 1.3. Contract: progress tracking

Trong khi worker process bulk job, mỗi **25 screens** nó update `payload.progress`:

```json
{
  "action": "recompute_all",
  "_actor": {...},
  "progress": {
    "label":      "recompute_all",
    "total":      87,
    "done":       50,
    "failed":     2,
    "started_at": "2026-04-20T10:00:00+00:00",
    "updated_at": "2026-04-20T10:00:28+00:00",
    "finished":   false
  }
}
```

Khi worker xong:
- `progress.finished = true`
- `progress.done + progress.failed = progress.total`
- `status = 'done'` (nếu có ≥ 1 screen success) hoặc `'failed'` nếu fail-all (retry lên tới 3 lần)

Khi bị cancel giữa chừng:
- `progress.cancelled_at_index = <N>` (screen thứ N đang process)
- `status = 'cancelled'`, `cancelled_at = NOW()`, `finished_at = NOW()`

### 1.4. Contract: cancellation protocol

Laravel có 2 loại cancel:

#### Hard cancel (job status `pending`)

Job chưa được pick bởi worker. Set trực tiếp:

```sql
UPDATE core.recompute_jobs
SET status = 'cancelled', cancelled_at = NOW(), finished_at = NOW()
WHERE id = $JOB_ID AND status = 'pending';
```

Worker sẽ không bao giờ pick job này (do `WHERE status='pending'` trong SKIP LOCKED query).

#### Cooperative cancel (job status `processing`, bulk job)

Job đang chạy. Set status → worker sẽ detect trong vòng 25 screens tiếp theo:

```sql
UPDATE core.recompute_jobs
SET status = 'cancelled'
WHERE id = $JOB_ID AND status = 'processing';
```

Worker tự set `cancelled_at` + `finished_at` khi dừng. Không còn race condition nào cần handle.

**Screen-type và city-type job không support cooperative cancel** — chúng chạy nhanh, cancel không thiết thực. Nếu user cố cancel `processing` screen/city → nothing happens, worker complete bình thường và mark done. UX hint: chỉ hiển thị nút "Cancel" cho `bulk` jobs đang processing.

### 1.5. CLI available (ops dùng — Laravel không cần)

```bash
python -m app.cli enqueue-recompute-all
python -m app.cli enqueue-recompute-stale
python -m app.cli enqueue-recompute-by-city --city Hanoi
python -m app.cli cancel-job --id 123
python -m app.cli show-job --id 123
python -m app.cli list-recent-jobs --limit 20 [--status failed]
python -m app.cli jobs-status
```

---

## 2. Laravel side — điều chỉnh `JobOrchestrator` theo guide 02

Guide 02 mô tả `enqueueBulk` nhận `array $screen_ids`. Phase 2.B mở rộng thêm `enqueueBulkAction`.

### 2.1. Update `App\Services\Oohx\JobOrchestrator`

Thêm method mới vào service đã có:

```php
/**
 * Enqueue bulk job với action dispatch (Phase 2.B).
 *
 * @param  string  $action  'recompute_all' | 'recompute_stale' | 'recompute_by_city'
 * @param  ?string $city    bắt buộc nếu action='recompute_by_city'
 */
public function enqueueBulkAction(
    string $action,
    ?string $city = null,
    int $priority = 150,
): RecomputeJob {
    $valid = ['recompute_all', 'recompute_stale', 'recompute_by_city'];
    abort_unless(in_array($action, $valid, true), 422, "Invalid action: $action");
    abort_if($action === 'recompute_by_city' && !$city, 422, 'city required for recompute_by_city');

    return RecomputeJob::create([
        'job_type' => 'bulk',
        'city'     => null,
        'payload'  => $this->tagActor([
            'action' => $action,
            'city'   => $city,
        ]),
        'priority'    => $priority,
        'status'      => 'pending',
        'retry_count' => 0,
        'requested_at'=> now(),
    ]);
}

/**
 * Cooperative cancel cho job đang processing (bulk only).
 * Job pending cũng set cancelled luôn.
 */
public function cancel(int $jobId): RecomputeJob
{
    $job = RecomputeJob::findOrFail($jobId);
    abort_unless(in_array($job->status, ['pending', 'processing']), 422,
        "Cannot cancel job in status {$job->status}");

    // processing: chỉ flip status, worker sẽ tự set cancelled_at/finished_at
    $updates = ['status' => 'cancelled'];
    if ($job->status === 'pending') {
        $updates['cancelled_at'] = now();
        $updates['finished_at']  = now();
    }

    $job->update($updates);
    return $job->fresh();
}

/**
 * Return progress object từ payload.progress (hoặc null nếu chưa bắt đầu).
 */
public function getProgress(int $jobId): ?array
{
    $row = DB::connection('oohx_control')
        ->table('core.recompute_jobs')
        ->where('id', $jobId)
        ->select(['status', 'payload->progress as progress_json'])
        ->first();

    if (!$row) return null;

    $progress = $row->progress_json ? json_decode($row->progress_json, true) : null;
    return [
        'status'   => $row->status,
        'progress' => $progress,
    ];
}
```

### 2.2. UI update (guide 02 §7)

Chỗ modal "Enqueue bulk" hiện tại (radio chọn source: campaign screens / media_owner / CSV) — **thêm 3 radio mới** cho action-based:

```
┌─────────────────────────────────────┐
│ Enqueue bulk recompute              │
│                                     │
│ Source:                             │
│   ○ Recompute ALL active screens    │  ← action=recompute_all
│   ○ Recompute stale screens         │  ← action=recompute_stale
│      (screens not on active version)│
│   ○ Recompute by city               │  ← action=recompute_by_city
│      City: [Hanoi ▼]                │
│   ○ Campaign screens (existing)     │  ← screen_ids path
│   ○ Paste external_ids (existing)   │
│                                     │
│ Priority: [150]                     │
│                                     │
│ [Cancel]                 [Enqueue]  │
└─────────────────────────────────────┘
```

Submit dispatch:
- 3 radio mới → `JobOrchestrator::enqueueBulkAction($action, $city)`
- 2 radio cũ → `JobOrchestrator::enqueueBulk($screen_ids)` (giữ nguyên)

### 2.3. Progress widget

Trong `/admin/oohx/jobs/{id}` (guide 02 §7.3), thêm panel Progress khi job có `payload.progress`:

```
┌──────────────────────────────────────────────────────────────────┐
│ Progress                                                         │
│                                                                  │
│ Label:      recompute_all                                        │
│ Started:    2026-04-20 10:00:00  (3m ago)                        │
│ Updated:    2026-04-20 10:02:45  (20s ago)                       │
│                                                                  │
│ ██████████░░░░░░░░░  50/87 done · 2 failed                       │
│                                                                  │
│ ETA:        ~40s remaining                                       │
│                                                                  │
│ [Cancel]  [Refresh]                                              │
└──────────────────────────────────────────────────────────────────┘
```

ETA compute (Blade/JS):
```
elapsed_s = now - progress.started_at
throughput = progress.done / elapsed_s   # screens/s
remaining  = progress.total - progress.done
eta_s      = remaining / throughput
```

Auto-refresh mỗi 10s khi `status = 'processing'`, stop refresh khi `progress.finished = true` hoặc `status ∈ {done, failed, cancelled}`.

### 2.4. Cancel UX cho bulk processing

Trong `/admin/oohx/jobs/{id}`:

- Job `pending` → nút "Cancel" active, confirm modal: "Cancel pending job?"
- Job `processing` + `job_type=bulk` → nút "Cancel" active, confirm: "Worker sẽ stop sau ≤25 screens. Continue?"
- Job `processing` + `job_type=screen/city` → nút "Cancel" **disabled**, tooltip "Screen/city jobs run to completion."
- Job `done/failed/cancelled` → nút "Cancel" ẩn.

---

## 3. Use cases — Laravel side orchestration

### 3.1. Activate version mới → recompute stale (chain từ guide 01)

Khi ops activate formula version mới trong guide 01 UI:

```php
// app/Http/Controllers/Admin/Oohx/FormulaVersionController@activate
public function activate(string $tag)
{
    $v = $this->configSvc->activateVersion($tag);

    // Optional: auto-enqueue recompute-stale
    if (request()->boolean('recompute_stale')) {
        $job = $this->jobOrchestrator->enqueueBulkAction(
            'recompute_stale', priority: 50,
        );
        return redirect()->route('admin.oohx.jobs.show', $job->id)
            ->with('status', "Activated $tag. Enqueued recompute-stale job #$job->id.");
    }

    return back()->with('status', "Activated $v->tag. Trigger recompute manually from Jobs.");
}
```

Modal trước khi activate:
```
Activate formula version v-2026-05-15?

This will change active coefficients immediately.
Existing estimates will use the OLD version until recomputed.

☑ Also enqueue recompute-stale job (fastest sync)
☐ Just activate, I'll trigger recompute later

[Cancel]  [Activate]
```

### 3.2. Dashboard "stale estimates" widget → 1-click fix

Trong guide 05 monitoring dashboard:

```
┌────────────────────────────────────────────────────────────┐
│ 13 estimates on older formula version                      │
│ [Enqueue recompute-stale →]                                │
└────────────────────────────────────────────────────────────┘
```

Nút POST sang `JobOrchestrator::enqueueBulkAction('recompute_stale')`. Redirect tới job detail page để ops theo dõi.

### 3.3. CLI ops parallel path

Nếu Laravel UI chưa ready, ops có thể trigger cùng effect từ Data Engine VPS:

```bash
python -m app.cli enqueue-recompute-stale
# Hoặc force drain ngay thay vì đợi cron 10 phút:
python -m app.cli recompute-pending-jobs --max 200
```

---

## 4. DB Schema additions — cần update Eloquent model

Cột mới thêm qua migration 007 (đã apply):

```sql
ALTER TABLE core.recompute_jobs
  ADD COLUMN cancelled_at TIMESTAMPTZ,
  ADD COLUMN notified_at  TIMESTAMPTZ;
```

Update `App\Models\Oohx\RecomputeJob`:

```php
protected $fillable = [
    // ... existing ...
    'cancelled_at',  // NEW
    'notified_at',   // NEW (optional, cho notification phase sau)
];

protected $casts = [
    // ... existing ...
    'cancelled_at' => 'datetime',
    'notified_at'  => 'datetime',
];
```

---

## 5. Test plan for Laravel team

### 5.1. Tinker smoke test

```php
use App\Services\Oohx\JobOrchestrator;

$svc = app(JobOrchestrator::class);

// 1. Enqueue recompute_stale
$job = $svc->enqueueBulkAction('recompute_stale', priority: 100);
dd($job->id);  // ví dụ 123

// 2. Đợi 10 phút (cron) hoặc ep CLI:
//    ssh oohx@139.162.20.95 'cd python-data-engine && .venv/bin/python -m app.cli recompute-pending-jobs'

// 3. Check progress
$svc->getProgress(123);
// ['status' => 'done', 'progress' => ['total' => 16, 'done' => 16, 'failed' => 0, ...]]

// 4. Enqueue recompute_all với cancel test
$job2 = $svc->enqueueBulkAction('recompute_all');
// trước khi cron pick lên, cancel:
$svc->cancel($job2->id);
// Status ngay lập tức = cancelled
```

### 5.2. UI flow test

- [ ] Enqueue `recompute_all` từ UI → job xuất hiện pending
- [ ] SSH force drain → status processing, progress hiện
- [ ] Progress widget auto-refresh hiển thị 0/87 → 25/87 → 50/87 → 87/87
- [ ] Final status = done, `formula_version_id` trên tất cả rows = active id
- [ ] Cancel `processing` bulk → worker stop sau ≤25 screens, status=cancelled
- [ ] Cancel `pending` → status=cancelled ngay
- [ ] Cancel `processing` screen/city → nút disabled (UI assertion)

### 5.3. Data contract test

```sql
-- Sau khi recompute_all done:
SELECT formula_version_id, COUNT(*)
FROM output.screen_traffic_estimates
GROUP BY formula_version_id;

-- Expect: 1 row, formula_version_id = active_version_id, count = num_active_screens
```

---

## 6. Contract change summary (what Laravel team must update)

1. `RecomputeJob` model: thêm casts cho `cancelled_at`, `notified_at`
2. `JobOrchestrator`: thêm `enqueueBulkAction()` + `getProgress()` + update `cancel()` cho bulk processing semantic
3. Jobs index page: thêm 3 radio option action-based
4. Jobs show page: thêm Progress panel
5. FormulaVersion activate flow: option tự-enqueue recompute-stale

---

## 7. Things Laravel team KHÔNG làm (vẫn giữ ranh giới)

- ❌ Không tự process bulk job từ Laravel — chỉ enqueue, worker Python chạy.
- ❌ Không overwrite `status` từ processing → done/failed — chỉ worker set đó. Laravel chỉ được set `cancelled`.
- ❌ Không sửa `payload.progress` từ Laravel — worker own field này.
- ❌ Không dùng `enqueueBulk($screen_ids)` cho > 5000 screens — dùng `enqueueBulkAction('recompute_all')` cho case đó (nhẹ DB, không carry list lớn qua payload).

---

## 8. Known limitations (discussion cho Phase 3)

- **Progress granularity 25 screens**: nếu bulk chỉ 10 screens, progress chỉ flush khi xong. Laravel UI có thể show "running, no progress yet" cho case này. Fine cho MVP.
- **Không priority queue trong bulk**: bulk job chạy screens theo `ORDER BY id`, không prioritize city hay media_owner. Nếu cần, phase 3 thêm `payload.order_by`.
- **Cancellation chỉ ở ranh giới 25 screens**: nếu cần cancel tức thời, phase 3 có thể giảm xuống 5 screens (đổi `_PROGRESS_INTERVAL` trong `jobs/recompute.py`).
- **Không parallel worker**: 1 worker/cron. Nếu bulk 10k screens → ~2.5h. Để tăng tốc, tuần sau có thể chạy 2 cron parallel, `FOR UPDATE SKIP LOCKED` đã support.

---

## 9. Changelog

| Ngày | Change |
|---|---|
| 2026-04-20 | Phase 2.B backend shipped. Bulk action dispatch (`recompute_all`, `recompute_stale`, `recompute_by_city`) + progress + cancellation. CLI helpers. Handoff doc. |

---

*Handoff owner: Data Engine tech lead. Gửi Slack ping khi nhận.*
