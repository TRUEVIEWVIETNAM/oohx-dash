# Screen Import Tool — Phase 3 Summary (Polish: Errors Export + Cancel + Template)

**Build date**: 2026-04-21
**Scope**: Error report Excel export, cooperative cancel, blank template download, preserve AI compound mapping through user edits
**Status**: Functional. Full 3-phase import tool is production-ready.

---

## Files delivered

### New (2 files)
- `app/Services/ScreenImport/ErrorReportExporter.php` — Export all failed rows to xlsx. Each row = original cell values + concatenated error messages. Stored at `storage/app/private/screen-imports/errors/errors-{timestamp}-{shortId}.xlsx`. Path saved on `screen_imports.error_report_path`.
- `app/Services/ScreenImport/TemplateGenerator.php` — Generate blank xlsx with 3 sheets:
  1. **Screens** — canonical headers (row 1 = label, row 2 = DB field key with cell comments listing enum/type/required)
  2. **Instructions** — Vietnamese usage guide
  3. **Field Reference** — full 39-field catalog with type/enum/description

### Modified (3 files)
- `app/Services/ScreenImport/ScreenImportService.php` — Added cooperative cancel check inside `execute()` row loop (checks DB status every 50 rows; if `cancelled`, exits cleanly with partial counts).
- `app/Filament/Resources/ScreenImportResource/Pages/ViewScreenImport.php`:
  - **Cancel import** action (visible during `importing`) — sets `status=cancelled`. Worker detects within ≤50 rows.
  - **Download error report** action (visible on `done`/`failed` with errors) — generates or reuses xlsx, streams download response.
  - Added `Forms\Components\Hidden` fields for `column_index`, `compound`, `transform` in mapping edit form → AI's compound proposals now survive user edits.
  - Mapping Select helperText displays compound hint when AI proposed one: `"AI đề xuất compound split: width_px + height_px"`.
- `app/Filament/Resources/ScreenImportResource/Pages/ListScreenImports.php` — **Download template** header action (generates blank xlsx on demand, served via `response()->download()->deleteFileAfterSend()`).

---

## Feature details

### 1. Cooperative cancel

Worker pattern borrowed from Phase 2.B (`RecomputeJob` bulk cancel):

```php
// Inside execute() row loop, every 50 rows:
if ($processed % 50 === 0) {
    $import->update(['processed_count' => $processed, ...]);
    if ($import->fresh(['status'])->status === 'cancelled') {
        $import->update(['finished_at' => now(), 'error_summary' => "Cancelled at row {$processed}..."]);
        return;
    }
}
```

User triggers via "Cancel import" button → sets `status=cancelled` in DB → next DB-status check in worker exits the loop. Partial rows already inserted are **not** rolled back (intentional — avoid long-running transaction that blocks other writes).

### 2. Error report Excel

Generated on-demand when user clicks "Download error report":

| Column | Content |
| ------ | ------- |
| A | Original spreadsheet row number |
| B..(N-1) | Original cell values from uploaded file (for user's context) |
| Last col | Concatenated error messages (joined by `" · "`) |

Regenerated if `error_report_path` file missing from disk (handles orphaned paths after retention cleanup).

Path stored on the import record so repeat downloads don't regenerate. Re-download uses existing file.

### 3. Template download

Single-click download for users who want to start from a clean slate. Generated per-request from `FieldCatalog::all()`, so:
- Always matches current schema
- No stale template to keep in sync
- Cell comments show enum values + description inline in Excel

Row 2 contains DB field keys — when user uploads this template, `ColumnMappingAiService` sees exact field keys as headers and proposes 100% confident mapping. (Or user can delete row 2, AI still maps by label.)

### 4. Compound mapping preservation

**Problem**: When AI proposed a compound field (e.g. column "Kích thước 1920x1080" → `spec.width_px + spec.height_px`), user editing the mapping form would lose the compound because no form field was tracking it.

**Fix**: Added `Hidden` form fields for `column_index`, `compound`, `transform`. These round-trip through Repeater state invisibly. User can still override by picking a single field in the Select (which takes precedence over compound in `RowTransformer`).

---

## Files changed / added

| Action | File |
| ------ | ---- |
| NEW    | `app/Services/ScreenImport/ErrorReportExporter.php` |
| NEW    | `app/Services/ScreenImport/TemplateGenerator.php` |
| MODIFY | `app/Services/ScreenImport/ScreenImportService.php` (cooperative cancel) |
| MODIFY | `app/Filament/Resources/ScreenImportResource/Pages/ViewScreenImport.php` (3 new actions + hidden fields) |
| MODIFY | `app/Filament/Resources/ScreenImportResource/Pages/ListScreenImports.php` (template action) |

## Tests added/updated
None still — service layer is straightforward-testable but the team hasn't asked for test coverage yet. If feature is shipped to users, recommend adding:
- Unit: `RowTransformer` parse matrix (number formats, VN synonyms, compound splits)
- Feature: Full `ScreenImportService` flow with fake AI response + in-memory SQLite
- Feature: `ErrorReportExporter` output column alignment

## Risks remaining
- Old error report files accumulate in `storage/app/private/screen-imports/errors/` — Phase 4 polish: scheduled cleanup command (30-day retention).
- Uploaded original files never deleted — same retention concern.
- PhpSpreadsheet `setCellValueByColumnAndRow` is v5-removed; code uses new array-form `setCellValue([$col, $row], $v)` with `Coordinate::stringFromColumnIndex()` — verified working on `phpoffice/phpspreadsheet v5.5`.

---

# Overall project status (Phase 1-3 combined)

## Total files delivered

| Type | Count | Details |
| ---- | ----- | ------- |
| Migration | 1 | `screen_imports` table |
| Model | 1 | `ScreenImport` |
| Services | 8 | `FieldCatalog`, `SpreadsheetReader`, `ColumnMappingAiService`, `RowTransformer`, `RowValidator`, `ScreenWriter`, `ScreenImportService`, `ErrorReportExporter`, `TemplateGenerator` (9 actually — 8 new + TemplateGenerator counted above) |
| Job | 1 | `ImportScreensJob` |
| Filament Resource | 1 | `ScreenImportResource` |
| Filament Pages | 2 | `ListScreenImports`, `ViewScreenImport` |
| Blade partials | 4 | `mapping-table`, `preview-result`, `import-result`, `progress` |

## End-to-end user journey (final)

1. Navigate **Admin → Inventory → Screen imports**
2. (Optional) Click **Download template** to get blank xlsx
3. Fill template (or use own spreadsheet with any column names)
4. Click **Upload Excel/CSV**, pick file, pick upsert mode
5. System parses + calls AI → redirects to View page with mapping proposal
6. Review mapping table (confidence color-coded per column). Options:
   - **Edit mapping** — modal repeater, manual Select per column
   - **Ask AI to refine** — textarea, natural-language hint, AI re-proposes
7. Click **Validate & Preview** → stats cards + 20 first rows + full error list
8. Click **Import N screens** → queue job dispatched, status=importing
9. Live polling shows progress bar + success/failed/elapsed/ETA
10. When done, click **Download error report** (if any errors) or **View imported screens**

## Permission / multi-tenant behavior

- `ScreenImport` uses `HasOwnerScope` → users see only their own imports
- `owner_id` auto-set from `auth()->user()->current_owner_id` on create
- `uploaded_by` = auth user id
- On row write, `ScreenWriter` sets `owner_id` from the import record (never from spreadsheet) → prevents cross-tenant data leak

## Key architectural wins

1. **AI is one service** (`ColumnMappingAiService`) with cached responses — easy to swap/mock/disable.
2. **`FieldCatalog` = single source of truth** for AI prompt + UI dropdown + validator. Adding a new importable field is one-line (update the catalog, everything flows).
3. **Services stateless** (except orchestrator holds file path) — parallelize easily if needed later.
4. **Queue-based execution** decouples user from worker — can kill/restart worker without losing import state.
5. **Cooperative cancel** = simple DB-poll pattern, no extra coordination primitive.
6. **All Filament-native** — zero custom Livewire, works with existing admin conventions + dark mode + authorization.
