<?php

namespace App\Filament\Resources\OohxConfig;

use App\Filament\Resources\OohxConfig\RoadClassMultiplierResource\Pages;
use App\Models\Oohx\Config\RoadClassMultiplier;
use App\Services\Oohx\ConfigManagerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RoadClassMultiplierResource extends Resource
{
    protected static ?string $model = RoadClassMultiplier::class;

    protected static ?string $navigationIcon  = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Hệ số theo cấp đường';
    protected static ?string $modelLabel      = 'Hệ số theo đường';
    protected static ?int    $navigationSort  = 72;

    public static function canDelete($r): bool { return false; }
    protected static function hasViewPage(): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('road_class')
                ->label('Cấp đường')
                ->helperText('Cấp đường theo OSM — vd: highway, primary, secondary, tertiary, residential, service')
                ->required()
                ->disabled(fn ($record) => (bool) $record)
                ->dehydrated(true)
                ->maxLength(50),

            Forms\Components\TextInput::make('multiplier')
                ->label('Hệ số nhân')
                ->helperText('Khoảng (0, 5]. 1.0 = neutral, > 1 amplify, < 1 dampen.')
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->maxValue(5)
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
                Tables\Columns\TextColumn::make('road_class')
                    ->label('Cấp đường')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('multiplier')
                    ->label('Hệ số nhân')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->color(fn (float $state) => match (true) {
                        $state >= 2.0 => 'success',
                        $state >= 1.0 => 'info',
                        default       => 'gray',
                    }),

                Tables\Columns\TextColumn::make('note')->wrap()->limit(60)->toggleable(),
                Tables\Columns\TextColumn::make('updated_by')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->since()->sortable(),
            ])
            ->defaultSort('multiplier', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (RoadClassMultiplier $record, array $data) {
                        app(ConfigManagerService::class)->updateCoefficient(
                            'road_class',
                            $record->road_class,
                            (float) $data['multiplier'],
                            $data['note'] ?? null,
                        );
                        Notification::make()->title("Updated {$record->road_class}")->success()->send();
                        return $record->fresh();
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Thêm cấp đường')
                    ->using(function (array $data) {
                        app(ConfigManagerService::class)->updateCoefficient(
                            'road_class',
                            $data['road_class'],
                            (float) $data['multiplier'],
                            $data['note'] ?? null,
                        );
                        return RoadClassMultiplier::find($data['road_class']);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoadClassMultipliers::route('/'),
        ];
    }
}
