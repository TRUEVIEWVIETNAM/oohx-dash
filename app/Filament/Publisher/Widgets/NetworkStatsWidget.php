<?php

namespace App\Filament\Publisher\Widgets;

use App\Models\Network;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NetworkStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;
    protected static ?int $sort = -1;

    // ─────────────────────────────────────────────────────────────────────────

    protected function getOwnerId(): string
    {
        return (string) (auth()->user()?->current_owner_id ?? '');
    }

    // ─────────────────────────────────────────────────────────────────────────

    protected function getStats(): array
    {
        $ownerId = $this->getOwnerId();

        // ── Networks ─────────────────────────────────────────────────────────
        $total  = Network::where('owner_id', $ownerId)->count();
        $active = Network::where('owner_id', $ownerId)->where('status', 'active')->count();
        $paused = $total - $active;

        $networkIds = Network::where('owner_id', $ownerId)->pluck('id');

        // Đếm qua ĐỊA ĐIỂM, không qua screen_inventory.
        //
        // Trước đây widget này đếm theo `screen_inventory.network_id` trong khi
        // trang công khai đếm theo `sites.network_id`, nên cùng một mạng lưới
        // hiện hai con số màn hình khác nhau ở hai nơi (F-12). Nay cả hai đi
        // chung một đường: mạng lưới là chuỗi địa điểm, màn hình thừa hưởng
        // mạng lưới của nơi nó đứng.
        $screensInNetworks = $networkIds->isNotEmpty()
            ? Screen::whereHas('site', fn ($q) => $q->whereIn('network_id', $networkIds))
            : null;

        $networksWithScreens = $networkIds->isNotEmpty()
            ? Site::whereIn('network_id', $networkIds)
                ->whereHas('screens')
                ->distinct('network_id')
                ->count('network_id')
            : 0;

        $totalScreensInNetworks = $screensInNetworks ? (clone $screensInNetworks)->count() : 0;

        // Trung bình floor CPM của các màn hình trong những mạng lưới này.
        $avgFloorCpm = $networkIds->isNotEmpty()
            ? ScreenInventory::whereHas('screen.site', fn ($q) => $q->whereIn('network_id', $networkIds))
                ->whereNotNull('floor_cpm')
                ->where('floor_cpm', '>', 0)
                ->avg('floor_cpm')
            : null;

        // ─────────────────────────────────────────────────────────────────────

        return [
            Stat::make('Tổng Networks', number_format($total))
                ->description(
                    $active . ' active'
                    . ($paused > 0 ? ' · ' . $paused . ' paused' : '')
                )
                ->descriptionIcon('heroicon-s-squares-2x2')
                ->icon('heroicon-o-squares-2x2')
                ->color('primary'),

            Stat::make('Networks có màn hình', number_format($networksWithScreens))
                ->description($totalScreensInNetworks . ' màn hình đã gán network')
                ->descriptionIcon('heroicon-s-computer-desktop')
                ->icon('heroicon-o-computer-desktop')
                ->color('info'),

            Stat::make('Avg Floor CPM', $avgFloorCpm
                ? number_format((float) $avgFloorCpm, 0, '.', ',') . ' VND'
                : '—'
            )
                ->description('Trung bình floor CPM tất cả screens')
                ->descriptionIcon('heroicon-s-banknotes')
                ->icon('heroicon-o-banknotes')
                ->color('warning'),
        ];
    }
}
