<?php

namespace App\Filament\Resources\ScreenImportResource\Pages;

use App\Filament\Resources\ScreenImportResource;
use App\Filament\Resources\ScreenResource;
use App\Models\ScreenImport;
use App\Services\ScreenImport\ErrorReportExporter;
use App\Services\ScreenImport\FieldCatalog;
use App\Services\ScreenImport\ScreenImportService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Single-page UI that shifts based on $record->status.
 *
 *   uploaded/mapping → show mapping editor + "Run preview"
 *   previewed        → show preview table + "Run import"
 *   importing        → progress indicator (Phase 2)
 *   done/failed      → result summary + link to imported screens
 */
class ViewScreenImport extends ViewRecord
{
    protected static string $resource = ScreenImportResource::class;

    /**
     * Phase 2: Livewire poll khi import đang chạy — UI tự refresh stats mỗi 3 giây.
     * Dừng poll khi status là terminal (done/failed/cancelled).
     */
    protected ?string $pollingInterval = '3s';

    public function getPollingInterval(): ?string
    {
        /** @var ScreenImport $r */
        $r = $this->record;
        return $r->is_active ? '3s' : null;
    }

    protected function getHeaderActions(): array
    {
        /** @var ScreenImport $record */
        $record = $this->record;
        $status = $record->status;

        $actions = [];

        // ── Refine mapping with AI comment (Phase 2) ──────────────────────────
        if (in_array($status, ['uploaded', 'mapping', 'previewed'], true)) {
            $actions[] = Actions\Action::make('refineWithAi')
                ->label('Ask AI to refine')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->modalHeading('Refine mapping với AI')
                ->modalDescription('Mô tả tự nhiên điều chỉnh bạn muốn (vd: "cột 3 là giá VND không phải USD", "cột 5 là tên network")')
                ->form([
                    Forms\Components\Textarea::make('comment')
                        ->label('Your comment / hint')
                        ->required()
                        ->rows(3)
                        ->placeholder('Ví dụ: cột "Giá" là VND không phải USD. Cột "Địa điểm" là city chứ không phải tên site.'),
                ])
                ->action(function (array $data) use ($record) {
                    try {
                        app(ScreenImportService::class)->refineMapping($record, $data['comment']);
                        Notification::make()
                            ->title('Mapping đã được refine')
                            ->body('AI đã đề xuất mapping mới dựa trên comment. Xem lại bên dưới.')
                            ->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Refine failed')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                });
        }

        // ── Edit mapping (visible during mapping + previewed stages) ───────────
        if (in_array($status, ['uploaded', 'mapping', 'previewed'], true)) {
            $actions[] = Actions\Action::make('editMapping')
                ->label('Edit mapping')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->modalHeading('Column mapping')
                ->modalDescription('Chỉnh mapping từ cột file sang field DB. Bỏ trống = skip cột.')
                ->modalWidth('5xl')
                ->fillForm(fn () => ['mapping' => $this->buildMappingFormState($record)])
                ->form([
                    Forms\Components\Repeater::make('mapping')
                        ->label('')
                        ->schema([
                            Forms\Components\Grid::make(12)->schema([
                                Forms\Components\TextInput::make('header')
                                    ->label('File column')
                                    ->readOnly()
                                    ->columnSpan(4),

                                Forms\Components\Select::make('field')
                                    ->label('DB field')
                                    ->options(FieldCatalog::groupedOptions())
                                    ->searchable()
                                    ->placeholder('— skip this column —')
                                    ->helperText(fn (array $state) => ! empty($state['compound'])
                                        ? 'AI đề xuất compound split: ' . implode(' + ', $state['compound']) . '. Chọn field ở đây sẽ ghi đè.'
                                        : null)
                                    ->columnSpan(5),

                                Forms\Components\TextInput::make('confidence_display')
                                    ->label('AI conf.')
                                    ->readOnly()
                                    ->columnSpan(1),

                                Forms\Components\Textarea::make('reason')
                                    ->label('Reason / note')
                                    ->rows(1)
                                    ->columnSpan(2),

                                Forms\Components\Hidden::make('column_index'),
                                Forms\Components\Hidden::make('compound'),
                                Forms\Components\Hidden::make('transform'),
                            ]),
                        ])
                        ->itemLabel(fn (array $state): ?string => $state['header'] ?? null)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->collapsed(),
                ])
                ->action(function (array $data) use ($record) {
                    $cleaned = [];
                    foreach ($data['mapping'] ?? [] as $row) {
                        $idx = (int) ($row['column_index'] ?? -1);
                        if ($idx < 0) continue;
                        $cleaned[$idx] = [
                            'header'     => $row['header']     ?? '',
                            'field'      => $row['field']      ?: null,
                            'compound'   => $row['compound']   ?? null,
                            'confidence' => 1.0,
                            'reason'     => $row['reason']     ?? 'User edit',
                            'transform'  => $row['transform']  ?? null,
                        ];
                    }
                    app(ScreenImportService::class)->saveUserMapping($record, $cleaned);
                    Notification::make()->title('Mapping đã lưu')->success()->send();
                });
        }

        // ── Run preview ────────────────────────────────────────────────────────
        if (in_array($status, ['uploaded', 'mapping'], true)) {
            $actions[] = Actions\Action::make('runPreview')
                ->label('Validate & Preview')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Chạy dry-run validation trên toàn file. KHÔNG ghi DB.')
                ->action(function () use ($record) {
                    try {
                        app(ScreenImportService::class)->dryRun($record);
                        Notification::make()
                            ->title('Preview hoàn tất')
                            ->body($record->fresh()->error_summary ?? 'Xem kết quả preview.')
                            ->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Preview failed')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                });
        }

        // ── Run import ─────────────────────────────────────────────────────────
        if ($status === 'previewed') {
            $validCount = ($record->total_rows ?? 0) - count($record->validation_errors ?? []);

            $actions[] = Actions\Action::make('runImport')
                ->label("Import {$validCount} screens")
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('success')
                ->disabled(fn () => $validCount === 0)
                ->requiresConfirmation()
                ->modalHeading('Confirm import')
                ->modalDescription("Sẽ ghi {$validCount} screens vào DB. Mode: {$record->upsert_mode}. Rows lỗi sẽ skip.")
                ->action(function () use ($record) {
                    try {
                        app(ScreenImportService::class)->queueExecution($record);
                        Notification::make()
                            ->title('Import queued')
                            ->body('Job đã vào queue. Page sẽ tự refresh progress mỗi 3 giây.')
                            ->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Enqueue failed')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                });

            $actions[] = Actions\Action::make('backToMapping')
                ->label('Back to mapping')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->action(fn () => $record->update(['status' => 'mapping']));
        }

        // ── Cancel (importing) — cooperative cancel via DB status ─────────────
        if ($status === 'importing') {
            $actions[] = Actions\Action::make('cancelImport')
                ->label('Cancel import')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancel this import?')
                ->modalDescription('Worker sẽ dừng trong vòng ≤50 rows tiếp theo. Các rows đã import không rollback.')
                ->action(function () use ($record) {
                    $record->update(['status' => 'cancelled']);
                    Notification::make()
                        ->title('Cancel signal đã gửi')
                        ->body('Worker sẽ detect trong ≤50 rows.')
                        ->warning()->send();
                });
        }

        // ── Download error report (done/failed with errors) ───────────────────
        if (in_array($status, ['done', 'failed'], true) && ! empty($record->validation_errors)) {
            $actions[] = Actions\Action::make('downloadErrors')
                ->label('Download error report')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('danger')
                ->action(function () use ($record) {
                    try {
                        $path = $record->error_report_path
                            ?? app(ErrorReportExporter::class)->generate($record);
                        $fullPath = Storage::disk('local')->path($path);

                        if (! file_exists($fullPath)) {
                            // Regenerate if file disappeared
                            $path = app(ErrorReportExporter::class)->generate($record);
                            $fullPath = Storage::disk('local')->path($path);
                        }

                        return response()->download($fullPath, basename($fullPath));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Export failed')
                            ->body($e->getMessage())
                            ->danger()->send();
                        return null;
                    }
                });
        }

        // ── Retry (failed) ─────────────────────────────────────────────────────
        if ($status === 'failed') {
            $actions[] = Actions\Action::make('retryAnalyze')
                ->label('Re-analyze file')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function () use ($record) {
                    try {
                        app(ScreenImportService::class)->analyze($record);
                        app(ScreenImportService::class)->proposeMapping($record);
                        Notification::make()->title('Re-analyzed')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Retry failed')->body($e->getMessage())->danger()->send();
                    }
                });
        }

        // ── View imported screens (done) ───────────────────────────────────────
        if ($status === 'done' && ($record->success_count ?? 0) > 0) {
            $actions[] = Actions\Action::make('viewScreens')
                ->label('Xem screens đã import')
                ->icon('heroicon-o-tv')
                ->color('success')
                ->url(ScreenResource::getUrl('index'))
                ->openUrlInNewTab();
        }

        return $actions;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        /** @var ScreenImport $record */
        $record = $this->record;

        return $infolist->schema([
            Infolists\Components\Section::make('Overview')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn ($state) => ScreenImport::STATUS_LABELS[$state] ?? $state ?? '—')
                        ->color(fn (string $state) => match ($state) {
                            'uploaded', 'mapping' => 'info',
                            'previewed'           => 'warning',
                            'importing'           => 'primary',
                            'done'                => 'success',
                            'failed', 'cancelled' => 'danger',
                            default               => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('original_filename')->label('File'),
                    Infolists\Components\TextEntry::make('total_rows')->label('Total rows')->numeric(),
                    Infolists\Components\TextEntry::make('upsert_mode')->label('Mode'),

                    Infolists\Components\TextEntry::make('success_count')
                        ->label('Imported')
                        ->numeric()
                        ->color('success')
                        ->visible(fn () => in_array($record->status, ['importing', 'done'], true)),
                    Infolists\Components\TextEntry::make('failed_count')
                        ->label('Failed')
                        ->numeric()
                        ->color('danger')
                        ->visible(fn () => in_array($record->status, ['importing', 'done'], true)),
                    Infolists\Components\TextEntry::make('uploader.name')->label('Uploaded by'),
                    Infolists\Components\TextEntry::make('created_at')->label('Created')->since(),

                    Infolists\Components\TextEntry::make('error_summary')
                        ->label('Summary')
                        ->columnSpanFull()
                        ->visible(fn () => ! empty($record->error_summary)),
                ]),

            // ── Progress (Phase 2 — importing state) ──────────────────────────
            Infolists\Components\Section::make('Import progress')
                ->schema([
                    Infolists\Components\ViewEntry::make('import_progress')
                        ->view('filament.resources.screen-import.progress')
                        ->viewData([
                            'processedCount' => $record->processed_count,
                            'successCount'   => $record->success_count,
                            'failedCount'    => $record->failed_count,
                            'totalRows'      => $record->total_rows,
                            'progressPct'    => $record->progress_percent,
                            'startedAt'      => $record->started_at,
                        ])
                        ->columnSpanFull(),
                ])
                ->visible(fn () => $record->status === 'importing'),

            // ── AI comment history (Phase 2) ──────────────────────────────────
            Infolists\Components\Section::make('AI refinement history')
                ->description('Danh sách comment đã gửi để refine mapping.')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('ai_comment_history')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('at')->label('When')->since(),
                            Infolists\Components\TextEntry::make('comment')->label('Comment')->columnSpanFull(),
                        ])
                        ->columnSpanFull()
                        ->columns(2),
                ])
                ->visible(fn () => ! empty($record->ai_comment_history))
                ->collapsible()
                ->collapsed(),

            // ── Mapping review ────────────────────────────────────────────────
            Infolists\Components\Section::make('Column mapping')
                ->description('AI-proposed mapping. Click "Edit mapping" trên header để chỉnh.')
                ->schema([
                    Infolists\Components\ViewEntry::make('mapping_table')
                        ->view('filament.resources.screen-import.mapping-table')
                        ->viewData([
                            'headers'    => $record->headers ?? [],
                            'mapping'    => $record->effective_mapping,
                            'sampleRows' => $record->sample_rows ?? [],
                        ])
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->visible(fn () => in_array($record->status, ['uploaded', 'mapping', 'previewed'], true) && ! empty($record->headers)),

            // ── Preview result ────────────────────────────────────────────────
            Infolists\Components\Section::make('Preview (dry-run)')
                ->description('20 rows đầu + tổng số lỗi. KHÔNG ghi DB.')
                ->schema([
                    Infolists\Components\ViewEntry::make('preview_result')
                        ->view('filament.resources.screen-import.preview-result')
                        ->viewData([
                            'preview'    => $record->preview_data ?? [],
                            'errors'     => $record->validation_errors ?? [],
                            'totalRows'  => $record->total_rows,
                        ])
                        ->columnSpanFull(),
                ])
                ->visible(fn () => $record->status === 'previewed' && ! empty($record->preview_data)),

            // ── Import result ─────────────────────────────────────────────────
            Infolists\Components\Section::make('Import result')
                ->schema([
                    Infolists\Components\ViewEntry::make('import_result')
                        ->view('filament.resources.screen-import.import-result')
                        ->viewData([
                            'successCount' => $record->success_count,
                            'failedCount'  => $record->failed_count,
                            'totalRows'    => $record->total_rows,
                            'errors'       => $record->validation_errors ?? [],
                            'startedAt'    => $record->started_at,
                            'finishedAt'   => $record->finished_at,
                        ])
                        ->columnSpanFull(),
                ])
                ->visible(fn () => in_array($record->status, ['done', 'failed'], true)),
        ]);
    }

    /**
     * Convert effective_mapping to Repeater form state.
     * Repeater requires sequential array with a key per row.
     */
    private function buildMappingFormState(ScreenImport $record): array
    {
        $headers = $record->headers ?? [];
        $mapping = $record->effective_mapping;

        $state = [];
        foreach ($headers as $idx => $header) {
            $m = $mapping[$idx] ?? [];
            $state["col_{$idx}"] = [
                'column_index'       => $idx,
                'header'             => "[{$idx}] {$header}",
                'field'              => $m['field']     ?? null,
                'compound'           => $m['compound']  ?? null,
                'confidence_display' => isset($m['confidence']) ? (int) round($m['confidence'] * 100) . '%' : '—',
                'reason'             => $m['reason']    ?? '',
                'transform'          => $m['transform'] ?? null,
            ];
        }
        return $state;
    }
}
