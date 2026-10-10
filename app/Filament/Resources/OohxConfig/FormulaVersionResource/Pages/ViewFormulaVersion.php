<?php

namespace App\Filament\Resources\OohxConfig\FormulaVersionResource\Pages;

use App\Filament\Resources\OohxConfig\FormulaVersionResource;
use App\Filament\Resources\OohxRecomputeJobResource;
use App\Models\Oohx\Config\AuditLog;
use App\Services\Oohx\ConfigManagerService;
use App\Services\Oohx\JobOrchestrator;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewFormulaVersion extends ViewRecord
{
    protected static string $resource = FormulaVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Phase 3.A — Preview impact (dry-run)
            Actions\Action::make('preview')
                ->label('Xem trước tác động')
                ->icon('heroicon-o-magnifying-glass-plus')
                ->color('info')
                ->visible(fn () => ! $this->record->is_active)
                ->modalHeading(fn () => "Preview {$this->record->tag} vs active")
                ->modalDescription('Dry-run so sánh estimates. KHÔNG touch output.* table.')
                ->form([
                    Forms\Components\Select::make('city')
                        ->label('Lọc theo tỉnh/thành (không bắt buộc)')
                        ->options(fn () => \App\Models\Oohx\Screen::query()
                            ->whereNotNull('city')
                            ->distinct()
                            ->orderBy('city')
                            ->pluck('city', 'city')
                            ->toArray())
                        ->placeholder('Tất cả tỉnh/thành')
                        ->searchable(),
                    Forms\Components\TextInput::make('sample_size')
                        ->label('Cỡ mẫu')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(JobOrchestrator::PREVIEW_MAX_SAMPLE_SIZE)
                        ->default(100),
                    Forms\Components\TextInput::make('seed')
                        ->label('Giá trị nạp (không bắt buộc)')
                        ->numeric()
                        ->helperText('Reproducible — cùng seed → cùng sample.'),
                ])
                ->action(function (array $data) {
                    try {
                        $job = app(JobOrchestrator::class)->enqueuePreview(
                            tag: $this->record->tag,
                            sampleSize: (int) $data['sample_size'],
                            city: $data['city'] ?? null,
                            seed: isset($data['seed']) && $data['seed'] !== '' ? (int) $data['seed'] : null,
                        );
                        Notification::make()
                            ->title("Preview job #{$job->id} queued")
                            ->body("Polling job detail page để xem result khi done.")
                            ->success()->persistent()->send();

                        redirect()->to(OohxRecomputeJobResource::getUrl('view', ['record' => $job->id]));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Không đưa được việc xem trước vào hàng đợi')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            Actions\Action::make('activate')
                ->label('Kích hoạt phiên bản này')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => ! $this->record->is_active)
                ->requiresConfirmation()
                ->modalHeading(fn () => "Activate {$this->record->tag}?")
                ->modalDescription(function () {
                    $hasPreview = app(JobOrchestrator::class)->hasRecentPreview($this->record->tag, minutes: 30);
                    $warning = $hasPreview
                        ? ''
                        : "⚠ No preview run in last 30m. Recommend Preview impact trước. ";
                    return $warning . 'Switch active version. Python sẽ dùng formula mới trong ≤ 5 phút.';
                })
                ->form([
                    Forms\Components\Checkbox::make('recompute_stale')
                        ->label('Đưa luôn việc tính lại phần đã cũ vào hàng đợi')
                        ->helperText('Tự trigger bulk recompute cho screens chưa có estimate trên version mới.')
                        ->default(false),
                ])
                ->action(function (array $data) {
                    try {
                        $hasPreview = app(JobOrchestrator::class)->hasRecentPreview($this->record->tag, minutes: 30);

                        app(ConfigManagerService::class)->activateVersion($this->record->tag);

                        if (! $hasPreview) {
                            AuditLog::create([
                                'actor'      => auth()->user()?->email ?? 'system',
                                'action'     => 'activate_without_preview',
                                'target'     => "tag={$this->record->tag}",
                                'note'       => 'Activated without recent preview (last 30m).',
                                'created_at' => now(),
                            ]);
                        }

                        if ($data['recompute_stale'] ?? false) {
                            $job = app(JobOrchestrator::class)->enqueueBulkAction('recompute_stale', priority: 50);
                            Notification::make()
                                ->title("Activated {$this->record->tag} + queued recompute-stale")
                                ->body("Job #{$job->id}. Xem progress ở Recompute Jobs.")
                                ->success()->persistent()->send();
                        } else {
                            Notification::make()
                                ->title("Activated {$this->record->tag}")
                                ->success()->send();
                        }

                        $this->refreshFormData(['is_active', 'activated_at']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Kích hoạt không được')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                }),
        ];
    }
}
