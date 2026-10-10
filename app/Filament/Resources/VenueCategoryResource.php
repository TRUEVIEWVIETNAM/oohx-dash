<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VenueCategoryResource\Pages;
use App\Filament\Resources\VenueCategoryResource\RelationManagers;
use App\Models\VenueCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class VenueCategoryResource extends Resource
{
    protected static ?string $model = VenueCategory::class;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationGroup = 'Cấu hình hệ thống';
    protected static ?string $navigationLabel = 'Danh mục Việt Nam';
    protected static ?string $modelLabel = 'Danh mục Việt Nam';
    protected static ?string $pluralModelLabel = 'Danh mục DOOH Việt Nam';
    protected static ?int $navigationSort = 9;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin danh mục')->columns(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Tên (tiếng Anh)')
                    ->required()
                    ->maxLength(100),

                Forms\Components\TextInput::make('name_vi')
                    ->label('Tên tiếng Việt')
                    ->required()
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (callable $set, ?string $state) => $set('slug', \Illuminate\Support\Str::slug($state, '-', 'vi'))),

                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->unique(VenueCategory::class, 'slug', ignoreRecord: true)
                    ->maxLength(100)
                    ->helperText('Tự động từ tên tiếng Việt'),

                Forms\Components\TextInput::make('icon')
                    ->label('Biểu tượng Material')
                    ->required()
                    ->maxLength(50)
                    ->helperText('Tên biểu tượng Material Icons — vd: "storefront", "directions_bus"')
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('preview_icon')
                            ->icon('heroicon-o-eye')
                            ->tooltip('Xem trước biểu tượng tại fonts.google.com/icons')
                    ),

                Forms\Components\FileUpload::make('thumb')
                    ->label('Ảnh thumbnail')
                    ->image()
                    ->directory('venue-categories')
                    ->disk('public')
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('16:9')
                    ->imageResizeTargetWidth('800')
                    ->imageResizeTargetHeight('450')
                    ->maxSize(51200)
                    ->columnSpan(2)
                    ->helperText('Ảnh đại diện cho danh mục, hiển thị ở trang chủ. Khuyến nghị 800×450px, tối đa 50MB.'),

                Forms\Components\Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(2)
                    ->columnSpan(2)
                    ->maxLength(500),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Thứ tự hiển thị')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),

                Forms\Components\Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->default(true)
                    ->helperText('Hiển thị trên frontpage và filter'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->width('50px')
                    ->alignCenter(),

                Tables\Columns\ImageColumn::make('thumb')
                    ->label('Ảnh nhỏ')
                    ->disk('public')
                    ->width(60)
                    ->height(40)
                    ->defaultImageUrl('https://placehold.co/60x40/f5f5f7/999?text=—'),

                Tables\Columns\TextColumn::make('icon')
                    ->label('Biểu tượng')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Tên (tiếng Anh)')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name_vi')
                    ->label('Tên VN')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->color('gray')
                    ->copyable(),

                Tables\Columns\TextColumn::make('venue_types_count')
                    ->label('Loại theo OpenOOH')
                    ->counts('venueTypes')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('screen_count')
                    ->label('Màn hình')
                    ->alignCenter()
                    ->badge()
                    ->color('success')
                    ->getStateUsing(function (VenueCategory $record): int {
                        return DB::table('screens')
                            ->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
                            ->join('venue_types', 'screen_inventory.venue_type', '=', 'venue_types.string_value')
                            ->where('venue_types.vn_category_id', $record->id)
                            ->where('screens.active', true)
                            ->count();
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Đang bật')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Đang bật'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('toggle_active')
                        ->label(fn (VenueCategory $r) => $r->is_active ? 'Deactivate' : 'Activate')
                        ->icon(fn (VenueCategory $r) => $r->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                        ->color(fn (VenueCategory $r) => $r->is_active ? 'warning' : 'success')
                        ->action(fn (VenueCategory $r) => $r->update(['is_active' => ! $r->is_active])),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Bật các mục đã chọn')
                        ->icon('heroicon-o-eye')
                        ->action(fn ($records) => $records->each->update(['is_active' => true])),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Tắt các mục đã chọn')
                        ->icon('heroicon-o-eye-slash')
                        ->action(fn ($records) => $records->each->update(['is_active' => false])),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('reseed')
                    ->label('Đồng bộ lại danh mục Việt Nam')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Sẽ re-seed 12 danh mục VN DOOH và cập nhật mapping venue_types → vn_category_id.')
                    ->action(function () {
                        \Artisan::call('db:seed', ['--class' => 'VenueCategorySeeder', '--force' => true]);
                        \Filament\Notifications\Notification::make()
                            ->title('Re-sync VN Categories hoàn tất')
                            ->success()->send();
                    }),
            ])
            ->emptyStateHeading('Chưa có danh mục VN nào')
            ->emptyStateDescription('Chạy seeder để tạo 12 danh mục DOOH Việt Nam')
            ->emptyStateActions([
                Tables\Actions\Action::make('seed')
                    ->label('Nhập danh mục Việt Nam')
                    ->action(function () {
                        \Artisan::call('db:seed', ['--class' => 'VenueCategorySeeder', '--force' => true]);
                        \Filament\Notifications\Notification::make()
                            ->title('Import hoàn tất')->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VenueTypesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVenueCategories::route('/'),
            'create' => Pages\CreateVenueCategory::route('/create'),
            'edit'   => Pages\EditVenueCategory::route('/{record}/edit'),
        ];
    }
}
