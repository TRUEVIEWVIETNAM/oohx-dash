# Laravel Integration — 04. Screen Context Inspector

> **Role of this guide**: tài liệu handoff cho team Laravel.
> Mục đích: với **1 screen**, trả lời câu hỏi "tại sao impressions = X" — hiện đầy đủ **context** (nearest road, POI, venue, weather nếu có) + **factor resolved từng bước** + **formula breakdown**.

---

## 1. Mục tiêu feature

Sales / media planner / ops cần:

1. Xem nhanh estimate của 1 screen (có sẵn từ phase 1 qua `output.*`).
2. **Hiểu vì sao** estimate ra con số đó: nearest road nào, bao nhiêu POI, venue footfall bao nhiêu, zone factor mấy.
3. Xem **các bước tính**: `base → × road × lane × poi = passby`, rồi `× visibility × direction = OTS`, rồi `× SOV × dwell = impressions`.
4. Biết **formula version nào** được dùng, estimate **cũ bao lâu**.
5. Shortcut **re-enqueue recompute** screen này.

Không làm ở guide này:
- ❌ Không edit screen — screen là source-of-truth ở Laravel side.
- ❌ Không edit context metrics — Python tính lại chúng.

---

## 2. Prerequisites

- Connection `oohx` (read-only) đã setup từ [`oohx-matrix-integration.md`](../../oohx-matrix-integration.md).
- Model `App\Models\Oohx\Screen` + `App\Models\Oohx\ScreenEstimate` đã có.
- Cần thêm 1 model mới: `ScreenContextMetrics` cho `metrics.screen_context_metrics`.

---

## 3. Schema reference

### 3.1. `metrics.screen_context_metrics` (read-only)

```sql
-- Trích cột liên quan UI
screen_id                   BIGINT PK
nearest_road_id             BIGINT
nearest_road_class          TEXT
distance_to_nearest_road_m  NUMERIC
distance_to_main_road_m     NUMERIC
lane_count                  INTEGER
intersection_count_100m     INTEGER
intersection_count_300m     INTEGER
intersection_count_500m     INTEGER
poi_count_100m              INTEGER
poi_count_300m              INTEGER
poi_count_500m              INTEGER
shopping_poi_300m           INTEGER
food_poi_300m               INTEGER
office_poi_300m             INTEGER
entertainment_poi_300m      INTEGER
transport_poi_300m          INTEGER
education_poi_300m          INTEGER
healthcare_poi_300m         INTEGER
population_density_300m     NUMERIC
population_density_500m     NUMERIC
landuse_type                TEXT
venue_id                    BIGINT
venue_name                  TEXT
venue_type                  TEXT
venue_footfall              NUMERIC
zone_factor                 NUMERIC
visibility_factor           NUMERIC
direction_factor            NUMERIC
road_score                  NUMERIC
poi_score                   NUMERIC
population_score            NUMERIC
venue_score                 NUMERIC
weather_factor              NUMERIC    -- từ phase 2.D
seasonality_factor          NUMERIC
calibration_factor          NUMERIC
context_tags                JSONB
enrich_version              TEXT
enriched_at                 TIMESTAMPTZ
```

### 3.2. `source.*` (read-only)

- `source.roads` — để JOIN lấy name của nearest road.
- `source.venues` — đã có venue_name trong metrics.
- `source.weather_snapshots` — snapshot mới nhất của city.

### 3.3. `output.screen_traffic_estimates`

Thêm cột (từ upgrade phase 2): `formula_version_id`.

---

## 4. Laravel model

`app/Models/Oohx/ScreenContextMetrics.php`:

```php
<?php

namespace App\Models\Oohx;

use Illuminate\Database\Eloquent\Model;

class ScreenContextMetrics extends Model
{
    protected $connection = 'oohx';          // read-only
    protected $table      = 'metrics.screen_context_metrics';
    protected $primaryKey = 'screen_id';
    public    $incrementing = false;
    public    $timestamps = false;

    protected $casts = [
        'distance_to_nearest_road_m' => 'float',
        'distance_to_main_road_m'    => 'float',
        'zone_factor'                => 'float',
        'visibility_factor'          => 'float',
        'direction_factor'           => 'float',
        'road_score'                 => 'float',
        'poi_score'                  => 'float',
        'population_score'           => 'float',
        'venue_score'                => 'float',
        'venue_footfall'             => 'float',
        'population_density_300m'    => 'float',
        'population_density_500m'    => 'float',
        'weather_factor'             => 'float',
        'seasonality_factor'         => 'float',
        'calibration_factor'         => 'float',
        'context_tags'               => 'array',
        'enriched_at'                => 'datetime',
    ];

    public function screen()   { return $this->belongsTo(Screen::class, 'screen_id'); }
    public function estimate() { return $this->hasOne(ScreenEstimate::class, 'screen_id'); }
}
```

Bổ sung vào `ScreenEstimate.php`:

```php
public function contextMetrics()
{
    return $this->hasOne(ScreenContextMetrics::class, 'screen_id');
}

public function formulaVersion()
{
    return $this->belongsTo(\App\Models\Oohx\Config\FormulaVersion::class, 'formula_version_id');
}
```

---

## 5. Service layer

`app/Services/Oohx/ScreenInspectorService.php`:

```php
<?php

namespace App\Services\Oohx;

use Illuminate\Support\Facades\DB;

class ScreenInspectorService
{
    /**
     * Load everything needed to inspect a screen in 1 query.
     * Returns a flat object with keys: screen, metrics, estimate,
     * nearest_road (name), weather (latest for city), formula_version_tag.
     */
    public function inspect(string $externalId): ?array
    {
        $base = DB::connection('oohx')->selectOne("
            SELECT
                s.id, s.external_id, s.name, s.media_owner_name,
                s.indoor_outdoor, s.zone_type, s.venue_name, s.venue_type,
                s.city, s.district, s.ward, s.address, s.lat, s.lon, s.status,

                m.nearest_road_id, m.nearest_road_class,
                m.distance_to_nearest_road_m, m.distance_to_main_road_m,
                m.lane_count,
                m.poi_count_100m, m.poi_count_300m, m.poi_count_500m,
                m.shopping_poi_300m, m.food_poi_300m, m.office_poi_300m,
                m.entertainment_poi_300m, m.transport_poi_300m,
                m.education_poi_300m, m.healthcare_poi_300m,
                m.population_density_300m, m.population_density_500m,
                m.venue_id, m.venue_name AS resolved_venue_name, m.venue_type AS resolved_venue_type,
                m.venue_footfall,
                m.zone_factor, m.visibility_factor, m.direction_factor,
                m.road_score, m.poi_score, m.population_score, m.venue_score,
                m.weather_factor, m.seasonality_factor, m.calibration_factor,
                m.context_tags, m.enrich_version, m.enriched_at,

                e.estimated_daily_passby, e.estimated_daily_screen_flow,
                e.estimated_daily_ots, e.estimated_daily_impressions,
                e.estimated_weekly_impressions, e.estimated_monthly_impressions,
                e.confidence_score, e.estimation_method,
                e.model_version, e.impression_multiplier,
                e.last_calculated_at, e.formula_version_id,

                r.name AS nearest_road_name,
                fv.tag AS formula_version_tag,
                fv.description AS formula_version_description

            FROM core.screens s
            LEFT JOIN metrics.screen_context_metrics m     ON m.screen_id = s.id
            LEFT JOIN output.screen_traffic_estimates e    ON e.screen_id = s.id
            LEFT JOIN source.roads r                       ON r.id = m.nearest_road_id
            LEFT JOIN config.formula_versions fv           ON fv.id = e.formula_version_id
            WHERE s.external_id = ?
        ", [$externalId]);

        if (!$base) return null;

        $weather = $base->city
            ? DB::connection('oohx')->selectOne("
                SELECT observed_at, temperature_c, precipitation_mm, weather_code
                FROM source.weather_snapshots
                WHERE city = ?
                ORDER BY observed_at DESC
                LIMIT 1
            ", [$base->city])
            : null;

        return [
            'screen'           => $this->pickScreenFields($base),
            'metrics'          => $this->pickMetricsFields($base),
            'estimate'         => $this->pickEstimateFields($base),
            'breakdown'        => $this->buildBreakdown($base),
            'nearest_road'     => [
                'id'        => $base->nearest_road_id,
                'name'      => $base->nearest_road_name,
                'class'     => $base->nearest_road_class,
                'lane_count'=> $base->lane_count,
                'distance_m'=> $base->distance_to_nearest_road_m,
            ],
            'latest_weather'       => $weather,
            'formula_version'      => $base->formula_version_tag ? [
                'tag'         => $base->formula_version_tag,
                'description' => $base->formula_version_description,
            ] : null,
        ];
    }

    /**
     * Build the per-step breakdown for UI. Uses context metrics + known
     * formula shape. NOT calling Python — mirror the formula here.
     * If Python formula changes shape, update this too (keep docs in sync).
     */
    private function buildBreakdown(object $b): array
    {
        $isOutdoor = $b->indoor_outdoor === 'outdoor';

        if ($isOutdoor) {
            // passby = base × road × lane × intersection × poi × population
            // OTS    = passby × visibility × direction
            // impr   = OTS × sov × dwell
            return [
                'mode' => 'outdoor',
                'steps' => [
                    ['label' => 'Base city traffic',       'value' => '—',  'note' => "city=$b->city"],
                    ['label' => '× road_class',            'value' => $this->fmt($b->road_score),
                        'note' => "nearest=$b->nearest_road_class"],
                    ['label' => '× lane_factor',           'value' => '—',
                        'note' => "lanes=".($b->lane_count ?? 'n/a')],
                    ['label' => '× intersection_factor',   'value' => '1.0', 'note' => 'MVP placeholder'],
                    ['label' => '× poi_factor',            'value' => $this->fmt($b->poi_score),
                        'note' => "poi_300m=$b->poi_count_300m"],
                    ['label' => '× population_factor',     'value' => $this->fmt($b->population_score ?? 1.0),
                        'note' => "MVP placeholder"],
                    ['label' => '⇒ daily passby',          'value' => $this->num($b->estimated_daily_passby), 'emphasize' => true],
                    ['label' => '× visibility',            'value' => $this->fmt($b->visibility_factor)],
                    ['label' => '× direction',             'value' => $this->fmt($b->direction_factor)],
                    ['label' => '⇒ daily OTS',             'value' => $this->num($b->estimated_daily_ots), 'emphasize' => true],
                    ['label' => '× share_of_voice × dwell_factor',
                        'value' => $this->fmt($b->impression_multiplier), 'note' => 'combined delivery multiplier'],
                    ['label' => '⇒ daily impressions',     'value' => $this->num($b->estimated_daily_impressions), 'emphasize' => true],
                ],
            ];
        }

        return [
            'mode' => 'indoor',
            'steps' => [
                ['label' => 'Venue footfall',              'value' => $this->num($b->venue_footfall),
                    'note' => "venue=".($b->resolved_venue_name ?? 'n/a')],
                ['label' => '× zone_factor',               'value' => $this->fmt($b->zone_factor),
                    'note' => "zone=$b->zone_type"],
                ['label' => '⇒ daily screen_flow',         'value' => $this->num($b->estimated_daily_screen_flow), 'emphasize' => true],
                ['label' => '× visibility',                'value' => $this->fmt($b->visibility_factor)],
                ['label' => '× direction',                 'value' => $this->fmt($b->direction_factor)],
                ['label' => '⇒ daily OTS',                 'value' => $this->num($b->estimated_daily_ots), 'emphasize' => true],
                ['label' => '× share_of_voice × dwell',    'value' => $this->fmt($b->impression_multiplier)],
                ['label' => '⇒ daily impressions',         'value' => $this->num($b->estimated_daily_impressions), 'emphasize' => true],
            ],
        ];
    }

    private function pickScreenFields(object $b): array
    {
        return [
            'id'              => $b->id,
            'external_id'     => $b->external_id,
            'name'            => $b->name,
            'media_owner'     => $b->media_owner_name,
            'indoor_outdoor'  => $b->indoor_outdoor,
            'zone_type'       => $b->zone_type,
            'city'            => $b->city,
            'district'        => $b->district,
            'ward'            => $b->ward,
            'address'         => $b->address,
            'lat'             => (float) $b->lat,
            'lon'             => (float) $b->lon,
            'status'          => $b->status,
        ];
    }

    private function pickMetricsFields(object $b): array
    {
        return [
            'enrich_version'             => $b->enrich_version,
            'enriched_at'                => $b->enriched_at,
            'poi' => [
                '100m'           => $b->poi_count_100m,
                '300m'           => $b->poi_count_300m,
                '500m'           => $b->poi_count_500m,
                'shopping_300m'  => $b->shopping_poi_300m,
                'food_300m'      => $b->food_poi_300m,
                'office_300m'    => $b->office_poi_300m,
                'entertainment_300m' => $b->entertainment_poi_300m,
                'transport_300m' => $b->transport_poi_300m,
                'education_300m' => $b->education_poi_300m,
                'healthcare_300m'=> $b->healthcare_poi_300m,
            ],
            'population' => [
                '300m' => $b->population_density_300m,
                '500m' => $b->population_density_500m,
            ],
            'venue' => $b->venue_id ? [
                'id'        => $b->venue_id,
                'name'      => $b->resolved_venue_name,
                'type'      => $b->resolved_venue_type,
                'footfall'  => $b->venue_footfall,
            ] : null,
            'factors' => [
                'zone'        => $b->zone_factor,
                'visibility'  => $b->visibility_factor,
                'direction'   => $b->direction_factor,
                'weather'     => $b->weather_factor,
                'seasonality' => $b->seasonality_factor,
                'calibration' => $b->calibration_factor,
            ],
            'scores' => [
                'road'        => $b->road_score,
                'poi'         => $b->poi_score,
                'population'  => $b->population_score,
                'venue'       => $b->venue_score,
            ],
            'context_tags' => $b->context_tags,
        ];
    }

    private function pickEstimateFields(object $b): array
    {
        return [
            'daily_passby'         => $b->estimated_daily_passby,
            'daily_screen_flow'    => $b->estimated_daily_screen_flow,
            'daily_ots'            => $b->estimated_daily_ots,
            'daily_impressions'    => $b->estimated_daily_impressions,
            'weekly_impressions'   => $b->estimated_weekly_impressions,
            'monthly_impressions'  => $b->estimated_monthly_impressions,
            'confidence_score'     => $b->confidence_score,
            'estimation_method'    => $b->estimation_method,
            'model_version'        => $b->model_version,
            'impression_multiplier'=> $b->impression_multiplier,
            'last_calculated_at'   => $b->last_calculated_at,
        ];
    }

    private function fmt($val): string
    {
        return $val === null ? 'null' : (string) round((float) $val, 3);
    }

    private function num($val): string
    {
        return $val === null ? '—' : number_format((float) $val, 0);
    }
}
```

---

## 6. Routes + Controller

`routes/web.php`:

```php
use App\Http\Controllers\Admin\Oohx\ScreenInspectorController;

Route::middleware(['auth', 'can:view-oohx-screens'])
    ->prefix('admin/oohx/screens')
    ->name('admin.oohx.screens.')
    ->group(function () {
        Route::get('{externalId}/inspect', [ScreenInspectorController::class, 'inspect'])->name('inspect');
    });
```

`app/Http/Controllers/Admin/Oohx/ScreenInspectorController.php`:

```php
<?php

namespace App\Http\Controllers\Admin\Oohx;

use App\Http\Controllers\Controller;
use App\Services\Oohx\ScreenInspectorService;
use App\Services\Oohx\JobOrchestrator;
use Illuminate\Http\Request;

class ScreenInspectorController extends Controller
{
    public function __construct(
        private ScreenInspectorService $svc,
        private JobOrchestrator $orchestrator,
    ) {}

    public function inspect(string $externalId, Request $request)
    {
        $data = $this->svc->inspect($externalId);
        abort_unless($data, 404, "Screen $externalId not found");

        return view('admin.oohx.screens.inspect', [
            'data'        => $data,
            'can_recompute' => $request->user()->can('manage-oohx-jobs'),
        ]);
    }
}
```

Shortcut re-enqueue: form POST sang `admin.oohx.jobs.enqueue.screen` với `screen_id` = `$data['screen']['id']`.

---

## 7. UI specification

Trang `admin/oohx/screens/{externalId}/inspect`:

```
┌────────────────────────────────────────────────────────────────────────┐
│  IN-001 — Vincom Ba Trieu Entrance                                     │
│  indoor · entrance · Hanoi / Hai Ba Trung                              │
│  Coord: 21.0175, 105.85005   [🔗 OpenStreetMap]                        │
│                                                                        │
│  Last calculated: 2h ago (model=mvp-1.0, version=v-2026-04-20)         │
│  Enriched:        2h ago (enrich=mvp-1.0)                              │
│                                                                        │
│  [🔄 Re-enqueue recompute]                                             │
├────────────────────────────────────────────────────────────────────────┤
│ 📊 Estimate                                                            │
│   Daily impressions:  2,063                                            │
│   Monthly:            61,875                                           │
│   Confidence:         0.75 (indoor, venue matched, footfall > 0)       │
│   Method:             rule_based_indoor                                │
├────────────────────────────────────────────────────────────────────────┤
│ 🔎 Breakdown                                                           │
│                                                                        │
│   venue_footfall      =  25,000      (venue: Vincom Ba Trieu)          │
│   × zone_factor       =  1.00        (entrance)                        │
│   ─────────────────────────────                                        │
│   ⇒ daily screen_flow =  25,000                                        │
│   × visibility        =  0.55                                          │
│   × direction         =  1.00                                          │
│   ─────────────────────────────                                        │
│   ⇒ daily OTS         =  13,750                                        │
│   × SOV × dwell       =  0.15                                          │
│   ─────────────────────────────                                        │
│   ⇒ daily impressions =  2,063                                         │
├────────────────────────────────────────────────────────────────────────┤
│ 🛣 Road context (indoor — not used but shown)                          │
│   Nearest: Ba Trieu (primary, 4 lanes)   20m away                      │
│   Nearest main road (≤2km): Ba Trieu, 20m                              │
├────────────────────────────────────────────────────────────────────────┤
│ 📍 POI counts                                                          │
│   Within 300m:  total=9  shopping=2  food=3  entertainment=1           │
│                 office=1  transport=1  education=0  healthcare=1       │
├────────────────────────────────────────────────────────────────────────┤
│ 🏢 Venue                                                               │
│   Name:     Vincom Ba Trieu                                            │
│   Type:     mall                                                       │
│   Footfall: 25,000/day                                                 │
├────────────────────────────────────────────────────────────────────────┤
│ 🌤 Weather (latest city snapshot, not yet in formula)                  │
│   Observed:     2h ago                                                 │
│   Temperature:  28°C                                                   │
│   Precip:       0.0 mm                                                 │
├────────────────────────────────────────────────────────────────────────┤
│ 🏷 Context tags                                                        │
│   { "indoor_outdoor": "indoor", "venue_matched": true,                 │
│     "zone_type": "entrance" }                                          │
└────────────────────────────────────────────────────────────────────────┘
```

Outdoor screens — tương tự nhưng đổi breakdown section thành outdoor formula.

### 7.1. Visual hints

- Highlight dòng "⇒" với bold + màu đậm.
- Confidence score hiện kèm emoji: 🟢 ≥ 0.70, 🟡 0.50–0.69, 🔴 < 0.50.
- Khi `last_calculated_at` cũ > 24h → banner vàng "⚠ Estimate cũ, nên re-enqueue".
- Nếu `formula_version.tag` khác với active version hiện tại → banner "⚠ Screen đang dùng formula cũ. Re-enqueue để apply version mới."

---

## 8. API endpoint (optional)

Nếu Laravel cần trả JSON cho frontend SPA:

```php
Route::get('api/oohx/screens/{externalId}/inspect', [ScreenInspectorController::class, 'json'])
    ->middleware(['auth:sanctum']);

// Controller
public function json(string $externalId)
{
    $data = $this->svc->inspect($externalId);
    abort_unless($data, 404);
    return response()->json($data);
}
```

Trả về structure như trong `inspect()` service.

---

## 9. Link từ các chỗ khác

- Screen list (có sẵn từ phase 1) → mỗi row có nút "Inspect" → link tới `/admin/oohx/screens/{externalId}/inspect`.
- Campaign detail → list screens → mỗi screen inspect link.
- Jobs list (guide 02) → job type `screen` → link tới inspect của screen đó.

---

## 10. Performance notes

- Query `inspect()` đã merge 4 bảng (screens, metrics, estimates, formula_versions) + 1 query phụ cho weather → 2 round-trip tổng.
- Không cache Laravel side — data đã là precomputed + lookup trực tiếp, < 10ms mỗi request qua tunnel.
- Nếu traffic UI cao, cache Laravel 60s trên `$externalId` đủ để không spam DB.

---

## 11. Test plan

- [ ] Truy cập `/admin/oohx/screens/IN-001/inspect` → UI hiện đầy đủ 7 panel.
- [ ] Truy cập với `externalId` không tồn tại → 404.
- [ ] Outdoor screen: breakdown hiện đúng 11 step (base, road, lane, intersection, poi, population, passby, visibility, direction, OTS, multiplier, impressions).
- [ ] Indoor screen: breakdown hiện đúng 7 step.
- [ ] Re-enqueue button → redirect sang jobs với flash message.
- [ ] Banner "formula cũ" hiện đúng khi `formula_version_id ≠ active version id`.
- [ ] Banner "estimate cũ" hiện đúng khi `last_calculated_at < NOW() - 24h`.

---

## 12. Assumptions cho Claude Code

1. Model `Screen`, `ScreenEstimate` đã có từ integration phase 1. Confirm.
2. Thêm model `ScreenContextMetrics` mới.
3. Cần confirm cột `formula_version_id` đã được ALTER vào `output.screen_traffic_estimates` (từ upgrade proposal). Nếu chưa → hỏi ops.
4. Cột `weather_factor`, `seasonality_factor`, `calibration_factor` có thể NULL ở MVP. UI render "—".
5. Middleware `can:view-oohx-screens` đã define.
6. Formula shape (outdoor/indoor) trong `buildBreakdown()` mirror Python; nếu Python đổi → cần update parallel.

---

## 13. Liên quan

- Guide 01: active formula version — link đến từ inspector banner.
- Guide 02: re-enqueue button → gọi `JobOrchestrator::enqueueScreen()`.
- Guide 03: weather data source — nếu không có snapshot, UI hiện "No data yet, trigger collector →".
- Guide 05: dashboard "screens with stale estimate" → click link sang inspector.

*Updated: 2026-04-20.*
