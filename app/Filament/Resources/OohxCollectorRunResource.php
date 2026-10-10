<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OohxCollectorRunResource\Pages;
use App\Models\Oohx\CollectorRun;
use App\Services\Oohx\CollectorManager;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Run history cho Data Engine collectors (Phase 2.C).
 *
 * Read-mostly — enqueue action ở Overview Page, không trong Resource này.
 * Operations ở đây:
 *   - List / filter / search past runs
 *   - View detail (stats, params, error)
 *   - Cancel pending runs (không support cooperative cancel)
 */
class OohxCollectorRunResource extends Resource
{
    protected static ?string $model = CollectorRun::class;

    protected static ?string $navigationIcon  = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Lịch sử chạy bộ thu';
    protected static ?string $modelLabel      = 'Lần chạy bộ thu';
    protected static ?int    $navigationSort  = 67;

    public static function canCreate(): bool { return false; }
    public static function canEdit($r): bool { return false; }
    public static function canDelete($r): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('collector_name')
                    ->label('Bộ thu')
                    ->badge()
                    ->color(fn (string $state) => config("oohx_collectors.{$state}.color", 'gray'))
                    ->formatStateUsing(fn (string $state) =>
                        config("oohx_collectors.{$state}.display_name", $state))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('city')
                    ->badge()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'   => 'warning',
                        'running'   => 'info',
                        'done'      => 'success',
                        'failed'    => 'danger',
                        'cancelled' => 'gray',
                        default     => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('rows_ingested')
                    ->label('Số dòng')
                    ->numeric(decimalPlaces: 0, thousandsSeparator: ',')
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('bytes_fetched_human')
                    ->label('Kích thước')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('priority')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('retry_count')
                    ->label('Số lần thử lại')
                    ->numeric()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('requested_at')
                    ->label('Yêu cầu lúc')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->tooltip(fn ($state) => $state?->format('Y-m-d H:i:s')),

                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Thời lượng')
                    ->formatStateUsing(fn ($state) => $state !== null ? "{$state}s" : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('requested_by')
                    ->label('Bởi')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                SelectFilter::make('collector_name')
                    ->label('Bộ thu')
                    ->options(fn () => collect(app(CollectorManager::class)->listCollectors())
                        ->mapWithKeys(fn ($meta, $name) => [$name => $meta['display_name'] ?? $name])
                        ->toArray()),

                SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'running'   => 'Running',
                        'done'      => 'Done',
                        'failed'    => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),

                SelectFilter::make('city')
                    ->options(fn () => CollectorRun::query()
                        ->whereNotNull('city')
                        ->distinct()
                        ->orderBy('city')
                        ->pluck('city', 'city')
                        ->toArray()),

                Tables\Filters\Filter::make('requested_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('requested_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('requested_at', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('cancel')
                    ->label('Huỷ')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CollectorRun $r) => $r->is_cancellable)
                    ->requiresConfirmation()
                    ->modalDescription('Huỷ lần chạy đang chờ. Lần chạy đang diễn ra thì không huỷ được — nó chạy tới khi xong.')
                    ->action(fn (CollectorRun $r) => self::handleCancel($r->id)),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Siêu dữ liệu')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('id')->label('Mã lần chạy'),
                    Infolists\Components\TextEntry::make('collector_name')
                        ->badge()
                        ->formatStateUsing(fn (string $state) =>
                            config("oohx_collectors.{$state}.display_name", $state))
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('city')->placeholder('—'),
                    Infolists\Components\TextEntry::make('priority'),
                    Infolists\Components\TextEntry::make('retry_count')->label('Số lần thử lại'),
                    Infolists\Components\TextEntry::make('requested_by')->label('Bởi')->columnSpan(2),
                ]),

            Infolists\Components\Section::make('Kết quả')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('rows_ingested')
                        ->numeric()
                        ->weight('bold'),
                    Infolists\Components\TextEntry::make('bytes_fetched_human')
                        ->label('Dung lượng đã tải'),
                    Infolists\Components\TextEntry::make('duration_seconds')
                        ->label('Thời lượng')
                        ->formatStateUsing(fn ($state) => $state !== null ? "{$state}s" : '—'),
                ]),

            // Stats breakdown - generic (handle cả POI schema lẫn weather schema)
            Infolists\Components\Section::make('Số liệu chi tiết')
                ->visible(fn ($record) => ! empty($record->stats))
                ->schema([
                    Infolists\Components\ViewEntry::make('stats_widget')
                        ->view('filament.resources.oohx-collector-run.stats-widget'),
                ]),

            Infolists\Components\Section::make('Thời gian')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('requested_at')->dateTime()->since(),
                    Infolists\Components\TextEntry::make('started_at')->dateTime()->placeholder('—'),
                    Infolists\Components\TextEntry::make('finished_at')->dateTime()->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Tham số')
                ->collapsible()
                ->schema([
                    Infolists\Components\TextEntry::make('params_json')
                        ->label(false)
                        ->getStateUsing(fn ($record) => empty($record->params)
                            ? '(default — no params)'
                            : json_encode($record->params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap break-all']),
                ]),

            Infolists\Components\Section::make('Lỗi')
                ->visible(fn ($record) => ! empty($record->error_message))
                ->schema([
                    Infolists\Components\TextEntry::make('error_message')
                        ->label(false)
                        ->prose()
                        ->markdown(false),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollectorRuns::route('/'),
            'view'  => Pages\ViewCollectorRun::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $n = CollectorRun::active()->count();
            return $n > 0 ? (string) $n : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pending + Running runs';
    }

    // ── Shared handlers ──────────────────────────────────────────────

    public static function handleCancel(int $runId): void
    {
        try {
            app(CollectorManager::class)->cancel($runId);
            Notification::make()->title("Run #{$runId} cancelled")->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Huỷ không được')->body($e->getMessage())->danger()->send();
        }
    }
}
