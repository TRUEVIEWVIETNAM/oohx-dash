<?php

namespace App\Filament\Resources\OohxConfig;

use App\Filament\Resources\OohxConfig\BaseCityTrafficResource\Pages;
use App\Models\Oohx\Config\BaseCityTraffic;
use App\Services\Oohx\ConfigManagerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Resource cho config.base_city_traffic.
 *
 * Edit-only flow (no create — list các city Data Engine biết, ops chỉ tune values).
 * Mọi UPDATE đi qua ConfigManagerService::updateCoefficient để đảm bảo audit log.
 */
class BaseCityTrafficResource extends Resource
{
    protected static ?string $model = BaseCityTraffic::class;

    protected static ?string $navigationIcon  = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Lưu lượng nền theo tỉnh/thành';
    protected static ?string $modelLabel      = 'Mức nền theo tỉnh/thành';
    protected static ?int    $navigationSort  = 71;

    public static function canDelete($r): bool { return false; }

    protected static function hasViewPage(): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('city')
                ->label('Tỉnh/thành')
                ->required()
                ->disabled(fn ($record) => (bool) $record) // PK không edit được sau create
                ->dehydrated(true)
                ->maxLength(100),

            Forms\Components\TextInput::make('baseline_passby')
                ->label('Lượt qua điểm mỗi ngày (mức nền)')
                ->helperText('Khoảng [0, 1,000,000]. Dùng làm điểm xuất phát cho công thức outdoor.')
                ->required()
                ->numeric()
                ->minValue(0)
                ->maxValue(1_000_000)
                ->step(1),

            Forms\Components\Textarea::make('note')
                ->label('Note (lý do thay đổi)')
                ->rows(2)
                ->maxLength(500),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('city')
                    ->label('Tỉnh/thành')
                    ->searchable()
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('baseline_passby')
                    ->label('Mức nền')
                    ->numeric(decimalPlaces: 0, thousandsSeparator: ',')
                    ->sortable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Ghi chú')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_by')
                    ->label('Người sửa')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật lúc')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('city')
            ->actions([
                Tables\Actions\EditAction::make()
                    // Override save để route qua ConfigManagerService (audit + atomic)
                    ->using(function (BaseCityTraffic $record, array $data) {
                        app(ConfigManagerService::class)->updateCoefficient(
                            'base_city_traffic',
                            $record->city,
                            (float) $data['baseline_passby'],
                            $data['note'] ?? null,
                        );
                        Notification::make()
                            ->title("Updated baseline for {$record->city}")
                            ->success()
                            ->send();
                        return $record->fresh();
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Thêm tỉnh/thành')
                    ->using(function (array $data) {
                        app(ConfigManagerService::class)->updateCoefficient(
                            'base_city_traffic',
                            $data['city'],
                            (float) $data['baseline_passby'],
                            $data['note'] ?? null,
                        );
                        return BaseCityTraffic::find($data['city']);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBaseCityTraffic::route('/'),
        ];
    }
}
