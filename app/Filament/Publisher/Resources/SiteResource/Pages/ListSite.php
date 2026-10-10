<?php

namespace App\Filament\Publisher\Resources\SiteResource\Pages;

use App\Filament\Publisher\Resources\SiteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSite extends ListRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importSites')
                ->label('Nhập địa điểm từ Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(fn () => \App\Filament\Publisher\Pages\ImportSites::getUrl())
                ->openUrlInNewTab(false),

            Actions\CreateAction::make(),
        ];
    }
}
