<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VietnamCommuneResource\Pages;
use App\Models\VietnamCommune;
use App\Models\VietnamProvince;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VietnamCommuneResource extends Resource
{
    protected static ?string $model            = VietnamCommune::class;
    protected static ?string $navigationIcon   = null;
    protected static ?string $navigationGroup  = 'Cấu hình hệ thống';
    protected static ?string $navigationLabel  = 'Phường / Xã / Thị trấn';
    protected static ?string $modelLabel       = 'Phường / Xã';
    protected static ?string $pluralModelLabel = 'Phường / Xã / Thị trấn';
    protected static ?int    $navigationSort   = 2;

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([

                Forms\Components\Select::make('province_id')
                    ->label('Tỉnh / Thành phố')
                    ->options(fn() => VietnamProvince::orderByRaw("type = 'thanh_pho' DESC")
                        ->orderBy('name')
                        ->pluck('full_name', 'id'))
                    ->searchable()
                    ->required()
                    ->live(),

                Forms\Components\Select::make('type')
                    ->label('Loại')
                    ->options([
                        'phuong'   => 'Phường',
                        'xa'       => 'Xã',
                        'thi_tran' => 'Thị trấn',
                    ])
                    ->default('xa')
                    ->required(),

                Forms\Components\TextInput::make('code')
                    ->label('Mã xã/phường')
                    ->required()
                    ->maxLength(20)
                    ->unique(VietnamCommune::class, 'code', ignoreRecord: true)
                    ->helperText('VD: 00001'),

                Forms\Components\TextInput::make('name')
                    ->label('Tên ngắn')
                    ->required()
                    ->maxLength(150)
                    ->helperText('VD: Phúc Xá'),

                Forms\Components\TextInput::make('full_name')
                    ->label('Tên đầy đủ')
                    ->required()
                    ->maxLength(200)
                    ->helperText('VD: Phường Phúc Xá')
                    ->columnSpan(2),

            ]),
        ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')->sortable()->searchable()->badge()->color('gray'),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Tên đầy đủ')->searchable()->sortable()->wrap(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn(string $state) => match($state) {
                        'phuong'   => 'Phường',
                        'thi_tran' => 'Thị trấn',
                        default    => 'Xã',
                    })
                    ->color(fn(string $state) => match($state) {
                        'phuong'   => 'success',
                        'thi_tran' => 'warning',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('province.name')
                    ->label('Tỉnh/Thành')
                    ->sortable()->searchable(),

                Tables\Columns\TextColumn::make('sites_count')
                    ->label('Địa điểm')
                    ->counts('sites')
                    ->sortable(),
            ])
            ->defaultSort('province_id')
            ->filters([
                SelectFilter::make('province_id')
                    ->label('Tỉnh / Thành phố')
                    ->options(fn() => VietnamProvince::orderByRaw("type = 'thanh_pho' DESC")
                        ->orderBy('name')
                        ->pluck('full_name', 'id'))
                    ->searchable(),

                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([
                        'phuong'   => 'Phường',
                        'xa'       => 'Xã',
                        'thi_tran' => 'Thị trấn',
                    ]),
            ])
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
            'index'  => Pages\ListVietnamCommunes::route('/'),
            'create' => Pages\CreateVietnamCommune::route('/create'),
            'edit'   => Pages\EditVietnamCommune::route('/{record}/edit'),
        ];
    }
}
