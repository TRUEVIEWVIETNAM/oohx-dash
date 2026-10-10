<?php

namespace App\Filament\Resources\OohxConfig;

use App\Filament\Resources\OohxConfig\SeasonalityFactorResource\Pages;
use App\Models\Oohx\Config\SeasonalityFactor;
use App\Services\Oohx\ConfigManagerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Phase 2.D: config.seasonality_factors — multiplier per (city, month).
 *
 * Composite PK (city, month) — Filament route binding dùng synthetic "city:month"
 * qua getRouteKey() / resolveRouteBinding() trong Model.
 *
 * Mutations đi qua ConfigManagerService::updateSeasonalityFactor (audit log).
 * Heatmap view dedicated ở trang riêng: OohxConfig/SeasonalityHeatmapPage.
 */
class SeasonalityFactorResource extends Resource
{
    protected static ?string $model = SeasonalityFactor::class;

    protected static ?string $navigationIcon  = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Hệ số mùa vụ';
    protected static ?string $modelLabel      = 'Hệ số mùa vụ';
    protected static ?int    $navigationSort  = 75;

    public static function canDelete($r): bool { return false; }

    protected static function hasViewPage(): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('city')
                ->label('Tỉnh/thành')
                ->required()
                ->disabled(fn ($record) => (bool) $record)
                ->dehydrated(true)
                ->maxLength(100),

            Forms\Components\Select::make('month')
                ->label('Tháng')
                ->options([
                    1 => '01 · Jan', 2 => '02 · Feb', 3 => '03 · Mar', 4 => '04 · Apr',
                    5 => '05 · May', 6 => '06 · Jun', 7 => '07 · Jul', 8 => '08 · Aug',
                    9 => '09 · Sep', 10 => '10 · Oct', 11 => '11 · Nov', 12 => '12 · Dec',
                ])
                ->required()
                ->disabled(fn ($record) => (bool) $record)
                ->dehydrated(true),

            Forms\Components\TextInput::make('factor')
                ->label('Hệ số')
                ->helperText('Khoảng (0, 2]. 1.0=neutral, >1 amplify (summer peak), <1 dampen (Tet low).')
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->maxValue(2)
                ->step(0.01),

            Forms\Components\Textarea::make('note')
                ->label('Ghi chú')
                ->rows(2)
                ->maxLength(500),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('city')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('month_label')
                    ->label('Tháng')
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('month', $direction)),

                Tables\Columns\TextColumn::make('factor')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state > 1.05 => 'success',
                        $state < 0.95 => 'danger',
                        default       => 'warning',
                    }),

                Tables\Columns\TextColumn::make('note')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_by')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('city')
            ->filters([
                SelectFilter::make('city')
                    ->options(fn () => SeasonalityFactor::query()
                        ->distinct()
                        ->orderBy('city')
                        ->pluck('city', 'city')
                        ->toArray()),

                SelectFilter::make('month')
                    ->options([
                        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
                        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
                        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (SeasonalityFactor $record, array $data) {
                        app(ConfigManagerService::class)->updateSeasonalityFactor(
                            $record->city,
                            (int) $record->month,
                            (float) $data['factor'],
                            $data['note'] ?? null,
                        );
                        Notification::make()
                            ->title("Updated {$record->city} · {$record->month_label}")
                            ->success()
                            ->send();
                        // Re-fetch fresh instance (composite PK — không find())
                        return SeasonalityFactor::byKey($record->city, (int) $record->month)->first();
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Thêm hệ số')
                    ->using(function (array $data) {
                        app(ConfigManagerService::class)->updateSeasonalityFactor(
                            $data['city'],
                            (int) $data['month'],
                            (float) $data['factor'],
                            $data['note'] ?? null,
                        );
                        return SeasonalityFactor::byKey($data['city'], (int) $data['month'])->first();
                    }),

                Tables\Actions\Action::make('view_heatmap')
                    ->label('Xem bản đồ nhiệt')
                    ->icon('heroicon-o-squares-2x2')
                    ->color('info')
                    ->url(fn () => Pages\SeasonalityHeatmap::getUrl()),

                Tables\Actions\Action::make('seed_help')
                    ->label('Nạp giá trị mặc định')
                    ->icon('heroicon-o-command-line')
                    ->color('gray')
                    ->modalHeading('Seed defaults từ Python CLI')
                    ->modalDescription(
                        'Laravel UI không duplicate defaults bên Python để tránh drift. ' .
                        'SSH vào Data Engine VPS và chạy CLI command dưới để seed Hà Nội, HCMC, Đà Nẵng, Hải Phòng với default factors từ Python _DEFAULT_SEASONALITY.'
                    )
                    ->modalContent(view('filament.resources.oohx-config.seasonality-seed-hint'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'   => Pages\ListSeasonalityFactors::route('/'),
            'heatmap' => Pages\SeasonalityHeatmap::route('/heatmap'),
        ];
    }
}
