<?php

namespace App\Jobs;

use App\Models\ScreenImport;
use App\Services\ScreenImport\ScreenImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Chạy Screen import execution trong queue worker.
 *
 * Delegate tới ScreenImportService::execute() — job chỉ là queue wrapper.
 * Progress được service flush mỗi 50 rows; View page polling sẽ đọc từ DB.
 */
class ImportScreensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cho phép job chạy đến 30 phút (5000 rows × ~300ms/row tối đa).
     */
    public int $timeout = 1800;

    /**
     * Không retry — import semi-idempotent nhưng double-run có thể tạo duplicate Networks/Sites.
     */
    public int $tries = 1;

    public function __construct(public string $importId) {}

    public function handle(ScreenImportService $service): void
    {
        $import = ScreenImport::withoutGlobalScopes()->findOrFail($this->importId);
        if ($import->status !== ScreenImport::STATUS_PREVIEWED && $import->status !== ScreenImport::STATUS_IMPORTING) {
            return; // Status changed — skip stale job
        }
        $service->execute($import);
    }

    public function failed(\Throwable $e): void
    {
        $import = ScreenImport::withoutGlobalScopes()->find($this->importId);
        $import?->update([
            'status'        => ScreenImport::STATUS_FAILED,
            'error_summary' => 'Queue job failed: ' . $e->getMessage(),
            'finished_at'   => now(),
        ]);
    }
}
