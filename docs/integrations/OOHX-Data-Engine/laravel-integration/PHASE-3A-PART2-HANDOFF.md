# Phase 3.A Part 2 — Data Engine Handoff for Laravel Team + Ops

> **Role of this document**: handoff Phase 3.A Part 2 (HCMC data parity + health monitoring + backup runbook) cho Laravel team và DE ops.
>
> **Status**: backend + scripts + docs shipped + syntax-validated. Cần ops deploy trên VPS.
>
> **Scope Phase 3.A Part 2**:
> - **TASK-DE-3A.4** HCMC POI + roads parity (ops script)
> - **TASK-DE-3A.5** `HealthService` + CLI `health-check` (8 checks)
> - **TASK-DE-3A.6** Monitoring cron + email alert (SMTP setup)
> - **TASK-DE-3A.7** Backup + restore runbook (pg_dump + retention)
>
> **Laravel UI scope**: rất nhỏ
> - Bỏ badge "incomplete data" cho HCMC screens (sau §2)
> - (Optional Phase 3.B) Render health dashboard từ JSON digest
>
> **Reference**:
> - [PHASE-3A-HANDOFF.md](PHASE-3A-HANDOFF.md) — Phase 3.A Part 1 (preview)
> - [docs/ops/BACKUP-RESTORE.md](../ops/BACKUP-RESTORE.md) — runbook backup
> - [docs/ops/MONITORING-SETUP.md](../ops/MONITORING-SETUP.md) — runbook monitoring
> - [DATA-ENGINE-ACTION-PLAN.md §7](../DATA-ENGINE-ACTION-PLAN.md) — plan full

---

## 1. What's shipped

| Category | Artifact |
|---|---|
| Python | [`app/services/health.py`](../../python-data-engine/app/services/health.py) — `HealthService.check_all()` |
| CLI | [`app/cli.py`](../../python-data-engine/app/cli.py) — `health-check [--json] [--exit-on-warn]` |
| Ops script | [`scripts/hcmc_parity.sh`](../../python-data-engine/scripts/hcmc_parity.sh) — one-shot HCMC collectors + verify |
| Ops script | [`scripts/backup_oohx_data.sh`](../../python-data-engine/scripts/backup_oohx_data.sh) — daily `pg_dump` + retention |
| Cron | [`deploy/cron/oohx-monitoring.crontab`](../../python-data-engine/deploy/cron/oohx-monitoring.crontab) — 2 lines (health /30m + daily digest 08:00) |
| Cron | [`deploy/cron/oohx-backup.crontab`](../../python-data-engine/deploy/cron/oohx-backup.crontab) — 1 line (dump 02:00) |
| Logrotate | [`deploy/logrotate/oohx-health`](../../python-data-engine/deploy/logrotate/oohx-health) — 4 logs xoay (health, digest, backup, hcmc) |
| Runbook | [`docs/ops/BACKUP-RESTORE.md`](../ops/BACKUP-RESTORE.md) — setup + restore drill quarterly |
| Runbook | [`docs/ops/MONITORING-SETUP.md`](../ops/MONITORING-SETUP.md) — msmtp + cron + smoke test end-to-end |

**Không có migration mới** — Part 2 không đụng DB schema.

---

## 2. HCMC parity — ops action (block badge update)

### 2.1. Pre-check (trên VPS, user oohx)

```bash
cd /home/oohx/apps/oohx-matrix/python-data-engine

psql -U oohx -d oohx_data -c "
SELECT city, COUNT(*) FROM source.pois   GROUP BY city ORDER BY city;
SELECT city, COUNT(*) FROM source.roads  GROUP BY city ORDER BY city;
SELECT city, COUNT(*) FILTER (WHERE status='active') AS active_screens
FROM core.screens GROUP BY city ORDER BY city;"
```

### 2.2. Run parity script

```bash
bash scripts/hcmc_parity.sh
# tee log vào /home/oohx/logs/hcmc-parity.log
```

Expected runtime: **15-30 phút**. Script tự log trước/sau counts + exit 0 khi pass (POI ≥ 5000, roads ≥ 5000).

Nếu Overpass bị rate-limit → script vẫn tiếp tục roads, exit 1 cuối. Rerun sau 10-15 phút.

### 2.3. Post-verify query

```sql
-- Road hit rate (% HCMC screens có nearest road match)
SELECT ROUND(100.0 * COUNT(*) FILTER (WHERE m.nearest_road_id IS NOT NULL)
             / NULLIF(COUNT(*),0)::numeric, 1) AS hit_rate_pct
FROM metrics.screen_context_metrics m
JOIN core.screens s ON s.id = m.screen_id
WHERE s.city='HCMC';
-- Pass: ≥ 90%
```

### 2.4. Laravel UI change sau khi pass

Laravel hiện render badge **"Incomplete data"** cho HCMC screens trong inspector (Guide 04). Sau khi script pass, Laravel team có thể:

**Option A — Hardcode bỏ badge**:
```php
// Xóa hoặc comment out logic detect HCMC-specific
```

**Option B — Dynamic check (đề xuất)**:
```php
// App\Services\ScreenInspector::hasCompleteData()
public function hasCompleteData(Screen $screen): bool
{
    $metrics = $this->dataEngine
        ->table('metrics.screen_context_metrics')
        ->where('screen_id', $screen->data_engine_id)
        ->first();
    return $metrics
        && $metrics->nearest_road_id !== null
        && $metrics->poi_count_300m > 0;
}
```

Option B an toàn hơn vì tự detect — không chỉ cho HCMC mà cho bất cứ city nào sau này (Đà Nẵng, Hải Phòng).

---

## 3. Health monitoring — architecture

### 3.1. Data flow

```
[cron /30min]  health-check --exit-on-warn → exit 0/1/2 → cron MAILTO
[cron 08:00]   health-check --json         → /home/oohx/logs/health-digest-YYYYMMDD.json
```

### 3.2. 8 checks

| Check | Warn | Critical |
|---|---|---|
| `db_connection` | — | ping fail |
| `collector_queue_backlog` | > 50 pending | > 200 pending |
| `collector_stale` | collector nào quá SLA (weather 8h, POI 8d, roads 35d) | — |
| `job_queue_backlog` | > 500 pending | > 2000 pending |
| `job_failure_rate_24h` | ratio > 0.20 | ratio > 0.50 |
| `formula_coverage` | < 90% sau 24h grace activate | < 50% |
| `enrichment_stale` | có screen `enriched_at > 7 ngày` | > 50% stale |
| `weather_freshness` | city nào > 12h không có snapshot | > 48h |

**Grace period** cho `formula_coverage`: 24h sau mỗi lần activate version mới — để recompute-stale cron catch up. Trong grace window → luôn ok.

### 3.3. Output schema (stable contract cho Laravel)

```json
{
  "status": "ok|warn|critical",
  "checked_at": "2026-04-21T10:00:00+00:00",
  "host": "oohx-data-01",
  "checks": {
    "db_connection": {
      "status": "ok",
      "value": true
    },
    "collector_queue_backlog": {
      "status": "ok",
      "value": 3,
      "threshold_warn": 50,
      "threshold_critical": 200
    },
    "collector_stale": {
      "status": "warn",
      "value": [
        {
          "collector": "osm_roads",
          "last_done": "2026-03-10T02:00:00+00:00",
          "overdue_hours": 96.5,
          "sla_hours": 840.0
        }
      ],
      "note": "1 collector(s) stale"
    },
    "job_queue_backlog":    { "status": "ok", "value": 12, ... },
    "job_failure_rate_24h": { "status": "ok", "value": 0.04, "ok_count": 45, "fail_count": 2, ... },
    "formula_coverage": {
      "status": "ok",
      "value": 0.97,
      "active_version_tag": "v2-2026-Q2",
      "active_version_id": 42,
      "matched": 78,
      "stale": 2,
      "total": 80,
      "in_grace_period": false
    },
    "enrichment_stale":   { "status": "ok", "value": 0, "total_active": 80, ... },
    "weather_freshness": {
      "status": "ok",
      "value": {
        "Hanoi": { "status": "ok", "last_obs": "2026-04-21T04:00:00+00:00", "hours_since": 6.0 },
        "HCMC":  { "status": "ok", "last_obs": "2026-04-21T04:00:00+00:00", "hours_since": 6.0 }
      },
      "threshold_warn_hours": 12,
      "threshold_critical_hours": 48
    }
  },
  "summary": { "ok": 6, "warn": 1, "critical": 0 }
}
```

**Semver**: add field OK; rename/remove = breaking → ping Laravel + bump handoff.

### 3.4. Exit codes CLI

| Exit | Nghĩa | Trigger email? |
|---|---|---|
| 0 | all ok, hoặc warn (không `--exit-on-warn`) | không |
| 1 | warn + `--exit-on-warn` | ✓ |
| 2 | critical | ✓ luôn |

---

## 4. Laravel integration options

Laravel có **3 cách** tiêu thụ health data, phụ thuộc vào mức effort/UI yêu cầu:

### 4.1. Option A — không làm gì (MVP)

Ops nhận email alert qua cron MAILTO. Laravel dashboard không render health.

**Pro**: zero Laravel work. **Con**: sales/product không biết state DE.

### 4.2. Option B — đọc JSON digest (đề xuất MVP Laravel)

Laravel có SSH tunnel (đã có từ Phase 1). Có 2 file Laravel có thể scp:

| File | Refresh rate | Dùng khi |
|---|---|---|
| `/home/oohx/logs/health-digest-latest.json` | **hourly (5 phút sau giờ)** | Dashboard realtime — **đề xuất** |
| `/home/oohx/logs/health-digest-YYYYMMDD.json` | daily 08:00 UTC | Audit history per-day |

**Critical**: scp target `latest.json` (symlink), KHÔNG hardcode ngày. Ngày chưa fire cron 08:00 → file `-YYYYMMDD.json` không tồn tại → scp fail.

```php
// App\Services\DataEngine\HealthDigest.php
public function latest(): ?array
{
    $path = "/tmp/oohx-health-latest.json";
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}
```

Cron Laravel-side để scp:
```cron
*/10 * * * * scp -q oohx@139.162.20.95:/home/oohx/logs/health-digest-latest.json /tmp/oohx-health-latest.json
```

**Pro**: không cần DB query, không load DE thêm. **Con**: delay 10-60 phút tùy scp interval.

### 4.3. Option C — real-time query qua tunnel (Phase 3.B)

Laravel query trực tiếp `oohx_readonly` role qua tunnel (đã có). Replicate logic `HealthService` bằng SQL.

**Pro**: live. **Con**: duplicate logic — risk divergence khi threshold đổi. **Defer**.

---

## 5. Health dashboard UI (Option B) suggestion

```
┌ Data Engine Health ─────────────────────────────────────┐
│  Last check: 2026-04-21 10:00 UTC  ●  Overall: ⚠ warn  │
│                                                         │
│  ✓ Database              OK                            │
│  ✓ Collector queue       3 pending                     │
│  ⚠ Collector stale       osm_roads (96h overdue)      │
│  ✓ Job queue             12 pending                    │
│  ✓ Job failure rate      4% (last 24h)                │
│  ✓ Formula coverage      97% (v2-2026-Q2)             │
│  ✓ Enrichment            all fresh                     │
│  ✓ Weather freshness     Hanoi 6h | HCMC 6h           │
│                                                         │
│  Last backup: oohx_data_20260421.dump (287 MB)         │
└─────────────────────────────────────────────────────────┘
```

Threshold colors:
- 🟢 ok → green
- 🟡 warn → amber
- 🔴 critical → red
- Unknown (no data) → grey

---

## 6. Deploy checklist (ops)

Thứ tự **ops phải chạy** sau khi pull code Part 2:

### 6.1. Pull + verify CLI

```bash
cd /home/oohx/apps/oohx-matrix
git pull   # hoặc rsync

cd python-data-engine
.venv/bin/python -m app.cli --help | grep health-check
# Expect: health-check ...
```

### 6.2. HCMC parity (one-shot, 15-30 phút)

Xem §2.2. Chạy trong screen/tmux vì có thể 30 phút:

```bash
screen -S hcmc
bash scripts/hcmc_parity.sh
# Ctrl+A D để detach
```

Sau pass → update Laravel team "HCMC data done, có thể deploy Option B badge logic".

### 6.3. Smoke test health-check

```bash
.venv/bin/python -m app.cli health-check
echo "Exit: $?"   # 0 nếu OK

.venv/bin/python -m app.cli health-check --json | jq .status
```

### 6.4. Setup SMTP (msmtp)

Theo [MONITORING-SETUP.md §2](../ops/MONITORING-SETUP.md#2-smtp-setup-option-a--relay-qua-laravel-vps):

```bash
sudo apt install -y msmtp msmtp-mta
sudo vim /etc/msmtprc   # config relay Laravel VPS
sudo chmod 600 /etc/msmtprc

echo "test" | mail -s "oohx test" ops@oohx.local
```

### 6.5. Install monitoring + backup cron

```bash
crontab -l > /tmp/cur.cron || true
cat deploy/cron/oohx-monitoring.crontab >> /tmp/cur.cron
cat deploy/cron/oohx-backup.crontab     >> /tmp/cur.cron
crontab /tmp/cur.cron

# Verify (phải thấy health-check và backup)
crontab -l | grep -E "health-check|backup_oohx_data"
```

### 6.6. Install logrotate

```bash
sudo cp deploy/logrotate/oohx-health /etc/logrotate.d/oohx-health
sudo logrotate -d /etc/logrotate.d/oohx-health    # dry-run
```

### 6.7. Sudo rule cho backup

```bash
sudo visudo -f /etc/sudoers.d/oohx-backup
# oohx ALL=(postgres) NOPASSWD: /usr/bin/pg_dump
```

### 6.8. First backup + drill

```bash
# Chạy manual lần đầu
bash scripts/backup_oohx_data.sh

# Drill restore (xem BACKUP-RESTORE.md §4)
sudo -u postgres createdb oohx_data_drill
sudo -u postgres psql -d oohx_data_drill -c "CREATE EXTENSION postgis;"
sudo -u postgres pg_restore -d oohx_data_drill --no-owner --no-privileges -j 2 \
    /home/oohx/backups/oohx_data_$(date +%Y%m%d).dump
# Sanity check counts
sudo -u postgres psql -d oohx_data_drill -c "SELECT COUNT(*) FROM core.screens;"
sudo -u postgres dropdb oohx_data_drill
```

---

## 7. Test plan (cho Laravel team)

### 7.1. HCMC data quality

- [ ] `SELECT COUNT(*) FROM source.pois WHERE city='HCMC';` ≥ 5000
- [ ] `SELECT COUNT(*) FROM source.roads WHERE city='HCMC';` ≥ 5000
- [ ] HCMC screen inspector không còn "Incomplete data" badge
- [ ] HCMC screen có `nearest_road_class` ∈ {primary, secondary, tertiary, residential, ...} (không toàn NULL)
- [ ] Estimate HCMC `estimated_daily_impressions` distribute reasonable (không flat giá trị mặc định)

### 7.2. Health monitoring (nếu làm Option B)

- [ ] Laravel scp JSON digest file mỗi giờ — file update timestamp
- [ ] Dashboard render 8 checks với màu đúng (ok/warn/crit)
- [ ] Fake warn trên DE (delete weather snapshots 24h) → sau 1h digest mới → Laravel UI hiện warn
- [ ] Restore weather snapshots → next digest → UI trở lại ok

### 7.3. Email alert (ops)

- [ ] Induce warn (§ MONITORING-SETUP.md §6.4) → ops nhận email trong 30 phút
- [ ] Induce critical (stop postgres) → email subject/body rõ ràng
- [ ] Cleanup → no false positive sau 1h

### 7.4. Backup

- [ ] Cron `backup_oohx_data.sh` chạy 02:00 → dump file mới trong `/home/oohx/backups/`
- [ ] Sau 8 ngày: `ls /home/oohx/backups/` giữ 7 daily + anchor ngày 01/08/15/22
- [ ] Quarterly drill pass (xem BACKUP-RESTORE.md §4)

---

## 8. Open questions cần chốt

1. **Laravel option chọn A/B/C cho health dashboard?**
   → DE vote B (JSON digest qua scp) — MVP đủ, không tăng load DE.
2. **MAILTO email địa chỉ cụ thể?**
   → Cần ops cung cấp; hiện placeholder `ops@oohx.local`.
3. **SMTP relay Laravel VPS có sẵn port 25 open không?** Nếu không → fallback SendGrid free tier.
4. **Quarterly backup drill — ai responsible?** Đề xuất DE ops, ghi vào calendar review.
5. **Log shipping?** `health-digest-*.json` hiện chỉ local VPS. Laravel đọc qua SSH/scp. Phase 3.B có thể push lên S3 nếu cần audit history dài.

---

## 9. Changelog

| Ngày | Version | Change |
|---|---|---|
| 2026-04-21 | 3.A.2 | Part 2 shipped: HCMC parity script, HealthService + CLI, cron templates, backup runbook, monitoring setup. Ops action items §6. |

---

*Handoff owner: Data Engine tech lead. Update khi Laravel ship badge update hoặc monitoring dashboard.*
