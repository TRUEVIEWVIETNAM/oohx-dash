# Phase 4.0 — Data Engine Handoff (Infra polish)

> **Role of this document**: handoff Phase 4.0 — CI pipeline + collector test coverage. **Laravel không đụng gì** — pure infra.
>
> **Status**: code shipped + syntax-validated. Cần ops deploy CI + run tests để confirm.
>
> **Scope Phase 4.0** (1 tuần, chủ ý hẹp):
> - GitHub Actions CI: ruff lint + unit tests + integration tests với Postgres service
> - Collector test coverage: HTTP wrapper, base class, 3 collectors, runner (6 test files mới)
> - Ruff config baseline
>
> **Defer to Phase 4.1**:
> - Enrichment service tests (heavy SQL mocking)
> - Data quality upgrades (Branch B)
>
> **Reference**:
> - [PHASE-3B-HANDOFF.md](PHASE-3B-HANDOFF.md) — unit test foundation
> - [DATA-ENGINE-ACTION-PLAN.md §7](../DATA-ENGINE-ACTION-PLAN.md)

---

## 1. What's shipped

### 1.1. CI/CD

| File | Purpose |
|---|---|
| [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) | 2 jobs: `unit` (lint+test+coverage gate) + `integration` (Postgres+PostGIS service container) |
| [`python-data-engine/ruff.toml`](../../python-data-engine/ruff.toml) | Ruff baseline: E/F/I/B/UP/SIM rules, py312 target |
| [`python-data-engine/requirements-dev.txt`](../../python-data-engine/requirements-dev.txt) | +ruff (pytest/cov/mock đã có từ 3.B) |

**CI flow**:
```
Push/PR → unit job
           ├─ ruff check + format --check
           ├─ pytest tests/unit
           └─ coverage gate ≥ 80% cho 3 module critical
         → integration job (parallel)
           ├─ Spin up postgis/postgis:16-3.4 service container
           ├─ Apply migrations + seed config
           └─ pytest tests/integration -m integration
```

**Coverage gate**: CI fail nếu `app/config.py`, `app/services/preview.py`, hoặc `app/services/traffic_estimation.py` coverage < 80%. Đảm bảo critical path luôn được test khi refactor.

### 1.2. Test files mới (Phase 4.0)

| File | Focus | Tests |
|---|---|---|
| [`tests/unit/test_http.py`](../../python-data-engine/tests/unit/test_http.py) | Retry, backoff, 429 rate-limit, 4xx non-retry, exhaust | ~20 |
| [`tests/unit/test_collector_base.py`](../../python-data-engine/tests/unit/test_collector_base.py) | BBox validation/WKT, CollectorResult merge, orchestration, cache hit/miss | ~15 |
| [`tests/unit/test_overpass_poi.py`](../../python-data-engine/tests/unit/test_overpass_poi.py) | Tile split (0.10°), bbox resolve, category map (shop/amenity/leisure/healthcare), Overpass QL build, persist parse | ~25 |
| [`tests/unit/test_open_meteo_weather.py`](../../python-data-engine/tests/unit/test_open_meteo_weather.py) | Centroid fallback, multi-city plan, persist parse, **regression: wall-clock observed_at** (fix Phase 3.A timezone bug) | ~15 |
| [`tests/unit/test_osm_roads.py`](../../python-data-engine/tests/unit/test_osm_roads.py) | road_class normalize (link variants), LineString WKT, highway regex, persist parse, `_parse_int/_parse_bool` OSM quirks | ~20 |
| [`tests/unit/test_collector_runner.py`](../../python-data-engine/tests/unit/test_collector_runner.py) | REGISTRY lookup, drain flow, max_runs, run_sync validation | ~10 |

**Total mới**: ~105 tests. Cộng với 61 tests cũ từ Phase 3.B → **~166 unit tests**.

### 1.3. Testing patterns ngoại lệ (tips để maintain)

- **`_Sequence` class** trong `test_http.py`: mock `requests.request` với response/exception queue theo thứ tự. Dùng lại cho HTTP tests.
- **`FakeDB` class** trong `test_health_service.py` (3.B): mock PostgreSQL với canned rows. Re-use cho tests cần DB.
- **`with patch("module.http_post", ...)`**: mock HTTP calls ở level _module attribute_ — tránh patch `requests.request` (quá rộng).
- **`with patch.dict(REGISTRY, {"fake": ...})`**: swap REGISTRY entry tạm thời — restore sau test.
- **`no_sleep` fixture**: patch `time.sleep` → test HTTP retry logic không chờ thật 2/4/8s.

---

## 2. Coverage expectations (post Phase 4.0)

Sau khi CI chạy, expected coverage:

| Module | Before 4.0 | After 4.0 | Target |
|---|---|---|---|
| `app/config.py` | 100% | 100% | ✅ |
| `app/services/preview.py` | 97% | 97% | ✅ |
| `app/services/traffic_estimation.py` | 97% | 97% | ✅ |
| `app/services/health.py` | 63% | 63% | ⚠ unchanged — weather loop + backup_freshness chưa cover |
| `app/collectors/_http.py` | 0% | **~95%** | ✅ new |
| `app/collectors/base.py` | 0% | **~85%** | ✅ new |
| `app/collectors/overpass_poi.py` | 0% | **~75%** | ✅ (fetch path không test với real HTTP) |
| `app/collectors/open_meteo_weather.py` | 0% | **~80%** | ✅ new |
| `app/collectors/osm_roads.py` | 0% | **~75%** | ✅ new |
| `app/collectors/runner.py` | 0% | **~90%** | ✅ new |
| `app/services/enrichment.py` | 0% | 0% | ⚠ defer Phase 4.1 (heavy SQL mocking) |

Overall app/ coverage dự kiến: **~40-50%** (vs ~26% cũ).

---

## 3. Regression coverage — bugs cũ có tests ngăn tái

| Bug Phase | Test coverage |
|---|---|
| 3.A timezone `observed_at` future | `test_observed_at_uses_wall_clock_not_api_time` |
| 3.A SQL cast `IndeterminateDatatype` | `test_update_payload_key_merges_not_overwrites` (integration) |
| 3.A preview touching output.* | `test_compute_is_pure_no_db_writes` + `test_preview_does_not_touch_output` |
| 3.A CLI collect-weather single city | `test_plan_no_city_iterates_all_active_cities` |

---

## 4. Deploy checklist (ops)

### 4.1. Pull code

```bash
cd /home/oohx/apps/oohx-matrix && git pull
```

### 4.2. Install ruff

```bash
cd python-data-engine
.venv/bin/pip install -r requirements-dev.txt
```

### 4.3. Lint check

```bash
.venv/bin/ruff check app tests
```

**Expected**: Baseline pass hoặc vài warning non-blocking. Nếu có violations → fix hoặc adjust `ruff.toml` per-file-ignores.

### 4.4. Format check

```bash
.venv/bin/ruff format --check app tests
```

Nếu fail → chạy `.venv/bin/ruff format app tests` để auto-fix, review diff, commit.

### 4.5. Run tests

```bash
# Unit — fast
.venv/bin/pytest tests/unit -v

# Integration — cần DB
.venv/bin/pytest tests/integration -v -m integration

# Full coverage
.venv/bin/pytest --cov=app --cov-report=term-missing | tail -40
```

**Expected**: ~166 unit pass + 8 integration pass + app coverage ~45%.

### 4.6. Enable CI on GitHub

1. Push code lên branch — CI sẽ auto-run
2. Verify tab **Actions** trên GitHub: 2 jobs `unit` + `integration` đều pass
3. (Optional) Add branch protection rule main: require CI pass trước khi merge

---

## 5. Laravel side — zero change

Phase 4.0 không đổi API, schema, data format. Laravel team không cần làm gì.

---

## 6. Known limitations / deferred

| Gap | Lý do defer | Khi nào ship |
|---|---|---|
| Enrichment tests 0% | SQL mock cho 5-6 query heavy — effort > value hiện tại | Phase 4.1 nếu có regression, hoặc Phase 4.B auto-calibration |
| Coverage gate chỉ 3 module | Nếu thêm module sẽ gate quá strict khi dev refactor. Tăng dần. | Phase 4.X |
| CI chỉ run trên main + PR | Chưa có schedule nightly tests | Khi cần catch upstream API break (Overpass/Open-Meteo format change) |
| No mutation testing | Overkill cho scope hiện tại | Có thể xem xét nếu team scale 3+ dev |
| No linting Python type annotations | `mypy` chưa add — code typed Optional là đủ guard | Phase 4.2 nếu cần |

---

## 7. Open questions

1. **Coverage target enforce strict?** Hiện chỉ 3 module gate 80%. Có nên thêm `app/services/health.py` ≥ 75%?
   → DE vote: chờ 4.1 khi tests của weather_freshness + backup_freshness được viết.
2. **CI schedule (nightly/weekly)**: nên run tests bình thường hoặc chạy lại Overpass integration để catch API break?
   → Đề xuất: weekly (chủ nhật 06:00 UTC) — đủ phát hiện drift mà không spam runner.
3. **Ruff strict dần theo phase nào?** Hiện ignore line-too-long (E501), B008 CLI decorators. Có thể bật từ 4.1.
4. **Secrets management**: CI integration job cần DB credentials — hiện dùng test fixtures. Nếu sau này cần real API keys (TomTom Phase 4.B), setup GitHub Secrets.

---

## 8. Next phase (based on 3.0 roadmap proposal)

Sau 4.0 xong → wait-see 2-4 tuần cho Laravel feedback từ Phase 3 UI. Decision matrix:

- "Advertiser báo estimate lệch" → **Phase 4.1** (Branch B data quality)
- "Sales cần campaign planner" → **Phase 4.2** (Branch A)
- Không signal rõ → **Phase 4.1** default (improve accuracy là fundament)

---

## 9. Changelog

| Ngày | Version | Change |
|---|---|---|
| 2026-04-21 | 4.0.1 | Phase 4.0 shipped: CI pipeline (ruff+pytest), ~105 tests collector coverage, regression tests cho 4 bugs Phase 3.A. |

---

*Handoff owner: Data Engine tech lead. Update sau khi CI chạy green trên GitHub Actions.*
