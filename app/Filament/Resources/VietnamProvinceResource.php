<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VietnamProvinceResource\Pages;
use App\Models\VietnamProvince;
use App\Models\VietnamRegion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class VietnamProvinceResource extends Resource
{
    protected static ?string $model            = VietnamProvince::class;
    protected static ?string $navigationIcon   = null;
    protected static ?string $navigationGroup  = 'Cấu hình hệ thống';
    protected static ?string $navigationLabel  = 'Tỉnh / Thành phố';
    protected static ?string $modelLabel       = 'Tỉnh / Thành phố';
    protected static ?string $pluralModelLabel = 'Tỉnh / Thành phố';
    protected static ?int    $navigationSort   = 1;

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([

                Forms\Components\TextInput::make('code')
                    ->label('Mã tỉnh/thành')
                    ->required()
                    ->maxLength(10)
                    ->unique(VietnamProvince::class, 'code', ignoreRecord: true)
                    ->helperText('VD: 01 (Hà Nội), 79 (TP.HCM)'),

                Forms\Components\Select::make('type')
                    ->label('Loại')
                    ->options(['tinh' => 'Tỉnh', 'thanh_pho' => 'Thành phố trực thuộc TW'])
                    ->default('tinh')
                    ->required(),

                Forms\Components\TextInput::make('name')
                    ->label('Tên ngắn')
                    ->required()
                    ->maxLength(100)
                    ->helperText('VD: Hà Nội, Hồ Chí Minh'),

                Forms\Components\TextInput::make('full_name')
                    ->label('Tên đầy đủ')
                    ->required()
                    ->maxLength(150)
                    ->helperText('VD: Thành phố Hà Nội, Tỉnh Hà Giang'),

                Forms\Components\Select::make('region_id')
                    ->label('Vùng kinh tế')
                    ->options(fn() => VietnamRegion::orderBy('sort')->pluck('full_name', 'id'))
                    ->searchable()
                    ->nullable(),

                Forms\Components\FileUpload::make('photo_url')
                    ->label('Ảnh đại diện')
                    ->image()
                    ->directory('provinces')
                    ->disk('public')
                    ->maxSize(51200)
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('4:3')
                    ->imageResizeTargetWidth('800')
                    ->imageResizeTargetHeight('600')
                    ->helperText('Ảnh đại diện tỉnh/thành (4:3, tối đa 50MB)'),

            ]),
        ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo_url')
                    ->label('Ảnh')
                    ->disk('public')
                    ->width(48)
                    ->height(36)
                    ->defaultImageUrl('https://placehold.co/48x36/F5F5F7/999?text=—'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')->sortable()->searchable()->badge()->color('gray'),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Tên đầy đủ')->searchable()->sortable()->wrap(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn(string $state) => $state === 'thanh_pho' ? 'Thành phố TT TW' : 'Tỉnh')
                    ->color(fn(string $state) => $state === 'thanh_pho' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('region.name')
                    ->label('Vùng')->sortable()->searchable()->badge()->color('info'),

                Tables\Columns\TextColumn::make('communes_count')
                    ->label('Số phường/xã')
                    ->counts('communes')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sites_count')
                    ->label('Địa điểm')
                    ->counts('sites')
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại')
                    ->options(['tinh' => 'Tỉnh', 'thanh_pho' => 'Thành phố TT TW']),

                SelectFilter::make('region_id')
                    ->label('Vùng kinh tế')
                    ->options(fn() => VietnamRegion::orderBy('sort')->pluck('full_name', 'id'))
                    ->searchable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('import_excel')
                    ->label('Nhập từ Excel')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->url(fn() => route('filament.admin.resources.vietnam-provinces.import'))
                    ->visible(false), // Dùng artisan command: php artisan vietnam:import
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->before(function (VietnamProvince $record, Tables\Actions\DeleteAction $action) {
                            if ($record->communes()->exists() || $record->sites()->exists()) {
                                Notification::make()
                                    ->danger()
                                    ->title('Không thể xóa')
                                    ->body('Tỉnh này có phường/xã hoặc sites liên quan.')
                                    ->send();
                                $action->cancel();
                            }
                        }),
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
            'index'  => Pages\ListVietnamProvinces::route('/'),
            'create' => Pages\CreateVietnamProvince::route('/create'),
            'edit'   => Pages\EditVietnamProvince::route('/{record}/edit'),
        ];
    }
}
