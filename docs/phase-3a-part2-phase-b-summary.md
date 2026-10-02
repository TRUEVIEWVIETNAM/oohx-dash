# Phase 3.A Part 2 — Phase B Summary (Health Monitor Dashboard)

**Build date**: 2026-04-21 (revised 2026-04-21 after handoff §4.2 update)
**Scope**: Option B per handoff §4.2 — scp JSON digest + Filament page rendering 8 health checks
**Status**: Functional, mock digest verified, scheduler registered

---

## Architecture (data flow) — revised per handoff §4.2 update

```
[DE VPS 139.162.20.95]                      [Laravel VPS]                [Admin UI]
/home/oohx/logs/                             storage/app/oohx-health/     /admin/oohx-health
  health-digest-latest.json (symlink)  scp   └── health-digest-latest     (polling 5m)
  (DE cron hourly +5min)            ────────►     + optional date files       ▲
                                                                               │
  health-digest-YYYYMMDD.json                                          read   ─┤
  (DE cron daily 08:00 UTC)                                            + cache │
                                                               HealthDigestService
                                                                               │
                         Laravel scheduler /10 min ─────────────────────────────┘
                         → oohx:fetch-health (default: latest.json only)
```

**Key change**: primary scp target switched from `health-digest-YYYYMMDD.json` (date-gated,
fails before 08:00 UTC) to `health-digest-latest.json` (symlink, always available + refresh
hourly). Schedule tightened 30m → 10m to pick up DE hourly refresh faster.

---

## Files delivered (6 new, 2 modified)

### New
- `app/Services/Oohx/HealthDigestService.php` — stateless reader + parser + UI helper. Cache TTL 60s on `latest()`. Maps digest status → color + icon + label. Computes age from `checked_at`.
- `app/Console/Commands/OohxFetchHealthDigest.php` — `php artisan oohx:fetch-health` scp's `health-digest-latest.json` by default (per handoff §4.2). Options: `--date=YYYYMMDD` (single audit file), `--all` (latest + last 7 days), `--latest-only` (explicit). Silent-fail when SSH key or remote file missing. Records status in cache key `oohx:health:last_fetch`.
- `app/Filament/Pages/OohxHealth.php` — custom Filament page at `/admin/oohx-health`. Poll 5m. `canAccess()` restricts to super_admin. Navigation badge shows "WARN"/"CRITICAL" in colors when digest not OK.
- `resources/views/filament/pages/oohx-health.blade.php` — full layout: empty state, overall banner (icon + color + host + timestamp + age), stale banner if >2h, summary counts (ok/warn/critical), 2-col grid of 8 check cards with expandable per-check detail.
- `resources/views/filament/pages/oohx-health-raw.blade.php` — modal content for "View raw JSON" action.
- `storage/app/private/oohx-health/health-digest-20260421.json` — mock digest (dev-only, can delete when real data flows).

### Modified
- `config/oohx.php` — added `data_engine.health_digest_remote_dir` (default `/home/oohx/logs`, overridable via `OOHX_HEALTH_REMOTE_DIR` env).
- `routes/console.php` — added schedule entry `->everyTenMinutes()->withoutOverlapping(5)->runInBackground()` writing to `storage/logs/oohx-health.log`. (Was `/30min` in initial build; tightened after handoff §4.2 update — DE refreshes `latest.json` hourly, 10-min poll gives <15min worst-case staleness.)

---

## UI rendering (8 check cards)

Each card has:
- Icon (health-check-specific, e.g. `circle-stack` for DB, `cloud` for weather, `queue-list` for queue)
- Label (VN-friendly mapping from handoff key, e.g. `db_connection` → "Database connection")
- Status badge (OK/WARN/CRITICAL) with color
- Value formatted per check type:
  - `collector_queue_backlog` → "3 pending"
  - `job_failure_rate_24h` → "4.0%"
  - `formula_coverage` → "97.0%"
  - `weather_freshness` → "Hanoi 6.0h · HCMC 6.0h"
  - `collector_stale` → "1 collector(s) stale"
- Note field (if digest includes `"note"`)
- Per-check extended details (auto-expanded for warn/critical, collapsed for ok):
  - `collector_stale`: list of collectors with overdue hours + SLA
  - `weather_freshness`: per-city mini-grid with color-coded hours_since
  - `formula_coverage`: active version tag, matched/stale/total, in_grace_period indicator
  - `job_failure_rate_24h`: ok_count / fail_count breakdown
  - `enrichment_stale`: total_active for context

Threshold values (when digest provides them) shown as muted tiny text.

Overall banner shows digest age color-coded: <30m green, <2h gray, <12h warning, >12h red "STALE".

---

## Semver compliance with handoff §3.3

The service tolerates schema drift: missing check keys just omit those cards; unknown check keys still render with default icon + raw value. Add-field changes from DE side are non-breaking.

---

## Verification (smoke test passed)

```bash
php artisan tinker -x "..."
# Output:
# Path: storage/app/private/oohx-health/health-digest-20260421.json
# Age (min): 0
# Overall status: warn
# Badge: Warning (color=warning)
# Checks count: 8
```

Scheduler registered:
```bash
php artisan schedule:list | grep oohx:fetch-health
# */30 * * * *  php artisan oohx:fetch-health ......... Next Due: ...
```

Command registered:
```bash
php artisan list | grep oohx:fetch-health
# oohx:fetch-health  SCP health digest JSON từ Data Engine VPS ...
```

---

## Operations — how to deploy

1. Ops confirms DE side is running (handoff §6.2-6.3 — health CLI works, cron writes `/home/oohx/logs/health-digest-*.json`)
2. Laravel-side (VPS with SSH key to DE): no action needed — scheduler picks up next run.
3. First run: `php artisan oohx:fetch-health` manually to seed. Output tells you if SCP succeeded.
4. Navigate to `/admin/oohx-health` → Should render within 5s.
5. If stale/empty:
   - Check `storage/logs/oohx-health.log`
   - Run with `-v` flag: `php artisan oohx:fetch-health -v`
   - Verify SSH key path: `ls -la $(grep OOHX_SSH_KEY .env)` 

---

## Tests added
None — service is straightforward, no business logic beyond formatting. If user requests coverage:
- `HealthDigestServiceTest`: parse stable schema, age calc, overall-badge mapping for all 4 statuses + stale timeout.
- `OohxFetchHealthDigestTest`: mock SCP exit codes (0/1), verify cache key recorded.

## Risks remaining
- If DE changes JSON schema in a breaking way (rename/remove) — service returns null for those checks; handoff promises semver, but no runtime guard enforces it. Acceptable for now.
- Local dev: no SSH tunnel → command always fails → UI always shows "No health digest yet" unless mock file is created. Mock file shipped for Phase B verification; ops should remove before deploy OR leave as stale-warning example.
- SSH key permission — if sysadmin set `chmod 775` on the key at some point, SCP will silently refuse. Handoff §6.4 covers SMTP but not this — covered by existing `OohxSyncToEngine` pattern (same key, already working for outbound).

---

## Handoff §4.2 update — revision delta (2026-04-21)

DE team updated §4.2 sau khi nhận ra date-pattern scp fails cho tới khi daily cron fires 08:00 UTC. Two changes:

| Field | Before | After |
|---|---|---|
| Primary scp source | `health-digest-YYYYMMDD.json` (date-gated) | `health-digest-latest.json` (symlink, always exists) |
| Recommended cron interval | every 30 min | every 10 min |
| Latency worst-case | 30m + 1h DE cycle = ~90m | 10m + 1h DE cycle = ~70m, typically <15m |

**Laravel actions taken**:
1. `OohxFetchHealthDigest::buildTargets()` default returns `['health-digest-latest.json']`. Date-pattern only when `--date=` or `--all`.
2. `HealthDigestService::latestPath()` prefers `latest.json` first, falls back to date-pattern scan (7 days + broader scan) → zero breakage if DE temporarily stops updating symlink.
3. `routes/console.php` schedule tightened to `everyTenMinutes()`.

**Verification**:
```bash
$ php artisan schedule:list | grep fetch-health
*/10 * * * *  php artisan oohx:fetch-health ...

$ php artisan tinker -x '...' # priority test
Resolved path: health-digest-latest.json
Is latest.json: YES
```

**Zero data loss**: `latest.json` missing on DE side → fallback cascade still finds most recent
dated digest. Ops can mix-and-match (e.g., DE only writes daily temporarily) — UI still works.
