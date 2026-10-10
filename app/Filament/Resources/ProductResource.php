<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Network;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Screen;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Sàn';

    protected static ?string $navigationLabel = 'Sản phẩm';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin cơ bản')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Tên sản phẩm')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('VD: Billboard LED Nguyễn Huệ')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (callable $set, ?string $state) => $set('slug', \Illuminate\Support\Str::slug($state, '-', 'vi'))),
                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->unique(ignoreRecord: true)
                        ->helperText('Tự động từ tên'),
                ]),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('type')
                        ->label('Loại')
                        ->options(Product::TYPE_LABELS)
                        ->required()
                        ->reactive(),
                    Forms\Components\Select::make('category')
                        ->label('Danh mục')
                        ->options(Product::CATEGORIES)
                        ->required()
                        ->searchable(),
                    Forms\Components\Select::make('listing_mode')
                        ->label('Chế độ bán')
                        ->options(Product::LISTING_MODES)
                        ->required()
                        ->reactive()
                        ->helperText('Cho phép buyer mua cả gói, mua lẻ, hoặc cả hai'),
                ]),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('owner_id')
                        ->label('Media owner')
                        ->options(Owner::active()->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->reactive(),
                    Forms\Components\Select::make('network_id')
                        ->label('Mạng lưới')
                        ->options(fn (Forms\Get $get) => $get('owner_id')
                            ? Network::where('owner_id', $get('owner_id'))->pluck('name', 'id')
                            : [])
                        ->searchable()
                        ->nullable(),
                    Forms\Components\Select::make('site_id')
                        ->label('Địa điểm')
                        ->options(fn (Forms\Get $get) => $get('owner_id')
                            ? Site::where('owner_id', $get('owner_id'))->pluck('name', 'id')
                            : [])
                        ->searchable()
                        ->nullable(),
                ]),
            ]),

            Forms\Components\Section::make('Giá & Số lượng')->schema([
                Forms\Components\Grid::make(4)->schema([
                    Forms\Components\TextInput::make('floor_price')
                        ->label('Giá gói')
                        ->prefix('₫')
                        ->formatStateUsing(fn ($state) => $state !== null ? (int) round((float) $state) : null)
                        ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
                        ->stripCharacters('.')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->helperText('Giá cả gói / giá mặc định'),
                    Forms\Components\TextInput::make('individual_price')
                        ->label('Giá lẻ / screen')
                        ->prefix('₫')
                        ->formatStateUsing(fn ($state) => $state !== null ? (int) round((float) $state) : null)
                        ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
                        ->stripCharacters('.')
                        ->numeric()
                        ->minValue(0)
                        ->nullable()
                        ->visible(fn (Forms\Get $get) => in_array($get('listing_mode'), ['individual_only', 'both']))
                        ->helperText('Giá khi buyer mua từng screen'),
                    Forms\Components\TextInput::make('package_discount_pct')
                        ->label('Giảm giá gói (%)')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->visible(fn (Forms\Get $get) => $get('listing_mode') === 'both')
                        ->helperText('% giảm khi mua cả gói so với mua lẻ'),
                    Forms\Components\Select::make('price_unit')
                        ->label('Đơn vị')
                        ->options(['month'=>'Tháng','week'=>'Tuần','day'=>'Ngày','campaign'=>'Campaign'])
                        ->default('month'),
                ]),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('min_quantity')
                        ->label('SL tối thiểu')
                        ->numeric()
                        ->default(1)
                        ->minValue(1),
                    Forms\Components\TextInput::make('max_quantity')
                        ->label('SL tối đa')
                        ->numeric()
                        ->nullable()
                        ->minValue(1),
                    Forms\Components\TextInput::make('total_units')
                        ->label('Tổng đơn vị')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->helperText('Tổng screens/items khả dụng'),
                ]),
                Forms\Components\Repeater::make('package_options')
                    ->label('Gói booking (chỉ cho type Package)')
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Tên gói')->required(),
                        Forms\Components\TextInput::make('quantity')->label('Số lượng')->numeric()->minValue(1)->required(),
                        Forms\Components\TextInput::make('price')->label('Giá (₫)')->prefix('₫')->formatStateUsing(fn ($state) => $state !== null ? (int) round((float) $state) : null)->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))->stripCharacters(',')->numeric()->minValue(0)->required(),
                    ])
                    ->columns(3)
                    ->visible(fn (Forms\Get $get) => $get('type') === 'package')
                    ->collapsible()
                    ->defaultItems(0),
            ]),

            Forms\Components\Section::make('Nội dung')->schema([
                Forms\Components\Textarea::make('short_description')
                    ->label('Mô tả ngắn')
                    ->rows(2)
                    ->maxLength(300),
                Forms\Components\RichEditor::make('description')
                    ->label('Mô tả chi tiết'),
                Forms\Components\FileUpload::make('cover_photo')
                    ->label('Ảnh bìa')
                    ->image()
                    ->directory('products/covers')
                    ->disk('public')
                    ->maxSize(51200),
                Forms\Components\FileUpload::make('photos')
                    ->label('Thư viện ảnh')
                    ->image()
                    ->multiple()
                    ->directory('products/photos')
                    ->disk('public')
                    ->maxSize(51200)
                    ->reorderable(),
            ]),

            Forms\Components\Section::make('Thông số kỹ thuật')->schema([
                Forms\Components\KeyValue::make('specs')
                    ->label('Thông số')
                    ->keyLabel('Thuộc tính')
                    ->valueLabel('Giá trị')
                    ->helperText('VD: material → hiflex, size_m → 12x4, resolution → P4'),
            ])->collapsible(),

            Forms\Components\Section::make('Vị trí & Trạng thái')->schema([
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('city')->label('Thành phố'),
                    Forms\Components\TextInput::make('region')->label('Khu vực'),
                    Forms\Components\Select::make('status')
                        ->label('Trạng thái')
                        ->options(Product::STATUS_LABELS)
                        ->default('draft')
                        ->required(),
                ]),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Toggle::make('featured')->label('Nổi bật'),
                    Forms\Components\TextInput::make('sort_order')->label('Thứ tự')->numeric()->default(0),
                ]),
            ]),

            Forms\Components\Section::make('Gắn Screens')->schema([
                Forms\Components\Select::make('screenIds')
                    ->label('Màn hình')
                    ->multiple()
                    ->options(fn (Forms\Get $get) => $get('owner_id')
                        ? Screen::withoutGlobalScope('owner_scope')
                            ->where('owner_id', $get('owner_id'))
                            ->where('active', true)
                            ->pluck('name', 'id')
                        : [])
                    ->searchable()
                    ->preload()
                    ->helperText('Chọn screens thuộc product này (chọn Owner trước)')
                    ->afterStateHydrated(function (Forms\Components\Select $component, ?Product $record) {
                        if ($record) {
                            $component->state($record->screens()->pluck('screens.id')->toArray());
                        }
                    }),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover_photo')
                    ->label('Ảnh')
                    ->disk('public')
                    ->width(56)
                    ->height(42)
                    ->defaultImageUrl('https://placehold.co/56x42/F5F5F7/999?text=—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Sản phẩm')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(40),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Chủ sở hữu')
                    ->sortable()
                    ->limit(20),

                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Product::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'single' => 'info', 'package' => 'success', 'custom' => 'warning', default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('category')
                    ->label('Danh mục')
                    ->formatStateUsing(fn (string $state) => Product::CATEGORIES[$state] ?? $state),

                Tables\Columns\TextColumn::make('listing_mode')
                    ->label('Chế độ bán')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Product::LISTING_MODES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'package_only' => 'info', 'individual_only' => 'success', 'both' => 'warning', default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('floor_price')
                    ->label('Giá')
                    ->money('VND')
                    ->sortable(),

                Tables\Columns\TextColumn::make('screens_count')
                    ->label('Màn hình')
                    ->counts('screens')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Product::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success', 'draft' => 'gray', 'paused' => 'warning', 'sold_out' => 'danger', default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('featured')
                    ->label('Nổi bật')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(Product::TYPE_LABELS),
                Tables\Filters\SelectFilter::make('category')
                    ->options(Product::CATEGORIES),
                Tables\Filters\SelectFilter::make('status')
                    ->options(Product::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('owner_id')
                    ->label('Chủ sở hữu')
                    ->options(Owner::active()->pluck('name', 'id'))
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view'   => Pages\ViewProduct::route('/{record}'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
