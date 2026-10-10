<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScreenImportResource\Pages;
use App\Models\ScreenImport;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ScreenImportResource extends Resource
{
    protected static ?string $model = ScreenImport::class;

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Lần nhập màn hình';
    protected static ?string $navigationGroup = 'Kho điểm phát';
    protected static ?int    $navigationSort  = 10;

    protected static ?string $modelLabel       = 'Lần nhập màn hình';
    protected static ?string $pluralModelLabel = 'Lần nhập màn hình';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('Tệp')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->original_filename)
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
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

                Tables\Columns\TextColumn::make('total_rows')
                    ->label('Số dòng')
                    ->numeric()
                    ->alignRight()
                    ->sortable(),

                Tables\Columns\TextColumn::make('success_count')
                    ->label('Đã nhập')
                    ->numeric()
                    ->alignRight()
                    ->color('success'),

                Tables\Columns\TextColumn::make('failed_count')
                    ->label('Lỗi')
                    ->numeric()
                    ->alignRight()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('uploader.name')
                    ->label('Người tải lên')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ScreenImport::STATUS_UPLOADED  => 'Uploaded',
                        ScreenImport::STATUS_MAPPING   => 'Mapping',
                        ScreenImport::STATUS_PREVIEWED => 'Previewed',
                        ScreenImport::STATUS_IMPORTING => 'Importing',
                        ScreenImport::STATUS_DONE      => 'Done',
                        ScreenImport::STATUS_FAILED    => 'Failed',
                        ScreenImport::STATUS_CANCELLED => 'Cancelled',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScreenImports::route('/'),
            'view'  => Pages\ViewScreenImport::route('/{record}'),
        ];
    }
}
