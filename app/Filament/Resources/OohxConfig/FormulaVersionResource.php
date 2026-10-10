<?php

namespace App\Filament\Resources\OohxConfig;

use App\Filament\Resources\OohxConfig\FormulaVersionResource\Pages;
use App\Models\Oohx\Config\FormulaVersion;
use App\Services\Oohx\ConfigManagerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Resource cho config.formula_versions.
 *
 * UI flow:
 *   1. Header action "Publish current state as new version" — snapshot config.* tables
 *   2. Per-row "Activate" — switch active version (atomic, partial unique index ensure max 1 active)
 *   3. View page hiển thị snapshot JSON (4 nhóm coefficient)
 *
 * Lưu ý: KHÔNG cho edit/delete versions — versions là immutable historical record.
 */
class FormulaVersionResource extends Resource
{
    protected static ?string $model = FormulaVersion::class;

    protected static ?string $navigationIcon  = 'heroicon-o-archive-box';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Phiên bản công thức';
    protected static ?string $modelLabel      = 'Phiên bản công thức';
    protected static ?int    $navigationSort  = 70;

    public static function canCreate(): bool { return false; } // create qua publishVersion action only
    public static function canEdit($r): bool { return false; }
    public static function canDelete($r): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('tag')
                    ->label('Thẻ')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color(fn ($record) => $record->is_active ? 'success' : 'gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Đang bật')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('description')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_by')
                    ->label('Người công bố')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Đã công bố')
                    ->dateTime()
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('activated_at')
                    ->label('Đã kích hoạt')
                    ->dateTime()
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('publish_version')
                    ->label('Công bố trạng thái hiện tại')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('primary')
                    ->modalHeading('Publish formula version mới')
                    ->modalDescription('Snapshot toàn bộ config.* hiện tại. Có thể activate sau publish.')
                    ->form([
                        Forms\Components\TextInput::make('tag')
                            ->label('Nhãn phiên bản')
                            ->required()
                            ->placeholder('vd: v-2026-05-15')
                            ->helperText('Unique identifier — không trùng version cũ.')
                            ->maxLength(50),

                        Forms\Components\Textarea::make('description')
                            ->label('Mô tả')
                            ->required()
                            ->placeholder('Lý do publish version này')
                            ->rows(3)
                            ->maxLength(500),

                        Forms\Components\Toggle::make('activate_immediately')
                            ->label('Kích hoạt ngay sau khi công bố')
                            ->helperText('Bật = Python sẽ dùng formula mới trong ≤ 5 phút.')
                            ->default(false),
                    ])
                    ->action(function (array $data) {
                        try {
                            $svc = app(ConfigManagerService::class);
                            $version = $svc->publishVersion($data['tag'], $data['description']);

                            if ($data['activate_immediately'] ?? false) {
                                $svc->activateVersion($data['tag']);
                                Notification::make()
                                    ->title("Published + activated {$data['tag']}")
                                    ->body('Python sẽ dùng version mới trong ≤ 5 phút.')
                                    ->success()->persistent()->send();
                            } else {
                                Notification::make()
                                    ->title("Published {$data['tag']}")
                                    ->body('Click "Activate" để switch.')
                                    ->success()->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Công bố không được')
                                ->body($e->getMessage())
                                ->danger()->persistent()->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // Phase 3.A — Preview impact (dry-run so sánh với active version)
                Tables\Actions\Action::make('preview')
                    ->label('Xem trước tác động')
                    ->icon('heroicon-o-magnifying-glass-plus')
                    ->color('info')
                    ->visible(fn (FormulaVersion $record) => ! $record->is_active)
                    ->modalHeading(fn (FormulaVersion $record) => "Preview {$record->tag} vs active")
                    ->modalDescription('Dry-run so sánh estimates — sample N screens. KHÔNG touch output.* table. Chỉ in-memory comparison.')
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
                            ->maxValue(\App\Services\Oohx\JobOrchestrator::PREVIEW_MAX_SAMPLE_SIZE)
                            ->default(100)
                            ->helperText('Tối đa 2000. Khuyến nghị để 100 cho thị trường chính.'),

                        Forms\Components\TextInput::make('seed')
                            ->label('Giá trị nạp (không bắt buộc)')
                            ->numeric()
                            ->placeholder('vd: 42')
                            ->helperText('Reproducible — cùng seed → cùng sample screens.'),
                    ])
                    ->action(function (FormulaVersion $record, array $data) {
                        try {
                            $job = app(\App\Services\Oohx\JobOrchestrator::class)->enqueuePreview(
                                tag: $record->tag,
                                sampleSize: (int) $data['sample_size'],
                                city: $data['city'] ?? null,
                                seed: isset($data['seed']) && $data['seed'] !== '' ? (int) $data['seed'] : null,
                            );
                            Notification::make()
                                ->title("Preview job #{$job->id} queued")
                                ->body("Worker cron sẽ pick lên trong ≤10 phút. View result ở Recompute Jobs.")
                                ->success()
                                ->persistent()
                                ->send();

                            // Redirect sang job detail để user poll result
                            redirect()->to(
                                \App\Filament\Resources\OohxRecomputeJobResource::getUrl('view', ['record' => $job->id])
                            );
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Không đưa được việc xem trước vào hàng đợi')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('activate')
                    ->label('Kích hoạt')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (FormulaVersion $record) => ! $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(fn (FormulaVersion $record) => "Activate {$record->tag}?")
                    // Phase 3.A — Soft-gate: warning nếu chưa preview trong 30 phút
                    ->modalDescription(function (FormulaVersion $record) {
                        $hasPreview = app(\App\Services\Oohx\JobOrchestrator::class)
                            ->hasRecentPreview($record->tag, minutes: 30);
                        $warning = $hasPreview
                            ? ''
                            : "⚠ No preview run recently (last 30m). Recommend \"Preview impact\" trước để review delta_pct_p99. ";
                        return $warning . 'Switch active version. Python sẽ dùng formula mới trong ≤ 5 phút. Existing estimates vẫn dùng version cũ cho tới khi recompute.';
                    })
                    ->form([
                        Forms\Components\Checkbox::make('recompute_stale')
                            ->label('Đưa luôn việc tính lại phần đã cũ vào hàng đợi')
                            ->helperText('Tự trigger bulk recompute cho screens chưa có estimate trên version mới. Khuyến nghị cho production changes.')
                            ->default(false),
                    ])
                    ->action(function (FormulaVersion $record, array $data) {
                        try {
                            $hasPreview = app(\App\Services\Oohx\JobOrchestrator::class)
                                ->hasRecentPreview($record->tag, minutes: 30);

                            app(ConfigManagerService::class)->activateVersion($record->tag);

                            // Audit log explicitly khi activate without preview (soft-gate trace)
                            if (! $hasPreview) {
                                \App\Models\Oohx\Config\AuditLog::create([
                                    'actor'      => auth()->user()?->email ?? 'system',
                                    'action'     => 'activate_without_preview',
                                    'target'     => "tag={$record->tag}",
                                    'note'       => 'Activated without recent preview (last 30m).',
                                    'created_at' => now(),
                                ]);
                            }

                            if ($data['recompute_stale'] ?? false) {
                                $job = app(\App\Services\Oohx\JobOrchestrator::class)
                                    ->enqueueBulkAction('recompute_stale', priority: 50);
                                Notification::make()
                                    ->title("Activated {$record->tag} + enqueued recompute-stale")
                                    ->body("Job #{$job->id} queued. Xem progress ở Recompute Jobs.")
                                    ->success()->persistent()->send();
                            } else {
                                Notification::make()
                                    ->title("Activated {$record->tag}")
                                    ->body('Trigger recompute manually từ Recompute Jobs nếu cần.')
                                    ->success()->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Kích hoạt không được')
                                ->body($e->getMessage())
                                ->danger()->persistent()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Siêu dữ liệu phiên bản')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('tag')->badge(),
                    Infolists\Components\IconEntry::make('is_active')->boolean(),
                    Infolists\Components\TextEntry::make('id')->label('ID'),
                    Infolists\Components\TextEntry::make('description')->columnSpan(2),
                    Infolists\Components\TextEntry::make('created_by')->label('Người công bố'),
                    Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    Infolists\Components\TextEntry::make('activated_at')->dateTime()->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Snapshot — coefficient frozen tại thời điểm publish')
                ->collapsible()
                ->schema([
                    Infolists\Components\ViewEntry::make('snapshot')
                        ->view('filament.resources.oohx-config.formula-version-snapshot'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFormulaVersions::route('/'),
            'view'  => Pages\ViewFormulaVersion::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $active = FormulaVersion::where('is_active', true)->first();
            return $active?->tag;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}
