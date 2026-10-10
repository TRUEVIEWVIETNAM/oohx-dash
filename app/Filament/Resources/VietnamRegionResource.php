<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VietnamRegionResource\Pages;
use App\Models\VietnamRegion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VietnamRegionResource extends Resource
{
    protected static ?string $model            = VietnamRegion::class;
    protected static ?string $navigationIcon   = null;
    protected static ?string $navigationGroup  = 'Cấu hình hệ thống';
    protected static ?string $navigationLabel  = 'Vùng kinh tế';
    protected static ?string $modelLabel       = 'Vùng kinh tế';
    protected static ?string $pluralModelLabel = 'Vùng kinh tế';
    protected static ?int    $navigationSort   = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Mã vùng')
                    ->required()->maxLength(10)
                    ->unique(VietnamRegion::class, 'code', ignoreRecord: true)
                    ->helperText('VD: TAY_BAC, DBSH, BTB'),

                Forms\Components\TextInput::make('sort')
                    ->label('Thứ tự hiển thị')
                    ->numeric()->default(0),

                Forms\Components\TextInput::make('name')
                    ->label('Tên ngắn')
                    ->required()->maxLength(100)
                    ->helperText('VD: Tây Bắc'),

                Forms\Components\TextInput::make('full_name')
                    ->label('Tên đầy đủ')
                    ->required()->maxLength(150)
                    ->helperText('VD: Vùng Tây Bắc'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort')
                    ->label('#')->sortable()->width('60px'),
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Tên vùng')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('provinces_count')
                    ->label('Tỉnh/Thành')->counts('provinces')->sortable(),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVietnamRegions::route('/'),
            'create' => Pages\CreateVietnamRegion::route('/create'),
            'edit'   => Pages\EditVietnamRegion::route('/{record}/edit'),
        ];
    }
}
