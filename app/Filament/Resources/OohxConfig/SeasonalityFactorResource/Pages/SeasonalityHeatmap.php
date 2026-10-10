<?php

namespace App\Filament\Resources\OohxConfig\SeasonalityFactorResource\Pages;

use App\Filament\Resources\OohxConfig\SeasonalityFactorResource;
use App\Services\Oohx\ConfigManagerService;
use Filament\Actions;
use Filament\Resources\Pages\Page;

class SeasonalityHeatmap extends Page
{
    protected static string $resource = SeasonalityFactorResource::class;

    protected static string $view = 'filament.resources.oohx-config.seasonality-heatmap';

    protected static ?string $title = 'Bản đồ nhiệt mùa vụ';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_list')
                ->label('Về danh sách')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => SeasonalityFactorResource::getUrl('index')),
        ];
    }

    public function getViewData(): array
    {
        return [
            'heatmap' => app(ConfigManagerService::class)->seasonalityHeatmapData(),
            'months'  => [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
                          7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'],
        ];
    }
}
