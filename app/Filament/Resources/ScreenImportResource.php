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
    protected static ?string $navigationLabel = 'Screen imports';
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?int    $navigationSort  = 10;

    protected static ?string $modelLabel       = 'Screen import';
    protected static ?string $pluralModelLabel = 'Screen imports';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('File')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->original_filename)
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
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

                Tables\Columns\TextColumn::make('total_rows')
                    ->label('Rows')
                    ->numeric()
                    ->alignRight()
                    ->sortable(),

                Tables\Columns\TextColumn::make('success_count')
                    ->label('Imported')
                    ->numeric()
                    ->alignRight()
                    ->color('success'),

                Tables\Columns\TextColumn::make('failed_count')
                    ->label('Failed')
                    ->numeric()
                    ->alignRight()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('uploader.name')
                    ->label('Uploaded by')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'uploaded'  => 'Uploaded',
                        'mapping'   => 'Mapping',
                        'previewed' => 'Previewed',
                        'importing' => 'Importing',
                        'done'      => 'Done',
                        'failed'    => 'Failed',
                        'cancelled' => 'Cancelled',
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
