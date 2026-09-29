<?php

namespace App\Filament\Shared\Widgets;

use App\Models\Network;
use App\Models\Screen;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Stats widget for the ViewNetwork detail page.
 * Receives $record (Network) from the page via getHeaderWidgetsData().
 */
class NetworkDetailStats extends BaseWidget
{
    public ?Network $record = null;

    protected function getStats(): array
    {
        if (! $this->record) {
            return [];
        }

        $networkId = $this->record->id;

        $base = fn() => Screen::whereHas('site', fn($q) => $q->where('network_id', $networkId));

        $total   = $base()->count();
        $active  = $base()->where('active', true)->count();
        $online  = $base()->where('status', 'online')->count();
        $offline = $base()->where('status', 'offline')->count();
        $sites   = $base()->distinct('site_id')->count('site_id');

        $onlineColor  = $online  > 0 ? 'success' : 'gray';
        $offlineColor = $offline > 0 ? 'danger'  : 'gray';

        return [
            Stat::make('Total Screens', number_format($total))
                ->description($active . ' active · ' . ($total - $active) . ' inactive')
                ->descriptionIcon('heroicon-s-computer-desktop')
                ->icon('heroicon-o-computer-desktop')
                ->color('primary'),

            Stat::make('Online', number_format($online))
                ->description($online . ' of ' . $total . ' screens connected')
                ->descriptionIcon('heroicon-s-signal')
                ->icon('heroicon-o-signal')
                ->color($onlineColor),

            Stat::make('Offline', number_format($offline))
                ->description('Device disconnected')
                ->descriptionIcon('heroicon-s-signal-slash')
                ->icon('heroicon-o-signal-slash')
                ->color($offlineColor),

            Stat::make('Sites', number_format($sites))
                ->description('Physical locations')
                ->descriptionIcon('heroicon-s-map-pin')
                ->icon('heroicon-o-map-pin')
                ->color('info'),
        ];
    }
}
