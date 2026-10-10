<?php

namespace App\Filament\Resources\OohxCampaignEstimateResource\Pages;

use App\Filament\Resources\OohxCampaignEstimateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOohxCampaignEstimates extends ListRecords
{
    protected static string $resource = OohxCampaignEstimateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Chiến dịch mới')
                ->icon('heroicon-o-plus'),
        ];
    }
}
