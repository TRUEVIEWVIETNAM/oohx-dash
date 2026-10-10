<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VenueTypeResource\Pages;
use App\Models\VenueCategory;
use App\Models\VenueType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;

class VenueTypeResource extends Resource
{
    protected static ?string $model          = VenueType::class;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationGroup = 'Cấu hình hệ thống';
    protected static ?string $navigationLabel = 'Phân loại địa điểm';
    protected static ?string $modelLabel      = 'Loại địa điểm';
    protected static ?string $pluralModelLabel = 'Phân loại địa điểm';
    protected static ?int    $navigationSort  = 10;

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Phân loại OpenOOH')->columns(2)->schema([

                Forms\Components\TextInput::make('enumeration_id')
                    ->label('Mã enum')
                    ->numeric()
                    ->unique(VenueType::class, 'enumeration_id', ignoreRecord: true)
                    ->helperText('Mã chuẩn OpenOOH — vd: 10101'),

                Forms\Components\TextInput::make('string_value')
                    ->label('Giá trị chuỗi')
                    ->unique(VenueType::class, 'string_value', ignoreRecord: true)
                    ->helperText('vd: transit.airports.arrivals_hall')
                    ->maxLength(255),

                Forms\Components\Select::make('parent_id')
                    ->label('Danh mục cha')
                    ->options(fn() => VenueType::whereDoesntHave('children', fn($q) => $q->whereNotNull('id'))
                        ->orWhereHas('children')
                        ->orderBy('depth')->orderBy('category')->orderBy('venue_type')
                        ->get()
                        ->mapWithKeys(fn($vt) => [$vt->id => str_repeat('— ', $vt->depth) . $vt->venue_type])
                        ->toArray())
                    ->searchable()
                    ->nullable()
                    ->placeholder('Danh mục gốc (không có cha)')
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        if ($state) {
                            $parent = VenueType::find($state);
                            if ($parent) {
                                $set('depth', $parent->depth + 1);
                                $set('category', $parent->category);
                            }
                        } else {
                            $set('depth', 0);
                        }
                    }),

                Forms\Components\TextInput::make('depth')
                    ->label('Cấp sâu')
                    ->numeric()
                    ->default(0)
                    ->disabled()
                    ->dehydrated()
                    ->helperText('0 = gốc, 1 = danh mục, 2 = danh mục con'),

                Forms\Components\TextInput::make('category')
                    ->label('Danh mục')
                    ->required()
                    ->maxLength(100),

                Forms\Components\TextInput::make('subcategory')
                    ->label('Danh mục con')
                    ->nullable()
                    ->maxLength(100),

                Forms\Components\TextInput::make('venue_type')
                    ->label('Loại địa điểm (đường dẫn đầy đủ)')
                    ->required()
                    ->unique(VenueType::class, 'venue_type', ignoreRecord: true)
                    ->maxLength(255)
                    ->columnSpan(2)
                    ->helperText('vd: Transit : Airports : Arrival Hall'),

                Forms\Components\Textarea::make('description')
                    ->label('Mô tả')
                    ->rows(2)
                    ->columnSpan(2),

            ]),

            Forms\Components\Section::make('Danh mục DOOH Việt Nam')->columns(2)->schema([
                Forms\Components\Select::make('vn_category_id')
                    ->label('Danh mục VN')
                    ->relationship('vnCategory', 'name_vi')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->placeholder('Chưa gán danh mục VN')
                    ->helperText('Gán venue type này vào 1 trong 12 danh mục DOOH Việt Nam')
                    ->columnSpan(2),
            ]),

            Forms\Components\Section::make('Cài đặt')->columns(2)->schema([
                Forms\Components\Toggle::make('hivestack_supported')
                    ->label('Hỗ trợ Hivestack')
                    ->default(true)
                    ->helperText('Hiển thị trong Hivestack import mapping'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Đang bật')
                    ->default(true)
                    ->helperText('Hiển thị trong form chọn venue type'),
            ]),
        ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('enumeration_id')
                    ->label('ID')
                    ->sortable()
                    ->searchable()
                    ->width('80px'),

                Tables\Columns\TextColumn::make('venue_type')
                    ->label('Loại địa điểm')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn(VenueType $record) =>
                        str_repeat('— ', $record->depth) . $record->venue_type
                    )
                    ->description(fn(VenueType $record) => $record->string_value),

                Tables\Columns\TextColumn::make('category')
                    ->label('Danh mục')
                    ->searchable()
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('depth')
                    ->label('Cấp')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ((int)$state) {
                        0 => 'Root',
                        1 => 'Category',
                        2 => 'Sub',
                        default => "L{$state}",
                    })
                    ->color(fn($state) => match ((int)$state) {
                        0 => 'danger',
                        1 => 'warning',
                        2 => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('vnCategory.name_vi')
                    ->label('Danh mục Việt Nam')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('children_count')
                    ->label('Danh mục con')
                    ->counts('children')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Đang bật')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\IconColumn::make('hivestack_supported')
                    ->label('Hivestack')
                    ->boolean(),
            ])
            ->defaultSort('enumeration_id', 'asc')
            ->filters([
                SelectFilter::make('category')
                    ->options(fn() => VenueType::distinct()->pluck('category', 'category')),

                SelectFilter::make('depth')
                    ->label('Cấp')
                    ->options([0 => 'Root', 1 => 'Category', 2 => 'Subcategory']),

                SelectFilter::make('vn_category_id')
                    ->label('Danh mục Việt Nam')
                    ->relationship('vnCategory', 'name_vi')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')->label('Đang bật'),
                TernaryFilter::make('hivestack_supported')->label('Hỗ trợ Hivestack'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('toggle_active')
                        ->label(fn(VenueType $r) => $r->is_active ? 'Deactivate' : 'Activate')
                        ->icon(fn(VenueType $r) => $r->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                        ->color(fn(VenueType $r) => $r->is_active ? 'warning' : 'success')
                        ->action(fn(VenueType $r) => $r->update(['is_active' => !$r->is_active])),
                    Tables\Actions\DeleteAction::make()
                        ->before(function (VenueType $record) {
                            if ($record->children()->exists()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Không thể xoá — còn ' . $record->children()->count() . ' children')
                                    ->danger()->send();
                                $this->halt();
                            }
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Bật các mục đã chọn')
                        ->icon('heroicon-o-eye')
                        ->action(fn($records) => $records->each->update(['is_active' => true])),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Tắt các mục đã chọn')
                        ->icon('heroicon-o-eye-slash')
                        ->action(fn($records) => $records->each->update(['is_active' => false])),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('reseed')
                    ->label('Đồng bộ lại từ OpenOOH v1.1')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Sẽ xoá toàn bộ venue types hiện tại và seed lại từ OpenOOH Taxonomy v1.1. Dữ liệu custom sẽ mất.')
                    ->action(function () {
                        \Artisan::call('db:seed', ['--class' => 'OpenOOHVenueTypeSeeder', '--force' => true]);
                        \Filament\Notifications\Notification::make()
                            ->title('Re-sync hoàn tất')
                            ->success()->send();
                    }),
            ])
            ->emptyStateHeading('Chưa có venue type nào')
            ->emptyStateDescription('Chạy seeder để import OpenOOH Taxonomy v1.1')
            ->emptyStateActions([
                Tables\Actions\Action::make('seed')
                    ->label('Nhập phân loại OpenOOH')
                    ->action(function () {
                        \Artisan::call('db:seed', ['--class' => 'OpenOOHVenueTypeSeeder', '--force' => true]);
                        \Filament\Notifications\Notification::make()
                            ->title('Import hoàn tất')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVenueType::route('/'),
            'create' => Pages\CreateVenueType::route('/create'),
            'edit'   => Pages\EditVenueType::route('/{record}/edit'),
        ];
    }
}
