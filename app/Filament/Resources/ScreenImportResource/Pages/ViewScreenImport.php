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
        if (in_array($status, ScreenImport::EDITABLE_STATUSES, true)) {
            $actions[] = Actions\Action::make('refineWithAi')
                ->label('Nhờ AI tinh chỉnh')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->modalHeading('Refine mapping với AI')
                ->modalDescription('Mô tả tự nhiên điều chỉnh bạn muốn (vd: "cột 3 là giá VND không phải USD", "cột 5 là tên network")')
                ->form([
                    Forms\Components\Textarea::make('comment')
                        ->label('Nhận xét / gợi ý của bạn')
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
                            ->title('Tinh chỉnh không được')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                });
        }

        // ── Edit mapping (visible during mapping + previewed stages) ───────────
        if (in_array($status, ScreenImport::EDITABLE_STATUSES, true)) {
            $actions[] = Actions\Action::make('editMapping')
                ->label('Sửa bảng ghép cột')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->modalHeading('Ghép cột')
                ->modalDescription('Chỉnh mapping từ cột file sang field DB. Bỏ trống = skip cột.')
                ->modalWidth('5xl')
                ->fillForm(fn () => ['mapping' => $this->buildMappingFormState($record)])
                ->form([
                    Forms\Components\Repeater::make('mapping')
                        ->label('')
                        ->schema([
                            Forms\Components\Grid::make(12)->schema([
                                Forms\Components\TextInput::make('header')
                                    ->label('Cột trong tệp')
                                    ->readOnly()
                                    ->columnSpan(4),

                                Forms\Components\Select::make('field')
                                    ->label('Trường trong CSDL')
                                    ->options(FieldCatalog::groupedOptions())
                                    ->searchable()
                                    ->placeholder('— bỏ qua cột này —')
                                    ->helperText(fn (array $state) => ! empty($state['compound'])
                                        ? 'AI đề xuất compound split: ' . implode(' + ', $state['compound']) . '. Chọn field ở đây sẽ ghi đè.'
                                        : null)
                                    ->columnSpan(5),

                                Forms\Components\TextInput::make('confidence_display')
                                    ->label('Độ tin cậy AI')
                                    ->readOnly()
                                    ->columnSpan(1),

                                Forms\Components\Textarea::make('reason')
                                    ->label('Lý do / ghi chú')
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
        if (in_array($status, [ScreenImport::STATUS_UPLOADED, ScreenImport::STATUS_MAPPING], true)) {
            $actions[] = Actions\Action::make('runPreview')
                ->label('Kiểm và xem trước')
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
                            ->title('Xem trước không được')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                });
        }

        // ── Run import ─────────────────────────────────────────────────────────
        if ($status === ScreenImport::STATUS_PREVIEWED) {
            $validCount = ($record->total_rows ?? 0) - count($record->validation_errors ?? []);

            $actions[] = Actions\Action::make('runImport')
                ->label("Import {$validCount} screens")
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('success')
                ->disabled(fn () => $validCount === 0)
                ->requiresConfirmation()
                ->modalHeading('Xác nhận nhập')
                ->modalDescription("Sẽ ghi {$validCount} screens vào DB. Mode: {$record->upsert_mode}. Rows lỗi sẽ skip.")
                ->action(function () use ($record) {
                    try {
                        app(ScreenImportService::class)->queueExecution($record);
                        Notification::make()
                            ->title('Đã đưa việc nhập vào hàng đợi')
                            ->body('Job đã vào queue. Page sẽ tự refresh progress mỗi 3 giây.')
                            ->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Không đưa vào hàng đợi được')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                });

            $actions[] = Actions\Action::make('backToMapping')
                ->label('Về bảng ghép cột')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->action(fn () => $record->update(['status' => ScreenImport::STATUS_MAPPING]));
        }

        // ── Cancel (importing) — cooperative cancel via DB status ─────────────
        if ($status === ScreenImport::STATUS_IMPORTING) {
            $actions[] = Actions\Action::make('cancelImport')
                ->label('Huỷ lần nhập')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Huỷ lần nhập này?')
                ->modalDescription('Worker sẽ dừng trong vòng ≤50 rows tiếp theo. Các rows đã import không rollback.')
                ->action(function () use ($record) {
                    $record->update(['status' => ScreenImport::STATUS_CANCELLED]);
                    Notification::make()
                        ->title('Cancel signal đã gửi')
                        ->body('Worker sẽ detect trong ≤50 rows.')
                        ->warning()->send();
                });
        }

        // ── Download error report (done/failed with errors) ───────────────────
        if (in_array($status, ScreenImport::FINISHED_STATUSES, true) && ! empty($record->validation_errors)) {
            $actions[] = Actions\Action::make('downloadErrors')
                ->label('Tải báo cáo lỗi')
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
                            ->title('Xuất không được')
                            ->body($e->getMessage())
                            ->danger()->send();
                        return null;
                    }
                });
        }

        // ── Retry (failed) ─────────────────────────────────────────────────────
        if ($status === ScreenImport::STATUS_FAILED) {
            $actions[] = Actions\Action::make('retryAnalyze')
                ->label('Phân tích lại tệp')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function () use ($record) {
                    try {
                        app(ScreenImportService::class)->analyze($record);
                        app(ScreenImportService::class)->proposeMapping($record);
                        Notification::make()->title('Đã phân tích lại')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Thử lại không được')->body($e->getMessage())->danger()->send();
                    }
                });
        }

        // ── View imported screens (done) ───────────────────────────────────────
        if ($status === ScreenImport::STATUS_DONE && ($record->success_count ?? 0) > 0) {
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
            Infolists\Components\Section::make('Tổng quan')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn ($state) => ScreenImport::STATUS_LABELS[$state] ?? $state ?? '—')
                        ->color(fn (string $state) => match ($state) {
                            ScreenImport::STATUS_UPLOADED, ScreenImport::STATUS_MAPPING => 'info',
                            ScreenImport::STATUS_PREVIEWED           => 'warning',
                            ScreenImport::STATUS_IMPORTING           => 'primary',
                            ScreenImport::STATUS_DONE                => 'success',
                            ScreenImport::STATUS_FAILED, ScreenImport::STATUS_CANCELLED => 'danger',
                            default               => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('original_filename')->label('Tệp'),
                    Infolists\Components\TextEntry::make('total_rows')->label('Tổng số dòng')->numeric(),
                    Infolists\Components\TextEntry::make('upsert_mode')->label('Chế độ'),

                    Infolists\Components\TextEntry::make('success_count')
                        ->label('Đã nhập')
                        ->numeric()
                        ->color('success')
                        ->visible(fn () => in_array($record->status, ScreenImport::STARTED_STATUSES, true)),
                    Infolists\Components\TextEntry::make('failed_count')
                        ->label('Lỗi')
                        ->numeric()
                        ->color('danger')
                        ->visible(fn () => in_array($record->status, ScreenImport::STARTED_STATUSES, true)),
                    Infolists\Components\TextEntry::make('uploader.name')->label('Người tải lên'),
                    Infolists\Components\TextEntry::make('created_at')->label('Tạo lúc')->since(),

                    Infolists\Components\TextEntry::make('error_summary')
                        ->label('Tóm tắt')
                        ->columnSpanFull()
                        ->visible(fn () => ! empty($record->error_summary)),
                ]),

            // ── Progress (Phase 2 — importing state) ──────────────────────────
            Infolists\Components\Section::make('Tiến độ nhập')
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
                ->visible(fn () => $record->status === ScreenImport::STATUS_IMPORTING),

            // ── AI comment history (Phase 2) ──────────────────────────────────
            Infolists\Components\Section::make('Lịch sử AI tinh chỉnh')
                ->description('Danh sách comment đã gửi để refine mapping.')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('ai_comment_history')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('at')->label('Lúc')->since(),
                            Infolists\Components\TextEntry::make('comment')->label('Nhận xét')->columnSpanFull(),
                        ])
                        ->columnSpanFull()
                        ->columns(2),
                ])
                ->visible(fn () => ! empty($record->ai_comment_history))
                ->collapsible()
                ->collapsed(),

            // ── Mapping review ────────────────────────────────────────────────
            Infolists\Components\Section::make('Ghép cột')
                ->description('AI-proposed mapping. Click "Edit mapping" trên header để chỉnh.')
                ->schema([
                    Infolists\Components\ViewEntry::make('mapping_table')
                        ->view('filament.resources.screen-import.mapping-table')
                        ->viewData([
                            'headers'    => $record->headers ?? [],
                            ScreenImport::STATUS_MAPPING    => $record->effective_mapping,
                            'sampleRows' => $record->sample_rows ?? [],
                        ])
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->visible(fn () => in_array($record->status, ScreenImport::EDITABLE_STATUSES, true) && ! empty($record->headers)),

            // ── Preview result ────────────────────────────────────────────────
            Infolists\Components\Section::make('Xem trước (chạy thử)')
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
                ->visible(fn () => $record->status === ScreenImport::STATUS_PREVIEWED && ! empty($record->preview_data)),

            // ── Import result ─────────────────────────────────────────────────
            Infolists\Components\Section::make('Kết quả nhập')
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
                ->visible(fn () => in_array($record->status, ScreenImport::FINISHED_STATUSES, true)),
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
