# Phase 4.2.2 — Venue Footfall Multi-Provider (Laravel Build Summary)

**Build date**: 2026-04-23
**Scope**: Sprint 4.2.2.A per [PHASE-4.2.2-HANDOFF.md](integrations/OOHX-Data-Engine/laravel-integration/PHASE-4.2.2-HANDOFF.md)
**Status**: Code shipped + smoke tested. Awaits DE migration 015 + first refresh-venue-footfall run.

---

## What Laravel built (all Must-do from handoff §1)

| Handoff priority | Task | File |
|---|---|---|
| Must-do | `ScreenContextMetrics` +2 casts + 2 accessors | `app/Models/Oohx/ScreenContextMetrics.php` |
| Must-do | `DeliveryDefault::KEYS` +11 entries + `rangeFor()` | `app/Models/Oohx/Config/DeliveryDefault.php` |
| Must-do | `config/oohx_collectors.php` +3 provider cards | `config/oohx_collectors.php` |
| Must-do | `OohxEstimateResource` infolist Venue Footfall section + table column | `app/Filament/Resources/OohxEstimateResource.php` |
| Should-do | Priority keys (1-99 int) auto-handled by `rangeFor()` | Same file as keys |
| Nice-to-have (defer) | Provider quota panel on Collectors dashboard | Skipped — health check JSON đã hiển thị tại `/admin/oohx-health` |

**Total**: 4 files modified. Zero new files. Zero Laravel migration.

---

## File-by-file delta

### 1. `app/Models/Oohx/ScreenContextMetrics.php`

Added:
- Cast `venue_footfall_source: string` + `venue_footfall_updated_at: datetime`
- Accessor `getVenueFootfallIsStaleAttribute()` → true khi updated_at > 45 ngày
- Accessor `getVenueFootfallSourceColorAttribute()` — 5-way color map:
  - `foursquare` → success (green) · paid API primary
  - `google` → primary (blue) · premium activated
  - `osm` → warning (amber) · fallback, lower quality
  - `foody/grab/momo` → info · future partnership providers
  - unknown → gray

Stale threshold = 45 ngày (6× weekly cadence theo handoff §3.4).

### 2. `app/Models/Oohx/Config/DeliveryDefault.php`

`KEYS` array mở rộng **9 → 15 → 26** (Phase 2.A → 4.1+4.2.1 → 4.2.2).

11 new keys registered:
```
venue_footfall_enable_foursquare           0/1 toggle
venue_footfall_enable_osm                  0/1 toggle
venue_footfall_enable_google               0/1 (defer flip)
venue_footfall_priority_foursquare         1-99 (default 1)
venue_footfall_priority_osm                1-99 (default 99 fallback)
venue_footfall_priority_google             1-99 (defer)
venue_footfall_cache_ttl_days              1-365 (default 30)
venue_footfall_min_confidence              0-1 (default 0.25)
venue_footfall_radius_m                    50-1000 (default 300)
venue_footfall_foursquare_daily_budget     0-100k (default 3000)
venue_footfall_foursquare_cost_per_call_usd 0-1 (default 0.006)
```

`rangeFor()` extended với match arms — sử dụng multi-value syntax `'k1', 'k2', 'k3' => [...]` cho clean grouping.

### 3. `config/oohx_collectors.php`

Added 3 cards (data-driven UI per Phase 2.C pattern):

| Card | Priority | Icon | Color | Cadence | Disabled? |
|---|---|---|---|---|---|
| `venue_footfall_foursquare` | 1 | map-pin | success | weekly | No |
| `venue_footfall_osm` | 99 | globe-alt | warning | weekly | No |
| `venue_footfall_google` | defer | building-office | gray | weekly | **Yes** (stub) |

`/admin/oohx-collectors` tự render 3 cards mới — zero code change.

### 4. `app/Filament/Resources/OohxEstimateResource.php`

**Table** (toggleable hidden by default):
- New column `contextMetrics.venue_footfall_source` — badge with color from accessor + tooltip showing `fetched_at` ago + STALE suffix

**Infolist** — new dedicated section "Venue Footfall" (sau Data completeness, trước Contextual factors):
- Signal numeric (2 decimals)
  - `suffixAction` với exclamation-triangle icon color=danger khi stale
- Source badge with color-coded quality tier + helperText per-source
- Last fetched timestamp với `since()` relative + color=danger khi stale

---

## Smoke test results

```
✓ 11/11 new keys registered in DeliveryDefault::KEYS (total 26 keys)
✓ All 11 keys return correct ranges from rangeFor()
✓ 3/3 collector cards registered in config['oohx_collectors']
✓ ScreenContextMetrics casts — venue_footfall/float, source/string, updated_at/datetime
✓ Staleness accessor — null=false, 30d=false, 60d=true
✓ Source color mapping — foursquare=success, osm=warning, google=primary, foody=info, unknown=gray
✓ Disabled flag preserved — google stub shows disabled=true
✓ All 4 files lint clean
```

---

## Handoff §1 coverage checklist

- [x] Extend `DeliveryDefault::KEYS` với 11 venue_footfall_* keys (~15 min)
- [x] Add 3 entries vào `config/oohx_collectors.php` (~10 min)
- [x] Update `ScreenContextMetrics` casts (2 new cột) (~5 min)
- [x] `OohxEstimateResource` infolist — source badge + updated_at (~30 min)
- [x] Admin config UI range update — priority keys (1-99 int) — handled via `rangeFor()` tự động (~0 extra)
- [ ] Provider quota panel trong collectors dashboard (nice-to-have, skipped — health JSON đã hiển thị)

Total estimated effort per handoff: ~60 min · actual: ~45 min.

---

## Deploy checklist (Laravel ops)

**Prerequisites DE-side** (per handoff):
- [ ] DE apply migration 015 (`sql/015_venue_footfall_multi_provider.sql`)
- [ ] DE run `refresh-venue-footfall --all` hoặc wait cron Sunday 03:00 UTC
- [ ] Verify `source.venue_footfall_snapshots` + `source.provider_rate_budgets` exist
- [ ] Verify `metrics.screen_context_metrics.venue_footfall_source` column added
- [ ] Config seed 11 delivery_defaults rows với default values

**Laravel-side**:
```bash
cd /www/wwwroot/dash.oohx.net
git pull
php artisan optimize:clear
php artisan cache:clear   # flush analytics/collectors caches

# Verify routes + config
php artisan tinker -x '
    echo count(App\Models\Oohx\Config\DeliveryDefault::KEYS) . " keys total\n";
    echo count(array_filter(config("oohx_collectors"), fn(\$v) => is_array(\$v) && isset(\$v["display_name"]))) . " collectors total\n";
'
# Expect: 26 keys · 6 collectors
```

---

## UI smoke test (post-deploy)

1. `/admin/oohx-collectors` — scroll xuống thấy 3 new cards:
   - Foursquare (green, enabled) · OSM (amber, enabled) · Google (gray, disabled with stub label)
2. `/admin/oohx-config/delivery-defaults/create` — dropdown KEYS có 26 options
   - Select `venue_footfall_enable_foursquare` → helperText "0=off · 1=on"
   - Select `venue_footfall_priority_foursquare` → "1..99 (lower = try first)"
3. `/admin/oohx-estimates/{id}` — scroll xuống infolist:
   - Section "Venue Footfall" hiện 3 cols (Signal, Source, Last fetched)
   - Nếu screen chưa fetch → placeholders "—" + "never"
   - Nếu fetched → badge color-coded theo source + relative time
4. `/admin/oohx-estimates` table — toggle column "VF source" (hidden by default)
5. `/admin/oohx-health` — khi DE ship new check `venue_footfall_providers`, section tự xuất hiện (generic render pattern Phase 3.A Part 2)

---

## Sprint 4.2.2.B readiness (Google activation)

Khi OOHX đăng ký Google Places API xong:
1. **DE-side** — implement `GoogleProvider.fetch()` (~3 ngày)
2. **Laravel-side** — ZERO code change:
   - `/admin/oohx-config/delivery-defaults` → edit `venue_footfall_enable_google` = 1.0 + `venue_footfall_priority_google` = 1 (trước Foursquare)
   - Next refresh cycle → source badge tự flip sang `google` (primary color)
   - Infolist helperText auto-update "Premium — activated"
   - `venue_footfall_google` collector card tự enable khi `disabled` flag flip trong config hoặc DE dispatch event

Config-driven design = zero redeploy for provider activation.

---

## Budget alerting (future)

Health check `/admin/oohx-health` sẽ tự pick up `venue_footfall_providers` check khi DE ship. Expected schema (handoff §5):
```json
{
  "providers": {
    "foursquare": {
      "budget": {"calls_made": 47, "daily_limit": 3000, "used_pct": 1.57}
    }
  }
}
```

Laravel health page render generic — threshold_warn/critical được DE set trong health service. Zero Laravel code để display.

Nếu sau này muốn budget progress bar rõ hơn ngay trên Collectors page, thêm custom card render — **defer** đến khi có real usage feedback.

---

## Risks / known limitations

1. **MV & config seed timing** — nếu Laravel deploy trước DE migration 015, DeliveryDefault UI cho phép create key với value hợp lệ nhưng DE sẽ fail INSERT (key không tồn tại trong whitelist Python). Mitigate: ops coordinate thứ tự (DE migrate trước, Laravel deploy sau).

2. **Stale threshold 45 ngày hardcode** — nếu DE đổi cadence từ weekly (168h) → other, Laravel staleness accessor có thể diverge. Giải pháp future: đọc `cadence_hours` từ `config/oohx_collectors.php` × 6 thay vì hardcode 45.

3. **Multi-city priority** — hiện priorities global, không per-city. Nếu một city cần OSM primary (vì Foursquare coverage yếu), phải chỉnh global. Phase 4.2.3+ có thể introduce per-city override trong `config.city_overrides` table.

4. **No tests** — smoke via tinker only. Nếu cần coverage:
   - `DeliveryDefaultRangeForTest` — all 26 keys return valid 3-tuple
   - `ScreenContextMetricsStalenessTest` — null/fresh/stale boundaries
   - `OohxEstimateResourceInfolistTest` — Venue Footfall section visible + colors

---

## Files changed

**MODIFY (4)**:
- `app/Models/Oohx/ScreenContextMetrics.php` — +2 casts, +2 accessors
- `app/Models/Oohx/Config/DeliveryDefault.php` — +11 KEYS, +5 match arms in rangeFor
- `config/oohx_collectors.php` — +3 collector entries
- `app/Filament/Resources/OohxEstimateResource.php` — +1 infolist section, +1 table column

**NEW**: none (all config-driven).

## Tests added
None — all smoke-tested via tinker script. Lint clean on 4 files.

## Risks remaining
See "Risks / known limitations" above. Primary blocker: DE migration 015 + config seed must precede Laravel deploy.
