<?php

namespace App\Filament\Resources\OohxCollectorRunResource\Pages;

use App\Filament\Resources\OohxCollectorRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCollectorRun extends ViewRecord
{
    protected static string $resource = OohxCollectorRunResource::class;

    public function getPollingInterval(): ?string
    {
        return $this->record->is_active ? '30s' : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cancel')
                ->label('Huỷ')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->is_cancellable)
                ->requiresConfirmation()
                ->modalDescription('Huỷ lần chạy đang chờ.')
                ->action(function () {
                    OohxCollectorRunResource::handleCancel($this->record->id);
                    $this->refreshFormData(['status', 'finished_at']);
                }),
        ];
    }
}
