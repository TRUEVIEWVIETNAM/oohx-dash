# OOHX — Laravel ↔ Data Engine Integration Guide

Hướng dẫn tích hợp app Laravel (OOHX marketplace) với service Python + PostGIS
(OOHX Data Engine) chạy trên VPS riêng.

> Đây là file đưa cho Claude Code ở **Laravel side** để triển khai.
> File này **không** thay đổi code bên Data Engine — bên đó đã MVP-ready.

---

## 1. Bối cảnh

Hệ thống OOHX gồm 2 VPS:

```
┌──────────────────────────┐       ┌──────────────────────────┐
│ LARAVEL VPS              │       │ DATA ENGINE VPS          │
│ 172.104.188.62           │       │ 139.162.20.95            │
│                          │       │                          │
│ - Laravel app            │       │ - PostgreSQL 16 + PostGIS│
│ - MySQL (inventory,      │       │ - Python Data Engine     │
│   user, billing, ...)    │       │ - cron jobs              │
│                          │       │ - schemas:               │
│                          │       │     core, source,        │
│                          │       │     metrics, output      │
└──────────────────────────┘       └──────────────────────────┘
```

Data Engine:
- Nhận **screens** từ Laravel (ingest).
- Enrich context không gian (nearest road, POI, venue footfall).
- Tính rule-based estimate: `daily_passby`, `daily_ots`, `daily_impressions`,
  `weekly`/`monthly`, `confidence_score`.
- Ghi ra `output.screen_traffic_estimates`.
- Laravel **chỉ đọc** schema `output` (+ `core.screens` để join external_id).

Luồng dữ liệu:

```
Laravel ──(30m)──► export JSON ──rsync──► Data Engine inbox
                                              │
                                              ▼
                                          ingest-screens
                                              │
                              cron(10m) ──► recompute-pending-jobs
                                              │
                                              ▼
Laravel ◄── SSH tunnel :5433→:5432 ──  output.screen_traffic_estimates
        (SELECT only, user oohx_readonly)
```

**Không realtime.** Laravel luôn đọc dữ liệu **precomputed**.

---

## 2. Prerequisites (đã setup sẵn, chỉ verify)

Trên Laravel VPS:

| Thành phần | Trạng thái | Verify |
|---|---|---|
| SSH key `/root/.ssh/oohx_tunnel` | đã tạo | `ls -la /root/.ssh/oohx_tunnel` |
| SSH key `/root/.ssh/oohx_sync`   | đã tạo | `ls -la /root/.ssh/oohx_sync` |
| systemd `oohx-pg-tunnel.service` | `active (running)` | `sudo systemctl status oohx-pg-tunnel` |
| Port 5433 listen              | có | `sudo ss -tnlp \| grep 5433` |
| DB connect qua tunnel          | ok | `psql -h 127.0.0.1 -p 5433 -U oohx_readonly -d oohx_data -c "SELECT 1;"` |

Nếu tunnel service không chạy, xem `journalctl -u oohx-pg-tunnel -n 50`.

---

## 3. PHẦN A — Laravel đọc estimates (INBOUND)

### A.1 Cài PHP driver

```bash
php -v                                 # ghi nhận version
sudo apt install -y php-pgsql
sudo systemctl reload php8.3-fpm 2>/dev/null \
    || sudo systemctl reload php8.2-fpm 2>/dev/null \
    || sudo systemctl reload php8.1-fpm 2>/dev/null
php -m | grep -i pgsql                 # pdo_pgsql + pgsql phải xuất hiện
```

### A.2 `.env` — thêm block mới, không đụng `DB_*` gốc MySQL

```env
# OOHX Data Engine (read-only, qua SSH tunnel)
DB_OOHX_HOST=127.0.0.1
DB_OOHX_PORT=5433
DB_OOHX_DATABASE=oohx_data
DB_OOHX_USERNAME=oohx_readonly
DB_OOHX_PASSWORD=<paste password ở đây>
```

> Password `oohx_readonly` do ops đặt khi `CREATE ROLE`. Hỏi ops nếu không có.

### A.3 `config/database.php` — thêm connection

Trong mảng `connections`:

```php
'oohx' => [
    'driver'      => 'pgsql',
    'host'        => env('DB_OOHX_HOST', '127.0.0.1'),
    'port'        => env('DB_OOHX_PORT', 5433),
    'database'    => env('DB_OOHX_DATABASE', 'oohx_data'),
    'username'    => env('DB_OOHX_USERNAME', 'oohx_readonly'),
    'password'    => env('DB_OOHX_PASSWORD', ''),
    'charset'     => 'utf8',
    'prefix'      => '',
    'schema'      => 'output',
    'sslmode'     => 'prefer',
    'search_path' => 'output,core,public',
],
```

Clear:

```bash
php artisan config:clear
```

### A.4 Eloquent Models

**`app/Models/Oohx/Screen.php`**

```php
<?php

namespace App\Models\Oohx;

use Illuminate\Database\Eloquent\Model;

class Screen extends Model
{
    protected $connection = 'oohx';
    protected $table      = 'core.screens';
    public    $timestamps = true;

    protected $casts = [
        'lat'                => 'float',
        'lon'                => 'float',
        'source_updated_at'  => 'datetime',
        'synced_at'          => 'datetime',
    ];

    public function estimate()
    {
        return $this->hasOne(ScreenEstimate::class, 'screen_id');
    }
}
```

**`app/Models/Oohx/ScreenEstimate.php`**

```php
<?php

namespace App\Models\Oohx;

use Illuminate\Database\Eloquent\Model;

class ScreenEstimate extends Model
{
    protected $connection = 'oohx';
    protected $table      = 'output.screen_traffic_estimates';
    protected $primaryKey = 'screen_id';
    public    $incrementing = false;
    public    $timestamps = false;

    protected $casts = [
        'estimated_daily_passby'          => 'float',
        'estimated_daily_screen_flow'     => 'float',
        'estimated_daily_ots'             => 'float',
        'estimated_daily_impressions'     => 'float',
        'estimated_weekly_impressions'    => 'float',
        'estimated_monthly_impressions'   => 'float',
        'estimated_daily_reach'           => 'float',
        'estimated_weekly_reach'          => 'float',
        'estimated_monthly_reach'         => 'float',
        'estimated_frequency'             => 'float',
        'impression_multiplier'           => 'float',
        'estimated_cpm'                   => 'float',
        'confidence_score'                => 'float',
        'last_calculated_at'              => 'datetime',
        'updated_at'                      => 'datetime',
    ];

    public function screen()
    {
        return $this->belongsTo(Screen::class, 'screen_id');
    }
}
```

### A.5 Service

**`app/Services/OohxDataEngine.php`**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class OohxDataEngine
{
    public function getEstimateByExternalId(string $externalId): ?object
    {
        return DB::connection('oohx')->selectOne("
            SELECT s.external_id,
                   s.name,
                   s.indoor_outdoor,
                   s.city,
                   s.zone_type,
                   e.estimated_daily_passby,
                   e.estimated_daily_screen_flow,
                   e.estimated_daily_ots,
                   e.estimated_daily_impressions,
                   e.estimated_weekly_impressions,
                   e.estimated_monthly_impressions,
                   e.confidence_score,
                   e.estimation_method,
                   e.model_version,
                   e.last_calculated_at
            FROM output.screen_traffic_estimates e
            JOIN core.screens s ON s.id = e.screen_id
            WHERE s.external_id = ?
        ", [$externalId]);
    }

    public function getEstimatesByExternalIds(array $externalIds): Collection
    {
        if (empty($externalIds)) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));

        $rows = DB::connection('oohx')->select("
            SELECT s.external_id,
                   s.indoor_outdoor,
                   e.estimated_daily_impressions,
                   e.estimated_monthly_impressions,
                   e.confidence_score,
                   e.estimation_method,
                   e.last_calculated_at
            FROM output.screen_traffic_estimates e
            JOIN core.screens s ON s.id = e.screen_id
            WHERE s.external_id IN ($placeholders)
        ", $externalIds);

        return collect($rows)->keyBy('external_id');
    }

    public function topScreensByImpressions(string $city, int $limit = 20): array
    {
        return DB::connection('oohx')->select("
            SELECT s.external_id, s.name, s.indoor_outdoor, s.zone_type,
                   e.estimated_daily_impressions,
                   e.confidence_score
            FROM output.screen_traffic_estimates e
            JOIN core.screens s ON s.id = e.screen_id
            WHERE s.city = ?
            ORDER BY e.estimated_daily_impressions DESC NULLS LAST
            LIMIT ?
        ", [$city, $limit]);
    }
}
```

### A.6 Controller + Route (ví dụ — điều chỉnh theo codebase)

**`app/Http/Controllers/Api/OohxEstimateController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OohxDataEngine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OohxEstimateController extends Controller
{
    public function __construct(private OohxDataEngine $engine) {}

    public function show(string $externalId): JsonResponse
    {
        $estimate = $this->engine->getEstimateByExternalId($externalId);
        abort_unless($estimate, 404, 'Estimate not found');
        return response()->json($estimate);
    }

    public function topByCity(Request $request): JsonResponse
    {
        $city  = $request->query('city', 'Hanoi');
        $limit = min((int) $request->query('limit', 20), 100);
        return response()->json($this->engine->topScreensByImpressions($city, $limit));
    }
}
```

**`routes/api.php`**

```php
use App\Http\Controllers\Api\OohxEstimateController;

Route::prefix('oohx')->group(function () {
    Route::get('estimates',              [OohxEstimateController::class, 'topByCity']);
    Route::get('estimates/{externalId}', [OohxEstimateController::class, 'show']);
});
```

### A.7 Smoke test

```bash
php artisan route:list | grep oohx

php artisan tinker
```

```php
app(\App\Services\OohxDataEngine::class)->getEstimateByExternalId('IN-001');
app(\App\Services\OohxDataEngine::class)->topScreensByImpressions('Hanoi', 5);
```

Hoặc HTTP:
```bash
curl http://127.0.0.1/api/oohx/estimates/IN-001
curl "http://127.0.0.1/api/oohx/estimates?city=Hanoi&limit=5"
```

Sample data có sẵn trên Data Engine: OD-001, OD-002, IN-001, IN-002 (Hanoi).

---

## 4. PHẦN B — Laravel đẩy screens (OUTBOUND)

### B.1 SSH sync key — đã tồn tại

- Private key: `/root/.ssh/oohx_sync` (trên Laravel VPS)
- Public key đã được paste vào `/home/oohx/.ssh/authorized_keys` trên Data Engine VPS.

Test thủ công:
```bash
ssh -i /root/.ssh/oohx_sync oohx@139.162.20.95 "echo ok"
```
Nếu báo `Permission denied`, hỏi ops.

Inbox trên Data Engine: `/home/oohx/inbox/`.

### B.2 Artisan: export screens → JSON

**`app/Console/Commands/OohxExportScreens.php`**

> **Điều chỉnh query bên trong `handle()` cho đúng schema Laravel hiện tại.**
> Payload output BẮT BUỘC có: `external_id`, `indoor_outdoor` (`indoor`/`outdoor`),
> `lat`, `lon`. Các field khác optional nhưng nên đẩy đủ cho enrichment tốt hơn.

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OohxExportScreens extends Command
{
    protected $signature   = 'oohx:export-screens {--out=storage/app/oohx/screens.json}';
    protected $description = 'Export screens from Laravel DB to JSON for Data Engine ingest.';

    public function handle(): int
    {
        // TODO (Claude Code): cập nhật tên bảng + cột cho đúng schema Laravel.
        // Các field BẮT BUỘC trong payload: external_id, indoor_outdoor, lat, lon.
        $rows = DB::table('screens')
            ->where('status', 'active')
            ->get()
            ->map(fn ($r) => [
                'external_id'      => (string) ($r->code ?? $r->id),
                'name'             => $r->name             ?? null,
                'media_owner_name' => $r->media_owner_name ?? null,
                'venue_name'       => $r->venue_name       ?? null,
                'venue_type'       => $r->venue_type       ?? null,
                'indoor_outdoor'   => $r->indoor_outdoor   ?? 'outdoor',
                'zone_type'        => $r->zone_type        ?? null,
                'screen_type'      => $r->screen_type      ?? null,
                'screen_size'      => $r->screen_size      ?? null,
                'orientation'      => $r->orientation      ?? null,
                'city'             => $r->city             ?? null,
                'district'         => $r->district         ?? null,
                'ward'             => $r->ward             ?? null,
                'address'          => $r->address          ?? null,
                'lat'              => (float) $r->lat,
                'lon'              => (float) $r->lon,
                'status'           => $r->status           ?? 'active',
            ])
            ->values();

        $out = $this->option('out');
        $abs = base_path($out);
        @mkdir(dirname($abs), 0755, true);
        file_put_contents($abs, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info("Exported {$rows->count()} screens to {$out}");
        return self::SUCCESS;
    }
}
```

### B.3 Artisan: sync + trigger ingest

**`app/Console/Commands/OohxSyncToEngine.php`**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class OohxSyncToEngine extends Command
{
    protected $signature   = 'oohx:sync-to-engine';
    protected $description = 'Export screens, rsync to Data Engine VPS, then trigger ingest.';

    public function handle(): int
    {
        $remoteHost  = config('oohx.remote_host',  '139.162.20.95');
        $remoteUser  = config('oohx.remote_user',  'oohx');
        $sshKey      = config('oohx.ssh_key',      '/root/.ssh/oohx_sync');
        $remoteInbox = config('oohx.remote_inbox', '/home/oohx/inbox');

        $localFile = base_path('storage/app/oohx/screens.json');

        $this->call('oohx:export-screens', ['--out' => 'storage/app/oohx/screens.json']);
        if (!file_exists($localFile)) {
            $this->error('Export file not found.');
            return self::FAILURE;
        }

        $this->info('Rsync to Data Engine VPS...');
        $this->runCmd([
            'rsync', '-avz',
            '-e', "ssh -i {$sshKey} -o StrictHostKeyChecking=accept-new",
            $localFile,
            "{$remoteUser}@{$remoteHost}:{$remoteInbox}/screens.json",
        ]);

        $this->info('Trigger ingest on Data Engine VPS...');
        $this->runCmd([
            'ssh', '-i', $sshKey,
            '-o', 'StrictHostKeyChecking=accept-new',
            "{$remoteUser}@{$remoteHost}",
            'cd ~/python-data-engine && .venv/bin/python -m app.cli ingest-screens --file ~/inbox/screens.json',
        ]);

        $this->info('Done.');
        return self::SUCCESS;
    }

    private function runCmd(array $cmd): void
    {
        $p = new Process($cmd, null, null, null, 300);
        $p->mustRun(fn ($type, $buffer) => $this->line(rtrim($buffer)));
    }
}
```

### B.4 Config tách riêng

**`config/oohx.php`**

```php
<?php

return [
    'remote_host'  => env('OOHX_REMOTE_HOST',  '139.162.20.95'),
    'remote_user'  => env('OOHX_REMOTE_USER',  'oohx'),
    'ssh_key'      => env('OOHX_SSH_KEY',      '/root/.ssh/oohx_sync'),
    'remote_inbox' => env('OOHX_REMOTE_INBOX', '/home/oohx/inbox'),
];
```

Thêm vào `.env`:
```env
OOHX_REMOTE_HOST=139.162.20.95
OOHX_REMOTE_USER=oohx
OOHX_SSH_KEY=/root/.ssh/oohx_sync
OOHX_REMOTE_INBOX=/home/oohx/inbox
```

### B.5 Scheduler

**`app/Console/Kernel.php`** (Laravel 10) hoặc **`bootstrap/app.php`** / **`routes/console.php`** (Laravel 11+):

```php
$schedule->command('oohx:sync-to-engine')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/oohx-sync.log'));
```

Scheduler cron (Laravel VPS):
```cron
* * * * * cd /đường/dẫn/laravel-app && php artisan schedule:run >> /dev/null 2>&1
```

### B.6 Test outbound

```bash
php artisan oohx:export-screens
head -30 storage/app/oohx/screens.json

php artisan oohx:sync-to-engine
```

Kỳ vọng output cuối cùng từ remote ingest:
```json
{"file": "...screens.json", "ok": N, "fail": 0, "total": N}
```

Sau đó đợi 10–15 phút để cron `recompute-pending-jobs` bên Data Engine chạy
(hoặc yêu cầu ops chạy ngay `recompute-city --city Hanoi`).

---

## 5. Mapping field: Laravel screen → Data Engine payload

| Laravel column (ví dụ)     | Data Engine field | Required | Ghi chú |
|---------------------------|-------------------|:--:|---|
| `code` / `id`             | `external_id`     | ✅ | string, duy nhất trong Laravel |
| `name`                    | `name`            |    |  |
| `media_owner_name`        | `media_owner_name`|    |  |
| `venue_name`              | `venue_name`      |    | bắt buộc nếu indoor |
| `venue_type`              | `venue_type`      |    | `mall`, `airport`, `office`, ... |
| `indoor_outdoor`          | `indoor_outdoor`  | ✅ | chỉ nhận `indoor` hoặc `outdoor` |
| `zone_type`               | `zone_type`       |    | `entrance`, `checkout`, `escalator`, `food_court`, `cinema_corridor`, `inside_aisle`, `roadside`, `facade` |
| `screen_type`             | `screen_type`     |    | `LED`, `LCD`, ... |
| `screen_size`             | `screen_size`     |    | free text |
| `orientation`             | `orientation`     |    | `landscape` / `portrait` |
| `city`                    | `city`            |    | `Hanoi`, `HCMC`, ... — khớp Data Engine config |
| `district`, `ward`        | cùng tên          |    |  |
| `address`                 | `address`         |    |  |
| `lat`, `lon`              | `lat`, `lon`      | ✅ | WGS84, double |
| `status`                  | `status`          |    | default `active` |

> **Lưu ý tên city**: Data Engine config có các key cụ thể (`Hanoi`, `HCMC`,
> `Ho Chi Minh City`, `Danang`, ...). Giữ nguyên tên bạn dùng trong Laravel;
> nếu key chưa có trong config, fallback = `default = 6000`.
>
> **Zone type**: các giá trị trên là chuẩn; giá trị khác sẽ fallback về
> default theo indoor/outdoor.

---

## 6. Query patterns thường dùng

Trong Controller / Service / Blade:

```php
// 1 screen by external_id
$estimate = app(\App\Services\OohxDataEngine::class)
    ->getEstimateByExternalId($screen->code);

// Bulk cho danh sách screens (1 query, indexed by external_id)
$ids = $screens->pluck('code')->all();
$estimates = app(\App\Services\OohxDataEngine::class)->getEstimatesByExternalIds($ids);

foreach ($screens as $screen) {
    $e = $estimates->get($screen->code);
    $screen->daily_impressions = $e?->estimated_daily_impressions ?? 0;
    $screen->confidence        = $e?->confidence_score ?? 0;
}

// Eloquent style
\App\Models\Oohx\Screen::with('estimate')
    ->where('city', 'Hanoi')
    ->where('status', 'active')
    ->limit(20)
    ->get();
```

---

## 7. Thứ tự triển khai gợi ý

1. Verify tunnel (Phần 2).
2. Phần A (`.env` → config → model → service) + smoke test tinker.
3. Tạo API route ví dụ, test curl.
4. Phần B (export + sync command), test manual.
5. Bật scheduler cron.
6. Sau 30 phút, query qua API và confirm có dữ liệu mới.

---

## 8. Troubleshooting

| Triệu chứng | Nguyên nhân phổ biến | Fix |
|---|---|---|
| `could not find driver` | `php-pgsql` chưa cài hoặc FPM chưa reload | `sudo apt install -y php-pgsql && sudo systemctl reload php*-fpm` |
| `SQLSTATE[08006] connection refused 127.0.0.1:5433` | SSH tunnel không chạy | `sudo systemctl status oohx-pg-tunnel`; `journalctl -u oohx-pg-tunnel -n 50` |
| `password authentication failed for "oohx_readonly"` | `.env` sai password | sửa `DB_OOHX_PASSWORD`, `php artisan config:clear` |
| `relation "output.screen_traffic_estimates" does not exist` | chưa init-db bên Data Engine | ops chạy `python -m app.cli init-db` |
| Select ok nhưng rows = 0 | chưa sync + recompute | `php artisan oohx:sync-to-engine`, đợi cron 10m hoặc ops chạy `recompute-city` |
| `Permission denied (publickey)` khi sync | sai key path / chưa paste pubkey | kiểm tra `ls -la /root/.ssh/oohx_sync`; hỏi ops |
| Rsync success nhưng ingest fail | payload thiếu field bắt buộc | kiểm tra log Data Engine; đảm bảo `external_id/indoor_outdoor/lat/lon` có đủ |

Kiểm tra nhanh bên Data Engine (cần ssh vào):
```bash
ssh oohx@139.162.20.95
cd python-data-engine
.venv/bin/python -m app.cli ping
.venv/bin/python -m app.cli jobs-status
psql -h 127.0.0.1 -U oohx -d oohx_data -c "SELECT COUNT(*) FROM output.screen_traffic_estimates;"
```

---

## 9. Những gì **KHÔNG** làm bên Laravel

- ❌ Không tạo migration cho `oohx_data` DB — schema do Data Engine tự quản.
- ❌ Không `INSERT/UPDATE/DELETE` vào connection `oohx` — user `oohx_readonly`
      không có quyền; sẽ lỗi `permission denied`.
- ❌ Không đưa PostgreSQL port 5432 ra public — mọi kết nối qua SSH tunnel.
- ❌ Không hardcode IP/mật khẩu trong code — luôn dùng `.env` + `config/oohx.php`.

---

## 10. Liên hệ ops khi cần

Khi cần:
- Thêm city mới vào base traffic lookup
- Tune visibility / share_of_voice defaults
- Thêm bảng output mới (ví dụ `output.screen_context_summary`)
- Debug estimate sai lệch

→ báo ops để chỉnh config / recompute trên Data Engine VPS.
Không cần deploy lại Laravel.
