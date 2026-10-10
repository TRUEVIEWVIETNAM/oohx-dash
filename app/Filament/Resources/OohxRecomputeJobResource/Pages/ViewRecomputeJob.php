<?php

namespace App\Filament\Resources\OohxRecomputeJobResource\Pages;

use App\Filament\Resources\OohxRecomputeJobResource;
use App\Models\Oohx\RecomputeJob;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRecomputeJob extends ViewRecord
{
    protected static string $resource = OohxRecomputeJobResource::class;

    /**
     * Auto-refresh page mỗi 10s khi job đang active (pending/processing).
     * Stop polling khi đạt terminal state → tiết kiệm request.
     */
    protected ?string $pollingInterval = '10s';

    public function getPollingInterval(): ?string
    {
        /** @var RecomputeJob $r */
        $r = $this->record;
        return $r->is_active ? '10s' : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Phase 4.1 — campaign job done → link sang campaign detail
            Actions\Action::make('viewCampaign')
                ->label('Xem dự báo chiến dịch')
                ->icon('heroicon-o-megaphone')
                ->color('success')
                ->visible(fn () => $this->record->is_campaign
                    && $this->record->status === 'done'
                    && $this->record->campaign_id)
                ->url(fn () => \App\Filament\Resources\OohxCampaignEstimateResource::getUrl(
                    'view',
                    ['record' => $this->record->campaign_id]
                )),

            Actions\Action::make('retry')
                ->label('Thử lại')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => in_array($this->record->status, ['failed', 'cancelled'], true))
                ->requiresConfirmation()
                ->modalDescription('Reset job về pending để worker pick lại.')
                ->action(function () {
                    OohxRecomputeJobResource::handleRetry($this->record->id);
                    $this->refreshFormData(['status', 'retry_count', 'error_message']);
                }),

            Actions\Action::make('cancel')
                ->label('Huỷ')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->is_cancellable)
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->status === 'processing'
                    ? 'Bulk job đang chạy. Worker sẽ stop sau ≤25 screens. Continue?'
                    : 'Cancel pending job?')
                ->action(function () {
                    OohxRecomputeJobResource::handleCancel($this->record->id);
                    $this->refreshFormData(['status', 'cancelled_at', 'finished_at']);
                }),
        ];
    }
}
