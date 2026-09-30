<?php

namespace App\Filament\Publisher\Resources\RefundResource\Pages;

use App\Filament\Publisher\Resources\RefundResource;
use Filament\Resources\Pages\ListRecords;

class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
