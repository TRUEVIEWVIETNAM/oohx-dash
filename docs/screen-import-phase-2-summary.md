# Screen Import Tool — Phase 2 Summary (Queue + AI Refine + Polling)

**Build date**: 2026-04-21
**Scope**: Queue-based execution, natural-language AI mapping refinement, live progress polling
**Status**: Functional; requires a running queue worker (`php artisan queue:work`)

---

## Files delivered

### New (2 files)
- `app/Jobs/ImportScreensJob.php` — Queue wrapper around `ScreenImportService::execute()`. Timeout 1800s (30 min), `tries=1`, marks import as failed on exception.
- `resources/views/filament/resources/screen-import/progress.blade.php` — Live progress widget: bar + 4 stat cards (success/failed/elapsed/ETA) + rate.

### Modified (2 files)
- `app/Services/ScreenImport/ScreenImportService.php` — Added `queueExecution()` method (dispatches job, sets status to `importing` immediately). Updated `execute()` to be re-entrant (doesn't double-reset counters when called by queue worker).
- `app/Filament/Resources/ScreenImportResource/Pages/ViewScreenImport.php`:
  - Added `$pollingInterval = '3s'` with active/terminal-aware `getPollingInterval()` override — stops polling when import finishes (saves bandwidth).
  - Replaced `runImport` sync execution with `queueExecution()` + updated modal notification to "Job queued".
  - Added **Ask AI to refine** header action with Textarea modal form → calls `ScreenImportService::refineMapping()`.
  - Added infolist section "Import progress" (visible during `importing` status) using the new blade partial.
  - Added infolist section "AI refinement history" (RepeatableEntry over `ai_comment_history`).

---

## Flow changes vs Phase 1

**Phase 1 (sync)**:
```
User clicks Import → browser hangs → DB writes → response → status=done
```

**Phase 2 (queue + poll)**:
```
User clicks Import → ImportScreensJob dispatched → status=importing → browser returns immediately
  ↓ (every 3s poll)
Worker picks up job → processes 50 rows → flushes progress to DB
  ↓ (every 3s poll)
UI refreshes progress bar + stats
  ↓
Worker done → status=done → polling stops → result panel renders
```

---

## AI refinement loop (conversation-style)

`ColumnMappingAiService::propose()` already accepted `userComment` + `currentMapping` params in Phase 1. Phase 2 just wires the UI:

1. User sees AI's initial mapping with confidences + reasons
2. User clicks **Ask AI to refine** → textarea modal appears
3. User types: *"cột 'Giá' là VND không phải USD. Cột 'Địa điểm' là city chứ không phải tên site."*
4. Service sends to Claude with:
   - Same headers + samples
   - Current mapping (AI's previous proposal + any user edits)
   - New user comment
5. Claude returns refined mapping
6. `ai_comment_history` appended: `[{at, comment, tokens}]`
7. `user_mapping` reset to null — user reviews AI's new proposal
8. UI re-renders mapping table with new confidences/reasons

Cache key includes user comment + current mapping hash → no re-charge on duplicate refine.

---

## Polling mechanics

Uses Filament v3's `protected ?string $pollingInterval` property + `getPollingInterval()` override:

```php
protected ?string $pollingInterval = '3s';

public function getPollingInterval(): ?string
{
    return $this->record->is_active ? '3s' : null;  // is_active === status==='importing'
}
```

`$record->is_active` accessor lives on `ScreenImport` model. When status transitions to `done`/`failed`/`cancelled`, polling returns null → Livewire stops the interval.

Service flushes progress every 50 rows:
```php
if ($processed % 50 === 0) {
    $import->update(['processed_count' => ..., 'success_count' => ..., 'failed_count' => ...]);
}
```
So UI sees smooth updates for 5k-row file (100 flushes).

---

## Operations — how to run the worker

**Development**:
```bash
php artisan queue:work --tries=1 --timeout=1800
```

**Production (supervisord / systemd)**:
- Queue driver: `database` (already configured — `jobs` table from earlier migration)
- Single-worker sufficient for import workload (no parallelism needed; one file at a time)
- Supervisor recipe:
  ```
  [program:oohx-import-worker]
  command=php /var/www/html/artisan queue:work --queue=default --timeout=1800 --tries=1 --max-time=3600
  numprocs=1
  autostart=true
  autorestart=true
  ```

If no worker running, jobs sit in `jobs` table indefinitely; UI will show `status=importing` forever → user can delete the import record and re-upload.

---

## Tests added/updated
None yet — will add in Phase 3 alongside the error-report export.

---

## Risks & known limitations

| Risk | Status / mitigation |
| ---- | ------------------- |
| Worker not running → imports stuck | Operator responsibility; status=importing indefinitely is a clear signal |
| Queue worker dies mid-import | `tries=1` → job goes to `failed_jobs` table. `ImportScreensJob::failed()` marks the import as `failed` with the exception message. On next upload user can re-create. |
| User navigates away during importing | Polling stops (no open tab), but worker continues. User returns to see completed status. |
| No cooperative cancel yet | Phase 3 polish: add "Cancel" action that sets `status=cancelled`; worker checks inside the row loop every 50 rows and exits early. |
| No retry for partial imports | Phase 3 polish: when `status=failed` mid-batch, add "Resume from row N" action. |

---

## Files changed / added

| Action | File |
| ------ | ---- |
| NEW    | `app/Jobs/ImportScreensJob.php` |
| NEW    | `resources/views/filament/resources/screen-import/progress.blade.php` |
| MODIFY | `app/Services/ScreenImport/ScreenImportService.php` (added `queueExecution()`, re-entrant `execute()`) |
| MODIFY | `app/Filament/Resources/ScreenImportResource/Pages/ViewScreenImport.php` (polling, refine action, progress/history sections) |

## Risks remaining
- No automated tests yet.
- Cooperative cancel not implemented (tracked for Phase 3).
- Queue worker must be running (infra decision outside this task's scope).
