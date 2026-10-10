<?php

namespace App\Filament\Pages;

use App\Services\Oohx\CollectorManager;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Overview Page cho OOHX Data Engine collectors (Phase 2.C).
 *
 * Config-driven — loop config('oohx_collectors') render 1 card per collector,
 * hiển thị metadata + latest run per city + trigger buttons. Thêm collector mới
 * chỉ cần thêm entry vào config/oohx_collectors.php (Phase 2.D forward-compat).
 *
 * Staleness indicator: age vs cadence_hours → green/yellow/red/gray
 * Navigation badge: số (collector, city) pairs overdue.
 */
class OohxCollectors extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-cloud-arrow-down';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Bộ thu dữ liệu';
    protected static ?int    $navigationSort  = 55;
    protected static ?string $title           = 'Bộ thu dữ liệu — nạp từ nguồn ngoài';
    protected static ?string $slug            = 'oohx-collectors';

    protected static string $view = 'filament.pages.oohx-collectors';

    // Auto-refresh overview mỗi 30s để cập nhật latest runs realtime
    protected ?string $pollingInterval = '30s';

    // ── Header actions ─────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            $this->viewHistoryAction(),
        ];
    }

    private function viewHistoryAction(): Action
    {
        return Action::make('view_history')
            ->label('Xem lịch sử chạy')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->url(fn () => route('filament.admin.resources.oohx-collector-runs.index'));
    }

    // ── Trigger action factory — dùng bởi Blade view ──────────────────

    /**
     * Action trigger 1 collector cho 1 city (hoặc custom bbox).
     * Render qua $this->triggerAction() trong Blade.
     */
    public function triggerAction(): Action
    {
        return Action::make('trigger')
            ->label('Chạy bộ thu')
            ->icon('heroicon-o-play')
            ->color('primary')
            ->modalHeading(fn (array $arguments) => "Trigger {$arguments['collector']}?")
            ->modalDescription(function (array $arguments) {
                $meta = config("oohx_collectors.{$arguments['collector']}", []);
                $runtime = $meta['expected_runtime_seconds'] ?? null;
                $rate    = $meta['rate_limit'] ?? '';
                $hint = "Worker Python cron pick lên sau ≤15 phút.";
                if ($runtime) $hint .= " Expected runtime: ~{$runtime}s.";
                if ($rate)    $hint .= " Rate limit: {$rate}.";
                return $hint;
            })
            ->form(fn (array $arguments) => $this->triggerForm($arguments['collector']))
            ->action(function (array $arguments, array $data) {
                $this->handleTrigger($arguments['collector'], $data);
            });
    }

    /**
     * Dynamic form cho trigger modal — fields khác nhau theo collector metadata.
     * Tất cả wrapped trong 1 method để tái dùng nếu cần.
     */
    private function triggerForm(string $collectorName): array
    {
        $meta = config("oohx_collectors.{$collectorName}", []);
        $supportsCity = $meta['supports_city'] ?? false;
        $supportsBbox = $meta['supports_bbox'] ?? false;

        $fields = [];

        if ($supportsCity) {
            $cityOptions = app(CollectorManager::class)->cityOptionsForCollector($collectorName);
            $fields[] = Forms\Components\Select::make('city')
                ->label('Tỉnh/thành')
                ->options($cityOptions)
                ->searchable()
                ->required()
                ->helperText('Built-in cities guaranteed có default bbox/centroid. Cities từ screens cần có ≥1 active screen.');
        }

        $fields[] = Forms\Components\TextInput::make('priority')
            ->label('Ưu tiên')
            ->helperText('Số nhỏ = ưu tiên cao. 50 = gấp, 100 = thường, 200 = chạy nền.')
            ->required()
            ->numeric()
            ->minValue(1)
            ->maxValue(1000)
            ->default(100);

        if ($supportsBbox) {
            $fields[] = Forms\Components\Checkbox::make('use_custom_bbox')
                ->label('Nâng cao: khung toạ độ tự đặt')
                ->helperText('Override default city bbox bằng tọa độ tùy chỉnh (WGS84 degrees).')
                ->default(false)
                ->live();

            $fields[] = Forms\Components\Grid::make(2)
                ->visible(fn (Forms\Get $get) => (bool) $get('use_custom_bbox'))
                ->schema([
                    Forms\Components\TextInput::make('bbox.min_lon')
                        ->label('Kinh độ nhỏ nhất')
                        ->required(fn (Forms\Get $get) => (bool) $get('use_custom_bbox'))
                        ->numeric()
                        ->step(0.0001)
                        ->placeholder('105.70'),
                    Forms\Components\TextInput::make('bbox.min_lat')
                        ->label('Vĩ độ nhỏ nhất')
                        ->required(fn (Forms\Get $get) => (bool) $get('use_custom_bbox'))
                        ->numeric()
                        ->step(0.0001)
                        ->placeholder('20.90'),
                    Forms\Components\TextInput::make('bbox.max_lon')
                        ->label('Kinh độ lớn nhất')
                        ->required(fn (Forms\Get $get) => (bool) $get('use_custom_bbox'))
                        ->numeric()
                        ->step(0.0001)
                        ->placeholder('106.00'),
                    Forms\Components\TextInput::make('bbox.max_lat')
                        ->label('Vĩ độ lớn nhất')
                        ->required(fn (Forms\Get $get) => (bool) $get('use_custom_bbox'))
                        ->numeric()
                        ->step(0.0001)
                        ->placeholder('21.15'),
                ]);
        }

        // Weather-specific: forecast_hours
        if ($collectorName === 'open_meteo_weather') {
            $fields[] = Forms\Components\TextInput::make('forecast_hours')
                ->label('Số giờ dự báo (không bắt buộc)')
                ->helperText('Leave empty để chỉ lấy current weather.')
                ->numeric()
                ->minValue(1)
                ->maxValue(168)
                ->placeholder('24');
        }

        return $fields;
    }

    /**
     * Execute trigger — gọi CollectorManager::trigger với params được build từ form.
     */
    private function handleTrigger(string $collectorName, array $data): void
    {
        try {
            $city = $data['city'] ?? null;

            // Validate city có active screens (theo decision #2 option B)
            $meta = config("oohx_collectors.{$collectorName}");
            if ($city && ! in_array($city, config('oohx_collectors.builtin_cities', []), true)) {
                $mgr = app(CollectorManager::class);
                if (! $mgr->cityHasScreens($city)) {
                    Notification::make()
                        ->title("City '{$city}' không có active screens")
                        ->body('Collector cần ≥1 screen để fallback bbox. Sync screen trước.')
                        ->warning()
                        ->persistent()
                        ->send();
                    return;
                }
            }

            // Build params
            $params = [];
            if (! empty($data['use_custom_bbox']) && ! empty($data['bbox'])) {
                $params['bbox'] = [
                    'min_lon' => (float) $data['bbox']['min_lon'],
                    'min_lat' => (float) $data['bbox']['min_lat'],
                    'max_lon' => (float) $data['bbox']['max_lon'],
                    'max_lat' => (float) $data['bbox']['max_lat'],
                ];
            }
            if (! empty($data['forecast_hours'])) {
                $params['forecast_hours'] = (int) $data['forecast_hours'];
            }

            $run = app(CollectorManager::class)->trigger(
                $collectorName,
                $city,
                $params,
                (int) ($data['priority'] ?? 100),
            );

            $targetLabel = $city ? "{$collectorName} · {$city}" : $collectorName;
            Notification::make()
                ->title("Enqueued run #{$run->id}")
                ->body("{$targetLabel} · priority {$run->priority}. Worker cron sẽ pick lên trong ≤15 phút.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Không chạy được')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    // ── View data ─────────────────────────────────────────────────────

    public function getViewData(): array
    {
        $mgr = app(CollectorManager::class);
        $collectors = $mgr->listCollectors();
        $latest = $mgr->latestByCollectorAndCity();
        $counts = $mgr->countsByStatus();
        $builtinCities = config('oohx_collectors.builtin_cities', []);

        // Compute staleness per (collector, city) cho built-in cities
        $staleness = [];
        foreach ($collectors as $name => $meta) {
            foreach ($builtinCities as $city) {
                $run = $latest[$name][$city] ?? null;
                $staleness[$name][$city] = [
                    'run'  => $run,
                    'info' => $mgr->computeStaleness($name, $run),
                ];
            }
        }

        return [
            'collectors'    => $collectors,
            'builtinCities' => $builtinCities,
            'staleness'     => $staleness,
            'counts'        => $counts,
            'overdue'       => $mgr->overdueCount(),
        ];
    }

    // ── Navigation badge: overdue count ────────────────────────────────

    public static function getNavigationBadge(): ?string
    {
        try {
            $n = app(CollectorManager::class)->overdueCount();
            return $n > 0 ? (string) $n : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Số (collector, city) overdue — cần trigger';
    }
}
