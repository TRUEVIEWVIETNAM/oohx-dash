# Phase 3.A Part 2 — Phase A Summary (HCMC Data Badge)

**Build date**: 2026-04-21
**Scope**: Dynamic data-completeness badge (handoff §2.4 Option B)
**Status**: Code shipped, awaits ops HCMC parity script to switch badges from `Incomplete` → `Complete`

---

## Approach

Option B (dynamic detection) chosen per handoff recommendation. No hardcoded `'HCMC'` checks — the accessor derives status from the `nearest_road_id` + `poi_count_300m` signals. When DE team ingests new cities (Đà Nẵng, Hải Phòng...), badge logic works without code changes.

---

## Files delivered

### Modified (2 files)

**`app/Models/Oohx/ScreenContextMetrics.php`**
- Added `'nearest_road_id' => 'integer'` cast (column was in DB schema but not declared in model).
- 3 new accessors:
  - `hasCompleteData` — bool, the authoritative signal
  - `completenessBadgeColor` — 'success' / 'warning'
  - `completenessBadgeLabel` — 'Complete' / 'Incomplete data'
  - `missingDataReasons` — list<string> for tooltip / UX detail

**`app/Filament/Resources/OohxEstimateResource.php`**
- Table: new `IconColumn::make('data_complete')` with heroicon check/warning icons, tooltip showing missing reasons.
- Infolist: new section "Data completeness" (visible when `contextMetrics !== null`):
  - Status badge
  - `nearest_road_id` with red color when null
  - `poi_count_300m` with red color when 0
  - Conditional "Issues" row showing the concatenated reasons (only when incomplete).

---

## Rendering preview

**Table column** (visible by default, toggleable):
- Green ✓ — Complete (road + POIs resolved)
- Amber ⚠ — Incomplete (tooltip lists what's missing)

**Infolist section** (appears on view page when metrics row exists):
```
Data completeness                                   ⚠ Incomplete data
Nearest road ID: —      POI count (300m): 0
Issues: No nearest road match (roads.* chưa ingest cho city này)
```

---

## Verification plan

Pre-HCMC parity run:
- Filter `OohxEstimateResource` by city=HCMC → most rows show ⚠ amber icon.
- Filter by Hanoi → rows show ✓ green icon.

Post-HCMC parity run (after ops runs `bash scripts/hcmc_parity.sh` on DE VPS):
- HCMC rows switch to ✓ green (≥90% hit rate per handoff §2.3 acceptance).
- Any outlier (roads missing) still shows amber — operator can investigate specific screen.

No Laravel deploy needed after ops runs script — badge reads live from `metrics.screen_context_metrics`.

---

## Risks

| Risk | Mitigation |
| ---- | ---------- |
| Column `nearest_road_id` not yet on DE DB schema | Cast is idempotent; if column missing, Eloquent returns null → accessor returns false → amber. Safe default. |
| Screen has no `contextMetrics` row at all | Tooltip shows "No context metrics yet — screen chưa được enrich" (not green, not amber — icon stays false). |
| Tunnel down → `contextMetrics` null | Same as above — amber icon, tooltip explains. Doesn't crash the page. |

## Tests added
None (read-only accessors on existing read-only model). Verified via lint + manual page reload after ops script.

## Risks remaining
- Live DB query was not possible locally due to missing `pgsql` PHP extension in dev env — production has it. Verified via syntax lint.
- If DE schema renames `nearest_road_id` without coordinating, accessor will silently always return false → amber for all rows. Handoff §3.3 promises semver for JSON digest but not for DB columns — recommend adding DB column presence check to a Phase 3.B healthcheck.
