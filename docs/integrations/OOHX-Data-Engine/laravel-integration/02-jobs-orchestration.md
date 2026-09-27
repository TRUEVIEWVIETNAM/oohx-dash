# Laravel Integration — 02. Jobs Orchestration

> **Role of this guide**: tài liệu handoff cho team Laravel, ứng với **Mục tiêu 1** của [OOHX-UPGRADE-PROPOSAL.md](../../OOHX-UPGRADE-PROPOSAL.md).
> Mục đích: Laravel admin enqueue + monitor + retry recompute jobs — **không** chạy Python trực tiếp, chỉ INSERT/UPDATE vào `core.recompute_jobs`.

---

## 1. Mục tiêu feature

Ops / admin cần:

1. **Enqueue** 1 job (per screen, per city, hoặc bulk).
2. **Xem** queue status realtime: pending / processing / done / failed counts.
3. **Inspect** 1 job: payload, retry_count, error_message, thời gian.
4. **Retry** job failed (reset status='pending', retry_count=0).
5. **Cancel** job pending (set status='cancelled').
6. **Trigger recompute sau khi activate formula version mới** (từ guide 01 nhảy vào).

Không làm ở guide này:
- ❌ Không chạy Python job trực tiếp — worker Python cron drain queue.
- ❌ Không xem logs Python — đó là ops concern, không cần UI.

---

## 2. Prerequisites

- Tunnel đang chạy, connection `oohx_control` đã setup (xem guide 01).
- Role `oohx_control` có quyền INSERT + UPDATE `core.recompute_jobs`.
- Python cron `recompute-pending-jobs` đang chạy mỗi 10 phút (đã có từ phase 1).

---

## 3. Schema reference

Bảng chính: `core.recompute_jobs` (đã có sẵn từ MVP, không migration mới):

```sql
CREATE TABLE core.recompute_jobs (
    id              BIGSERIAL PRIMARY KEY,
    job_type        TEXT NOT NULL CHECK (job_type IN ('screen','city','bulk')),
    screen_id       BIGINT REFERENCES core.screens(id) ON DELETE CASCADE,
    city            TEXT,
    payload         JSONB NOT NULL DEFAULT '{}'::jsonb,
    priority        INTEGER NOT NULL DEFAULT 100,      -- smaller = higher priority
    status          TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','processing','done','failed','cancelled')),
    retry_count     INTEGER NOT NULL DEFAULT 0,
    error_message   TEXT,
    requested_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at      TIMESTAMPTZ,
    finished_at     TIMESTAMPTZ
);
```

> Lưu ý: status `'cancelled'` cần thêm vào CHECK constraint nếu chưa có. Báo ops để chạy:
> ```sql
> ALTER TABLE core.recompute_jobs DROP CONSTRAINT recompute_jobs_status_check;
> ALTER TABLE core.recompute_jobs ADD CONSTRAINT recompute_jobs_status_check
>     CHECK (status IN ('pending','processing','done','failed','cancelled'));
> ```

Read-only joins:
- `core.screens (id, external_id, name, city)` — để hiện screen info.

---

## 4. Laravel models

Đặt tại `app/Models/Oohx/`:

### 4.1. `RecomputeJob.php`

```php
<?php

namespace App\Models\Oohx;

use Illuminate\Database\Eloquent\Model;

class RecomputeJob extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'core.recompute_jobs';
    public    $timestamps = false;

    protected $fillable = [
        'job_type', 'screen_id', 'city', 'payload',
        'priority', 'status', 'retry_count', 'error_message',
        'requested_at', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'requested_at' => 'datetime',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function screen()
    {
        return $this->belongsTo(Screen::class, 'screen_id');
    }

    public function scopePending($q)    { return $q->where('status', 'pending'); }
    public function scopeProcessing($q) { return $q->where('status', 'processing'); }
    public function scopeFailed($q)     { return $q->where('status', 'failed'); }
}
```

---

## 5. Service layer

`app/Services/Oohx/JobOrchestrator.php`:

```php
<?php

namespace App\Services\Oohx;

use App\Models\Oohx\RecomputeJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JobOrchestrator
{
    public function enqueueScreen(int $screenId, int $priority = 100, array $payload = []): RecomputeJob
    {
        return RecomputeJob::create([
            'job_type'     => 'screen',
            'screen_id'    => $screenId,
            'payload'      => $this->tagActor($payload),
            'priority'     => $priority,
            'status'       => 'pending',
            'retry_count'  => 0,
            'requested_at' => now(),
        ]);
    }

    public function enqueueCity(string $city, int $priority = 200, array $payload = []): RecomputeJob
    {
        return RecomputeJob::create([
            'job_type'     => 'city',
            'city'         => $city,
            'payload'      => $this->tagActor($payload),
            'priority'     => $priority,
            'status'       => 'pending',
            'retry_count'  => 0,
            'requested_at' => now(),
        ]);
    }

    public function enqueueBulk(array $screenIds, int $priority = 150, array $extraPayload = []): RecomputeJob
    {
        abort_if(empty($screenIds), 422, 'screen_ids cannot be empty');
        return RecomputeJob::create([
            'job_type'     => 'bulk',
            'payload'      => $this->tagActor(array_merge($extraPayload, ['screen_ids' => array_values($screenIds)])),
            'priority'     => $priority,
            'status'       => 'pending',
            'retry_count'  => 0,
            'requested_at' => now(),
        ]);
    }

    /** Reset a failed job to pending. Keeps retry_count. */
    public function retry(int $jobId): RecomputeJob
    {
        $job = RecomputeJob::findOrFail($jobId);
        abort_unless(in_array($job->status, ['failed', 'cancelled']), 422, 'Only failed/cancelled jobs can be retried');

        $job->update([
            'status'       => 'pending',
            'retry_count'  => 0,
            'error_message'=> null,
            'started_at'   => null,
            'finished_at'  => null,
            'payload'      => $this->tagActor($job->payload ?? [], 'retried'),
        ]);
        return $job;
    }

    /** Cancel a pending job. */
    public function cancel(int $jobId): RecomputeJob
    {
        $job = RecomputeJob::findOrFail($jobId);
        abort_unless($job->status === 'pending', 422, 'Only pending jobs can be cancelled');

        $job->update([
            'status'      => 'cancelled',
            'finished_at' => now(),
            'payload'     => $this->tagActor($job->payload ?? [], 'cancelled'),
        ]);
        return $job;
    }

    /** Counts by status — dùng cho dashboard header. */
    public function countsByStatus(): array
    {
        return DB::connection('oohx_control')->table('core.recompute_jobs')
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();
    }

    private function tagActor(array $payload, string $action = 'enqueued'): array
    {
        $payload['_actor'][$action] = [
            'by' => Auth::user()?->email ?? 'system',
            'at' => now()->toIso8601String(),
        ];
        return $payload;
    }
}
```

---

## 6. Controllers + Routes

`routes/web.php`:

```php
use App\Http\Controllers\Admin\Oohx\JobController;

Route::middleware(['auth', 'can:manage-oohx-jobs'])
    ->prefix('admin/oohx')
    ->name('admin.oohx.')
    ->group(function () {
        Route::get ('jobs',                 [JobController::class, 'index'])->name('jobs.index');
        Route::get ('jobs/{id}',            [JobController::class, 'show'])->name('jobs.show');
        Route::post('jobs/enqueue-screen',  [JobController::class, 'enqueueScreen'])->name('jobs.enqueue.screen');
        Route::post('jobs/enqueue-city',    [JobController::class, 'enqueueCity'])->name('jobs.enqueue.city');
        Route::post('jobs/enqueue-bulk',    [JobController::class, 'enqueueBulk'])->name('jobs.enqueue.bulk');
        Route::post('jobs/{id}/retry',      [JobController::class, 'retry'])->name('jobs.retry');
        Route::post('jobs/{id}/cancel',     [JobController::class, 'cancel'])->name('jobs.cancel');
    });
```

`app/Http/Controllers/Admin/Oohx/JobController.php`:

```php
<?php

namespace App\Http\Controllers\Admin\Oohx;

use App\Http\Controllers\Controller;
use App\Models\Oohx\RecomputeJob;
use App\Services\Oohx\JobOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobController extends Controller
{
    public function __construct(private JobOrchestrator $svc) {}

    public function index(Request $request)
    {
        $q = RecomputeJob::query();

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $q->where('job_type', $type);
        }
        if ($city = $request->query('city')) {
            $q->where('city', $city);
        }

        $jobs = $q->orderByDesc('requested_at')->paginate(50)->withQueryString();

        return view('admin.oohx.jobs.index', [
            'jobs'   => $jobs,
            'counts' => $this->svc->countsByStatus(),
            'filter' => $request->only(['status','type','city']),
        ]);
    }

    public function show(int $id)
    {
        $job = RecomputeJob::with('screen')->findOrFail($id);
        return view('admin.oohx.jobs.show', compact('job'));
    }

    public function enqueueScreen(Request $request)
    {
        $data = $request->validate([
            'screen_id' => 'required|integer|exists:oohx_control.core.screens,id',
            'priority'  => 'nullable|integer|min:1|max:1000',
        ]);
        $job = $this->svc->enqueueScreen($data['screen_id'], $data['priority'] ?? 100);
        return back()->with('status', "Enqueued job #$job->id for screen $job->screen_id");
    }

    public function enqueueCity(Request $request)
    {
        $data = $request->validate([
            'city'     => 'required|string|max:100',
            'priority' => 'nullable|integer|min:1|max:1000',
        ]);
        $job = $this->svc->enqueueCity($data['city'], $data['priority'] ?? 200);
        return back()->with('status', "Enqueued job #$job->id for city {$data['city']}");
    }

    public function enqueueBulk(Request $request)
    {
        $data = $request->validate([
            'screen_ids'   => 'required|array|min:1|max:5000',
            'screen_ids.*' => 'integer',
            'priority'     => 'nullable|integer|min:1|max:1000',
        ]);
        $job = $this->svc->enqueueBulk($data['screen_ids'], $data['priority'] ?? 150);
        return back()->with('status', "Enqueued bulk job #$job->id for ".count($data['screen_ids'])." screens");
    }

    public function retry(int $id)
    {
        $this->svc->retry($id);
        return back()->with('status', "Job #$id reset to pending.");
    }

    public function cancel(int $id)
    {
        $this->svc->cancel($id);
        return back()->with('status', "Job #$id cancelled.");
    }
}
```

---

## 7. UI specification

### 7.1. `/admin/oohx/jobs` — Queue dashboard

Header — counters realtime (refresh mỗi 10s qua JS):

```
┌──────────────────────────────────────────────────────────────────┐
│  🟡 Pending: 12   🔵 Processing: 2   🟢 Done: 5841   🔴 Failed: 3 │
└──────────────────────────────────────────────────────────────────┘

Filters: [All / Pending / Processing / Done / Failed / Cancelled]
         [All types / screen / city / bulk]
         [City: Hanoi ▼]

Actions:  [+ Enqueue screen]  [+ Enqueue city]  [+ Enqueue bulk]

┌────────────────────────────────────────────────────────────────────────┐
│ ID   Type    Target            Priority   Status      Age      Action │
├────────────────────────────────────────────────────────────────────────┤
│ 912  city    Hanoi             200        ⏳ pending   2m       View  │
│ 911  screen  IN-001 (id=3)     100        🔵 running   5m       View  │
│ 910  screen  OD-002 (id=2)     100        🟢 done      12m      View  │
│ 909  screen  OD-001 (id=1)     100        🔴 failed    20m      Retry │
│ ...                                                                    │
└────────────────────────────────────────────────────────────────────────┘
```

### 7.2. Enqueue modals

**Enqueue screen:**
```
┌─────────────────────────────────────┐
│ Enqueue recompute: single screen    │
│                                     │
│ Screen:   [🔍 Search external_id]   │  ← autocomplete từ core.screens
│ Priority: [100] (lower = higher)    │
│                                     │
│ [Cancel]                   [Submit] │
└─────────────────────────────────────┘
```

**Enqueue city:**
```
┌─────────────────────────────────────┐
│ Enqueue recompute: entire city      │
│                                     │
│ City:     [Hanoi ▼]                 │
│ Priority: [200]                     │
│                                     │
│ ⚠ Ước tính N screens active sẽ được │
│   recompute (~M phút cho batch).    │
│                                     │
│ [Cancel]                 [Enqueue]  │
└─────────────────────────────────────┘
```

**Enqueue bulk** (đối tượng: 1 campaign / 1 media owner / 1 venue):
```
┌─────────────────────────────────────┐
│ Enqueue recompute: bulk             │
│                                     │
│ Source:  ( ) Campaign screens       │
│          ( ) By media_owner_name    │
│          ( ) Paste external_ids     │
│          ( ) Upload CSV             │
│                                     │
│ [Preview list: 187 screens]         │
│                                     │
│ [Cancel]                 [Enqueue]  │
└─────────────────────────────────────┘
```

### 7.3. `/admin/oohx/jobs/{id}` — Job detail

```
┌──────────────────────────────────────────────────────────────────┐
│ Job #911                                                         │
│                                                                  │
│ Type:         screen                                             │
│ Screen:       IN-001 (id=3) — Vincom Ba Trieu Entrance           │
│ City:         Hanoi                                              │
│ Priority:     100                                                │
│ Status:       🔴 failed                                          │
│ Retry count:  2                                                  │
│ Requested:    2026-04-20 10:00:05   by nv@oohx.vn                │
│ Started:      2026-04-20 10:02:10                                │
│ Finished:     2026-04-20 10:02:11                                │
│                                                                  │
│ Error message:                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │ Screen 3 has no context metrics — run enrichment first      │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│ Payload:                                                         │
│  { "_actor": { "enqueued": {"by": "nv@...", "at": "..."} } }     │
│                                                                  │
│ [← Back]                          [Retry]  [Cancel]  [Copy JSON] │
└──────────────────────────────────────────────────────────────────┘
```

---

## 8. Integration với guide 01 (formula version activate)

Sau khi activate 1 formula version mới (guide 01), UI nên prompt:

```
✅ Activated version v-2026-04-21.
   Next step: recompute active screens to apply new formula.
   [Enqueue-city: Hanoi]  [Enqueue-city: HCMC]  [Skip]
```

Route shortcut: khi activate xong, Laravel gọi thẳng:

```php
$orchestrator->enqueueCity('Hanoi', priority: 50);  // high priority vì ops vừa request
$orchestrator->enqueueCity('HCMC',  priority: 50);
```

Cron 10 phút sẽ pick lên. Ops có thể chờ hoặc SSH vào Data Engine VPS chạy manual `recompute-pending-jobs` để ép chạy ngay.

---

## 9. Polling / auto-refresh

Không cần WebSocket/SSE. Đơn giản:

- Counter ở header: `setInterval(() => fetch('/admin/oohx/jobs/counts')).then(...), 10000)`.
- Trang list: auto-reload mỗi 30s (`<meta http-equiv="refresh" content="30">` hoặc Livewire `wire:poll.30s`).
- Chi tiết 1 job: tương tự 30s.

Endpoint `GET /admin/oohx/jobs/counts` trả `{pending, processing, done, failed, cancelled}`.

---

## 10. Notification khi job fail

Gợi ý (optional, phase 2.B+):

- Laravel Observer trên model `RecomputeJob`: khi `status` chuyển `failed` và `retry_count >= 3`, gửi Slack/email.
- **Caveat**: Laravel không tự biết status thay đổi (Python update, không qua Eloquent). Cần:
  - Cron Laravel mỗi 5 phút quét `status=failed AND finished_at > NOW() - interval '10m' AND notified_at IS NULL` → thêm cột `notified_at` vào `core.recompute_jobs` (cần migration Data Engine side, hỏi ops).

Hoặc đơn giản hơn: xem số liệu ở dashboard monitoring (guide 05).

---

## 11. Authorization

Gate `manage-oohx-jobs`:

```php
// app/Providers/AuthServiceProvider.php
Gate::define('manage-oohx-jobs', fn ($user) =>
    in_array($user->role, ['admin', 'data_ops'])
);
```

Hoặc policy nếu bạn có `Role`/`Permission` package.

---

## 12. Test plan

### 12.1. Manual test

- [ ] Enqueue screen qua UI, xem job xuất hiện trong `/admin/oohx/jobs` với status `pending`.
- [ ] Ép Python chạy: SSH vào Data Engine VPS, `python -m app.cli recompute-pending-jobs --max 10`.
- [ ] Job chuyển `processing → done` trong UI sau auto-refresh.
- [ ] Enqueue screen với `screen_id` không tồn tại → validator trả 422.
- [ ] Gán screen không có metrics → job fail sau 3 retry → show error_message.
- [ ] Click Retry trên job failed → status về `pending`, `retry_count=0`.
- [ ] Click Cancel trên job pending → status `cancelled`, không chạy.

### 12.2. Integration test

```php
public function test_enqueue_screen_creates_job(): void
{
    $this->actingAs($admin)
        ->post('/admin/oohx/jobs/enqueue-screen', ['screen_id' => 1, 'priority' => 100])
        ->assertRedirect();

    $this->assertDatabaseHas('oohx_control.core.recompute_jobs', [
        'job_type'  => 'screen',
        'screen_id' => 1,
        'status'    => 'pending',
    ]);
}
```

---

## 13. Assumptions cho Claude Code (Laravel side)

1. Data Engine cron `recompute-pending-jobs` đã chạy mỗi 10 phút (phase 1 đã setup). Confirm: `crontab -l` trên Data Engine VPS có dòng tương ứng.
2. CHECK constraint đã mở rộng thêm status `'cancelled'` (báo ops nếu chưa).
3. Có thể thêm cột `notified_at` vào `core.recompute_jobs` không? (chỉ cần khi làm notification ở §10.)
4. Laravel middleware `can:manage-oohx-jobs` đã được define.
5. `oohx_control` user đã có quyền INSERT + UPDATE trên bảng.

---

## 14. Liên quan

- Guide 01 (formula config): activate version → redirect sang flow enqueue-city ở đây.
- Guide 04 (screen inspector): từ trang inspector có button "Re-enqueue this screen".
- Guide 05 (monitoring): dashboard có widget "Jobs failed in last 24h" click vào là filter `/admin/oohx/jobs?status=failed`.

*Updated: 2026-04-20.*
