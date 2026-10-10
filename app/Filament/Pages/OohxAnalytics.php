<?php

namespace App\Filament\Pages;

use App\Services\Oohx\AnalyticsService;
use Filament\Pages\Page;

/**
 * Phase 4.2.7 — Analytics Dashboard cho Data Engine.
 *
 * Render 4 sections từ materialized views (refresh daily 04:15 UTC):
 *   1. Weekly trend — 12 tuần campaigns + KPI WoW
 *   2. Top cities — by total daily impressions
 *   3. Screen utilization — top booked + counts
 *   4. Formula version impact — active + history
 *
 * Performance: AnalyticsService cache 5 phút mỗi query → page load < 500ms.
 * Graceful: nếu MVs chưa migrate → empty state với hint cho ops.
 */
class OohxAnalytics extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-pie';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Phân tích';
    protected static ?int    $navigationSort  = 57;
    protected static ?string $title           = 'Data Engine — bảng phân tích';
    protected static ?string $slug            = 'oohx-analytics';

    protected static string $view = 'filament.pages.oohx-analytics';

    /** Refresh page mỗi 5 phút — match cache TTL. */
    protected ?string $pollingInterval = '5m';

    public bool   $available = false;
    public array  $staleness = [];
    public array  $weekOverWeek = [];
    public        $weeklyTrend;     // Collection
    public        $topCities;        // Collection
    public        $topUtilizedScreens; // Collection
    public array  $utilizationCounts = [];
    public        $formulaVersions;  // Collection

    public function mount(): void
    {
        $this->loadAll();
    }

    public function loadAll(): void
    {
        $svc = app(AnalyticsService::class);

        $this->available          = $svc->isAvailable();

        if (! $this->available) {
            // Skip queries — UI shows empty state
            $this->staleness          = ['color' => 'gray', 'label' => '—', 'computed_at' => null];
            $this->weekOverWeek       = ['current' => null, 'previous' => null, 'diff' => []];
            $this->weeklyTrend        = collect();
            $this->topCities          = collect();
            $this->topUtilizedScreens = collect();
            $this->utilizationCounts  = ['booked' => 0, 'total_with_data' => 0];
            $this->formulaVersions    = collect();
            return;
        }

        $this->staleness          = $svc->staleness();
        $this->weekOverWeek       = $svc->weekOverWeek();
        $this->weeklyTrend        = $svc->weeklyTrend(12);
        $this->topCities          = $svc->topCities(10);
        $this->topUtilizedScreens = $svc->topUtilizedScreens(20);
        $this->utilizationCounts  = $svc->utilizationCounts();
        $this->formulaVersions    = $svc->formulaVersionImpact(10);
    }

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u !== null && (
            ! method_exists($u, 'hasRole')
            || $u->hasRole('super_admin')
        );
    }
}
