# Phase 4.1 — Campaign Planner (Laravel Build Summary)

**Build date**: 2026-04-21
**Scope**: Branch A — Campaign Planner UI + job enqueue + result display
**Status**: Functional, validation paths smoke-tested, awaits ops to apply DE migration 011

---

## What Laravel team built

| Layer | Component | File |
|---|---|---|
| Model | `CampaignEstimate` (read-only `output.campaign_estimates`) | `app/Models/Oohx/CampaignEstimate.php` (NEW) |
| Model | `RecomputeJob` accessors for campaign job type | `app/Models/Oohx/RecomputeJob.php` (MODIFY — `target_label`, `is_campaign`, `campaign_result`, `campaign_id`) |
| Service | `JobOrchestrator::enqueueCampaign()` with UUID → DE bigint resolution | `app/Services/Oohx/JobOrchestrator.php` (MODIFY — +4 constants + method) |
| Resource | `OohxCampaignEstimateResource` + 3 pages (List, Create, View) | `app/Filament/Resources/OohxCampaignEstimateResource.php` (NEW) + pages (3 NEW) |
| UI (view) | Create page Blade with Forecast button | `resources/views/filament/resources/oohx-campaign-estimate/create.blade.php` (NEW) |
| UI (table) | Bulk action "Plan campaign with selected" on ScreenResource | `app/Filament/Shared/Resources/BaseScreenResource.php` (MODIFY) |
| UI (job) | Campaign result section on Job view + "View campaign forecast" header action | `app/Filament/Resources/OohxRecomputeJobResource.php` + `Pages/ViewRecomputeJob.php` (MODIFY) |

**Total**: 5 new + 5 modify.

---

## Architecture

```
[Admin chọn N screens trong ScreenResource table]
    │
    │ (option A) bulk action "Plan campaign with selected"
    │
    ↓
[Redirect /admin/oohx-campaign-estimates/create?screens=ulid1,ulid2,...]
    │
    │ (option B) direct access từ Campaign Planner nav
    │
    ↓
[Create form: screen_ids (multi-select), duration_days, campaign_name, total_budget, notes]
    │
    │ Submit → JobOrchestrator::enqueueCampaign()
    │
    ├── Resolve Laravel ULID → Laravel.uuid → DE core.screens.id (bigint)
    ├── Validate screen count, duration, budget
    ├── INSERT INTO core.recompute_jobs (job_type='campaign_estimate', payload={...}, priority=110)
    └── INSERT INTO config.audit_log
    │
    ↓
[Redirect /admin/oohx-recompute-jobs/{id}]
    │
    │ (poll 10s) DE worker cron /1min → dequeue → CampaignPlannerService.estimate_and_persist()
    │ (worker updates: status, payload.result)
    │
    ↓
[Job status = 'done' → "View campaign forecast" button appears]
    │
    ↓
[Redirect /admin/oohx-campaign-estimates/{campaign_id}]
    │
    │ Full infolist breakdown + warnings + model disclaimer
    └── Browse history: /admin/oohx-campaign-estimates
```

---

## Navigation structure

```
OOHX · Data Engine/
├── Recompute Jobs     (sort 65)
├── Health Monitor     (sort 56)
├── Collectors         (sort 55)
├── Collector Runs     (sort 67)
└── Campaign Planner   (sort 60) — Phase 4.1 NEW
```

---

## Entry points (2 paths, same service)

### Path A — Dedicated Campaign Planner page

`/admin/oohx-campaign-estimates/create` → form với searchable multi-select of all active Laravel screens.
Best for: ad-hoc estimates, screens across owners, small campaigns.

### Path B — Bulk action on ScreenResource

On `/admin/screens` table → check N screens → BulkActionGroup → "Plan campaign with selected" → redirects to Create page với `?screens=` pre-populated.
Best for: filter-driven (e.g. "all Hanoi LED billboards"), large screen lists.

Cả 2 path converge vào same `enqueueCampaign()` → same result UX.

---

## Validation rules

| Field | Rule | Source |
|---|---|---|
| screen_ids | Non-empty | `JobOrchestrator::CAMPAIGN_MIN_SCREENS = 1` |
| screen_ids | ≤ 500 | `JobOrchestrator::CAMPAIGN_MAX_SCREENS = 500` |
| duration_days | 1..365 | `JobOrchestrator::CAMPAIGN_MIN/MAX_DURATION_DAYS` |
| total_budget | ≥ 0 or null | Service check |
| Laravel UUIDs resolvable to DE | Must resolve ≥ 1 DE screen | Throws nếu `oohx:sync-to-engine` chưa chạy |

All validation happens in `enqueueCampaign()` → throws `InvalidArgumentException` → Filament notification `.danger()` shown to user.

---

## Warnings (handoff §6.2 compliance)

### Display warnings on campaign View infolist

| Trigger | Color | Message |
|---|---|---|
| `screens_missing_estimate > 0` | warning | "N/Total screens chưa có estimate — kết quả không đầy đủ" |
| `estimated_frequency > 50` | warning/danger | "Frequency cao — cân nhắc thêm screens ở vùng khác" |
| `estimated_frequency > 100` | danger | "Over-saturation — same viewers exposed nhiều lần" |
| `avg_confidence < 0.5` | danger | "data quality yếu" (badge + note) |

### Model disclaimer (handoff §7.4)

Both Create form + View page include collapsible "About the model" section:

> Reach dựa trên mô hình spatial dedup simple (ST_GeoHash 150m cells × 500 viewers/day × log saturation). Numbers là **directional estimate**, không phải measured reality. Sẽ refine dần khi có real population density (Phase 4.2.1) + traffic calibration samples (Phase 4.2.4).

---

## UUID → bigint resolution (critical)

Laravel `screens` table PK = ULID. DE `core.screens` PK = bigint. Mapping via `external_id` column:

```
Laravel Screen.id (ULID) ──┐
                           │
Laravel Screen.uuid ───────┼──> DE core.screens.external_id
                           │        │
                           │        ↓
                           └──> DE core.screens.id (bigint)
```

Flow in `enqueueCampaign()`:
1. Input: `$laravelScreenIds` (list of Laravel ULIDs)
2. Query: `Laravel Screen::withoutGlobalScopes()->whereIn('id', $laravelScreenIds)->pluck('uuid')`
3. Query: `Oohx\Screen::whereIn('external_id', $uuids)->pluck('id')` → bigint list
4. Insert into payload: `screen_ids => [1, 2, 3, ...]::bigint[]`

**Race condition**: if screen not yet synced to DE (run `oohx:sync-to-engine`), it's silently dropped from list. Service throws if ALL drop. UX: user sees "X không tìm thấy" error → runs manual sync → retries.

---

## Smoke test results

```bash
php artisan tinker -x ".." # validation paths
✓ Empty screens throws: Cần ≥ 1 screen cho campaign
✓ Duration=0 throws: duration_days phải trong 1..365, got 0
✓ Negative budget throws: total_budget không được âm
✓ > 500 screens throws: Vượt cap 500 screens / campaign

# Constants exposed
MIN_SCREENS: 1   MAX_SCREENS: 500
MIN_DURATION: 1  MAX_DURATION: 365
```

```bash
# RecomputeJob accessors
target_label: Tết 2026 Hanoi · 5 screens × 30 days
is_campaign: yes
campaign_id: 42
campaign_result keys: id, estimated_frequency
```

```bash
# Routes registered
GET|HEAD  admin/oohx-campaign-estimates            (index)
GET|HEAD  admin/oohx-campaign-estimates/create     (create)
GET|HEAD  admin/oohx-campaign-estimates/{record}   (view)
```

---

## Deploy checklist (Laravel ops)

```bash
# 1. Pull code
cd /www/wwwroot/dash.oohx.net && git pull

# 2. Prerequisite: ops DE phải apply migration 011 TRƯỚC
# (handoff §9.1 — adds output.campaign_estimates + opens job_type='campaign_estimate')

# 3. Clear cache
php artisan optimize:clear

# 4. Smoke
#    - Open /admin/oohx-campaign-estimates — empty table
#    - Click "New Campaign" → form loads
#    - Pick 3 screens → duration 30 → Forecast
#    - Redirect to /admin/oohx-recompute-jobs/{N} → status=pending → poll
#    - Worker runs → status=done → "View campaign forecast" button appears
#    - Click → campaign detail renders metrics + warnings + disclaimer
```

---

## Test plan (handoff §10 compliance)

### 10.1 Happy path
- [ ] Admin chọn 3 screens (bulk action) → form pre-populate → Forecast → poll 10s → done
- [ ] Result: `total_impressions > 0`, `reach > 0`, `frequency > 0`
- [ ] CPM khớp `budget / (impressions/1000)`
- [ ] Click "View campaign forecast" → navigate sang campaign detail

### 10.2 Edge cases
- [ ] 1 screen → success, reach ~500 × saturation
- [ ] 50+ screens → aggregate ≤ 5s (DE-side, Laravel chỉ poll)
- [ ] Screen chưa enrich → `screens_missing_estimate > 0`, warning section hiển thị
- [ ] Duration 1/30/180 days → saturation curve expected
- [ ] Budget = 0 hoặc null → CPM hidden on view

### 10.3 Reproducibility
- [ ] Enqueue 2 lần cùng input → 2 job_id + 2 campaign_id khác, numbers identical
- [ ] Campaign Planner history table hiển thị cả 2

### 10.4 Validation
- [ ] Empty screens → submit blocked by Filament min-items validation + service throws
- [ ] duration 0 / 500 → Filament number validation + service throws
- [ ] ULID không tồn tại → service throws "Không tìm thấy Laravel screen"
- [ ] Laravel screen chưa có uuid → silently dropped, remaining screens process

---

## Risks / limitations

| Risk | Status | Mitigation |
|---|---|---|
| DE migration 011 chưa apply → job sẽ fail với "invalid job_type" | Ops action required | §9.1 handoff checklist |
| Screen chưa sync DE → resolve trả về empty → service throws | Expected | Run `php artisan oohx:sync-to-engine` trước |
| Multi-owner leak (admin nhìn thấy screens của mọi owner) | By design — admin panel bypass `HasOwnerScope` | Confirmed via `withoutGlobalScopes()` trong resolver |
| Reach formula assumptions (handoff §7.2) | Acknowledged | Disclaimer hiển thị rõ, Phase 4.2.1 sẽ refine |
| Large screen_ids payload (500 × bigint) | Fine — PG `bigint[]` ≈ 4KB max | Capped at 500 |
| Poll timeout | ViewRecomputeJob polls 10s while active, stops at terminal | Same pattern as preview |

---

## Handoff §11 open questions — Laravel-side answers

1. **Reach constant 500/cell** — Laravel UI đã có disclaimer; không promise accuracy. Chờ Phase 4.2.1.
2. **Campaign cleanup** — handoff đề xuất 180 days. Laravel không enforce — chờ DE add cleanup CLI.
3. **Concurrent bidding** — zero lock needed (pure read aggregate), Laravel UI không retry.
4. **CPM formula** — trust DE implementation, hiển thị như-là.
5. **Multi-currency** — defer Phase 5, VND hardcoded trong Money column.

---

## Tests added
Validation path smoke-tested manually (not in phpunit suite yet). If coverage needed:
- `JobOrchestratorCampaignTest` — mock connection, assert `enqueueCampaign` inserts correct payload + audit log
- `CampaignEstimateAccessorTest` — assert `frequency_color`, `confidence_tier`, `missing_screens_warning` for all tier boundaries

## Files changed
**NEW (5)**:
- `app/Models/Oohx/CampaignEstimate.php`
- `app/Filament/Resources/OohxCampaignEstimateResource.php`
- `app/Filament/Resources/OohxCampaignEstimateResource/Pages/ListOohxCampaignEstimates.php`
- `app/Filament/Resources/OohxCampaignEstimateResource/Pages/ViewOohxCampaignEstimate.php`
- `app/Filament/Resources/OohxCampaignEstimateResource/Pages/CreateOohxCampaignEstimate.php`
- `resources/views/filament/resources/oohx-campaign-estimate/create.blade.php`

**MODIFY (4)**:
- `app/Models/Oohx/RecomputeJob.php`
- `app/Services/Oohx/JobOrchestrator.php`
- `app/Filament/Resources/OohxRecomputeJobResource.php`
- `app/Filament/Resources/OohxRecomputeJobResource/Pages/ViewRecomputeJob.php`
- `app/Filament/Shared/Resources/BaseScreenResource.php`

## Risks remaining
- **Integration test needs production tunnel** — can't verify end-to-end locally (no pgsql PHP extension + no DE reachable). Production smoke test from checklist required.
- **Large screen list + Laravel Select UX** — 1000+ active screens → `->preload()` may be slow. Consider lazy-search if ops reports. Currently capped at 1000 for preload, searchable beyond.
- **DE migration 011 not applied** — blocking prerequisite, handoff §9.1.
