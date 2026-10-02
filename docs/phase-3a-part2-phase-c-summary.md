# Phase 3.A Part 2 — Phase C Summary (Polish: Widget + Stale UX)

**Build date**: 2026-04-21
**Scope**: Dashboard summary widget + surfacing scheduler status in Health page
**Status**: Complete — Phase 3.A Part 2 Laravel-side shipped end-to-end

---

## Files delivered

### New (1)
- `app/Filament/Widgets/OohxHealthSummaryWidget.php` — 4-stat widget for admin Dashboard. Shows:
  - **DE Health** — big status label (OK/Warning/Critical/Stale) with color + icon; description shows digest age
  - **OK checks** — count (green)
  - **Warnings** — count (warning when >0, gray when 0)
  - **Critical** — count (danger when >0, gray when 0)
  
  All 4 stats link to `/admin/oohx-health`. `canView()` restricted to super_admin — hidden for publisher panel users.

### Modified (3)
- `app/Providers/Filament/AdminPanelProvider.php` — imported + registered `OohxHealthSummaryWidget::class` in `->widgets()` (alongside existing `RegistryStatsWidget`).
- `app/Filament/Pages/OohxHealth.php` — added `$lastFetch` public property populated from `Cache::get('oohx:health:last_fetch')`. This is the status recorded by `oohx:fetch-health` command on every run.
- `resources/views/filament/pages/oohx-health.blade.php`:
  - Empty state now shows whether the scheduler has run + what status (success/failed + message). If no run yet, explains expectation.
  - Footer (when digest loaded) shows last scheduler fetch status + timestamp. Helps debug "why is digest stale" — distinguishes DE cron failure vs. Laravel scp failure.

---

## Stale UX decision tree (what user sees)

| State | UI shown |
|-------|----------|
| No digest file + no scheduler runs yet | Empty state: "Scheduler chưa chạy lần nào. Chờ ≤30 phút hoặc click Fetch." |
| No digest + scheduler ran successfully | Empty state: "Last scheduler run: success" — implies DE cron itself isn't writing the file |
| No digest + scheduler failing | Empty state: "Last scheduler run: failed — <error>" — implies SSH/network/key issue |
| Digest loaded, fresh (<2h) | Normal dashboard, green age badge |
| Digest loaded, stale (2-12h) | Amber banner "Digest is stale. Check DE cron hoặc Laravel scheduler." Footer still shows last fetch for context. |
| Digest loaded, dead (>12h) | Red banner "STALE" + critical navigation badge |

This layered UX means ops can diagnose without grepping logs: the page tells them which side (DE or Laravel) is broken.

---

## Rendering preview

**Admin Dashboard** (super_admin only, top row):

```
┌─────────────┬─────────────┬─────────────┬─────────────┐
│ DE Health   │ OK checks   │ Warnings    │ Critical    │
│ ⚠ Warning   │ ✓ 7         │ ⚠ 1         │ × 0         │
│ 6m ago      │             │             │             │
└─────────────┴─────────────┴─────────────┴─────────────┘
(click any → /admin/oohx-health)
```

**Health page sidebar nav**:
- Icon: 🫀 heart icon
- Badge: "WARN" (color-coded) when not OK
- Label: "Health Monitor"

---

## Files changed (full Phase 3.A Part 2 delta)

### Phase A (2 modify)
- `app/Models/Oohx/ScreenContextMetrics.php` — added `nearest_road_id` cast + 4 accessors
- `app/Filament/Resources/OohxEstimateResource.php` — IconColumn + infolist section

### Phase B (6 new, 2 modify)
- NEW `app/Services/Oohx/HealthDigestService.php`
- NEW `app/Console/Commands/OohxFetchHealthDigest.php`
- NEW `app/Filament/Pages/OohxHealth.php`
- NEW `resources/views/filament/pages/oohx-health.blade.php`
- NEW `resources/views/filament/pages/oohx-health-raw.blade.php`
- NEW `storage/app/private/oohx-health/health-digest-*.json` (mock, remove in prod)
- MODIFY `config/oohx.php` (health_digest_remote_dir)
- MODIFY `routes/console.php` (schedule entry — `everyTenMinutes()` per handoff §4.2 update)

### Phase C (1 new, 3 modify)
- NEW `app/Filament/Widgets/OohxHealthSummaryWidget.php`
- MODIFY `app/Providers/Filament/AdminPanelProvider.php`
- MODIFY `app/Filament/Pages/OohxHealth.php`
- MODIFY `resources/views/filament/pages/oohx-health.blade.php`

**Total**: 7 new + 7 modify across 3 phases.

---

## Operations runbook summary (for Laravel ops)

```bash
# 1. Ensure .env has:
OOHX_REMOTE_HOST=139.162.20.95
OOHX_REMOTE_USER=oohx
OOHX_SSH_KEY=/path/to/key   # or storage/app/oohx-ssh/oohx_sync
OOHX_HEALTH_REMOTE_DIR=/home/oohx/logs   # optional, has default

# 2. Manual first fetch
php artisan oohx:fetch-health

# 3. Verify scheduler registered
php artisan schedule:list | grep oohx:fetch-health
# Expect: */30 * * * * php artisan oohx:fetch-health

# 4. Cron must be running system-wide:
# * * * * * cd /path/to/oohx-dash && php artisan schedule:run

# 5. Visit /admin/oohx-health
```

---

## Verification / test plan matching handoff §7

### §7.1 — HCMC data quality (Phase A)
After ops runs `hcmc_parity.sh`:
- [ ] Filter OohxEstimateResource by city=HCMC → IconColumn shows ✓ green (≥90% rows)
- [ ] Click any HCMC row → View page shows "Data completeness: Complete" badge
- [ ] Screen with pre-parity data (cached) → amber, tooltip lists missing roads

### §7.2 — Health dashboard (Phase B+C)
- [ ] `oohx:fetch-health` run → file in `storage/app/private/oohx-health/`
- [ ] `/admin/oohx-health` loads → 8 check cards render
- [ ] Dashboard `/admin` shows OohxHealthSummaryWidget at top (super_admin only)
- [ ] Fake warn (delete weather snapshots on DE side) → after next digest → UI shows warn state
- [ ] Delete all digest files → page shows empty state with scheduler status hint
- [ ] Navigation badge: "WARN" when digest.status=warn, absent when ok

---

## Handoff §8 open questions — Laravel-side answers

1. **Option A/B/C chosen**: **B** (built per DE team recommendation)
2. **MAILTO address**: ops action, not Laravel scope
3. **SMTP relay availability**: ops action
4. **Quarterly backup drill**: DE ops responsibility per handoff
5. **Log shipping to S3**: deferred to Phase 3.B if audit history becomes required

## Tests added
None — all three phases are display-layer changes reading existing data. Recommend tests if feature becomes load-bearing for SLA reporting:
- `HealthDigestServiceTest` — age calc + overall-badge mapping for all 4 status values
- `OohxFetchHealthDigestTest` — mock `Process` with success/failure exit codes
- Filament page smoke test using `livewire()->test()`

## Risks remaining
- Mock digest file in `storage/app/private/oohx-health/` should be deleted on production deploy OR gitignored (not committed).
- `Cache::get('oohx:health:last_fetch')` returns stale data if cache driver is `array` (per-request) — ensure prod uses `redis` or `database`.
- Widget appears on admin Dashboard for every super_admin on every page load; cost ≈ 1 disk read + 1 json_decode (cached 60s by HealthDigestService). Negligible.

---

# Phase 3.A Part 2 — Overall Laravel Summary

| Aspect | Delivered |
|---|---|
| HCMC badge (§2.4) | ✓ Option B dynamic — city-agnostic, future-proof |
| Health dashboard (§4.2) | ✓ Option B JSON digest + Filament page + 8 check cards + widget |
| Scheduler | ✓ `oohx:fetch-health` every 10 min (scp `latest.json` symlink), non-overlapping, background |
| Navigation | ✓ Under "OOHX · Data Engine" group, sort 56 (after Collectors 55) |
| Auth | ✓ super_admin only |
| Permissions | ✓ No DB writes. All read-only from storage file + existing oohx_control model |
| Fallback UX | ✓ Empty state + scheduler status hint + stale banner with diagnostic info |
| Semver drift tolerance | ✓ Missing check keys skip silently, unknown keys render with default rendering |

**Laravel team can now close Phase 3.A Part 2 tickets** after ops deploy is verified end-to-end on production.
