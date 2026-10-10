<?php

namespace App\Filament\Shared\Resources;

use App\Models\Network;
use App\Models\Screen;
use App\Models\VenueCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lớp base canonical cho Networks.
 * Admin và Publisher chỉ override phần khác biệt.
 */
abstract class BaseNetworkResource extends Resource
{
    protected static ?string $model           = Network::class;
    protected static ?string $navigationIcon  = null;
    protected static ?string $navigationGroup = 'Kho điểm phát';
    protected static ?string $navigationLabel = 'Mạng lưới';
    protected static ?int    $navigationSort  = 3;

    // ── Hooks cho subclass ────────────────────────────────────────────────────

    /** Admin trả về Select::make('owner_id'); Publisher trả null (auto từ session). */
    protected static function ownerFormField(): ?Forms\Components\Component
    {
        return null;
    }

    /** Admin thêm cột owner.name vào bảng. */
    protected static function additionalTableColumns(): array
    {
        return [];
    }

    /** Admin thêm owner filter vào bảng. */
    protected static function additionalFilters(): array
    {
        return [];
    }

    /**
     * Publisher override để trả về true (có trang View).
     * Admin không có View page cho Network.
     */
    protected static function hasViewPage(): bool
    {
        return false;
    }

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        $ownerField = static::ownerFormField();

        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema(
                array_values(array_filter([
                    $ownerField,

                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan($ownerField ? 1 : 2)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (callable $set, callable $get, ?string $state) {
                            if ($state && ! $get('code')) {
                                $set('code', \Illuminate\Support\Str::slug($state));
                            }
                        }),

                    Forms\Components\TextInput::make('code')
                        ->label('Mã mạng lưới')
                        ->unique(Network::class, 'code', ignoreRecord: true)
                        ->maxLength(100)
                        ->helperText('Tự động từ tên. Dùng để liên kết screens.'),

                    Forms\Components\Select::make('vn_category_id')
                        ->label('Loại hình (Venue Category)')
                        ->options(fn () => VenueCategory::where('is_active', true)
                            ->orderBy('sort_order')
                            ->pluck('name_vi', 'id')
                            ->toArray())
                        ->required()
                        ->searchable()
                        ->helperText('Phân loại network theo danh mục OOHX. VD: Aeon Mall → Trung tâm thương mại'),

                    Forms\Components\Textarea::make('description')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('default_floor_cpm')
                        ->label('Giá sàn CPM mặc định')
                        ->formatStateUsing(fn ($state) => $state !== null ? (int) round((float) $state) : null)
                        ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                        ->stripCharacters(',')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Giá sàn CPM mặc định cho toàn network'),

                    Forms\Components\Select::make('default_floor_cpm_currency')
                        ->label('Tiền tệ')
                        ->options(['VND' => 'VND', 'USD' => 'USD'])
                        ->default('VND'),

                    Forms\Components\Select::make('status')
                        ->options(Network::STATUS_LABELS)
                        ->default('active'),
                ]))
            ),

            Forms\Components\Section::make('Hình ảnh')->columns(2)->schema([
                Forms\Components\FileUpload::make('logo')
                    ->label('Logo')
                    ->image()
                    ->directory('networks/logos')
                    ->disk('public')
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('400')
                    ->imageResizeTargetHeight('400')
                    ->maxSize(1024)
                    ->helperText('Logo network, nền trong suốt. Khuyến nghị 400×400px PNG, tối đa 1MB.'),

                Forms\Components\FileUpload::make('banner')
                    ->label('Ảnh bìa')
                    ->image()
                    ->directory('networks/banners')
                    ->disk('public')
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('21:9')
                    ->imageResizeTargetWidth('1200')
                    ->imageResizeTargetHeight('514')
                    ->maxSize(2048)
                    ->helperText('Ảnh bìa hiển thị trên trang chi tiết network. Khuyến nghị 1200×514px, tối đa 2MB.'),
            ]),
        ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...static::additionalTableColumns(),

                Tables\Columns\ImageColumn::make('logo')
                    ->label('Logo')
                    ->disk('public')
                    ->width(36)
                    ->height(36)
                    ->circular()
                    ->defaultImageUrl(fn (Network $record) =>
                        'https://ui-avatars.com/api/?name=' . urlencode($record->name) . '&size=36&background=E5007D&color=fff&bold=true'
                    ),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('vnCategory.name_vi')
                    ->label('Loại hình')
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('default_floor_cpm')
                    ->label('Giá sàn CPM')
                    ->numeric(thousandsSeparator: ',')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('default_floor_cpm_currency')
                    ->label('Tiền tệ')
                    ->badge(),

                Tables\Columns\TextColumn::make('sites_count')
                    ->label('Địa điểm')
                    ->counts('sites')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('screens_count')
                    ->label('Màn hình')
                    ->counts('screens')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn ($state) => Network::STATUS_LABELS[$state] ?? $state ?? '—')
                    ->colors(['success' => 'active', 'warning' => 'paused']),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật lúc')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('vn_category_id')
                    ->label('Loại hình')
                    ->options(fn () => VenueCategory::where('is_active', true)
                        ->orderBy('sort_order')
                        ->pluck('name_vi', 'id')
                        ->toArray()),

                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(Network::STATUS_LABELS),

                SelectFilter::make('default_floor_cpm_currency')
                    ->label('Tiền tệ')
                    ->options(['VND' => 'VND', 'USD' => 'USD']),

                TernaryFilter::make('has_screens')
                    ->label('Có màn hình')
                    ->queries(
                        true:  fn (Builder $query) => $query->whereHas('screens'),
                        false: fn (Builder $query) => $query->whereDoesntHave('screens'),
                    ),

                ...static::additionalFilters(),
            ])
            ->filtersFormColumns(3)
            ->recordUrl(fn (Network $record) =>
                static::hasViewPage() ? static::getUrl('view', ['record' => $record]) : null
            )
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->modalDescription(function (Network $record) {
                            $siteCount = $record->sites()->count();
                            $screenCount = Screen::whereIn('site_id', $record->sites()->pluck('id'))->count();
                            $msg = "Bạn có chắc muốn xoá network \"{$record->name}\"?";
                            if ($siteCount > 0) {
                                $msg .= "\n\nSẽ đồng thời xoá {$siteCount} site(s), {$screenCount} screen(s) và toàn bộ dữ liệu liên quan.";
                            }
                            return $msg;
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->modalDescription('Xoá các networks đã chọn sẽ đồng thời xoá tất cả sites, screens và dữ liệu liên quan.'),
                ]),
            ])
            ->emptyStateHeading('Chưa có network nào')
            ->emptyStateIcon('heroicon-o-signal');
    }
}
