<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tạo người dùng'),
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()->with(['ownerUsers.owner', 'roles']);
    }
}
