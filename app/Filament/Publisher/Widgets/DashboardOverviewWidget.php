<?php

namespace App\Filament\Publisher\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class DashboardOverviewWidget extends BaseWidget
{
    protected static ?int $sort = -1;

    protected function getStats(): array
    {
        $ownerId = (string) (auth()->user()?->current_owner_id ?? '');
        if (! $ownerId) return [];

        $data = cache()->remember("dashboard_overview_{$ownerId}", 60, function () use ($ownerId) {
            $cutoff = now()->subMinutes(5)->toDateTimeString();

            $screens = DB::table('screens')
                ->where('owner_id', $ownerId)
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN last_heartbeat_at >= ? THEN 1 ELSE 0 END) as online
                ", [$cutoff])
                ->first();

            $sites = DB::table('sites')
                ->where('owner_id', $ownerId)
                ->whereNull('deleted_at')
                ->count();

            $networks = DB::table('networks')
                ->where('owner_id', $ownerId)
                ->whereNull('deleted_at')
                ->count();

            return [
                'screens_total'  => (int) ($screens->total ?? 0),
                'screens_active' => (int) ($screens->active ?? 0),
                'screens_online' => (int) ($screens->online ?? 0),
                'sites'          => $sites,
                'networks'       => $networks,
            ];
        });

        $total  = $data['screens_total'];
        $active = $data['screens_active'];
        $online = $data['screens_online'];

        return [
            Stat::make('Screens', number_format($total))
                ->description($active . ' active · ' . ($total - $active) . ' inactive')
                ->icon('heroicon-o-computer-desktop')
                ->color('primary'),

            Stat::make('Trực tuyến', number_format($online))
                ->description($total > 0 ? round($online / $total * 100) . '% connected' : '—')
                ->icon('heroicon-o-signal')
                ->color($online > 0 ? 'success' : 'gray'),

            Stat::make('Sites', number_format($data['sites']))
                ->icon('heroicon-o-map-pin')
                ->color('info'),

            Stat::make('Networks', number_format($data['networks']))
                ->icon('heroicon-o-squares-2x2')
                ->color('warning'),
        ];
    }
}
