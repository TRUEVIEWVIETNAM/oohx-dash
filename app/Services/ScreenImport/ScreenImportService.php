<?php

namespace App\Services\ScreenImport;

use App\Jobs\ImportScreensJob;
use App\Models\ScreenImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrator cho toàn bộ Screen Import flow.
 *
 * Stages:
 *   1. analyze()        — parse file → headers + sample → status=uploaded
 *   2. proposeMapping() — gọi AI → status=mapping
 *   3. refineMapping()  — (Phase 2) user comment → AI propose lại
 *   4. saveUserMapping()— persist user edits → status vẫn mapping
 *   5. dryRun()         — transform + validate tất cả rows → status=previewed
 *   6. execute()        — insert DB → status=importing → done
 *
 * Chạy sync cho MVP (Phase 1). Phase 2 sẽ dispatch queue job.
 */
class ScreenImportService
{
    public function __construct(
        private readonly ColumnMappingAiService $ai,
        private readonly RowTransformer         $transformer,
    ) {}

    /**
     * Stage 1: Parse file → save headers + sample + total row count.
     */
    public function analyze(ScreenImport $import): void
    {
        $filePath = Storage::disk('local')->path($import->file_path);
        $reader   = new SpreadsheetReader($filePath);
        $analysis = $reader->analyze();

        if (empty($analysis['headers'])) {
            $import->update([
                'status'        => ScreenImport::STATUS_FAILED,
                'error_summary' => 'File không có header row hoặc không đọc được.',
            ]);
            return;
        }
        if ($analysis['total_rows'] === 0) {
            $import->update([
                'status'        => ScreenImport::STATUS_FAILED,
                'error_summary' => 'File không có data rows.',
            ]);
            return;
        }
        if ($analysis['total_rows'] > SpreadsheetReader::MAX_ROWS) {
            $import->update([
                'status'        => ScreenImport::STATUS_FAILED,
                'error_summary' => "File vượt limit " . SpreadsheetReader::MAX_ROWS . " rows (có {$analysis['total_rows']} rows).",
            ]);
            return;
        }

        $import->update([
            'status'      => ScreenImport::STATUS_UPLOADED,
            'headers'     => $analysis['headers'],
            'sample_rows' => $analysis['sample_rows'],
            'total_rows'  => $analysis['total_rows'],
        ]);
    }

    /**
     * Stage 2: Call AI to propose column → field mapping.
     */
    public function proposeMapping(ScreenImport $import): void
    {
        if (empty($import->headers)) {
            throw new \RuntimeException('Cần analyze() trước khi propose mapping.');
        }

        try {
            $result = $this->ai->propose($import->headers, $import->sample_rows ?? []);
            $import->update([
                'status'     => ScreenImport::STATUS_MAPPING,
                'ai_mapping' => $result['mapping'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI mapping failed — falling back to empty mapping', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
            ]);
            // Graceful fallback: empty mapping, user maps manually
            $import->update([
                'status'        => ScreenImport::STATUS_MAPPING,
                'ai_mapping'    => [],
                'error_summary' => 'AI service không available — vui lòng map thủ công. (' . $e->getMessage() . ')',
            ]);
        }
    }

    /**
     * Stage 3 (Phase 2): Refine mapping based on user natural-language comment.
     */
    public function refineMapping(ScreenImport $import, string $userComment): void
    {
        $current = $import->user_mapping ?? $import->ai_mapping ?? [];

        $result = $this->ai->propose(
            $import->headers,
            $import->sample_rows ?? [],
            userComment: $userComment,
            currentMapping: $current,
        );

        $history = $import->ai_comment_history ?? [];
        $history[] = [
            'at'      => now()->toIso8601String(),
            'comment' => $userComment,
            'tokens'  => $result['tokens'] ?? null,
        ];

        $import->update([
            'ai_mapping'         => $result['mapping'],
            'user_mapping'       => null,  // reset user edits — user reviews AI's new proposal
            'ai_comment_history' => $history,
        ]);
    }

    /**
     * Stage 4: Persist user-edited mapping.
     */
    public function saveUserMapping(ScreenImport $import, array $mapping): void
    {
        // Normalize keys to int + validate fields
        $cleaned = [];
        foreach ($mapping as $idx => $m) {
            $idx = (int) $idx;
            $field = $m['field'] ?? null;
            if ($field && ! FieldCatalog::isValid($field)) $field = null;

            $compound = $m['compound'] ?? null;
            if (is_array($compound)) {
                $compound = array_values(array_filter($compound, fn ($k) => FieldCatalog::isValid($k)));
                if (count($compound) < 2) $compound = null;
            }

            $cleaned[$idx] = [
                'field'      => $field,
                'compound'   => $compound,
                'confidence' => (float) ($m['confidence'] ?? 1.0), // user edit = high confidence
                'reason'     => $m['reason']    ?? 'User edit',
                'transform'  => $m['transform'] ?? null,
                'header'     => $m['header']    ?? ($import->headers[$idx] ?? ''),
            ];
        }

        $import->update(['user_mapping' => $cleaned]);
    }

    /**
     * Stage 5: Run dry-run validation over ALL rows (not just sample).
     * Populates preview_data (first 20 transformed rows) + validation_errors (all).
     */
    public function dryRun(ScreenImport $import): void
    {
        if (empty($import->effective_mapping)) {
            throw new \RuntimeException('Chưa có mapping — map columns trước khi preview.');
        }

        $filePath = Storage::disk('local')->path($import->file_path);
        $reader   = new SpreadsheetReader($filePath);
        $validator = new RowValidator($import->owner_id);

        $mapping = $import->effective_mapping;
        $headerCount = count($import->headers ?? []);

        $errors = [];
        $preview = [];
        $validCount = 0;
        $rowNum = 0;

        foreach ($reader->iterate($headerCount) as [$spreadsheetRow, $cells]) {
            $rowNum++;
            $result = $this->transformer->transform($mapping, $cells);
            $rowErrors = array_merge($result['warnings'], $validator->validate($result['data']));

            if (count($preview) < 20) {
                $preview[] = [
                    'spreadsheet_row' => $spreadsheetRow,
                    'row_num'         => $rowNum,
                    'data'            => $result['data'],
                    'errors'          => $rowErrors,
                    'is_valid'        => empty($rowErrors),
                ];
            }

            if ($rowErrors) {
                $errors[$spreadsheetRow] = $rowErrors;
            } else {
                $validCount++;
            }
        }

        $import->update([
            'status'            => ScreenImport::STATUS_PREVIEWED,
            'preview_data'      => $preview,
            'validation_errors' => $errors,
            'success_count'     => 0,
            'failed_count'      => 0,
            'error_summary'     => $errors
                ? count($errors) . " / {$rowNum} rows có lỗi. " . ($validCount > 0 ? 'Import sẽ bỏ qua rows lỗi.' : 'Không có row valid.')
                : "Tất cả {$rowNum} rows sẵn sàng import.",
        ]);
    }

    /**
     * Stage 6a (Phase 2): Dispatch queue job thay vì chạy sync.
     * Set status → importing NGAY để UI phản hồi, job sẽ update progress.
     */
    public function queueExecution(ScreenImport $import): void
    {
        if ($import->status !== ScreenImport::STATUS_PREVIEWED) {
            throw new \RuntimeException("Chỉ queue được sau khi preview. Status hiện tại: {$import->status}");
        }
        $import->update([
            'status'          => ScreenImport::STATUS_IMPORTING,
            'started_at'      => now(),
            'processed_count' => 0,
            'success_count'   => 0,
            'failed_count'    => 0,
            'error_summary'   => 'Queued for background processing…',
        ]);
        ImportScreensJob::dispatch($import->id);
    }

    /**
     * Stage 6b: Execute import — write valid rows to DB. Gọi sync hoặc qua queue job.
     */
    public function execute(ScreenImport $import): void
    {
        if (! in_array($import->status, [ScreenImport::STATUS_PREVIEWED, ScreenImport::STATUS_IMPORTING], true)) {
            throw new \RuntimeException("Chỉ execute được sau khi preview. Status hiện tại: {$import->status}");
        }

        // Idempotent re-entry: if already in 'importing' via queue, skip the reset that queueExecution did.
        if ($import->status === ScreenImport::STATUS_PREVIEWED) {
            $import->update([
                'status'          => ScreenImport::STATUS_IMPORTING,
                'started_at'      => now(),
                'processed_count' => 0,
                'success_count'   => 0,
                'failed_count'    => 0,
            ]);
        }

        $filePath = Storage::disk('local')->path($import->file_path);
        $reader   = new SpreadsheetReader($filePath);
        $validator = new RowValidator($import->owner_id);
        $writer    = new ScreenWriter($import->owner_id, $import->upsert_mode ?? 'skip');

        $mapping     = $import->effective_mapping;
        $headerCount = count($import->headers ?? []);

        $success = 0;
        $failed  = 0;
        $processed = 0;
        $errors = $import->validation_errors ?? [];

        try {
            foreach ($reader->iterate($headerCount) as [$spreadsheetRow, $cells]) {
                $processed++;
                $result    = $this->transformer->transform($mapping, $cells);
                $rowErrors = array_merge($result['warnings'], $validator->validate($result['data']));

                if ($rowErrors) {
                    $failed++;
                    $errors[$spreadsheetRow] = $rowErrors;
                    continue;
                }

                try {
                    DB::transaction(fn () => $writer->write($result['data'], $validator));
                    $success++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[$spreadsheetRow] = ['DB error: ' . $e->getMessage()];
                    Log::error('Screen import row failed', [
                        'import_id'       => $import->id,
                        'spreadsheet_row' => $spreadsheetRow,
                        'error'           => $e->getMessage(),
                    ]);
                }

                // Flush progress every 50 rows + check cooperative cancel
                if ($processed % 50 === 0) {
                    $import->update([
                        'processed_count' => $processed,
                        'success_count'   => $success,
                        'failed_count'    => $failed,
                    ]);

                    // Phase 3: Cooperative cancel — worker reads status from DB.
                    //
                    // fresh(['status']) là SAI: tham số của fresh() là danh sách QUAN HỆ
                    // để nạp kèm, không phải tên cột — mà ScreenImport không có quan hệ
                    // nào tên 'status'. Import lớn đi tới mốc này sẽ hỏng sau khi đã ghi
                    // một phần (Codex F13). Đọc thẳng cột từ DB.
                    $currentStatus = $import->newQuery()->whereKey($import->getKey())->value('status');
                    if ($currentStatus === ScreenImport::STATUS_CANCELLED) {
                        $import->update([
                            'processed_count' => $processed,
                            'success_count'   => $success,
                            'failed_count'    => $failed,
                            'finished_at'     => now(),
                            'error_summary'   => "Cancelled by user at row {$processed}. {$success} imported, {$failed} failed.",
                        ]);
                        return;
                    }
                }
            }

            $import->update([
                'status'            => ScreenImport::STATUS_DONE,
                'processed_count'   => $processed,
                'success_count'     => $success,
                'failed_count'      => $failed,
                'validation_errors' => $errors,
                'finished_at'       => now(),
                'error_summary'     => "Done: {$success} imported / {$failed} failed / {$processed} total",
            ]);
        } catch (\Throwable $e) {
            $import->update([
                'status'        => ScreenImport::STATUS_FAILED,
                'error_summary' => 'Fatal error: ' . $e->getMessage(),
                'finished_at'   => now(),
            ]);
            throw $e;
        }
    }
}
