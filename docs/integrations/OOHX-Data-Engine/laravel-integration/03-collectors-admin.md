# Laravel Integration — 03. Collectors Admin

> **Role of this guide**: tài liệu handoff cho team Laravel, ứng với **Mục tiêu 3** của [OOHX-UPGRADE-PROPOSAL.md](../../OOHX-UPGRADE-PROPOSAL.md).
> Mục đích: Laravel admin trigger + monitor external data collectors — POI (Overpass), roads (osm2pgsql), weather (Open-Meteo), population (WorldPop).

---

## 1. Mục tiêu feature

Ops cần:

1. **Xem** danh sách collector khả dụng (name, source, cadence khuyến nghị).
2. **Trigger** 1 collector run cho 1 city (hoặc bbox tuỳ ý).
3. **Theo dõi** run đang chạy + lịch sử 50 run gần nhất.
4. **Xem stats** 1 run: bao nhiêu rows ingested, bytes fetched, duration, error.
5. **Quota awareness**: Overpass rate limit, Open-Meteo free tier — cảnh báo trước khi trigger nhiều quá.

Không làm ở guide này:
- ❌ Không gọi thẳng Overpass/WorldPop từ Laravel — Python collectors lo.
- ❌ Không manage `source.pois/roads/venues/...` rows — chỉ orchestration.

---

## 2. Prerequisites

- Migration `sql/007_collectors_tables.sql` đã apply bên Data Engine.
- Các bảng `source.weather_snapshots`, `source.population_grid`, `source.traffic_samples` đã tồn tại (cùng migration hoặc tách riêng).
- Role `oohx_control` có `INSERT`, `UPDATE` trên `collectors.collector_runs`.
- Python side đã implement `Collector` base + 2+ collectors.
- Cron Python đã có `run-pending-collectors --max 5` mỗi 15 phút.

---

## 3. Schema reference

### 3.1. `collectors.collector_runs` (queue + history)

```sql
CREATE TABLE collectors.collector_runs (
    id              BIGSERIAL PRIMARY KEY,
    collector_name  TEXT NOT NULL,                     -- 'overpass_poi', 'open_meteo_weather', ...
    city            TEXT,
    bbox            geography(Polygon, 4326),
    params          JSONB NOT NULL DEFAULT '{}'::jsonb, -- collector-specific
    status          TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','running','done','failed','cancelled')),
    priority        INTEGER NOT NULL DEFAULT 100,
    retry_count     INTEGER NOT NULL DEFAULT 0,
    rows_ingested   INTEGER NOT NULL DEFAULT 0,
    bytes_fetched   BIGINT NOT NULL DEFAULT 0,
    stats           JSONB NOT NULL DEFAULT '{}'::jsonb, -- api_calls, cache_hits, ...
    error_message   TEXT,
    requested_by    TEXT,
    requested_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at      TIMESTAMPTZ,
    finished_at     TIMESTAMPTZ
);
```

### 3.2. `collectors.collector_cache` (Laravel KHÔNG cần đọc)

Raw API response cache, Python quản lý. Bỏ qua cho UI.

### 3.3. Collector metadata (hardcode phía Laravel)

Laravel không có bảng metadata collectors — trực tiếp define trong Laravel
config file `config/oohx_collectors.php`:

```php
<?php

return [
    'overpass_poi' => [
        'display_name'      => 'POI (OpenStreetMap / Overpass API)',
        'description'       => 'Fetch POI points by category within city bbox.',
        'provider'          => 'Overpass API',
        'cost'              => 'free',
        'rate_limit'        => '10,000 queries/day per IP',
        'cadence'           => 'weekly',
        'supports_city'     => true,
        'supports_bbox'     => true,
        'params_schema'     => [
            'categories' => ['type' => 'array', 'default' => null, 'example' => ['restaurant','cafe','shop']],
            'radius_m'   => ['type' => 'integer', 'default' => null, 'note' => 'Ignored if city set'],
        ],
    ],
    'osm_roads' => [
        'display_name'  => 'Roads (osm2pgsql)',
        'description'   => 'Import OSM road network into source.roads (primary, secondary, tertiary, residential, service, motorway, trunk).',
        'provider'      => 'OpenStreetMap extract',
        'cost'          => 'free',
        'rate_limit'    => '~1 full VN extract per week',
        'cadence'       => 'monthly',
        'supports_city' => true,
        'supports_bbox' => false,
    ],
    'open_meteo_weather' => [
        'display_name'  => 'Weather snapshot (Open-Meteo)',
        'description'   => 'Current + next 24h weather forecast for city centroid.',
        'provider'      => 'Open-Meteo',
        'cost'          => 'free (no API key required)',
        'rate_limit'    => '10,000 calls/day free tier',
        'cadence'       => 'every 6 hours',
        'supports_city' => true,
        'supports_bbox' => false,
    ],
    'worldpop_population' => [
        'display_name'  => 'Population density (WorldPop 100m)',
        'description'   => 'Import WorldPop raster tile into source.population_grid.',
        'provider'      => 'WorldPop (hub.worldpop.org)',
        'cost'          => 'free',
        'rate_limit'    => 'N/A (static download)',
        'cadence'       => 'yearly',
        'supports_city' => true,
        'supports_bbox' => false,
        'params_schema' => [
            'year' => ['type' => 'integer', 'default' => 2020],
        ],
    ],
];
```

---

## 4. Laravel model

`app/Models/Oohx/CollectorRun.php`:

```php
<?php

namespace App\Models\Oohx;

use Illuminate\Database\Eloquent\Model;

class CollectorRun extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'collectors.collector_runs';
    public    $timestamps = false;

    protected $fillable = [
        'collector_name', 'city', 'bbox', 'params',
        'status', 'priority', 'retry_count',
        'rows_ingested', 'bytes_fetched', 'stats',
        'error_message', 'requested_by', 'requested_at',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'params'       => 'array',
        'stats'        => 'array',
        'requested_at' => 'datetime',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function getDurationSecondsAttribute(): ?int
    {
        if (!$this->started_at || !$this->finished_at) return null;
        return $this->finished_at->diffInSeconds($this->started_at);
    }

    public function scopeRecent($q, int $days = 7)
    {
        return $q->where('requested_at', '>=', now()->subDays($days));
    }
}
```

---

## 5. Service

`app/Services/Oohx/CollectorManager.php`:

```php
<?php

namespace App\Services\Oohx;

use App\Models\Oohx\CollectorRun;
use Illuminate\Support\Facades\Auth;

class CollectorManager
{
    public function listCollectors(): array
    {
        return config('oohx_collectors', []);
    }

    /**
     * Enqueue 1 collector run. Python cron `run-pending-collectors` sẽ pick lên.
     *
     * @throws \InvalidArgumentException nếu collector_name không tồn tại
     */
    public function trigger(string $name, ?string $city = null, array $params = [], int $priority = 100): CollectorRun
    {
        $meta = config("oohx_collectors.$name")
            ?? throw new \InvalidArgumentException("Unknown collector: $name");

        if ($meta['supports_city'] && !$city) {
            throw new \InvalidArgumentException("$name requires city");
        }

        return CollectorRun::create([
            'collector_name' => $name,
            'city'           => $city,
            'params'         => $params,
            'status'         => 'pending',
            'priority'       => $priority,
            'retry_count'    => 0,
            'requested_by'   => Auth::user()?->email ?? 'system',
            'requested_at'   => now(),
        ]);
    }

    public function cancel(int $runId): CollectorRun
    {
        $run = CollectorRun::findOrFail($runId);
        abort_unless($run->status === 'pending', 422, 'Only pending runs can be cancelled');

        $run->update([
            'status'      => 'cancelled',
            'finished_at' => now(),
        ]);
        return $run;
    }

    public function latestByCollector(): array
    {
        $rows = \DB::connection('oohx_control')->select("
            SELECT DISTINCT ON (collector_name, city)
                collector_name, city, status,
                requested_at, finished_at, rows_ingested
            FROM collectors.collector_runs
            ORDER BY collector_name, city, requested_at DESC
        ");
        return $rows;
    }

    public function counts(): array
    {
        return \DB::connection('oohx_control')->table('collectors.collector_runs')
            ->selectRaw('status, COUNT(*) c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();
    }
}
```

---

## 6. Routes + Controller

`routes/web.php`:

```php
use App\Http\Controllers\Admin\Oohx\CollectorController;

Route::middleware(['auth', 'can:manage-oohx-collectors'])
    ->prefix('admin/oohx/collectors')
    ->name('admin.oohx.collectors.')
    ->group(function () {
        Route::get ('/',              [CollectorController::class, 'index'])->name('index');
        Route::get ('/runs',          [CollectorController::class, 'runs'])->name('runs');
        Route::get ('/runs/{id}',     [CollectorController::class, 'show'])->name('run.show');
        Route::post('/trigger',       [CollectorController::class, 'trigger'])->name('trigger');
        Route::post('/runs/{id}/cancel', [CollectorController::class, 'cancel'])->name('run.cancel');
    });
```

`app/Http/Controllers/Admin/Oohx/CollectorController.php`:

```php
<?php

namespace App\Http\Controllers\Admin\Oohx;

use App\Http\Controllers\Controller;
use App\Models\Oohx\CollectorRun;
use App\Services\Oohx\CollectorManager;
use Illuminate\Http\Request;

class CollectorController extends Controller
{
    public function __construct(private CollectorManager $svc) {}

    public function index()
    {
        return view('admin.oohx.collectors.index', [
            'collectors' => $this->svc->listCollectors(),
            'latest'     => collect($this->svc->latestByCollector())->groupBy('collector_name'),
            'counts'     => $this->svc->counts(),
        ]);
    }

    public function runs(Request $request)
    {
        $q = CollectorRun::query()->orderByDesc('requested_at');

        if ($name = $request->query('collector')) $q->where('collector_name', $name);
        if ($city = $request->query('city'))      $q->where('city', $city);
        if ($status = $request->query('status'))  $q->where('status', $status);

        return view('admin.oohx.collectors.runs', [
            'runs'   => $q->paginate(50)->withQueryString(),
            'filter' => $request->only(['collector','city','status']),
        ]);
    }

    public function show(int $id)
    {
        $run  = CollectorRun::findOrFail($id);
        $meta = config("oohx_collectors.{$run->collector_name}");
        return view('admin.oohx.collectors.run_show', compact('run', 'meta'));
    }

    public function trigger(Request $request)
    {
        $data = $request->validate([
            'collector' => 'required|string',
            'city'      => 'nullable|string|max:100',
            'params'    => 'nullable|array',
            'priority'  => 'nullable|integer|min:1|max:1000',
        ]);

        $run = $this->svc->trigger(
            $data['collector'],
            $data['city'] ?? null,
            $data['params'] ?? [],
            $data['priority'] ?? 100,
        );

        return back()->with('status', "Triggered {$run->collector_name} (run #$run->id) for ".($run->city ?? 'no-city'));
    }

    public function cancel(int $id)
    {
        $this->svc->cancel($id);
        return back()->with('status', "Cancelled run #$id");
    }
}
```

---

## 7. UI specification

### 7.1. `/admin/oohx/collectors` — Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Collectors — counts: pending=2  running=1  done=51  failed=3            │
├─────────────────────────────────────────────────────────────────────────┤
│ POI (OpenStreetMap / Overpass API)                                      │
│   Cost: free    Rate limit: 10,000/day    Cadence: weekly               │
│   Last run — Hanoi: ✅ done 2d ago (3,472 rows ingested)                 │
│   Last run — HCMC:  ✅ done 5d ago (4,118 rows)                          │
│   [Trigger for Hanoi]  [Trigger for HCMC]  [Custom...]                  │
├─────────────────────────────────────────────────────────────────────────┤
│ Roads (osm2pgsql)                                                       │
│   Cost: free    Cadence: monthly                                        │
│   Last run — Hanoi: ⚠️ never                                            │
│   [Trigger for Hanoi]  [Trigger for HCMC]                               │
├─────────────────────────────────────────────────────────────────────────┤
│ Weather snapshot (Open-Meteo)                                           │
│   Cost: free    Cadence: every 6h                                       │
│   Last run — Hanoi: 🔵 running                                          │
│   Last run — HCMC:  ✅ done 1h ago                                       │
│   [Trigger for Hanoi]  [Trigger for HCMC]                               │
├─────────────────────────────────────────────────────────────────────────┤
│ Population density (WorldPop 100m)                                      │
│   Cost: free    Cadence: yearly                                         │
│   Last run — Hanoi: ✅ done 3mo ago (year=2020)                          │
│   [Trigger for Hanoi]  [Trigger for HCMC]                               │
└─────────────────────────────────────────────────────────────────────────┘

[View all runs →]
```

### 7.2. `/admin/oohx/collectors/runs` — Run history

Filter: collector / city / status. Table:

```
ID   Collector          City    Status     Rows    Bytes   Duration  Requested         Action
512  overpass_poi       Hanoi   🔵 running  —       —       —         2m ago  @nv        View  Cancel
511  open_meteo_weather Hanoi   🟢 done     4       6.2 KB  3s        10m ago @system    View
510  overpass_poi       HCMC    🟢 done     4,118   620 KB  2m 14s    2d ago  @nv        View
509  overpass_poi       Hanoi   🔴 failed   0       0       1s        3d ago  @nv        View  Retry
...
```

### 7.3. `/admin/oohx/collectors/runs/{id}` — Run detail

```
┌──────────────────────────────────────────────────────────────────┐
│ Collector run #511                                               │
│                                                                  │
│ Collector:     open_meteo_weather                                │
│ City:          Hanoi                                             │
│ Status:        🟢 done                                           │
│ Priority:      100                                               │
│ Retry count:   0                                                 │
│ Rows ingested: 4                                                 │
│ Bytes fetched: 6,247                                             │
│ Duration:      3s                                                │
│                                                                  │
│ Requested:     2026-04-20 10:00  by @system                      │
│ Started:       2026-04-20 10:00:05                               │
│ Finished:      2026-04-20 10:00:08                               │
│                                                                  │
│ Params:                                                          │
│  { }                                                             │
│                                                                  │
│ Stats:                                                           │
│  {                                                               │
│    "api_calls":  1,                                              │
│    "cache_hits": 0,                                              │
│    "inserted":   4,                                              │
│    "updated":    0                                               │
│  }                                                               │
│                                                                  │
│ Error:         —                                                 │
└──────────────────────────────────────────────────────────────────┘
```

---

## 8. Trigger flow — end-to-end

1. Admin click "Trigger for Hanoi" trên POI row.
2. Modal confirm (nếu có params optional — show form):
   ```
   Trigger overpass_poi for Hanoi?
   Categories: [ ] shopping  [ ] food  [ ] transport  ...  (optional)
   Priority:   [100]
   [Cancel] [Submit]
   ```
3. POST `/admin/oohx/collectors/trigger` → `CollectorManager::trigger()` → INSERT row `status=pending`.
4. Python cron `run-pending-collectors` (mỗi 15 phút) pick lên → status=`running`.
5. Python collector fetch Overpass (có cache), ingest vào `source.pois`.
6. Python update row: `status='done'`, `rows_ingested=3472`, `stats={...}`, `finished_at=NOW()`.
7. Laravel UI auto-refresh 15s sau thấy status thay đổi.

### Manual force-run (khi không muốn đợi 15 phút)

Ops có thể SSH vào Data Engine VPS:
```bash
.venv/bin/python -m app.cli run-pending-collectors --max 5
```

Không nên expose lệnh này qua UI Laravel — giữ ranh giới.

---

## 9. Cost & rate-limit awareness

### 9.1. Overpass

- Rate limit ~10,000 queries/day/IP.
- 1 POI run Hanoi ≈ 1 query (với bbox city).
- Cache hit tỉ lệ cao nếu chạy lại trong 24h.
- Laravel UI cảnh báo nếu trigger > 20 lần/ngày:
  ```
  ⚠ Bạn đã trigger overpass_poi 23 lần hôm nay.
    Overpass có rate limit 10k/day. Đảm bảo không chạy loop?
  ```

### 9.2. Open-Meteo

- Free 10,000 calls/day.
- 1 city = 1 call. 4 runs/ngày × 3 city = 12 calls/ngày. Xa ngưỡng.

### 9.3. WorldPop

- Dataset static, download 1 lần/năm/city.
- Không cần rate-limit awareness.

### 9.4. osm2pgsql (roads)

- Download PBF VN ~500 MB, chạy 1–2 lần/tháng là đủ.
- Laravel UI nên cảnh báo:
  ```
  ⚠ Roads collector sẽ tải ~500 MB và xử lý 5–10 phút.
    Chỉ chạy khi thực sự cần refresh.
  ```

---

## 10. Integration với recompute flow

Sau khi 1 collector chạy xong, admin có thể muốn recompute (context metrics + estimates) để dùng data mới:

- Collector POI xong → cảnh báo:
  ```
  ✅ POI Hanoi done (3,472 rows). Context metrics cũ vẫn dùng POI count cũ.
     [Enqueue recompute-city Hanoi]   [Skip]
  ```
- Click → gọi `JobOrchestrator::enqueueCity('Hanoi')` (guide 02).

---

## 11. Authorization

```php
Gate::define('manage-oohx-collectors', fn ($user) =>
    in_array($user->role, ['admin', 'data_ops'])
);
```

---

## 12. Test plan

### 12.1. Manual

- [ ] Trigger `open_meteo_weather` Hanoi → xem run xuất hiện với `status=pending`.
- [ ] SSH Data Engine: `python -m app.cli run-pending-collectors --max 1`.
- [ ] Run chuyển `done`, rows_ingested ≥ 1.
- [ ] Query trực tiếp: `SELECT * FROM source.weather_snapshots WHERE city='Hanoi' ORDER BY observed_at DESC LIMIT 5;` → thấy data mới.
- [ ] Trigger collector không tồn tại → 422.
- [ ] Trigger collector supports_city=true mà không điền city → 422.

### 12.2. Integration

```php
public function test_trigger_collector_creates_run(): void
{
    $this->actingAs($admin)
        ->post('/admin/oohx/collectors/trigger', [
            'collector' => 'open_meteo_weather',
            'city'      => 'Hanoi',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('oohx_control.collectors.collector_runs', [
        'collector_name' => 'open_meteo_weather',
        'city'           => 'Hanoi',
        'status'         => 'pending',
    ]);
}
```

---

## 13. Assumptions cho Claude Code

1. Migration `sql/007_collectors_tables.sql` đã chạy.
2. Python Python `Collector` class + 2+ implementation đã có.
3. `run-pending-collectors` CLI đã chạy cron 15 phút.
4. `config/oohx_collectors.php` phía Laravel cần tạo mới theo §3.3.
5. Metadata (cost, rate_limit, cadence) có thể thay đổi nếu provider thay đổi — sync với ops.
6. Middleware `can:manage-oohx-collectors` đã define.

---

## 14. Liên quan

- Guide 01 (formula config): sau khi collector cập nhật POI, có thể cần update `poi_factor` coefficient.
- Guide 02 (jobs): mỗi collector run xong thường kéo theo 1 enqueue-city (recompute).
- Guide 05 (monitoring): dashboard có widget "Collectors with stale data >7 days" cảnh báo ops refresh.

*Updated: 2026-04-20.*
