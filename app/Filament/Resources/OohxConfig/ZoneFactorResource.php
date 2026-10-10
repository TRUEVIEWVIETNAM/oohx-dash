<?php

namespace App\Filament\Resources\OohxConfig;

use App\Filament\Resources\OohxConfig\ZoneFactorResource\Pages;
use App\Models\Oohx\Config\ZoneFactor;
use App\Services\Oohx\ConfigManagerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ZoneFactorResource extends Resource
{
    protected static ?string $model = ZoneFactor::class;

    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Hệ số khu vực';
    protected static ?string $modelLabel      = 'Hệ số khu vực';
    protected static ?int    $navigationSort  = 73;

    public static function canDelete($r): bool { return false; }
    protected static function hasViewPage(): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('zone_type')
                ->label('Loại khu vực')
                ->helperText('Vd: entrance, escalator, food_court, checkout, facade, roadside')
                ->required()
                ->disabled(fn ($record) => (bool) $record)
                ->dehydrated(true)
                ->maxLength(50),

            Forms\Components\TextInput::make('factor')
                ->label('Hệ số')
                ->helperText('Khoảng (0, 2]. Multiplier áp dụng cho indoor screens theo zone.')
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->maxValue(2)
                ->step(0.01),

            Forms\Components\Textarea::make('note')->rows(2)->maxLength(500),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('zone_type')->label('Khu vực')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('factor')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->color(fn (float $state) => $state >= 1.0 ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('note')->wrap()->limit(60)->toggleable(),
                Tables\Columns\TextColumn::make('updated_by')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->since()->sortable(),
            ])
            ->defaultSort('factor', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (ZoneFactor $record, array $data) {
                        app(ConfigManagerService::class)->updateCoefficient(
                            'zone',
                            $record->zone_type,
                            (float) $data['factor'],
                            $data['note'] ?? null,
                        );
                        Notification::make()->title("Updated {$record->zone_type}")->success()->send();
                        return $record->fresh();
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Thêm khu vực')
                    ->using(function (array $data) {
                        app(ConfigManagerService::class)->updateCoefficient(
                            'zone',
                            $data['zone_type'],
                            (float) $data['factor'],
                            $data['note'] ?? null,
                        );
                        return ZoneFactor::find($data['zone_type']);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZoneFactors::route('/'),
        ];
    }
}
