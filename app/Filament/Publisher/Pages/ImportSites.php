<?php

namespace App\Filament\Publisher\Pages;

use App\Services\SiteImportService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Wizard 4 bước import Sites từ file Excel.
 *
 * Step 1 — Upload   : Chọn file & validate format
 * Step 2 — Preview  : Xem trước dữ liệu, so sánh với DB
 * Step 3 — Importing: Tự động trigger import khi render
 * Step 4 — Report   : Kết quả chi tiết
 */
class ImportSites extends Page implements HasForms, HasActions
{
    use InteractsWithForms, InteractsWithActions;

    protected static ?string $slug            = 'sites/import';
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Import Sites';
    protected static ?int    $navigationSort  = 2;
    protected static bool    $shouldRegisterNavigation = false; // Ẩn khỏi nav, truy cập qua button
    protected static string  $view            = 'filament.publisher.pages.import-sites';

    // ── Wizard state ─────────────────────────────────────────────────────────
    /** 1=upload | 2=preview | 3=importing | 4=report */
    public int $step = 1;

    // ── Step 1 — form ────────────────────────────────────────────────────────
    public ?array $data = [];

    // ── Step 2/3 — data ──────────────────────────────────────────────────────
    public ?string $storedPath = null;
    public ?array  $preview    = null;

    // ── Step 4 — results ─────────────────────────────────────────────────────
    public ?array $results = null;

    // ── Preview pagination & filter (Sites) ──────────────────────────────────
    public string $filterAction = 'all';
    public int    $sitePage     = 1;
    public int    $perPage      = 100;

    // ── Preview pagination & filter (Screens) ────────────────────────────────
    public string $screenFilterAction = 'all';
    public int    $screenPage         = 1;

    // ─────────────────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function resolveOwnerId(): string
    {
        return (string) (auth()->user()?->current_owner_id ?? '');
    }

    // ── Form (Step 1) ────────────────────────────────────────────────────────

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('file')
                    ->label('File Excel (.xlsx)')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/octet-stream',
                    ])
                    // File nguồn của import không được nằm trên disk công khai:
                    // workbook chứa toàn bộ dữ liệu kho của media owner (Codex F17).
                    ->disk('private')
                    ->directory('imports/sites')
                    ->maxSize(20480)
                    ->required()
                    ->helperText('Dùng OOHX Import Template — 2 sheets: Sites + Screens. Tải template tại /storage/templates/oohx-import-template.xlsx'),
            ])
            ->statePath('data');
    }

    // ── Step 1 → Step 2: Upload & Preview ────────────────────────────────────

    public function uploadAndPreview(SiteImportService $svc): void
    {
        set_time_limit(120); // Đọc file + build preview + Livewire serialize có thể > 30s

        $formData = $this->form->getState();
        $raw      = $formData['file'] ?? null;

        // Filament đôi khi trả về array ngay cả với single-file upload
        if (is_array($raw)) {
            $raw = array_values(array_filter($raw))[0] ?? null;
        }

        if (! $raw) {
            Notification::make()->title('Vui lòng chọn file')->warning()->send();
            return;
        }

        $storedPath = $this->resolveUploadedPath($raw);

        if (! $storedPath) {
            $debugInfo = is_string($raw)
                ? "path='{$raw}' | local_exists=" . (\Storage::disk('local')->exists(ltrim($raw, '/')) ? 'yes' : 'no')
                  . " | public_exists=" . (\Storage::disk('public')->exists(ltrim($raw, '/')) ? 'yes' : 'no')
                : 'type=' . gettype($raw);

            Notification::make()
                ->title('Không tìm thấy file upload')
                ->body("Vui lòng thử lại.\n({$debugInfo})")
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        $this->storedPath = $storedPath;
        $fullPath = \Storage::disk($this->diskFor($storedPath))->path($storedPath);

        try {
            $this->preview = $svc->readPreview($fullPath, $this->resolveOwnerId());
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Lỗi đọc file')
                ->body('Kiểm tra lại định dạng file. ' . $e->getMessage())
                ->danger()
                ->send();
            \Storage::disk($this->diskFor($storedPath))->delete($storedPath);
            $this->storedPath = null;
            return;
        }

        $this->filterAction = 'all';
        $this->sitePage     = 1;
        $this->step         = 2;
    }

    // ── Step 2 → Step 3: Xác nhận import (Filament Action modal) ────────────

    public function confirmImportAction(): Action
    {
        $s = $this->getSummary();
        $hasErr = ($s['sites_error'] ?? 0) > 0 || ($s['screens_error'] ?? 0) > 0;
        $errCount = ($s['sites_error'] ?? 0) + ($s['screens_error'] ?? 0);

        $description = "**Sites:** {$s['sites_new']} mới, {$s['sites_update']} cập nhật";
        if (! empty($this->preview['screens'])) {
            $description .= "  \n**Screens:** {$s['screens_new']} mới, {$s['screens_update']} cập nhật";
        }
        if ($hasErr) {
            $description .= "  \n⚠ **{$errCount} dòng lỗi** sẽ bị bỏ qua.";
        }
        $description .= '  \nDữ liệu sẽ được lưu vào database. Không thể hoàn tác.';

        return Action::make('confirmImport')
            ->label('Confirm & Import')
            ->icon('heroicon-o-check-circle')
            ->color($hasErr ? 'warning' : 'success')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Xác nhận Import')
            ->modalDescription($description)
            ->modalIcon($hasErr ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
            ->modalIconColor($hasErr ? 'warning' : 'success')
            ->modalSubmitActionLabel('Xác nhận Import')
            ->action(fn () => $this->doConfirmImport());
    }

    public function doConfirmImport(): void
    {
        $this->step = 3;
    }

    // ── Step 3: Thực thi import (gọi từ Alpine.js) ───────────────────────────

    public function runImport(SiteImportService $svc): void
    {
        set_time_limit(120); // Import nhiều rows có thể mất nhiều thời gian

        if (! $this->storedPath) {
            $this->step = 1;
            return;
        }

        $fullPath = \Storage::disk($this->diskFor($this->storedPath))->path($this->storedPath);

        if (! file_exists($fullPath)) {
            Notification::make()->title('File không còn tồn tại')->body('Vui lòng upload lại.')->danger()->send();
            $this->step = 1;
            return;
        }

        try {
            $this->results = $svc->import($fullPath, $this->resolveOwnerId());
        } catch (\Throwable $e) {
            Notification::make()->title('Lỗi import')->body($e->getMessage())->danger()->send();
            $this->step = 2;
            return;
        }

        // Xoá file tạm sau khi import xong — phải xoá ĐÚNG disk đã lưu.
        \Storage::disk($this->diskFor($this->storedPath))->delete($this->storedPath);
        $this->storedPath = null;

        $this->step = 4;
    }

    // ── Navigation helpers ────────────────────────────────────────────────────

    public function backToUpload(): void
    {
        if ($this->storedPath) {
            \Storage::disk($this->diskFor($this->storedPath))->delete($this->storedPath);
        }

        $this->step               = 1;
        $this->preview            = null;
        $this->results            = null;
        $this->storedPath         = null;
        $this->filterAction       = 'all';
        $this->sitePage           = 1;
        $this->screenFilterAction = 'all';
        $this->screenPage         = 1;
        $this->form->fill();
    }

    public function importAnother(): void
    {
        $this->backToUpload();
    }

    public function goToSitesList(): void
    {
        $this->redirect(
            \App\Filament\Publisher\Resources\SiteResource::getUrl('index'),
            navigate: true
        );
    }

    // ── Preview filter & pagination ──────────────────────────────────────────

    public function setFilter(string $action): void
    {
        $this->filterAction = $action;
        $this->sitePage     = 1;
    }

    public function setScreenFilter(string $action): void
    {
        $this->screenFilterAction = $action;
        $this->screenPage         = 1;
    }

    public function getSummary(): array
    {
        $s = $this->preview['summary'] ?? [];

        // New format from SiteImportService (sites_new / screens_new / …)
        if (isset($s['sites_new'])) {
            $s['sites_total']   = ($s['sites_new']   ?? 0) + ($s['sites_update']   ?? 0) + ($s['sites_error']   ?? 0);
            $s['screens_total'] = ($s['screens_new'] ?? 0) + ($s['screens_update'] ?? 0) + ($s['screens_error'] ?? 0);
            return $s;
        }

        // Legacy / fallback
        return [
            'sites_new'      => $s['new']    ?? 0,
            'sites_update'   => $s['update'] ?? 0,
            'sites_error'    => $s['error']  ?? 0,
            'sites_skip'     => $s['skip']   ?? 0,
            'sites_total'    => ($s['new'] ?? 0) + ($s['update'] ?? 0) + ($s['error'] ?? 0),
            'screens_new'    => 0,
            'screens_update' => 0,
            'screens_error'  => 0,
            'screens_skip'   => 0,
            'screens_total'  => 0,
        ];
    }

    // ── Sites ─────────────────────────────────────────────────────────────────

    public function getFilteredSites(): array
    {
        return array_slice($this->applyFilter(), ($this->sitePage - 1) * $this->perPage, $this->perPage);
    }

    public function getSiteTotal(): int
    {
        return count($this->applyFilter());
    }

    public function hasNextPage(): bool
    {
        return ($this->sitePage * $this->perPage) < $this->getSiteTotal();
    }

    private function applyFilter(): array
    {
        $sites = $this->preview['sites'] ?? [];
        if ($this->filterAction === 'all') {
            return $sites;
        }
        return array_values(array_filter($sites, fn($s) => $s['action'] === $this->filterAction));
    }

    // ── Screens ───────────────────────────────────────────────────────────────

    public function getFilteredScreens(): array
    {
        return array_slice($this->applyScreenFilter(), ($this->screenPage - 1) * $this->perPage, $this->perPage);
    }

    public function getScreenTotal(): int
    {
        return count($this->applyScreenFilter());
    }

    public function hasScreenNextPage(): bool
    {
        return ($this->screenPage * $this->perPage) < $this->getScreenTotal();
    }

    private function applyScreenFilter(): array
    {
        $screens = $this->preview['screens'] ?? [];
        if ($this->screenFilterAction === 'all') {
            return $screens;
        }
        return array_values(array_filter($screens, fn($s) => $s['action'] === $this->screenFilterAction));
    }

    // ─────────────────────────────────────────────────────────────────────────

    protected function getFormActions(): array
    {
        return [];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Disk đang chứa file import.
     *
     * Trước đây lưu ở 'public' nhưng dọn ở 'local', nên workbook gốc — chứa toàn
     * bộ dữ liệu kho của media owner — nằm lại trên web mãi (Codex F17). Nay lưu
     * ở 'private'; hàm này vẫn nhận file cũ còn sót trên 'public'.
     */
    private function diskFor(?string $path): string
    {
        if ($path
            && \Storage::disk('public')->exists($path)
            && ! \Storage::disk('private')->exists($path)) {
            return 'public';
        }

        return 'private';
    }

    private function resolveUploadedPath(mixed $file): ?string
    {
        if ($file instanceof TemporaryUploadedFile) {
            $path = $file->storeAs('imports/sites', Str::ulid() . '.xlsx', 'private');
            return $path ?: null;
        }

        if (! is_string($file) || $file === '') {
            return null;
        }

        $cleanFile = ltrim($file, '/\\');

        // Disk mới là 'private'. Vẫn chấp nhận file cũ còn nằm trên 'public' để
        // import đang dở dang lúc deploy không bị hỏng.
        foreach (['private', 'public'] as $disk) {
            if (\Storage::disk($disk)->exists($cleanFile)) {
                return $cleanFile;
            }
        }

        return null;
    }
}
