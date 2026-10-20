<?php

namespace App\Filament\Publisher\Resources\QuoteRequestResource\Pages;

use App\Filament\Publisher\Resources\QuoteRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListQuoteRequests extends ListRecords
{
    protected static string $resource = QuoteRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
