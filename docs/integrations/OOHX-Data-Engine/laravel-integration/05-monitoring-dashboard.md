# Laravel Integration — 05. Monitoring Dashboard

> **Role of this guide**: tài liệu handoff cho team Laravel.
> Mục đích: 1 dashboard operations duy nhất giúp ops/admin biết hệ thống Data Engine có **healthy** không trong 30 giây nhìn.

---

## 1. Mục tiêu feature

Ops cần trả lời trong 1 trang:

1. **Freshness**: bao nhiêu screen active có estimate, bao nhiêu cũ > 24h, tuổi trung bình.
2. **Jobs health**: bao nhiêu pending / failed / processing hiện tại, tỉ lệ fail 7 ngày qua.
3. **Collectors health**: collector nào có last run > 7 ngày (stale).
4. **Formula version**: đang active version nào, publish khi nào.
5. **Alerts**: danh sách vấn đề cần attention.

Không làm ở guide này:
- ❌ Không là full observability (Prometheus/Grafana). Đây là **operator console trong Laravel admin**.
- ❌ Không log aggregator — đó là ops concern trên VPS.

---

## 2. Prerequisites

- Các connection: `oohx` (read), `oohx_control` (các bảng `collectors.*`, `config.*`).
- Middleware `can:view-oohx-monitoring` define.

---

## 3. Metrics cần hiển thị

### 3.1. Screen freshness

```sql
SELECT
    COUNT(*) FILTER (WHERE s.status = 'active')                                  AS active_total,
    COUNT(e.screen_id) FILTER (WHERE s.status = 'active')                        AS estimated_total,
    COUNT(*) FILTER (WHERE s.status = 'active' AND e.screen_id IS NULL)          AS never_estimated,
    COUNT(*) FILTER (WHERE s.status = 'active'
                     AND e.last_calculated_at < NOW() - INTERVAL '24 hours')     AS stale_over_24h,
    COUNT(*) FILTER (WHERE s.status = 'active'
                     AND e.last_calculated_at < NOW() - INTERVAL '7 days')       AS stale_over_7d,
    EXTRACT(epoch FROM AVG(NOW() - e.last_calculated_at))
        FILTER (WHERE s.status = 'active')                                       AS avg_age_seconds
FROM core.screens s
LEFT JOIN output.screen_traffic_estimates e ON e.screen_id = s.id;
```

### 3.2. Jobs health

```sql
-- Current status counts
SELECT status, COUNT(*) FROM core.recompute_jobs GROUP BY status;

-- 7-day failure rate
SELECT
    COUNT(*)                                         AS total_7d,
    COUNT(*) FILTER (WHERE status = 'failed')        AS failed_7d,
    COUNT(*) FILTER (WHERE status = 'done')          AS done_7d
FROM core.recompute_jobs
WHERE requested_at >= NOW() - INTERVAL '7 days';

-- Oldest pending job (stuck?)
SELECT MIN(requested_at) FROM core.recompute_jobs WHERE status = 'pending';

-- Recent failures
SELECT id, job_type, screen_id, city, error_message, finished_at
FROM core.recompute_jobs
WHERE status = 'failed' AND finished_at >= NOW() - INTERVAL '24 hours'
ORDER BY finished_at DESC LIMIT 10;
```

### 3.3. Collectors health

```sql
-- Latest run per (collector, city)
SELECT DISTINCT ON (collector_name, city)
    collector_name, city, status, rows_ingested,
    finished_at, NOW() - finished_at AS age
FROM collectors.collector_runs
WHERE status IN ('done', 'failed')
ORDER BY collector_name, city, finished_at DESC;

-- Stale collectors (no run in 7d when cadence says should)
-- (define cadence in Laravel config, compare)
```

### 3.4. Formula version

```sql
SELECT tag, description, activated_at, created_by
FROM config.formula_versions
WHERE is_active = TRUE;

-- Diff between active tag and actual output.*: how many screens still on older version
SELECT
    COUNT(*) FILTER (WHERE e.formula_version_id = fv_active.id)      AS on_active,
    COUNT(*) FILTER (WHERE e.formula_version_id != fv_active.id
                     OR e.formula_version_id IS NULL)                AS on_other
FROM output.screen_traffic_estimates e
CROSS JOIN (SELECT id FROM config.formula_versions WHERE is_active = TRUE LIMIT 1) fv_active;
```

### 3.5. Infrastructure (optional)

- DB size: `SELECT pg_size_pretty(pg_database_size('oohx_data'));`
- Biggest tables: `SELECT relname, pg_size_pretty(pg_total_relation_size(oid)) FROM pg_class WHERE ... ORDER BY ... LIMIT 10;`

Các số này chỉ show khi user có quyền `view-oohx-monitoring-advanced`.

---

## 4. Laravel service

`app/Services/Oohx/MonitoringDashboardService.php`:

```php
<?php

namespace App\Services\Oohx;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class MonitoringDashboardService
{
    /** All widgets in 1 call. Cache 60s optional. */
    public function snapshot(): array
    {
        return [
            'freshness'     => $this->freshness(),
            'jobs'          => $this->jobs(),
            'collectors'    => $this->collectors(),
            'formula'       => $this->formula(),
            'alerts'        => $this->computeAlerts(),
            'generated_at'  => now()->toIso8601String(),
        ];
    }

    public function freshness(): array
    {
        $row = DB::connection('oohx')->selectOne("
            SELECT
                COUNT(*) FILTER (WHERE s.status = 'active')                          AS active_total,
                COUNT(e.screen_id) FILTER (WHERE s.status = 'active')                AS estimated_total,
                COUNT(*) FILTER (WHERE s.status = 'active' AND e.screen_id IS NULL)  AS never_estimated,
                COUNT(*) FILTER (WHERE s.status = 'active'
                                 AND e.last_calculated_at < NOW() - INTERVAL '24 hours') AS stale_over_24h,
                COUNT(*) FILTER (WHERE s.status = 'active'
                                 AND e.last_calculated_at < NOW() - INTERVAL '7 days')   AS stale_over_7d,
                EXTRACT(epoch FROM AVG(NOW() - e.last_calculated_at))
                    FILTER (WHERE s.status = 'active')                                   AS avg_age_seconds
            FROM core.screens s
            LEFT JOIN output.screen_traffic_estimates e ON e.screen_id = s.id
        ");

        return [
            'active_total'     => (int) $row->active_total,
            'estimated_total'  => (int) $row->estimated_total,
            'never_estimated'  => (int) $row->never_estimated,
            'stale_over_24h'   => (int) $row->stale_over_24h,
            'stale_over_7d'    => (int) $row->stale_over_7d,
            'avg_age_hours'    => $row->avg_age_seconds === null ? null : round($row->avg_age_seconds / 3600, 1),
            'coverage_pct'     => $row->active_total > 0
                ? round(100 * $row->estimated_total / $row->active_total, 1) : 0,
        ];
    }

    public function jobs(): array
    {
        $counts = DB::connection('oohx')->table('core.recompute_jobs')
            ->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->toArray();

        $seven = DB::connection('oohx')->selectOne("
            SELECT COUNT(*) total,
                   COUNT(*) FILTER (WHERE status='failed') failed,
                   COUNT(*) FILTER (WHERE status='done')   done
            FROM core.recompute_jobs
            WHERE requested_at >= NOW() - INTERVAL '7 days'
        ");

        $oldestPending = DB::connection('oohx')
            ->table('core.recompute_jobs')
            ->where('status', 'pending')
            ->min('requested_at');

        $recentFailures = DB::connection('oohx')->select("
            SELECT id, job_type, screen_id, city,
                   COALESCE(NULLIF(LEFT(error_message, 120), ''), '(no message)') AS error,
                   finished_at
            FROM core.recompute_jobs
            WHERE status = 'failed' AND finished_at >= NOW() - INTERVAL '24 hours'
            ORDER BY finished_at DESC LIMIT 10
        ");

        $failureRate = ($seven->total ?? 0) > 0
            ? round(100 * $seven->failed / $seven->total, 2) : 0.0;

        return [
            'counts'            => $counts,
            'failure_rate_7d'   => $failureRate,
            'total_7d'          => (int) ($seven->total ?? 0),
            'oldest_pending_at' => $oldestPending,
            'recent_failures'   => $recentFailures,
        ];
    }

    public function collectors(): array
    {
        $latest = DB::connection('oohx')->select("
            SELECT DISTINCT ON (collector_name, city)
                collector_name, city, status, rows_ingested, finished_at,
                EXTRACT(epoch FROM (NOW() - finished_at)) AS age_seconds
            FROM collectors.collector_runs
            WHERE status IN ('done', 'failed', 'running')
            ORDER BY collector_name, city, finished_at DESC NULLS FIRST
        ");

        $byName = collect($latest)->groupBy('collector_name');

        $stale = [];
        foreach (config('oohx_collectors', []) as $name => $meta) {
            $maxAgeSeconds = $this->cadenceToSeconds($meta['cadence'] ?? null);
            if ($maxAgeSeconds === null) continue;

            foreach (($byName[$name] ?? []) as $r) {
                if ($r->age_seconds !== null && $r->age_seconds > $maxAgeSeconds) {
                    $stale[] = [
                        'collector'   => $name,
                        'city'        => $r->city,
                        'age_days'    => round($r->age_seconds / 86400, 1),
                        'max_age_days'=> round($maxAgeSeconds / 86400, 1),
                    ];
                }
            }
        }

        return ['latest' => $latest, 'stale' => $stale];
    }

    public function formula(): array
    {
        $active = DB::connection('oohx')->selectOne("
            SELECT id, tag, description, activated_at, created_by
            FROM config.formula_versions
            WHERE is_active = TRUE LIMIT 1
        ");

        if (!$active) return ['active' => null, 'coverage' => null];

        $coverage = DB::connection('oohx')->selectOne("
            SELECT
                COUNT(*) FILTER (WHERE formula_version_id = ?)                          AS on_active,
                COUNT(*) FILTER (WHERE formula_version_id != ? OR formula_version_id IS NULL) AS on_other
            FROM output.screen_traffic_estimates
        ", [$active->id, $active->id]);

        return [
            'active'    => $active,
            'coverage'  => [
                'on_active' => (int) ($coverage->on_active ?? 0),
                'on_other'  => (int) ($coverage->on_other ?? 0),
            ],
        ];
    }

    /** Derive actionable alerts from raw metrics. */
    public function computeAlerts(): array
    {
        $alerts = [];
        $f = $this->freshness();
        $j = $this->jobs();
        $c = $this->collectors();
        $fm = $this->formula();

        if ($f['never_estimated'] > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title'    => "{$f['never_estimated']} active screens chưa có estimate",
                'action'   => ['text' => 'Enqueue city recompute', 'url' => route('admin.oohx.jobs.index')],
            ];
        }
        if ($f['stale_over_24h'] > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title'    => "{$f['stale_over_24h']} screens có estimate cũ > 24h",
            ];
        }
        if (($j['counts']['pending'] ?? 0) > 100) {
            $alerts[] = [
                'severity' => 'warning',
                'title'    => "Queue pending {$j['counts']['pending']} — worker bị quá tải hoặc đã dừng?",
            ];
        }
        if ($j['failure_rate_7d'] > 5) {
            $alerts[] = [
                'severity' => 'danger',
                'title'    => "Failure rate 7d = {$j['failure_rate_7d']}% (threshold 5%)",
                'action'   => ['text' => 'Xem failed jobs',
                               'url'  => route('admin.oohx.jobs.index', ['status' => 'failed'])],
            ];
        }
        if ($j['oldest_pending_at'] !== null) {
            $mins = now()->diffInMinutes($j['oldest_pending_at']);
            if ($mins > 30) {
                $alerts[] = [
                    'severity' => 'danger',
                    'title'    => "Pending job cũ nhất {$mins} phút — cron Python có đang chạy?",
                ];
            }
        }
        foreach ($c['stale'] as $s) {
            $alerts[] = [
                'severity' => 'info',
                'title'    => "Collector {$s['collector']} / {$s['city']} chưa refresh {$s['age_days']}d (cadence {$s['max_age_days']}d)",
                'action'   => ['text' => 'Trigger collector',
                               'url'  => route('admin.oohx.collectors.index')],
            ];
        }
        if ($fm['coverage'] && $fm['coverage']['on_other'] > 0) {
            $alerts[] = [
                'severity' => 'info',
                'title'    => "Có {$fm['coverage']['on_other']} estimate vẫn dùng formula cũ. Enqueue recompute để update.",
            ];
        }

        return $alerts;
    }

    private function cadenceToSeconds(?string $cadence): ?int
    {
        return match ($cadence) {
            'every 6 hours'   => 6 * 3600 * 2,                    // 2x grace
            'daily'           => 86400 * 2,
            'weekly'          => 7 * 86400 * 2,
            'monthly'         => 30 * 86400 * 2,
            'yearly'          => 365 * 86400 * 2,
            default           => null,
        };
    }
}
```

---

## 5. Routes + Controller

```php
use App\Http\Controllers\Admin\Oohx\MonitoringController;

Route::middleware(['auth', 'can:view-oohx-monitoring'])
    ->prefix('admin/oohx')
    ->name('admin.oohx.')
    ->group(function () {
        Route::get('monitoring',        [MonitoringController::class, 'index'])->name('monitoring.index');
        Route::get('monitoring/json',   [MonitoringController::class, 'json'])->name('monitoring.json'); // cho auto-refresh
    });
```

```php
<?php

namespace App\Http\Controllers\Admin\Oohx;

use App\Http\Controllers\Controller;
use App\Services\Oohx\MonitoringDashboardService;
use Illuminate\Support\Facades\Cache;

class MonitoringController extends Controller
{
    public function __construct(private MonitoringDashboardService $svc) {}

    public function index()
    {
        $snapshot = Cache::remember('oohx:dashboard', 60, fn () => $this->svc->snapshot());
        return view('admin.oohx.monitoring.index', compact('snapshot'));
    }

    public function json()
    {
        $snapshot = Cache::remember('oohx:dashboard', 60, fn () => $this->svc->snapshot());
        return response()->json($snapshot);
    }
}
```

Cache 60s vì các query này không cần realtime.

---

## 6. UI specification

```
╔════════════════════════════════════════════════════════════════════════╗
║  OOHX Monitoring — refreshed 12s ago  [🔄]                             ║
╠════════════════════════════════════════════════════════════════════════╣
║                                                                        ║
║  🚨 Alerts (3)                                                         ║
║  ─────────────                                                         ║
║  🔴 Pending job cũ nhất 48 phút — cron Python có đang chạy?            ║
║  🟡 12 screens có estimate cũ > 24h                                    ║
║  ℹ️  Collector overpass_poi / HCMC chưa refresh 12d (cadence 14d)      ║
║                                                                        ║
╠════════════════════════════════════════════════════════════════════════╣
║                                                                        ║
║ ┌──────────────────┬───────────────────┬──────────────────────────┐    ║
║ │ Active screens   │ Coverage          │ Avg age                  │    ║
║ │       5,847      │       99.8%       │      2.3 h               │    ║
║ └──────────────────┴───────────────────┴──────────────────────────┘    ║
║ ┌──────────────────┬───────────────────┬──────────────────────────┐    ║
║ │ Never estimated  │ Stale > 24h       │ Stale > 7d               │    ║
║ │        12        │       137         │         0                │    ║
║ └──────────────────┴───────────────────┴──────────────────────────┘    ║
║                                                                        ║
╠════════════════════════════════════════════════════════════════════════╣
║ 📋 Jobs                                                                ║
║ ─────                                                                  ║
║ Current queue: 🟡 pending=23  🔵 processing=1  🟢 done=5,841  🔴 failed=6║
║ Last 7d: total=6,142 · failure rate=0.09% · oldest pending: 5m ago     ║
║                                                                        ║
║ Recent failures (last 24h):                                            ║
║  #912 city     HCMC       "no screen active"          5m ago           ║
║  #899 screen   id=42      "missing lat/lon"          2h ago            ║
║  ...                                                                   ║
║  [View all failed →]                                                   ║
║                                                                        ║
╠════════════════════════════════════════════════════════════════════════╣
║ 🛠 Collectors                                                          ║
║ ───────────                                                            ║
║ overpass_poi        Hanoi      🟢 done  2d ago  (3,472 rows)           ║
║ overpass_poi        HCMC       ⚠️  done 12d ago (stale)                 ║
║ open_meteo_weather  Hanoi      🟢 done  1h ago                         ║
║ open_meteo_weather  HCMC       🟢 done  1h ago                         ║
║ osm_roads           Hanoi      🟢 done  25d ago                        ║
║ worldpop_population Hanoi      🟢 done  92d ago                        ║
║                                                                        ║
║  [Go to collectors →]                                                  ║
║                                                                        ║
╠════════════════════════════════════════════════════════════════════════╣
║ 📐 Formula                                                             ║
║ ─────────                                                              ║
║ Active: v-2026-04-20 ("Post-Tet calibration")                          ║
║ Activated 2d ago by nv@oohx.vn                                         ║
║ Coverage: 5,834 estimates on this version · 13 still on older version  ║
║                                                                        ║
║  [Enqueue recompute to sync →]   [View version history →]              ║
║                                                                        ║
╚════════════════════════════════════════════════════════════════════════╝
```

Auto-refresh: trang reload mỗi 60s (meta refresh hoặc Livewire poll).

### 6.1. Color rules

- Coverage ≥ 99% → green.
- Coverage 90–99% → yellow.
- Coverage < 90% → red.
- Failure rate < 1% green, 1–5% yellow, > 5% red.
- Stale > cadence × 2 → info.
- Stale > cadence × 3 → warning.

---

## 7. Alerts routing (optional, phase 2.E+)

Nếu muốn gửi Slack/email:

- Laravel cron mỗi 5 phút chạy `oohx:monitoring-check`:
  ```php
  Artisan::command('oohx:monitoring-check', function () {
      $alerts = app(MonitoringDashboardService::class)->computeAlerts();
      $critical = array_filter($alerts, fn ($a) => $a['severity'] === 'danger');
      if (!empty($critical)) {
          Notification::send(
              User::where('role', 'data_ops')->get(),
              new OohxCriticalAlertNotification($critical),
          );
      }
  });
  ```
- Throttle (không gửi quá 1 lần/giờ cho cùng alert key): dùng `Cache::add("alert:$key", true, 3600)`.

---

## 8. Permissions

```php
Gate::define('view-oohx-monitoring',        fn ($u) => in_array($u->role, ['admin', 'data_ops', 'sales_lead']));
Gate::define('view-oohx-monitoring-advanced', fn ($u) => in_array($u->role, ['admin', 'data_ops']));
```

Advanced = show DB size, biggest tables, internal runs.

---

## 9. Test plan

- [ ] Truy cập `/admin/oohx/monitoring` trong ngữ cảnh hệ thống khoẻ → 0 alert, mọi widget xanh.
- [ ] Force seed: ingest 10 screens mới không enqueue → alert "10 never estimated" xuất hiện.
- [ ] Enqueue-city Hanoi, không cho cron chạy → sau 30 phút alert "pending oldest > 30m" xuất hiện.
- [ ] Cắt 1 collector không chạy 2 tuần → alert stale xuất hiện.
- [ ] Activate formula version mới mà không recompute → alert "X on older version" xuất hiện.
- [ ] Verify cache: mở 10 tab, check query count DB tăng chỉ 1 lần trong 60s.

---

## 10. Performance

- 5 query aggregate + 1 cho `snapshot()`, tổng < 50ms với index đầy đủ.
- Cache 60s giảm tải ~60x cho trang có nhiều admin xem đồng thời.
- Không mở connection thứ 3 — tái dùng `oohx` (read).

---

## 11. Assumptions cho Claude Code

1. `oohx_control` có SELECT trên `collectors.*` và `config.*` (đã include trong guide 01).
2. Cột `formula_version_id` đã được ALTER vào `output.screen_traffic_estimates`.
3. `config/oohx_collectors.php` đã tồn tại với `cadence` field (guide 03).
4. Middleware `can:view-oohx-monitoring` đã define.
5. Alert threshold (5% failure rate, 30 phút oldest pending) có thể điều chỉnh sau.

---

## 12. Liên quan

- Guide 01: click "View version history" → `admin.oohx.versions.index`.
- Guide 02: click "View all failed" → `admin.oohx.jobs.index?status=failed`.
- Guide 03: click "Go to collectors" → `admin.oohx.collectors.index`.
- Guide 04: alert "X never estimated" có thể liên kết tới list màn hình chưa estimate để inspect.

*Updated: 2026-04-20.*
