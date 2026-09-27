# Phase 4.2.7 — Analytics Layer for Laravel Dashboard

> **Role**: handoff 4 materialized views cho Laravel build dashboard queries nhanh + ổn định. Laravel team queries qua `oohx_readonly` role.
>
> **Status**: backend shipped + syntax-validated. Laravel team cần build dashboard UI; không có DB schema change cần Laravel làm.
>
> **Scope Phase 4.2.7**:
> - Migration 013: 4 materialized views (campaign weekly, city performance, screen utilization, formula impact)
> - CLI `refresh-analytics`
> - Daily cron refresh 04:15 UTC
> - Integration tests
>
> **Reference**:
> - [PHASE-4.1-HANDOFF.md](PHASE-4.1-HANDOFF.md) — Campaign Planner foundation
> - [DATA-ENGINE-ACTION-PLAN.md](../DATA-ENGINE-ACTION-PLAN.md)

---

## 1. Why materialized views

Laravel dashboard hiện đang query raw tables (`output.campaign_estimates`, `output.screen_traffic_estimates`, `config.formula_versions`) qua ORM. Khi data grow:

- `campaigns_weekly_trend` page: `SELECT DATE_TRUNC('week', ...) GROUP BY` trên cả table — slow khi > 1000 campaigns
- `city_performance`: JOIN 3 tables + aggregate — slow khi mỗi page load
- `screen_utilization`: cần `unnest(screen_ids)` + GROUP BY — expensive

**Materialized views**:
- Pre-compute heavy aggregates, lưu physical table
- Refresh daily (cron) — Laravel SELECT < 10ms
- Acceptable staleness: dashboard numbers "as of last 04:15 UTC"

Laravel manual refresh anytime qua `.venv/bin/python -m app.cli refresh-analytics` nếu cần fresh numbers.

---

## 2. 4 Materialized Views

### 2.1. `output.mv_campaign_weekly` — Campaign history trend

```sql
SELECT * FROM output.mv_campaign_weekly ORDER BY week_start DESC LIMIT 12;
```

| Column | Type | Meaning |
|---|---|---|
| `week_start` | date | Week Monday |
| `campaigns_count` | int | Campaigns created trong tuần |
| `total_impressions` | bigint | Sum total impressions for duration |
| `total_reach` | bigint | Sum unique reach estimate |
| `avg_frequency` | numeric | Mean frequency per campaign |
| `avg_screens_per_campaign` | numeric | Campaign size distribution |
| `avg_confidence` | numeric | Data quality index |
| `avg_cpm_vnd` | numeric | Cost efficiency trend |
| `unique_users` | int | Distinct admins who created campaigns |

**Laravel use case**: line chart 12 weeks trailing, KPI card "last week vs prev week", table breakdown.

### 2.2. `output.mv_city_performance` — Per-city rollup

```sql
SELECT * FROM output.mv_city_performance ORDER BY total_daily_impressions DESC;
```

| Column | Type | Meaning |
|---|---|---|
| `city` | text | City name |
| `screen_count` | int | Total active screens |
| `outdoor_count` / `indoor_count` | int | Type breakdown |
| `screens_with_estimate` | int | Data coverage (có estimate) |
| `avg_daily_impressions` | numeric | Per-screen average |
| `total_daily_impressions` | bigint | City-level capacity |
| `avg_confidence` | numeric | Data quality |
| `avg_population_density` | numeric | Urban/rural indicator (Phase 4.2.1 data) |

**Laravel use case**: city selector dropdown với impression/coverage badges, "inventory health" page.

### 2.3. `output.mv_screen_utilization` — Screen booking activity (90d)

```sql
SELECT * FROM output.mv_screen_utilization
WHERE campaign_count_90d > 0
ORDER BY allocated_impressions_90d DESC
LIMIT 50;
```

| Column | Type | Meaning |
|---|---|---|
| `screen_id` | bigint | DE screen_id (join với Laravel qua external_id) |
| `campaign_count_90d` | int | Distinct campaigns trong 90 ngày |
| `allocated_impressions_90d` | bigint | Fair-share impressions = Σ(campaign_total / screen_count) |
| `last_used_at` | timestamptz | Lần cuối xuất hiện trong campaign |

**Laravel use case**:
- "Top booked screens" list
- "Unused inventory" filter (`campaign_count_90d = 0 OR NULL`)
- Sales lead: "Screen X được 12 campaigns dùng → premium pricing"

**Note**: Screen không có row trong MV = KHÔNG booked 90 ngày qua. Laravel LEFT JOIN để hiển thị cả "unused" screens.

### 2.4. `output.mv_formula_version_impact` — Formula comparison

```sql
SELECT * FROM output.mv_formula_version_impact
ORDER BY activated_at DESC NULLS LAST
LIMIT 10;
```

| Column | Type | Meaning |
|---|---|---|
| `formula_version_id` | bigint | PK |
| `tag` | text | Version tag (eg `v2-2026-Q2`) |
| `description` | text | Human description |
| `is_active` | bool | Currently active? |
| `activated_at` | timestamptz | Khi activated |
| `screens_with_this_version` | int | Screens estimate gắn với version này |
| `avg_daily_impressions` | numeric | Mean output this version |
| `total_daily_impressions` | bigint | Sum |
| `avg_confidence` | numeric | Quality |
| `last_estimate_at` | timestamptz | Recent recompute |

**Laravel use case**:
- Formula version admin page — show impact of each version
- Before/after activate: see `total_daily_impressions` difference
- "Older version X vẫn còn Y screens chưa recompute" → alert

---

## 3. Refresh cadence

### 3.1. Automatic (cron)

Daily 04:15 UTC, cron auto-refresh all 4 MVs:

```cron
# deploy/cron/oohx-analytics.crontab
15 4 * * * cd $OOHX_PROJECT && .venv/bin/python -m app.cli refresh-analytics >> $OOHX_LOGS/analytics.log 2>&1
```

**Install on VPS**:
```bash
cd /home/oohx/apps/oohx-matrix/python-data-engine
crontab -l > /tmp/cur.cron
cat deploy/cron/oohx-analytics.crontab >> /tmp/cur.cron
crontab /tmp/cur.cron
```

### 3.2. Manual refresh (on-demand)

```bash
# Default — fast but locks MV briefly
.venv/bin/python -m app.cli refresh-analytics

# Non-blocking (concurrent) — required nếu Laravel dashboard đang query
.venv/bin/python -m app.cli refresh-analytics --concurrent
```

CONCURRENTLY yêu cầu UNIQUE index trên MV — migration 013 đã tạo.

### 3.3. Laravel-triggered refresh

Laravel có thể expose "Refresh analytics" button cho admin:
```php
// Laravel side — call DE via SSH or DB function wrapper
// Hoặc đơn giản: kick off cron manual via queue job
DB::connection('data_engine')->statement(
    "REFRESH MATERIALIZED VIEW CONCURRENTLY output.mv_campaign_weekly"
);
```
(Laravel `oohx_control` role có SELECT, không có REFRESH permission mặc định. Cần grant thêm nếu muốn self-refresh — xem §6.)

---

## 4. Laravel integration patterns

### 4.1. Eloquent model (read-only)

```php
namespace App\Models\Oohx;

class AnalyticsCampaignWeekly extends Model
{
    protected $connection = 'data_engine';
    protected $table = 'output.mv_campaign_weekly';
    public $timestamps = false;
    protected $primaryKey = 'week_start';
    public $incrementing = false;
    protected $keyType = 'date';

    // Read-only guard
    public function save(array $options = []) { throw new \Exception('MV is read-only'); }
}
```

Tương tự cho 3 MV khác.

### 4.2. Filament widget examples

```php
// Campaigns trend line chart — trailing 12 weeks
AnalyticsCampaignWeekly::query()
    ->orderByDesc('week_start')
    ->limit(12)
    ->get()
    ->reverse()
    ->pluck('total_impressions', 'week_start');

// Top 10 cities by impressions
AnalyticsCityPerformance::query()
    ->orderByDesc('total_daily_impressions')
    ->limit(10)
    ->get();

// Unused screens (last 90d)
AnalyticsScreenUtilization::query()
    ->where('campaign_count_90d', 0)
    ->orWhereNull('campaign_count_90d')
    ->pluck('screen_id');
```

### 4.3. Dashboard caching (optional)

MV refresh 1 lần/ngày → Laravel có thể cache tiếp trong Redis/Memcached 30 min mà không stale nhiều.

---

## 5. Staleness SLA

| MV | Refresh cadence | Acceptable staleness |
|---|---|---|
| `mv_campaign_weekly` | Daily 04:15 | < 24h (campaigns trong ngày hiện tại chưa hiện cho tới 04:15 ngày sau) |
| `mv_city_performance` | Daily 04:15 | < 24h (new screens added không reflect ngay) |
| `mv_screen_utilization` | Daily 04:15 | < 24h |
| `mv_formula_version_impact` | Daily 04:15 | < 24h |

Acceptable cho dashboard analytics. Nếu Laravel admin cần realtime số → manual refresh hoặc switch sang raw query (nhưng chậm).

---

## 6. Role permissions

Migration 013 đã GRANT `SELECT` cho:
- `oohx_readonly` — Laravel dashboard queries
- `oohx_control` — Laravel admin pages

**KHÔNG** grant REFRESH cho bất kỳ role nào — chỉ DE cron (postgres owner) refresh được. Đảm bảo:
- Laravel không accidentally trigger heavy refresh
- Audit trail rõ ràng — refresh chỉ qua DE cron / CLI

Nếu Laravel cần trigger refresh (Phase 4.2.8?), thêm:
```sql
GRANT ALL ON output.mv_* TO oohx_control;
```

Nhưng defer cho tới khi có use case rõ.

---

## 7. Deploy checklist (ops)

```bash
# 1. Pull code
cd /home/oohx/apps/oohx-matrix && git pull

# 2. Apply migration 013
cd python-data-engine
.venv/bin/python -m app.cli init-db --sql-dir sql

# 3. Verify MVs exist
psql -U oohx -d oohx_data -c "
SELECT schemaname, matviewname, ispopulated
FROM pg_matviews
WHERE schemaname='output';
"
# Expect 4 rows, all ispopulated=t

# 4. Test refresh
.venv/bin/python -m app.cli refresh-analytics
# Expect {"refreshed":[{view:"...", status:"ok"}, ...]}

# 5. Install cron
crontab -l > /tmp/cur.cron
cat deploy/cron/oohx-analytics.crontab >> /tmp/cur.cron
crontab /tmp/cur.cron
crontab -l | grep refresh-analytics

# 6. Integration tests
.venv/bin/pytest tests/integration/test_analytics_views.py -v -m integration
```

---

## 8. Laravel test plan

- [ ] Login admin → mở trang dashboard có chart campaigns weekly → render ≤ 1s
- [ ] City performance table list → ≥ 1 row per city có screens
- [ ] Formula version admin page → list với impact metrics
- [ ] Sau khi trigger manual refresh CLI, Laravel reload → numbers update
- [ ] Screen utilization filter "unused screens" → return expected subset

---

## 9. Known limitations

| Gap | Fix ở |
|---|---|
| MV refresh plain (không CONCURRENTLY) lock MV ~5s | Phase 4.2.8 — cron chuyển sang `--concurrent` khi data grow |
| MV không filter theo owner/tenant | Phase 5 multi-tenancy |
| `avg_population_density` = NULL nếu chưa ingest Kontur | Depends on Phase 4.2.1 ops action |
| 90 day window hardcoded trong screen_utilization | Tunable via CLI arg future |
| No view cho campaigns per owner/client | Laravel side — tự group theo Laravel-side owner_id |

---

## 10. Open questions

1. **Refresh cadence** — daily đủ? Hay hourly cho campaign_weekly (campaigns create thường xuyên hơn)?
   → Đề xuất: daily trước, monitor 2 tuần. Nếu sales feedback "numbers stale" → tăng lên hourly.
2. **CPU load refresh** — 4 MVs × ~1-5s mỗi = 20s peak. Acceptable 04:15 off-peak.
3. **Screen utilization 90d window** — có phù hợp? Hay 30/60/180? Ops tune trong migration 013 nếu cần.

---

## 11. Changelog

| Ngày | Version | Change |
|---|---|---|
| 2026-04-22 | 4.2.7 | Phase 4.2.7 shipped: 4 MVs (campaign weekly, city perf, screen util, formula impact), refresh-analytics CLI, daily cron 04:15 UTC, integration tests. Laravel zero migration. |

---

*Handoff owner: Data Engine tech lead. Ship dashboard UI next session với Laravel team.*
