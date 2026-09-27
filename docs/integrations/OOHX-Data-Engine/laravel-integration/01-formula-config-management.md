# Laravel Integration — 01. Formula & Config Management

> **Role of this guide**: tài liệu handoff cho team Laravel, ứng với **Mục tiêu 2** của [OOHX-UPGRADE-PROPOSAL.md](../../OOHX-UPGRADE-PROPOSAL.md).
> Mục đích: Laravel admin CRUD coefficient + publish version — **không** đụng Python code.

---

## 1. Mục tiêu feature

Ops / admin cần:

1. **Xem** coefficient hiện tại (road class, zone, base city traffic, defaults).
2. **Chỉnh** 1 coefficient (có validation + preview diff + note).
3. **Publish** 1 formula version mới = snapshot toàn bộ coefficient + tag + description.
4. **Activate / rollback** formula version.
5. **Audit log**: xem ai đổi gì, khi nào.

Không làm ở guide này:
- ❌ Không tạo formula expression DSL — shape của formula vẫn ở Python.
- ❌ Không recompute estimate — feature đó nằm ở guide 02 (jobs).

---

## 2. Prerequisites

- [OOHX-UPGRADE-PROPOSAL.md](../../OOHX-UPGRADE-PROPOSAL.md) phase 2.A đã xong (migration `sql/006_config_tables.sql` đã apply trên Data Engine VPS).
- Role `oohx_control` đã tạo với quyền INSERT/UPDATE/DELETE trên schema `config.*`.
- SSH tunnel (port 5433 trên Laravel VPS) đang chạy — tunnel hiện tại tái sử dụng, không cần tạo mới.
- Laravel đã có connection `oohx` (đọc) từ [`oohx-matrix-integration.md`](../../oohx-matrix-integration.md).

---

## 3. Schema reference (read + write)

| Bảng | Laravel quyền | Mô tả |
|---|---|---|
| `config.base_city_traffic` | SELECT, INSERT, UPDATE, DELETE | city → baseline passby |
| `config.road_class_multipliers` | SELECT, INSERT, UPDATE, DELETE | road_class → multiplier |
| `config.zone_factors` | SELECT, INSERT, UPDATE, DELETE | zone_type → factor |
| `config.delivery_defaults` | SELECT, INSERT, UPDATE, DELETE | key → value (vis, dir, SOV, dwell, caps) |
| `config.formula_versions` | SELECT, INSERT, UPDATE | snapshot version, activate |
| `config.audit_log` | SELECT, INSERT | immutable log |

Structure chính (trích):

```sql
-- base_city_traffic
(city TEXT PK, baseline_passby NUMERIC, note TEXT, updated_by TEXT, updated_at TIMESTAMPTZ)

-- road_class_multipliers
(road_class TEXT PK, multiplier NUMERIC CHECK 0 < x <= 5, note, updated_by, updated_at)

-- zone_factors
(zone_type TEXT PK, factor NUMERIC CHECK 0 < x <= 2, note, updated_by, updated_at)

-- delivery_defaults  (key lookup — 9 giá trị)
(key TEXT PK, value NUMERIC, description TEXT, updated_by TEXT, updated_at TIMESTAMPTZ)

-- formula_versions
(id BIGSERIAL PK, tag TEXT UNIQUE, description TEXT,
 snapshot JSONB NOT NULL,                -- frozen copy tất cả coefficient
 is_active BOOLEAN DEFAULT FALSE,        -- chỉ 1 active tại 1 thời điểm
 activated_at TIMESTAMPTZ,
 created_by TEXT, created_at TIMESTAMPTZ)

-- audit_log
(id BIGSERIAL PK, actor TEXT, action TEXT, target TEXT,
 old_value JSONB, new_value JSONB, note TEXT, created_at TIMESTAMPTZ)
```

---

## 4. Laravel connection setup

### 4.1. `.env` — thêm control connection

```env
# Hiện tại (đã có)
DB_OOHX_HOST=127.0.0.1
DB_OOHX_PORT=5433
DB_OOHX_DATABASE=oohx_data
DB_OOHX_USERNAME=oohx_readonly
DB_OOHX_PASSWORD=<read pwd>

# Mới — cùng tunnel, khác user
DB_OOHX_CONTROL_USERNAME=oohx_control
DB_OOHX_CONTROL_PASSWORD=<control pwd>
```

### 4.2. `config/database.php` — thêm `oohx_control`

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

---

## 5. Laravel models

Đặt tại `app/Models/Oohx/Config/*`:

### 5.1. `BaseCityTraffic.php`

```php
<?php

namespace App\Models\Oohx\Config;

use Illuminate\Database\Eloquent\Model;

class BaseCityTraffic extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'config.base_city_traffic';
    protected $primaryKey = 'city';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = ['city', 'baseline_passby', 'note', 'updated_by', 'updated_at'];
    protected $casts    = ['baseline_passby' => 'float', 'updated_at' => 'datetime'];
}
```

### 5.2. `RoadClassMultiplier.php`

```php
<?php

namespace App\Models\Oohx\Config;

use Illuminate\Database\Eloquent\Model;

class RoadClassMultiplier extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'config.road_class_multipliers';
    protected $primaryKey = 'road_class';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = ['road_class', 'multiplier', 'note', 'updated_by', 'updated_at'];
    protected $casts    = ['multiplier' => 'float', 'updated_at' => 'datetime'];
}
```

### 5.3. `ZoneFactor.php`

```php
<?php

namespace App\Models\Oohx\Config;

use Illuminate\Database\Eloquent\Model;

class ZoneFactor extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'config.zone_factors';
    protected $primaryKey = 'zone_type';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = ['zone_type', 'factor', 'note', 'updated_by', 'updated_at'];
    protected $casts    = ['factor' => 'float', 'updated_at' => 'datetime'];
}
```

### 5.4. `DeliveryDefault.php`

```php
<?php

namespace App\Models\Oohx\Config;

use Illuminate\Database\Eloquent\Model;

class DeliveryDefault extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'config.delivery_defaults';
    protected $primaryKey = 'key';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    protected $fillable = ['key', 'value', 'description', 'updated_by', 'updated_at'];
    protected $casts    = ['value' => 'float', 'updated_at' => 'datetime'];

    public const KEYS = [
        'visibility_outdoor',        // 0.25
        'visibility_indoor',         // 0.55
        'direction_outdoor_one',     // 0.60
        'direction_outdoor_two',     // 1.00
        'direction_indoor',          // 1.00
        'share_of_voice',            // 0.125
        'dwell_factor',              // 1.20
        'lane_factor_cap',           // 2.0
        'poi_factor_cap',            // 1.8
    ];
}
```

### 5.5. `FormulaVersion.php`

```php
<?php

namespace App\Models\Oohx\Config;

use Illuminate\Database\Eloquent\Model;

class FormulaVersion extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'config.formula_versions';
    public    $timestamps = false;

    protected $fillable = [
        'tag', 'description', 'snapshot',
        'is_active', 'activated_at', 'created_by', 'created_at',
    ];
    protected $casts = [
        'snapshot'     => 'array',
        'is_active'    => 'boolean',
        'activated_at' => 'datetime',
        'created_at'   => 'datetime',
    ];
}
```

### 5.6. `AuditLog.php`

```php
<?php

namespace App\Models\Oohx\Config;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $connection = 'oohx_control';
    protected $table      = 'config.audit_log';
    public    $timestamps = false;

    protected $fillable = ['actor', 'action', 'target', 'old_value', 'new_value', 'note', 'created_at'];
    protected $casts    = [
        'old_value'  => 'array',
        'new_value'  => 'array',
        'created_at' => 'datetime',
    ];
}
```

---

## 6. Service layer

`app/Services/Oohx/ConfigManagerService.php`:

```php
<?php

namespace App\Services\Oohx;

use App\Models\Oohx\Config\AuditLog;
use App\Models\Oohx\Config\BaseCityTraffic;
use App\Models\Oohx\Config\DeliveryDefault;
use App\Models\Oohx\Config\FormulaVersion;
use App\Models\Oohx\Config\RoadClassMultiplier;
use App\Models\Oohx\Config\ZoneFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConfigManagerService
{
    /**
     * Update a single coefficient atomically + write audit log.
     *
     * @param  string  $group     'base_city_traffic' | 'road_class' | 'zone' | 'delivery_default'
     * @param  string  $key       city / road_class / zone_type / key
     * @param  float   $newValue
     * @param  ?string $note
     */
    public function updateCoefficient(string $group, string $key, float $newValue, ?string $note = null): void
    {
        $actor = Auth::user()?->email ?? 'system';

        DB::connection('oohx_control')->transaction(function () use ($group, $key, $newValue, $note, $actor) {
            [$model, $pkColumn, $valueColumn] = match ($group) {
                'base_city_traffic' => [BaseCityTraffic::class,     'city',       'baseline_passby'],
                'road_class'        => [RoadClassMultiplier::class, 'road_class', 'multiplier'],
                'zone'              => [ZoneFactor::class,          'zone_type',  'factor'],
                'delivery_default'  => [DeliveryDefault::class,     'key',        'value'],
                default             => throw new \InvalidArgumentException("Unknown group: $group"),
            };

            $row = $model::where($pkColumn, $key)->first();
            $oldValue = $row?->{$valueColumn};

            $model::updateOrCreate(
                [$pkColumn => $key],
                [$valueColumn => $newValue, 'note' => $note, 'updated_by' => $actor, 'updated_at' => now()],
            );

            AuditLog::create([
                'actor'      => $actor,
                'action'     => "update_{$group}",
                'target'     => "$pkColumn=$key",
                'old_value'  => ['value' => $oldValue],
                'new_value'  => ['value' => $newValue],
                'note'       => $note,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Snapshot toàn bộ config.* hiện tại vào 1 formula_versions row.
     * Không activate — activate riêng qua activateVersion().
     */
    public function publishVersion(string $tag, ?string $description = null): FormulaVersion
    {
        $actor = Auth::user()?->email ?? 'system';
        $conn  = DB::connection('oohx_control');

        return $conn->transaction(function () use ($tag, $description, $actor, $conn) {
            $snapshot = [
                'base_city_traffic'       => BaseCityTraffic::all()->keyBy('city')->map->baseline_passby->all(),
                'road_class_multipliers'  => RoadClassMultiplier::all()->keyBy('road_class')->map->multiplier->all(),
                'zone_factors'            => ZoneFactor::all()->keyBy('zone_type')->map->factor->all(),
                'delivery_defaults'       => DeliveryDefault::all()->keyBy('key')->map->value->all(),
            ];

            $version = FormulaVersion::create([
                'tag'         => $tag,
                'description' => $description,
                'snapshot'    => $snapshot,
                'is_active'   => false,
                'created_by'  => $actor,
                'created_at'  => now(),
            ]);

            AuditLog::create([
                'actor'      => $actor,
                'action'     => 'publish_version',
                'target'     => "tag=$tag",
                'new_value'  => ['id' => $version->id, 'description' => $description],
                'note'       => null,
                'created_at' => now(),
            ]);

            return $version;
        });
    }

    /**
     * Activate 1 version; auto deactivate version cũ (unique partial index đảm bảo chỉ 1 active).
     */
    public function activateVersion(string $tag): FormulaVersion
    {
        $actor = Auth::user()?->email ?? 'system';

        return DB::connection('oohx_control')->transaction(function () use ($tag, $actor) {
            $target = FormulaVersion::where('tag', $tag)->firstOrFail();

            FormulaVersion::where('is_active', true)->update(['is_active' => false]);
            $target->update(['is_active' => true, 'activated_at' => now()]);

            AuditLog::create([
                'actor'      => $actor,
                'action'     => 'activate_version',
                'target'     => "tag=$tag",
                'new_value'  => ['id' => $target->id],
                'created_at' => now(),
            ]);

            return $target->fresh();
        });
    }

    public function diffVersions(string $tagA, string $tagB): array
    {
        $a = FormulaVersion::where('tag', $tagA)->firstOrFail()->snapshot;
        $b = FormulaVersion::where('tag', $tagB)->firstOrFail()->snapshot;

        $diff = [];
        foreach ($a as $group => $values) {
            foreach ($values as $key => $val) {
                $other = $b[$group][$key] ?? null;
                if ($other !== null && (float) $other !== (float) $val) {
                    $diff[] = [
                        'group'  => $group,
                        'key'    => $key,
                        'before' => $val,
                        'after'  => $other,
                        'delta'  => round($other - $val, 4),
                    ];
                }
            }
        }
        return $diff;
    }
}
```

---

## 7. Controllers + Routes

`routes/web.php` (admin group):

```php
use App\Http\Controllers\Admin\Oohx\ConfigController;
use App\Http\Controllers\Admin\Oohx\FormulaVersionController;

Route::middleware(['auth', 'can:manage-oohx-config'])
    ->prefix('admin/oohx')
    ->name('admin.oohx.')
    ->group(function () {
        Route::get('config',                      [ConfigController::class, 'index'])->name('config.index');
        Route::post('config/{group}/{key}',       [ConfigController::class, 'update'])->name('config.update');

        Route::get('formula-versions',            [FormulaVersionController::class, 'index'])->name('versions.index');
        Route::post('formula-versions',           [FormulaVersionController::class, 'publish'])->name('versions.publish');
        Route::post('formula-versions/{tag}/activate', [FormulaVersionController::class, 'activate'])->name('versions.activate');
        Route::get('formula-versions/diff',        [FormulaVersionController::class, 'diff'])->name('versions.diff');
        Route::get('audit-log',                    [FormulaVersionController::class, 'auditLog'])->name('audit.index');
    });
```

`app/Http/Controllers/Admin/Oohx/ConfigController.php`:

```php
<?php

namespace App\Http\Controllers\Admin\Oohx;

use App\Http\Controllers\Controller;
use App\Models\Oohx\Config\BaseCityTraffic;
use App\Models\Oohx\Config\DeliveryDefault;
use App\Models\Oohx\Config\RoadClassMultiplier;
use App\Models\Oohx\Config\ZoneFactor;
use App\Services\Oohx\ConfigManagerService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConfigController extends Controller
{
    public function __construct(private ConfigManagerService $svc) {}

    public function index()
    {
        return view('admin.oohx.config.index', [
            'baseCityTraffic' => BaseCityTraffic::orderBy('city')->get(),
            'roadMultipliers' => RoadClassMultiplier::orderBy('road_class')->get(),
            'zoneFactors'     => ZoneFactor::orderBy('zone_type')->get(),
            'deliveryDefaults'=> DeliveryDefault::orderBy('key')->get(),
        ]);
    }

    public function update(Request $request, string $group, string $key)
    {
        $allowedGroups = ['base_city_traffic', 'road_class', 'zone', 'delivery_default'];
        abort_unless(in_array($group, $allowedGroups, true), 422, 'Invalid group');

        $rules = match ($group) {
            'base_city_traffic' => ['value' => 'required|numeric|min:0|max:1000000'],
            'road_class'        => ['value' => 'required|numeric|min:0.01|max:5'],
            'zone'              => ['value' => 'required|numeric|min:0.01|max:2'],
            'delivery_default'  => ['value' => 'required|numeric|min:0|max:100'],
        };
        $data = $request->validate($rules + ['note' => 'nullable|string|max:500']);

        $this->svc->updateCoefficient($group, $key, (float) $data['value'], $data['note'] ?? null);

        return back()->with('status', "Updated $group.$key → {$data['value']}");
    }
}
```

`app/Http/Controllers/Admin/Oohx/FormulaVersionController.php`:

```php
<?php

namespace App\Http\Controllers\Admin\Oohx;

use App\Http\Controllers\Controller;
use App\Models\Oohx\Config\AuditLog;
use App\Models\Oohx\Config\FormulaVersion;
use App\Services\Oohx\ConfigManagerService;
use Illuminate\Http\Request;

class FormulaVersionController extends Controller
{
    public function __construct(private ConfigManagerService $svc) {}

    public function index()
    {
        return view('admin.oohx.versions.index', [
            'versions' => FormulaVersion::orderByDesc('created_at')->paginate(30),
        ]);
    }

    public function publish(Request $request)
    {
        $data = $request->validate([
            'tag'         => 'required|string|max:50|unique:oohx_control.config.formula_versions,tag',
            'description' => 'nullable|string|max:500',
        ]);

        $version = $this->svc->publishVersion($data['tag'], $data['description'] ?? null);
        return redirect()->route('admin.oohx.versions.index')
            ->with('status', "Published version $version->tag (id=$version->id). Click Activate when ready.");
    }

    public function activate(string $tag)
    {
        $v = $this->svc->activateVersion($tag);
        return back()->with('status', "Activated $v->tag. Trigger recompute-city to apply.");
    }

    public function diff(Request $request)
    {
        $data = $request->validate([
            'from' => 'required|string',
            'to'   => 'required|string',
        ]);
        return response()->json($this->svc->diffVersions($data['from'], $data['to']));
    }

    public function auditLog()
    {
        return view('admin.oohx.audit.index', [
            'entries' => AuditLog::orderByDesc('created_at')->paginate(50),
        ]);
    }
}
```

---

## 8. UI specification (wireframe mức text)

### 8.1. `/admin/oohx/config` — Coefficient editor

Trang duy nhất, 4 panel dọc:

```
┌───────────────────────────────────────────────────────────────────┐
│ 🏷  Active version: v-2026-04-20  [View diff with previous]       │
├───────────────────────────────────────────────────────────────────┤
│ [Panel 1] Base city traffic                                       │
│ City            Baseline passby/day   Updated       [Edit]        │
│ Hanoi           8,000                 2d ago  @nv    ✏️           │
│ HCMC            10,000                5d ago  @nv    ✏️           │
│ ...                                                               │
├───────────────────────────────────────────────────────────────────┤
│ [Panel 2] Road class multipliers                                  │
│ road_class      multiplier            Updated       [Edit]        │
│ highway         2.50                  7d ago  @nv    ✏️           │
│ primary         2.00                  7d ago  @nv    ✏️           │
│ ...                                                               │
├───────────────────────────────────────────────────────────────────┤
│ [Panel 3] Zone factors                                            │
├───────────────────────────────────────────────────────────────────┤
│ [Panel 4] Delivery defaults (visibility/direction/SOV/dwell)      │
└───────────────────────────────────────────────────────────────────┘
```

Click ✏️ → modal nhỏ:

```
┌─────────────────────────────────┐
│ Edit road_class.highway         │
│                                 │
│ Current: 2.50                   │
│ New:     [2.70        ]         │
│ Note:    [Calibrated from...] │
│                                 │
│ [Cancel]           [Save]       │
└─────────────────────────────────┘
```

Save → POST `/admin/oohx/config/road_class/highway`. Flash message "Updated ...".

### 8.2. `/admin/oohx/formula-versions` — Version manager

```
┌────────────────────────────────────────────────────────────────────┐
│ [+ Publish new version from current coefficients]                  │
├────────────────────────────────────────────────────────────────────┤
│ Tag              Created           Created by     Status    Action │
│ v-2026-04-20     2026-04-20 10:00  nv@oohx.vn     🟢 ACTIVE  ↩️     │
│ v-2026-03-15     2026-03-15 14:20  nv@oohx.vn     inactive   [Activate] │
│ mvp-1.0          2026-02-01 09:00  seed           inactive   [Activate] │
└────────────────────────────────────────────────────────────────────┘

Click [Publish new]:
┌─────────────────────────────────────────┐
│ New formula version                     │
│ Tag:         [v-2026-04-21           ]  │
│ Description: [After Tet calibration...] │
│                                         │
│ Preview snapshot:                       │
│  base_city_traffic                      │
│    Hanoi:  8000                         │
│    HCMC:   10000                        │
│  ... (collapsible)                      │
│                                         │
│ [Cancel]              [Publish]         │
└─────────────────────────────────────────┘

Click [Activate]:
→ confirm modal "This will affect all future estimates. Run recompute-city after activation. Proceed?"
→ POST activate
→ flash "Activated. Go to Jobs to enqueue recompute-city."
```

### 8.3. `/admin/oohx/audit-log` — Audit log

Bảng đơn giản, filter theo actor / action / ngày:

```
Time              Actor         Action                 Target              Δ
2026-04-20 10:05  nv@oohx.vn    activate_version       tag=v-2026-04-20    —
2026-04-20 09:58  nv@oohx.vn    update_road_class      road_class=highway  2.50 → 2.70
2026-04-19 16:22  system        seed_config_from_code  —                   —
```

---

## 9. Publishing & activating flow (end-to-end)

Flow khuyến nghị:

1. **Ops chỉnh coefficient**: nhiều thay đổi nhỏ (ví dụ 3 road class multipliers) — mỗi lần Save = 1 audit log entry.
2. **Ops publish version**: tag `v-2026-04-21`, description "Post-Tet calibration". Version ghi snapshot nhưng `is_active=false`.
3. **QA preview**: click "View diff" so v hiện active và v mới → đánh giá delta hợp lý không.
4. **Ops activate**: toggle `is_active=true`, auto deactivate version cũ.
5. **Ops trigger recompute**: vào guide 02 (Jobs), enqueue-city cho Hanoi + HCMC.
6. **Monitor**: ~15 phút sau, output rows có `formula_version_id = <new>`, `last_calculated_at` recent.

Rollback = activate lại version cũ + recompute.

---

## 10. Test plan

### 10.1. Unit test `ConfigManagerService`

Mock 2 connection (không thực sự chạm Postgres thật):

```php
class ConfigManagerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_coefficient_writes_audit_log(): void
    {
        $svc = app(ConfigManagerService::class);
        $svc->updateCoefficient('road_class', 'highway', 2.7, 'Post-Tet calibration');

        $this->assertDatabaseHas('config.road_class_multipliers', [
            'road_class' => 'highway',
            'multiplier' => 2.7,
        ]);
        $this->assertDatabaseHas('config.audit_log', [
            'action' => 'update_road_class',
            'target' => 'road_class=highway',
        ]);
    }
}
```

### 10.2. Integration test (staging Data Engine)

- [ ] Điền 1 giá trị ngoài range (ví dụ multiplier = 10) → controller trả 422.
- [ ] Publish version trùng tag → trả 422 unique constraint.
- [ ] Activate version mới, xem `config.formula_versions` có đúng 1 row `is_active=true`.
- [ ] Chạy `python -m app.cli recompute-screen --screen-id <N>` trên Data Engine → xem `output.screen_traffic_estimates.formula_version_id` đúng id mới.
- [ ] Chỉnh 1 base_city_traffic → activate → recompute → verify `estimated_daily_passby` thay đổi tương ứng.

---

## 11. Assumptions cho Claude Code (Laravel side)

Khi bắt tay vào triển khai, **confirm** các giả định sau với team Data Engine:

1. Data Engine đã apply migration `sql/006_config_tables.sql` ✅
2. Đã seed bảng `config.*` từ hardcode hiện tại bằng `python -m app.cli seed-config-from-code` ✅
3. Role `oohx_control` đã tồn tại với password ops cung cấp ✅
4. Python đã sửa `config_loader.py` để ưu tiên load từ DB trước khi fallback hardcode ✅
5. Tên bảng chính xác theo schema trên. Nếu khác → báo ngay.
6. Laravel middleware `can:manage-oohx-config` đã được define trong `AuthServiceProvider`.

---

## 12. Liên quan

- Sau guide này, để thực sự thấy effect của đổi coefficient, cần guide 02 để enqueue recompute.
- Guide 04 (screen inspector) sẽ hiển thị `formula_version_id` của 1 estimate để debug.
- Guide 05 (monitoring) sẽ cảnh báo nếu sau activate version không có job recompute nào được trigger.

*Updated: 2026-04-20.*
