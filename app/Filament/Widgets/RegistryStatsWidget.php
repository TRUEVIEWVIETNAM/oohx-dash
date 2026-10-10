<?php
namespace App\Filament\Widgets;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\Site;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class RegistryStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->hasRole('super_admin');

        // Scope to current owner if not super admin
        $screenQuery = Screen::query();
        $siteQuery   = Site::query();
        $logQuery    = DB::table('impression_logs');

        if (! $isSuperAdmin && $user?->current_owner_id) {
            $screenQuery->where('owner_id', $user->current_owner_id);
            $siteQuery->where('owner_id', $user->current_owner_id);
            $logQuery->where('owner_id', $user->current_owner_id);
        }

        $totalScreens     = $screenQuery->count();
        $onlineScreens    = (clone $screenQuery)->where('status', 'online')->count();
        $programmatic     = (clone $screenQuery)
            ->whereHas('inventory', fn($q) => $q->where('programmatic_enabled', true))->count();

        $revenue30d       = (clone $logQuery)
            ->where('played_at', '>=', now()->subDays(30))->sum('revenue_owner');
        $impressions30d   = (clone $logQuery)
            ->where('played_at', '>=', now()->subDays(30))->sum('imp_count');

        $avgCpm = $impressions30d > 0
            ? round(($revenue30d / $impressions30d) * 1000, 2)
            : 0;

        return [
            Stat::make('Total Screens', number_format($totalScreens))
                ->description("{$onlineScreens} online now")
                ->descriptionIcon('heroicon-m-signal')
                ->color($onlineScreens > 0 ? 'success' : 'gray'),

            Stat::make('Programmatic Screens', number_format($programmatic))
                ->description('Bật RTB')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('primary'),

            Stat::make('Revenue (30d)', '$'.number_format($revenue30d, 2))
                ->description(number_format($impressions30d).' impressions')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Avg CPM (30d)', '$'.number_format($avgCpm, 2))
                ->description('USD cho 1.000 lượt hiển thị')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('warning'),
        ];
    }
}
