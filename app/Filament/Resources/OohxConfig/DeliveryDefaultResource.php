<?php

namespace App\Filament\Resources\OohxConfig;

use App\Filament\Resources\OohxConfig\DeliveryDefaultResource\Pages;
use App\Models\Oohx\Config\DeliveryDefault;
use App\Services\Oohx\ConfigManagerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DeliveryDefaultResource extends Resource
{
    protected static ?string $model = DeliveryDefault::class;

    protected static ?string $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Mặc định phân phối';
    protected static ?string $modelLabel      = 'Mặc định phân phối';
    protected static ?int    $navigationSort  = 74;

    public static function canDelete($r): bool { return false; }
    protected static function hasViewPage(): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('key')
                ->label('Khoá')
                ->options(array_combine(DeliveryDefault::KEYS, DeliveryDefault::KEYS))
                ->helperText('Khoá nằm trong danh sách cho phép của Data Engine. Giai đoạn 4.1 và 4.2.1 thêm 6 khoá mới.')
                ->required()
                ->disabled(fn ($record) => (bool) $record)
                ->dehydrated(true)
                ->live(),

            Forms\Components\TextInput::make('value')
                ->label('Giá trị')
                ->helperText(function (Forms\Get $get) {
                    $key = $get('key');
                    if (! $key) return 'Chọn key trước để xem range hợp lệ.';
                    [, , $label] = DeliveryDefault::rangeFor($key);
                    return "Range: {$label}. Phase 4.2.1 widened DB CHECK to 0..1,000,000.";
                })
                ->required()
                ->numeric()
                ->minValue(0)
                ->maxValue(1000000)   // Phase 4.2.1 migration 014 widened constraint
                ->step(0.001),

            Forms\Components\Textarea::make('description')
                ->label('Mô tả')
                ->helperText('Giải thích ý nghĩa value (tránh confuse khi audit).')
                ->rows(2)
                ->maxLength(500),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('value')->numeric(decimalPlaces: 4)->sortable(),
                Tables\Columns\TextColumn::make('description')->wrap()->limit(80)->toggleable(),
                Tables\Columns\TextColumn::make('updated_by')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->since()->sortable(),
            ])
            ->defaultSort('key')
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (DeliveryDefault $record, array $data) {
                        app(ConfigManagerService::class)->updateCoefficient(
                            'delivery_default',
                            $record->key,
                            (float) $data['value'],
                            $data['description'] ?? null,
                        );
                        Notification::make()->title("Updated {$record->key}")->success()->send();
                        return $record->fresh();
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Thêm giá trị mặc định')
                    ->using(function (array $data) {
                        app(ConfigManagerService::class)->updateCoefficient(
                            'delivery_default',
                            $data['key'],
                            (float) $data['value'],
                            $data['description'] ?? null,
                        );
                        return DeliveryDefault::find($data['key']);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryDefaults::route('/'),
        ];
    }
}
