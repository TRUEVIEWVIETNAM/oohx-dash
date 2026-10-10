<?php

namespace App\Filament\Pages;

use App\Services\Oohx\HealthDigestService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Data Engine Health — Phase 3.A Part 2 (handoff §4.2 Option B).
 *
 * Đọc JSON digest được DE cron viết hàng ngày 08:00 UTC, scp về bởi
 * `oohx:fetch-health` command (scheduled /30 min).
 *
 * UI chỉ render. Không query DB — chi phí dashboard load ≈ disk IO + json_decode.
 * Poll 5 phút — đủ bắt file update sau scp cron.
 */
class OohxHealth extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-heart';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Theo dõi sức khoẻ';
    protected static ?int    $navigationSort  = 56;
    protected static ?string $title           = 'Data Engine — theo dõi sức khoẻ';
    protected static ?string $slug            = 'oohx-health';

    protected static string $view = 'filament.pages.oohx-health';

    // Page auto-refresh mỗi 5 phút (file update 30 min, 5 min là safe)
    protected ?string $pollingInterval = '5m';

    /** @var array{digest: array, path: string, age_minutes: int}|null */
    public ?array $digestResult = null;

    public ?array $overallBadge = null;

    public array $checks = [];

    /** Last run status of `oohx:fetch-health` command (from cache). */
    public ?array $lastFetch = null;

    public function mount(): void
    {
        $this->loadDigest();
    }

    public function loadDigest(): void
    {
        $service = app(HealthDigestService::class);
        $this->digestResult = $service->latest();
        $this->overallBadge = $service->overallBadge($this->digestResult);
        $this->lastFetch    = Cache::get('oohx:health:last_fetch');

        $digest = $this->digestResult['digest'] ?? [];
        $rawChecks = $digest['checks'] ?? [];

        $this->checks = [];
        foreach ($rawChecks as $key => $check) {
            $this->checks[$key] = [
                'key'       => $key,
                'label'     => $service->checkLabel($key),
                'icon'      => $service->checkIcon($key),
                'status'    => $check['status'] ?? 'unknown',
                'color'     => $service->checkColor($check['status'] ?? null),
                'value'     => $service->formatCheckValue($key, $check),
                'raw'       => $check,
            ];
        }
    }

    // ── Header actions ─────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Lấy bản tóm lược mới nhất')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function () {
                    try {
                        Artisan::call('oohx:fetch-health');
                        app(HealthDigestService::class)->forget();
                        $this->loadDigest();

                        Notification::make()
                            ->title('Đã làm mới bản tóm lược')
                            ->body($this->digestResult
                                ? "Age: {$this->digestResult['age_minutes']} min"
                                : 'No digest file available yet.')
                            ->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Làm mới không được')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                }),

            Action::make('viewRaw')
                ->label('Xem JSON thô')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->modalWidth('4xl')
                ->modalContent(fn () => view('filament.pages.oohx-health-raw', [
                    'digest' => $this->digestResult['digest'] ?? null,
                    'path'   => $this->digestResult['path'] ?? null,
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng')
                ->visible(fn () => $this->digestResult !== null),
        ];
    }

    // ── Helpers exposed to Blade ───────────────────────────────────────

    /**
     * Badge cho staleness của digest:
     *   fresh (<30m) → green
     *   aging (<2h)  → gray
     *   stale (<12h) → warning
     *   dead (>12h)  → danger
     */
    public function getStalenessBadgeProperty(): array
    {
        $age = $this->digestResult['age_minutes'] ?? null;
        if ($age === null) return ['color' => 'gray', 'label' => '—'];
        if ($age < 30)     return ['color' => 'success', 'label' => "{$age} min ago"];
        if ($age < 120)    return ['color' => 'gray', 'label' => "{$age} min ago"];
        if ($age < 720)    return ['color' => 'warning', 'label' => round($age / 60, 1) . 'h ago'];
        return ['color' => 'danger', 'label' => round($age / 60, 1) . 'h ago — STALE'];
    }

    public function getSummaryCountsProperty(): array
    {
        $summary = $this->digestResult['digest']['summary'] ?? ['ok' => 0, 'warn' => 0, 'critical' => 0];
        return [
            'ok'       => $summary['ok']       ?? 0,
            'warn'     => $summary['warn']     ?? 0,
            'critical' => $summary['critical'] ?? 0,
        ];
    }

    public static function canAccess(): bool
    {
        // Phase A recommendation: super_admin only; publisher role không thấy.
        $u = auth()->user();
        return $u && (method_exists($u, 'hasRole') ? $u->hasRole('super_admin') : true);
    }

    // Navigation badge — overall status color indicator
    public static function getNavigationBadge(): ?string
    {
        try {
            $result = app(HealthDigestService::class)->latest();
            $badge = app(HealthDigestService::class)->overallBadge($result);
            return $badge['status'] === 'ok' ? null : strtoupper($badge['status']);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        try {
            $result = app(HealthDigestService::class)->latest();
            $badge = app(HealthDigestService::class)->overallBadge($result);
            return $badge['color'] === 'gray' ? null : $badge['color'];
        } catch (\Throwable) {
            return null;
        }
    }
}
