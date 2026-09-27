# Screen Import Tool — Phase 1 Summary (MVP)

**Build date**: 2026-04-21
**Scope**: Upload Excel/CSV → AI column mapping → manual mapping edit → dry-run preview → sync import
**Status**: Functional, migration applied, Filament UI wired under `/admin/screen-imports`

---

## Files delivered

### New (15 files)

**Database**
- `database/migrations/2026_04_21_000001_create_screen_imports_table.php` — `screen_imports` table with fields for status machine, AI mapping, user mapping, preview data, error tracking, and Phase 2/3 placeholder columns.

**Model**
- `app/Models/ScreenImport.php` — ULID primary key, HasOwnerScope, accessors for `is_terminal`, `is_active`, `progress_percent`, `effective_mapping`.

**Services** (`app/Services/ScreenImport/`)
- `FieldCatalog.php` — canonical list of 39 importable fields across 4 groups (Screen / Spec / Inventory / Site). Single source of truth used by AI prompt + UI dropdown + validator.
- `SpreadsheetReader.php` — PhpSpreadsheet wrapper. Detects header row, returns sample (5 rows) + total count + iterator for full data. Skips leading empty banner rows.
- `ColumnMappingAiService.php` — Calls Claude Haiku via `Http::timeout(60)->post(...)`. Cache TTL 120 min keyed by hash(headers+samples+comment+current). Returns `{mapping, raw, tokens, cost_usd}`. Falls back gracefully on API down.
- `RowTransformer.php` — Applies mapping to raw row → nested data by model group. Handles: int/float/bool/enum/time coercion, VN number format (150.000 → 150000), VN enum synonyms (bật→online, ngang→landscape, đồng→VND), compound fields (1920x1080 split into width_px + height_px).
- `RowValidator.php` — Per-row validation: required fields, FK resolution (site by external_id), GPS range, dimension bounds, venue category lookup.
- `ScreenWriter.php` — Writes Screen + ScreenSpec + ScreenInventory + (optionally) creates Site/Network. Honors `upsert_mode` (skip or update).
- `ScreenImportService.php` — Orchestrator with 6 stages: `analyze()`, `proposeMapping()`, `refineMapping()`, `saveUserMapping()`, `dryRun()`, `execute()`.

**Filament UI** (`app/Filament/Resources/ScreenImportResource/`)
- `ScreenImportResource.php` — Listing table with status badge, row counts, filters.
- `Pages/ListScreenImports.php` — Header action "Upload Excel/CSV" with FileUpload + upsert_mode selector. Runs analyze + proposeMapping synchronously, redirects to view page.
- `Pages/ViewScreenImport.php` — State-aware page: header actions adapt to status (Edit mapping / Validate & Preview / Import / Back to mapping / Retry / View imported screens). Infolist renders appropriate sections per status.

**Blade partials** (`resources/views/filament/resources/screen-import/`)
- `mapping-table.blade.php` — Column mapping overview table with confidence color coding + sample values.
- `preview-result.blade.php` — Dry-run preview: stats cards (valid/errors/total) + 20 first rows with per-row error detail + collapsed full-error list.
- `import-result.blade.php` — Post-import summary: 4 stat cards (imported/failed/processed/duration) + collapsed error list.

---

## UX flow (what user sees)

1. Navigate to **Inventory → Screen imports** (admin panel)
2. Click **Upload Excel/CSV** header action
3. Select file + upsert mode → submit
4. System parses file, calls Claude to propose mapping → redirects to View page
5. View page shows mapping table with AI confidence %. User clicks **Edit mapping** (opens modal with Repeater, one row per file column, searchable grouped Select for DB field)
6. User clicks **Validate & Preview** → dry-run runs, stats + preview rows appear
7. User clicks **Import N screens** → sync write to DB → status=done → summary panel + "View imported screens" button

Status machine:
```
uploaded → mapping → previewed → importing → done
                 ↓         ↓
                 └── failed ─┘
```

---

## Architecture decisions

- **Sync import** (Phase 1 MVP) — suitable for files ≤500 rows. Long files will block the browser; queue comes in Phase 2.
- **File storage**: disk `local` (`storage/app/private/screen-imports/YYYY-MM/`). File retained after import for audit / re-run.
- **AI cache** prevents re-spending tokens when user navigates back/forward.
- **FieldCatalog as single source of truth** → AI prompt, UI dropdown, and validator all stay consistent by design.
- **Upsert modes** implemented at `ScreenWriter` level: `skip` returns action=skipped, `update` runs `updateOrCreate`.

---

## What's imported vs skipped per row

For each valid row, writer creates/updates:
- `screens` record (keyed by `owner_id` + `external_id`)
- `screen_specs` record (1-to-1, `updateOrCreate`)
- `screen_inventory` record (1-to-1, `updateOrCreate`)
- **Auto-creates on miss**:
  - Site if `site.external_id` not found + `site.name` + `site.city` provided (default Network "Default")
  - Network if `inventory.network_name` not found

Rows missing required fields or failing validation are skipped, not blocking the rest of the batch.

---

## Number format handling (tested)

| Input           | Output    | Reasoning                          |
| --------------- | --------- | ---------------------------------- |
| `150.000`       | 150000    | VN thousands (3-digit after dot)   |
| `1.234.567`     | 1234567   | VN thousands (multiple dots)       |
| `1.5`           | 1.5       | Decimal (non-3-digit after dot)    |
| `1,234,567`     | 1234567   | EN thousands (multiple commas)     |
| `1.234.567,89`  | 1234567.89| VN full format                     |
| `150,000`       | 150000    | Ambiguous comma, 3-digit → thousands |
| `15.5`          | 15.5      | Decimal                            |

---

## Risks & known limitations (deferred to Phase 2/3)

| Risk | Mitigation plan |
| ---- | --------------- |
| Files >500 rows → browser timeout on sync import | Phase 2: queue job + polling |
| User must manually correct AI mapping with dropdown | Phase 2: natural-language comment ("cột 3 là giá VND") → AI refines |
| Errors can't be fixed offline easily | Phase 3: export errors to Excel with original row + error column |
| Upsert mode is binary (skip/update) | Phase 3: add "update but keep existing photos/price" granular modes |
| Compound splits limited to `x`, `×`, `,`, space | Phase 3: AI-suggested custom transforms |

---

## Critical files summary

| File | Purpose |
| ---- | ------- |
| `app/Services/ScreenImport/FieldCatalog.php` | Canonical field list (39 fields, 4 groups) |
| `app/Services/ScreenImport/ScreenImportService.php` | Orchestrator (6 stages) |
| `app/Services/ScreenImport/ColumnMappingAiService.php` | Claude integration |
| `app/Services/ScreenImport/RowTransformer.php` | Type coercion + VN synonyms + compound splits |
| `app/Services/ScreenImport/ScreenWriter.php` | Multi-model DB writes (Screen+Spec+Inventory+Site+Network) |
| `app/Filament/Resources/ScreenImportResource/Pages/ViewScreenImport.php` | State-aware Filament page |

## Tests added/updated
None in Phase 1 — services are unit-testable (stateless, constructor-injected deps) but tests will be added in Phase 2 alongside the queue job.

## Risks remaining
- No automated tests yet.
- Sync import limits real-world usability to small files.
- AI fallback when `ANTHROPIC_API_KEY` missing = empty mapping (user maps everything manually).
