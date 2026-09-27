# Phase 4.2.2 — Venue Footfall Multi-Provider (Proposal for DE team)

> **Role of this document**: Laravel-side proposal handoff → DE team triển khai backend Python. Thay đổi scope so với original Phase 4.2.2 (Google Places single-provider) → multi-provider architecture với Foursquare primary + OSM fallback + Google activate later.
>
> **Status**: proposal, chờ DE team review + confirm scope.
>
> **Authored by**: Laravel team
> **Target implementation**: DE team (Python)
> **Related**: [PHASE-4.2.1-HANDOFF.md](PHASE-4.2.1-HANDOFF.md) §8.3 (original scope)

---

## 1. Executive summary

Original Phase 4.2.2 scope (Google Places single-provider) không còn viable cho MVP vì:
1. Việt Nam hiện bị block đăng ký Google Places API (OOHX team sẽ register sau go-live)
2. Vendor lock-in risk — nếu Google thay policy/pricing sẽ phải rewrite

Đề xuất thay bằng **multi-provider architecture** với 4 providers theo priority:

| # | Provider | Status | Role |
|---|---|---|---|
| 1 | **Foursquare Places** | ✅ MVP primary | Global venue data, VN urban coverage decent |
| 2 | **OSM Overpass** | ✅ MVP fallback | Existing Phase 2.C infra, POI count proxy |
| 3 | **Google Places** | ⏸ Activate later | Register after go-live, flip config flag |
| 4 | **Foody / Grab / MoMo** | ⏭ Defer | Pending B2B partnership — NO scraping |

Laravel side: ~30 phút config work (entries vào `config/oohx_collectors.php` + ScreenContextMetrics cast).
DE side: 1-2 tuần build provider abstraction + Foursquare + OSM adapter + orchestrator.

---

## 2. Legal context — why no scraping

OOHX team ban đầu cân nhắc Foody scraping cho VN F&B coverage. Legal review Laravel team đã thực hiện:

### 2.1. Foody Terms of Service (vi phạm rõ ràng nếu scrape)

Trích điều khoản [foody.vn/dieu-khoan-su-dung](https://www.foody.vn/dieu-khoan-su-dung):
- "bạn không có quyền sử dụng website hoặc dịch vụ của chúng tôi vào mục đích thương mại" (without written consent)
- Cấm "tải lên, gửi, xuất bản, tái sản xuất hoặc phân phối" data
- Cấm automated access tới server/data areas
- All venue data là Foody IP — redistribution requires consent

### 2.2. Vietnam Luật An ninh mạng 2018

Luật không có điều khoản cụ thể về web scraping commercial, nhưng:
- ToS của website là contract binding → violation = civil liability
- IP infringement claim có thể apply nếu redistribute data
- OOHX dùng data Foody trong sản phẩm commercial (ad inventory estimation) → material damage potential

### 2.3. Risk assessment

| Risk | Likelihood | Impact |
|---|---|---|
| Foody sends C&D letter | Medium | Must stop + potential settlement cost |
| IP block from Foody side | High | Technical — reset via VPN rotation, không stop vấn đề root |
| Lawsuit nếu go-live scale | Low-Medium | Significant — VN courts enforce ToS contracts |
| Reputational | Medium | Advertiser/publisher trust bị ảnh hưởng |

### 2.4. Conclusion

**Drop Foody scraping khỏi MVP.** Defer pending formal B2B partnership — khi có budget/timeline, liên hệ Foody BD để đàm phán API license. Pattern tương tự applies cho Grab Places, MoMo venue data, Google Maps "popular times".

Multi-provider architecture được thiết kế để **plug-in** Foody/Grab/MoMo sau khi có partnership mà không phải rewrite.

---

## 3. Architecture — 4-layer provider-agnostic

```
┌──────────────────────────────────────────────────────────┐
│ metrics.screen_context_metrics                            │
│   venue_footfall (float)                                  │
│   venue_footfall_source (text)  -- NEW column             │
│   venue_footfall_updated_at (timestamptz)  -- NEW         │
└───────────────────────▲──────────────────────────────────┘
                        │ upsert từ best snapshot
┌───────────────────────┴──────────────────────────────────┐
│ VenueFootfallAggregator (Python service)                  │
│   Pick snapshot có priority cao nhất + confidence ≥ 0.3   │
│   Fallback xuống OSM nếu không provider nào khả dụng      │
└───────────────────────▲──────────────────────────────────┘
                        │ reads snapshots
┌───────────────────────┴──────────────────────────────────┐
│ source.venue_footfall_snapshots                           │
│   (screen_id, source_name, footfall_proxy, confidence,    │
│    raw_payload, fetched_at, ttl_days) UNIQUE (sid,source) │
└───────────────────────▲──────────────────────────────────┘
                        │ writes
┌───────────────────────┴──────────────────────────────────┐
│ VenueFootfallOrchestrator (chain of responsibility)       │
│   For each screen: iterate providers by priority,         │
│   first acceptable result wins, persist snapshot.         │
│   Rate-limit aware: skip provider when exhausted today.   │
└─────┬─────────────┬─────────────┬────────────────────────┘
      ▼             ▼             ▼
 ┌─────────┐  ┌──────────┐  ┌───────────┐
 │Foursqr  │  │   OSM    │  │  Google   │   [Future: Foody, Grab, MoMo]
 │Provider │  │ Provider │  │ Provider  │
 └─────────┘  └──────────┘  └───────────┘
```

---

## 4. Provider interface contract

### 4.1. Abstract base class (Python)

```python
# app/collectors/venue_footfall/base.py
from abc import ABC, abstractmethod
from dataclasses import dataclass

@dataclass
class VenueFootfallResult:
    source_name: str                 # 'foursquare' | 'osm' | 'google' | future
    venues_count: int                # Số venues tìm thấy trong radius
    total_footfall_proxy: float      # Aggregated signal (checkins / reviews / popularity)
    confidence: float                # 0..1, provider-self-assessed data quality
    raw_payload: dict                # Full provider response (JSONB)
    cost_usd: float = 0.0            # Accumulated API cost this call

class VenueFootfallProvider(ABC):
    source_name: str
    priority: int                     # 1 = try first
    is_enabled: bool

    @abstractmethod
    def fetch(self, lat: float, lon: float, radius_m: int = 300) -> VenueFootfallResult:
        """Raise RateLimitExhausted, ProviderError on failure."""

    @abstractmethod
    def health_check(self) -> bool:
        """Quick ping — used by DE health-check + Laravel dashboard."""

    def rate_limit_remaining(self) -> int | None:
        """Return None nếu không track."""
```

### 4.2. Provider implementations (Phase 4.2.2 MVP)

```python
# app/collectors/venue_footfall/foursquare_provider.py
class FoursquareProvider(VenueFootfallProvider):
    source_name = 'foursquare'
    priority = 1

    def fetch(self, lat, lon, radius_m=300):
        # Foursquare Places API v3: /places/search + /places/{id}/stats
        # Signal: stats.total_checkins + popularity (0..1)
        # Rate limit: 100k credits/month free tier
        ...

# app/collectors/venue_footfall/osm_provider.py
class OsmProvider(VenueFootfallProvider):
    source_name = 'osm'
    priority = 99  # fallback (lowest priority = last)

    def fetch(self, lat, lon, radius_m=300):
        # Reuse app/collectors/overpass_poi.py query logic
        # Signal: POI count × category weight (F&B × 2, retail × 1.5, office × 0.8)
        # Confidence: 0.3 fixed — always low because proxy only
        ...

# app/collectors/venue_footfall/google_provider.py
class GoogleProvider(VenueFootfallProvider):
    source_name = 'google'
    priority = 2  # primary when activated
    is_enabled = False  # config flag — flip to True when registered
    ...
```

### 4.3. Orchestrator

```python
# app/services/venue_footfall.py
class VenueFootfallService:
    def __init__(self, providers: list[VenueFootfallProvider]):
        self.providers = sorted(
            [p for p in providers if p.is_enabled],
            key=lambda p: p.priority,
        )

    def fetch_for_screen(self, screen) -> VenueFootfallResult:
        last_error = None
        for p in self.providers:
            try:
                result = p.fetch(screen.lat, screen.lon, radius_m=300)
                if result.confidence >= MIN_CONFIDENCE:
                    self._persist_snapshot(screen.id, result)
                    return result
            except RateLimitExhausted:
                logger.info(f"{p.source_name} rate limit — skip")
                continue
            except ProviderError as e:
                last_error = e
                logger.warning(f"{p.source_name} error: {e}")
                continue

        raise AllProvidersFailed(f"Last error: {last_error}")
```

---

## 5. Schema proposal

### 5.1. Migration SQL (1 new table + 2 new columns)

```sql
-- sql/015_venue_footfall_multi_provider.sql

-- Multi-provider snapshots — 1 row per (screen, provider)
CREATE TABLE source.venue_footfall_snapshots (
    id              BIGSERIAL PRIMARY KEY,
    screen_id       BIGINT NOT NULL REFERENCES core.screens(id) ON DELETE CASCADE,
    source_name     TEXT NOT NULL,            -- foursquare | osm | google | foody | grab | momo
    radius_m        INTEGER NOT NULL DEFAULT 300,
    venues_count    INTEGER,
    footfall_proxy  DOUBLE PRECISION,
    confidence      DOUBLE PRECISION CHECK (confidence >= 0 AND confidence <= 1),
    raw_payload     JSONB,
    cost_usd        DOUBLE PRECISION DEFAULT 0,
    fetched_at      TIMESTAMPTZ DEFAULT NOW(),
    ttl_days        INTEGER DEFAULT 30,
    UNIQUE (screen_id, source_name)
);
CREATE INDEX idx_vf_snapshots_screen ON source.venue_footfall_snapshots(screen_id);
CREATE INDEX idx_vf_snapshots_source ON source.venue_footfall_snapshots(source_name);
CREATE INDEX idx_vf_snapshots_fetched ON source.venue_footfall_snapshots(fetched_at);

-- Extend metrics với source tracking
ALTER TABLE metrics.screen_context_metrics
    ADD COLUMN IF NOT EXISTS venue_footfall_source    TEXT,
    ADD COLUMN IF NOT EXISTS venue_footfall_updated_at TIMESTAMPTZ;

-- Grants (Phase 2.A pattern)
GRANT SELECT ON source.venue_footfall_snapshots TO oohx_readonly;
GRANT ALL    ON source.venue_footfall_snapshots TO oohx_control;
GRANT USAGE  ON SEQUENCE source.venue_footfall_snapshots_id_seq TO oohx_control;
```

### 5.2. Aggregator output → `screen_context_metrics`

Job khi hoàn thành update:
- `venue_footfall` = `footfall_proxy` từ provider winner
- `venue_footfall_source` = winner's `source_name`
- `venue_footfall_updated_at` = `fetched_at`

Laravel-side: đã có accessor trên `ScreenContextMetrics` → tự pick up cast + display.

---

## 6. Config keys (leverage `config.delivery_defaults`)

6 keys mới — dùng `DeliveryDefault::rangeFor()` pattern Phase 4-B.3 (Laravel side đã sẵn sàng):

| Key | Default | Range | Purpose |
|---|---|---|---|
| `venue_footfall_enable_foursquare` | 1.0 | 0.0 / 1.0 | On/off Foursquare provider |
| `venue_footfall_enable_osm` | 1.0 | 0.0 / 1.0 | On/off OSM fallback |
| `venue_footfall_enable_google` | 0.0 | 0.0 / 1.0 | Flip 1.0 khi OOHX đăng ký xong |
| `venue_footfall_cache_ttl_days` | 30 | 1-365 | Re-fetch threshold |
| `venue_footfall_min_confidence` | 0.3 | 0.0-1.0 | Reject result below threshold |
| `venue_footfall_radius_m` | 300 | 50-1000 | Search radius |

Laravel Filament `/admin/oohx-config/delivery-defaults` tự hỗ trợ 6 keys mới sau khi DE thêm vào whitelist (và Laravel KEYS array extend — ~5 dòng code, chưa implement chờ DE confirm scope).

---

## 7. CLI proposal

Matches Phase 2.C collector pattern:

```bash
# One-time or per-screen
python -m app.cli fetch-venue-footfall --screen-id 42
python -m app.cli fetch-venue-footfall --screen-id 42 --provider foursquare  # force

# Bulk
python -m app.cli refresh-venue-footfall --city Hanoi --stale-days 30
python -m app.cli refresh-venue-footfall --all --max-runs 500

# Debug
python -m app.cli venue-footfall-health  # ping all enabled providers
python -m app.cli venue-footfall-summary  # per-source counts + last_fetched
```

Wire vào cron:
```cron
# Daily 03:00 UTC, process stale screens (ttl_days=30)
0 3 * * * cd $OOHX_PROJECT && .venv/bin/python -m app.cli refresh-venue-footfall --stale-days 30
```

---

## 8. Laravel integration surface

### 8.1. Zero-change path (recommended, match Phase 4.2.1 pattern)

DE ships backend → Laravel auto-picks up:
- `venue_footfall` field đã có trên `ScreenContextMetrics` (cast float) → no change
- `venue_footfall_source` + `venue_footfall_updated_at` → thêm cast (~3 lines)

### 8.2. New Collectors page cards (config-driven, ~1 file modify)

Thêm entries vào `config/oohx_collectors.php` (pattern Phase 2.C):

```php
'venue_footfall_foursquare' => [
    'label' => 'Venue Footfall — Foursquare',
    'icon'  => 'heroicon-o-map-pin',
    'description' => 'Checkins + popularity signal from Foursquare Places API.',
    'cadence_hours' => 24 * 7,  // weekly
    'supports_bbox' => false,
    'supports_city' => true,
],
'venue_footfall_osm' => [...],
'venue_footfall_google' => [...],
```

Page `/admin/oohx-collectors` tự render 3 cards + trigger buttons cho provider — zero code changes, config only.

### 8.3. Inspector page — show source tracking

Update `OohxEstimateResource` infolist "Data completeness" section (Phase 4-B.2):
- Add `venue_footfall` numeric column (đã có data từ Phase 2.D, giờ multi-source)
- Add `venue_footfall_source` badge (foursquare/osm/google color-coded)
- Add `venue_footfall_updated_at` since timestamp

Effort: ~15 phút modify.

### 8.4. Formula factor display

Phase 2.D formula có `venue_footfall` → `venue_score` → `context_tags`. Không đổi logic, chỉ data quality tăng.

---

## 9. Phasing recommendation

### Sprint 4.2.2.A (~1 tuần DE)

**Scope**: Provider interface + OSM adapter + Foursquare adapter + orchestrator + snapshots table.

**Deliverables**:
- [ ] Migration 015
- [ ] `venue_footfall/base.py`, `foursquare_provider.py`, `osm_provider.py`, `google_provider.py` (stub)
- [ ] `VenueFootfallService` orchestrator
- [ ] CLI `fetch-venue-footfall`, `refresh-venue-footfall`
- [ ] Cron weekly refresh stale screens
- [ ] Unit tests cho providers + orchestrator (follow Phase 4.0 CI pattern)
- [ ] Integration tests: 2 provider concurrent, rate-limit skip

**Laravel work (parallel, ~1h)**:
- [ ] Add 3 entries vào `config/oohx_collectors.php`
- [ ] Extend `DeliveryDefault::KEYS` với 6 new keys
- [ ] Update `ScreenContextMetrics` casts + infolist (2 new columns)

### Sprint 4.2.2.B (~3 ngày DE, khi OOHX đăng ký Google)

**Scope**: Activate GoogleProvider — từ stub → functional.

**Deliverables**:
- [ ] Implement `GoogleProvider.fetch()` với Google Places API v1 (New)
- [ ] Config flip `venue_footfall_enable_google` = 1.0
- [ ] Priority swap: Google (1) > Foursquare (2) > OSM (99)
- [ ] Migration: no change (schema đã support)

**Laravel work**: none — config flag flip via admin UI.

### Deferred — Foody / Grab / MoMo partnership

Chờ BD team đàm phán partnership. Khi có API access:
- Implement provider adapter (~2-3 days per provider)
- Plug vào orchestrator
- Flip config flag

---

## 10. Failure modes & observability

### 10.1. Health monitoring

Extend Phase 3.A Part 2 health digest với `venue_footfall_providers`:

```json
{
  "checks": {
    "venue_footfall_providers": {
      "status": "ok",
      "value": {
        "foursquare": {
          "status": "ok",
          "rate_limit_remaining": 47382,
          "last_success_at": "2026-04-23T08:00:00Z"
        },
        "osm": {
          "status": "ok",
          "last_success_at": "2026-04-23T08:02:00Z"
        },
        "google": {
          "status": "disabled",
          "note": "OOHX registration pending"
        }
      },
      "note": "2/3 providers active"
    }
  }
}
```

Laravel health page (Phase 3.A Part 2) auto-render new check nhờ generic rendering.

### 10.2. Graceful degradation

Nếu Foursquare rate limit exhausted → orchestrator fallback sang OSM. `venue_footfall_source` lưu `osm` → Laravel UI show warning "Using fallback data — accuracy reduced".

Nếu tất cả providers fail → `venue_footfall` giữ giá trị cũ (stale), `_updated_at` không update → Laravel UI hiện badge "Stale".

---

## 11. Open questions for DE team

1. **Provider SDK choice** — dùng official Python SDK hay raw HTTP (`requests`)? Foursquare có `foursquare-python` community lib. Đề xuất raw HTTP giống Phase 2.C để consistency.
2. **Rate limit tracking** — local counter trong memory hay Redis? Phase 2.C dùng PG. Đề xuất PG counter table.
3. **Retry policy** — Foursquare timeout → exponential backoff hay immediate fallback? Đề xuất backoff 2 lần rồi fallback.
4. **OSM as always-on** — có nên giữ OSM priority=99 luôn chạy kể cả khi Foursquare thành công? Trade-off: extra API call vs. có reference baseline cho comparison. Đề xuất only run fallback khi primary fail.
5. **Cost tracking** — Foursquare free tier 100k credits/month. Có cần alert khi > 80%?
6. **OSM confidence fixed 0.3** — hay dynamic theo POI count? Suggest dynamic: `min(0.5, log10(count) × 0.15)`.
7. **Foody defer confirmation** — DE team có agree drop scraping khỏi MVP không? Nếu DE muốn POC scraping privately, đề nghị document risk + isolate codepath với feature flag.

---

## 12. Laravel test plan (after DE ships)

### 12.1. Happy path
- [ ] DE apply migration 015 + `refresh-venue-footfall --all` chạy xong
- [ ] `/admin/oohx-estimates/{id}` → inspector hiện `venue_footfall: 1247.5 · source: foursquare`
- [ ] Pick 1 screen ngoài urban core → `source: osm` (fallback)
- [ ] `/admin/oohx-collectors` → 3 cards (Foursquare / OSM / Google disabled)
- [ ] `/admin/oohx-config/delivery-defaults` → 6 keys mới trong dropdown

### 12.2. Failure modes
- [ ] Flip `venue_footfall_enable_foursquare` = 0 → next refresh → source flip sang OSM
- [ ] Disable all → badge warning + stale data preserved
- [ ] DE `refresh-venue-footfall` fail → Laravel health page hiện warn

### 12.3. Google activation (Sprint 4.2.2.B)
- [ ] Flip `venue_footfall_enable_google` = 1.0 + restart DE
- [ ] Next refresh → source flip sang google (priority 1 < Foursquare 2)
- [ ] Numbers change, Laravel UI auto pick up

---

## 13. Summary

**Laravel team must-do after DE ships Sprint 4.2.2.A** (~2h total):
1. Extend `DeliveryDefault::KEYS` với 6 venue_footfall_* keys
2. Add 3 entries vào `config/oohx_collectors.php`
3. Update `ScreenContextMetrics` casts + `OohxEstimateResource` infolist

**Laravel team must-do Sprint 4.2.2.B**:
- Zero code — flip `venue_footfall_enable_google` config flag via admin UI

**DE team asks**:
- Review architecture §3-4
- Confirm drop Foody from MVP (§2)
- Answer open questions §11
- Schedule Sprint 4.2.2.A

---

## 14. References

- [PHASE-4.2.1-HANDOFF.md](PHASE-4.2.1-HANDOFF.md) §8.3 — original Google-only scope
- [PHASE-2C-HANDOFF.md](PHASE-2C-HANDOFF.md) — collector pattern baseline (OSM POI)
- [PHASE-3A-PART2-HANDOFF.md](PHASE-3A-PART2-HANDOFF.md) — health check extensibility
- [PHASE-4.0-HANDOFF.md](PHASE-4.0-HANDOFF.md) — CI pattern cho tests
- Foody Terms: https://www.foody.vn/dieu-khoan-su-dung
- Foursquare Places API: https://location.foursquare.com/developer/reference/places-api-overview
- Google Places API: https://developers.google.com/maps/documentation/places/web-service/overview
- Vietnam Luật An ninh mạng 2018: https://luatvietnam.vn/an-ninh-trat-tu/luat-an-ninh-mang-2018-166818-d1.html

---

## 15. Changelog

| Date | Author | Change |
|---|---|---|
| 2026-04-23 | Laravel team | Initial proposal — multi-provider + legal review + drop Foody from MVP |
