<?php

namespace App\Filament\Shared\Resources;

use App\Models\Network;
use App\Models\Screen;
use App\Models\Site;
use App\Models\VenueCategory;
use App\Services\PoiContextEnricher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Support\RawJs;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lớp base canonical cho Screens.
 * Phase 1: Marketplace listing/booking — form tối giản, adops collapsed.
 */
abstract class BaseScreenResource extends Resource
{
    protected static ?string $model           = Screen::class;
    protected static ?string $navigationIcon  = null;
    protected static ?string $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Screens';
    protected static ?int    $navigationSort  = 1;

    // ── Hooks cho subclass ────────────────────────────────────────────────────

    protected static function showOwnerField(): bool
    {
        return false; // Admin override = true
    }

    protected static function canPricing(): bool
    {
        return true;
    }

    protected static function siteFormOptions(): array
    {
        return Site::orderBy('name')->pluck('name', 'id')->toArray();
    }

    protected static function sitesByNetwork(?string $networkId): array
    {
        if (! $networkId) return static::siteFormOptions();
        return Site::where('network_id', $networkId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    protected static function networkFormOptions(): array
    {
        return Network::orderBy('name')->pluck('name', 'id')->toArray();
    }

    protected static function siteFilterOptions(): array
    {
        return cache()->remember('filter_sites_all', 300,
            fn () => Site::orderBy('name')->pluck('name', 'id')->toArray()
        );
    }

    protected static function networkFilterOptions(): array
    {
        return cache()->remember('filter_networks_all', 300,
            fn () => Network::orderBy('name')->pluck('name', 'id')->toArray()
        );
    }

    protected static function additionalTableColumns(): array
    {
        return [];
    }

    protected static function additionalFilters(): array
    {
        return [];
    }

    /**
     * Flatten nested arrays cho KeyValueEntry — Filament chỉ render scalar values.
     * VD: ['brands' => ['Starbucks','KFC']] → ['brands' => 'Starbucks, KFC']
     */
    protected static function flattenForKeyValue(?array $data): array
    {
        if (empty($data)) return [];
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                // List of strings → comma-joined; assoc → JSON
                $isList = array_keys($v) === range(0, count($v) - 1);
                $out[$k] = $isList ? implode(', ', array_map('strval', $v)) : json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($v)) {
                $out[$k] = $v ? 'true' : 'false';
            } else {
                $out[$k] = (string) ($v ?? '');
            }
        }
        return $out;
    }

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        $canPricing = static::canPricing();

        return $form->schema([

            Forms\Components\Tabs::make('screen_tabs')
                ->tabs([

                    // ── TAB 1: Thông tin chung ───────────────────────────────
                    Forms\Components\Tabs\Tab::make('Thông tin')
                        ->icon('heroicon-o-information-circle')
                        ->columns(3)
                        ->schema([
                            Forms\Components\Select::make('owner_id')
                                ->label('Media Owner')
                                ->relationship('owner', 'name')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->visible(static::showOwnerField())
                                ->afterStateUpdated(fn (callable $set) => $set('network_filter', null) || $set('site_id', null))
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('name')
                                ->label('Tên màn hình')
                                ->required()
                                ->maxLength(199)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (callable $set, callable $get, ?string $state) {
                                    if ($state && ! $get('external_id')) {
                                        $set('external_id', strtoupper(\Illuminate\Support\Str::slug($state, '-')));
                                    }
                                    if ($state && ! $get('slug')) {
                                        $set('slug', \Illuminate\Support\Str::slug($state, '-', 'vi'));
                                    }
                                })
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('external_id')
                                ->label('Screen ID')
                                ->maxLength(75)
                                ->helperText('Tự động từ tên. Có thể sửa tay.'),

                            Forms\Components\TextInput::make('slug')
                                ->label('Slug (SEO URL)')
                                ->maxLength(150)
                                ->unique(ignoreRecord: true)
                                ->helperText('Tự động từ tên. URL: /explore/{slug}'),

                            Forms\Components\Select::make('network_filter')
                                ->label('Network')
                                ->placeholder('Chọn network')
                                ->options(fn (callable $get) => static::showOwnerField() && $get('owner_id')
                                    ? Network::where('owner_id', $get('owner_id'))->orderBy('name')->pluck('name', 'id')->toArray()
                                    : static::networkFormOptions())
                                ->searchable()
                                ->live()
                                ->afterStateHydrated(function (Forms\Components\Select $component, ?Screen $record) {
                                    if ($record) {
                                        $component->state($record->site?->network_id);
                                    }
                                })
                                ->afterStateUpdated(fn (callable $set) => $set('site_id', null))
                                ->dehydrated(false),

                            Forms\Components\Select::make('site_id')
                                ->label('Site')
                                ->required()
                                ->placeholder(fn (callable $get) => $get('network_filter') ? 'Chọn site' : 'Chọn network trước')
                                ->options(function (callable $get) {
                                    $networkId = $get('network_filter');
                                    if (static::showOwnerField()) {
                                        $ownerId = $get('owner_id');
                                        if (! $ownerId) return [];
                                        $q = Site::where('owner_id', $ownerId);
                                        if ($networkId) $q->where('network_id', $networkId);
                                        return $q->orderBy('name')->pluck('name', 'id')->toArray();
                                    }
                                    return static::sitesByNetwork($networkId);
                                })
                                ->searchable()
                                ->live(),

                            Forms\Components\Select::make('inventory.vn_category_id')
                                ->label('Loại biển')
                                ->placeholder('Chọn loại biển')
                                ->options(fn () => VenueCategory::where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->pluck('name_vi', 'id')
                                    ->toArray())
                                ->searchable(),

                            Forms\Components\Grid::make(3)->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Device status')
                                    ->options([
                                        'online'      => 'Online',
                                        'offline'     => 'Offline',
                                        'maintenance' => 'Maintenance',
                                    ])
                                    ->default('offline'),

                                Forms\Components\Toggle::make('active')
                                    ->label('Hiển thị trên marketplace')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\Toggle::make('inventory.programmatic_enabled')
                                    ->label('Cho phép Programmatic')
                                    ->default(false)
                                    ->inline(false),
                            ])->columnSpan(3),

                            Forms\Components\Textarea::make('description')
                                ->label('Mô tả')
                                ->rows(2)
                                ->columnSpan(3),
                        ]),

                    // ── TAB 2: Kỹ thuật & Ảnh ───────────────────────────────
                    Forms\Components\Tabs\Tab::make('Kỹ thuật')
                        ->icon('heroicon-o-tv')
                        ->columns(2)
                        ->schema([
                            Forms\Components\FileUpload::make('spec.photos')
                                ->label('Ảnh màn hình')
                                ->image()
                                ->multiple()
                                ->reorderable()
                                ->maxFiles(10)
                                ->disk('public')
                                ->directory('screen-photos')
                                ->imagePreviewHeight('160')
                                ->columnSpan(2),

                            Forms\Components\View::make('filament.publisher.components.resolution-picker')
                                ->columnSpan(2),

                            Forms\Components\Hidden::make('spec.width_px')->default(1920),
                            Forms\Components\Hidden::make('spec.height_px')->default(1080),
                            Forms\Components\Hidden::make('spec.resolution_preset')->default('1920x1080'),

                            Forms\Components\TextInput::make('spec.width_cm')
                                ->label('Chiều rộng (cm)')
                                ->numeric(),

                            Forms\Components\TextInput::make('spec.height_cm')
                                ->label('Chiều cao (cm)')
                                ->numeric(),

                            Forms\Components\Hidden::make('spec.width_unit')->default('cm'),
                            Forms\Components\Hidden::make('spec.height_unit')->default('cm'),
                            Forms\Components\Hidden::make('spec.allow_image')->default(true),
                            Forms\Components\Hidden::make('spec.allow_video')->default(true),
                            Forms\Components\Hidden::make('spec.allow_html')->default(false),
                            Forms\Components\Hidden::make('spec.allow_zip')->default(false),
                        ]),

                    // ── TAB 3: Giá & Lịch ────────────────────────────────────
                    Forms\Components\Tabs\Tab::make('Giá & Lịch')
                        ->icon('heroicon-o-currency-dollar')
                        ->columns(3)
                        ->schema([
                            Forms\Components\Select::make('inventory.pricing_model')
                                ->label('Kiểu bán')
                                ->options([
                                    'io'   => 'I/O Booking (giá/màn hình/tuần hoặc tháng)',
                                    'cpm'  => 'CPM (giá/1000 impressions)',
                                    'both' => 'Cả hai (buyer chọn CPM hoặc I/O khi booking)',
                                ])
                                ->default('io')
                                ->required()
                                ->live()
                                ->columnSpan(3)
                                ->helperText('Billboard static chọn "I/O Booking". Màn hình digital có thể chọn "Cả hai" để buyer tự chọn.')
                                ->visible($canPricing),

                            // ── CPM fields (visible when cpm or both) ──
                            Forms\Components\TextInput::make('inventory.floor_cpm')
                                ->label('Đơn giá CPM (₫/1000 impressions)')
                                ->prefix('₫')
                                ->formatStateUsing(fn ($state) => $state !== null ? (int) round((float) $state) : null)
                                ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                                ->stripCharacters(',')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->helperText('Giá cho mỗi 1,000 lượt hiển thị. VD: 45,000 ₫/CPM')
                                ->visible(fn (Forms\Get $get) => $canPricing && in_array($get('inventory.pricing_model'), ['cpm', 'both'])),

                            // ── I/O fields (visible when io or both) ──
                            Forms\Components\TextInput::make('inventory.io_rate')
                                ->label('Đơn giá I/O')
                                ->prefix('₫')
                                ->formatStateUsing(fn ($state) => $state !== null ? (int) round((float) $state) : null)
                                ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                                ->stripCharacters(',')
                                ->numeric()
                                ->minValue(0)
                                ->helperText('VD: 350,000 ₫/màn hình/tuần')
                                ->visible(fn (Forms\Get $get) => $canPricing && in_array($get('inventory.pricing_model') ?? 'io', ['io', 'both'])),

                            Forms\Components\Select::make('inventory.io_rate_unit')
                                ->label('Đơn vị thời gian')
                                ->options([
                                    'week'  => 'Tuần',
                                    'month' => 'Tháng',
                                ])
                                ->default('month')
                                ->visible(fn (Forms\Get $get) => $canPricing && in_array($get('inventory.pricing_model') ?? 'io', ['io', 'both'])),

                            Forms\Components\TextInput::make('inventory.io_kpi_spots_per_day')
                                ->label('KPI spots/màn hình/ngày')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(9999)
                                ->inputMode('numeric')
                                ->helperText('Số spots cam kết phát mỗi ngày. VD: 120')
                                ->visible(fn (Forms\Get $get) => $canPricing && in_array($get('inventory.pricing_model') ?? 'io', ['io', 'both'])),

                            // ── Common fields ──
                            Forms\Components\TextInput::make('inventory.weekly_impressions')
                                ->label('Lượt xem / tuần')
                                ->formatStateUsing(fn ($state) => $state !== null ? (int) $state : null)
                                ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                                ->stripCharacters(',')
                                ->numeric()
                                ->minValue(0),

                            Forms\Components\TextInput::make('inventory.spot_length')
                                ->label('Thời lượng QC')
                                ->numeric()
                                ->default(15)
                                ->minValue(3)
                                ->maxValue(300)
                                ->suffix('giây'),

                            Forms\Components\Section::make('Lịch hoạt động')
                                ->columnSpan(3)
                                ->compact()
                                ->schema([
                                    Forms\Components\View::make('filament.publisher.components.operating-hours-grid'),
                                    Forms\Components\Hidden::make('inventory.operating_hours'),
                                ]),
                        ]),

                    // ── TAB 4: Insights (Phase 1 — Inventory Intelligence) ───
                    Forms\Components\Tabs\Tab::make('Insights')
                        ->icon('heroicon-o-chart-bar')
                        ->columns(3)
                        ->schema([
                            Forms\Components\Select::make('placement_zone')
                                ->label('Vị trí trong venue')
                                ->options([
                                    'entrance'   => 'Lối vào',
                                    'checkout'   => 'Quầy thanh toán',
                                    'escalator'  => 'Cạnh thang cuốn',
                                    'food_court' => 'Khu food court',
                                    'facade'     => 'Mặt tiền tòa nhà',
                                    'lobby'      => 'Sảnh',
                                    'parking'    => 'Bãi đậu xe',
                                    'other'      => 'Khác',
                                ])
                                ->placeholder('—')
                                ->columnSpan(1),

                            Forms\Components\Select::make('orientation')
                                ->label('Hướng màn hình')
                                ->options([
                                    'landscape' => 'Ngang (landscape)',
                                    'portrait'  => 'Dọc (portrait)',
                                    'square'    => 'Vuông (square)',
                                ])
                                ->placeholder('Tự động từ kích thước')
                                ->helperText('Để trống → tự suy ra từ width × height')
                                ->columnSpan(1),

                            Forms\Components\Placeholder::make('_orient_hint')
                                ->label('')
                                ->content('')
                                ->columnSpan(1),

                            // ── Lưu lượng ────────────────────────────────────
                            Forms\Components\Section::make('Lưu lượng (Footfall & Reach)')
                                ->description('Ước lượng lưu lượng khách qua vị trí. Minh bạch nguồn để agency tin cậy.')
                                ->columns(2)
                                ->columnSpan(3)
                                ->schema([
                                    Forms\Components\TextInput::make('daily_footfall')
                                        ->label('Lượt khách/ngày (ước lượng)')
                                        ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                                        ->stripCharacters(',')
                                        ->numeric()
                                        ->minValue(0)
                                        ->suffix('người/ngày'),

                                    Forms\Components\TextInput::make('monthly_reach')
                                        ->label('Reach/tháng (số người riêng biệt)')
                                        ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                                        ->stripCharacters(',')
                                        ->numeric()
                                        ->minValue(0)
                                        ->suffix('người/tháng'),

                                    Forms\Components\Textarea::make('traffic_methodology_note')
                                        ->label('Nguồn / phương pháp ước lượng')
                                        ->rows(2)
                                        ->columnSpanFull()
                                        ->placeholder('VD: Số liệu Vincom Retail Q4 2025 · Manual count tháng 3 · Industry benchmark'),
                                ]),

                            // ── Audience profile ─────────────────────────────
                            Forms\Components\Section::make('Audience profile')
                                ->description('Phân bố giới tính & độ tuổi (% — tổng từng nhóm nên ≈ 100).')
                                ->columns(3)
                                ->columnSpan(3)
                                ->schema([
                                    Forms\Components\TextInput::make('audience_profile.male_pct')
                                        ->label('Nam %')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                                    Forms\Components\TextInput::make('audience_profile.female_pct')
                                        ->label('Nữ %')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                                    Forms\Components\Placeholder::make('_aud_spacer')->label('')->content(''),

                                    Forms\Components\TextInput::make('audience_profile.age_18_24_pct')
                                        ->label('18-24 %')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                                    Forms\Components\TextInput::make('audience_profile.age_25_34_pct')
                                        ->label('25-34 %')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                                    Forms\Components\TextInput::make('audience_profile.age_35_44_pct')
                                        ->label('35-44 %')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),

                                    Forms\Components\TextInput::make('audience_profile.age_45_plus_pct')
                                        ->label('45+ %')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                                    Forms\Components\Placeholder::make('_aud_spacer2')->label('')->content('')->columnSpan(2),

                                    Forms\Components\TextInput::make('audience_profile.source_note')
                                        ->label('Nguồn audience')
                                        ->columnSpanFull()
                                        ->placeholder('VD: Survey Q1 2025 · Estimate theo industry benchmark'),
                                ]),

                            // ── Time performance ─────────────────────────────
                            Forms\Components\Section::make('Hiệu suất theo thời gian')
                                ->description('Khung giờ peak + ngày đẹp nhất + phân bổ traffic theo buổi.')
                                ->columns(3)
                                ->columnSpan(3)
                                ->schema([
                                    Forms\Components\TimePicker::make('time_performance.peak_hour_start')
                                        ->label('Peak bắt đầu')
                                        ->seconds(false)
                                        ->displayFormat('H:i'),
                                    Forms\Components\TimePicker::make('time_performance.peak_hour_end')
                                        ->label('Peak kết thúc')
                                        ->seconds(false)
                                        ->displayFormat('H:i'),
                                    Forms\Components\Select::make('time_performance.best_day')
                                        ->label('Ngày đẹp nhất')
                                        ->options([
                                            'mon' => 'Thứ 2',
                                            'tue' => 'Thứ 3',
                                            'wed' => 'Thứ 4',
                                            'thu' => 'Thứ 5',
                                            'fri' => 'Thứ 6',
                                            'sat' => 'Thứ 7',
                                            'sun' => 'Chủ nhật',
                                        ])
                                        ->placeholder('—'),

                                    Forms\Components\TextInput::make('time_performance.morning_pct')
                                        ->label('Sáng % (6-12h)')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                                    Forms\Components\TextInput::make('time_performance.afternoon_pct')
                                        ->label('Chiều % (12-18h)')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                                    Forms\Components\TextInput::make('time_performance.evening_pct')
                                        ->label('Tối % (18-24h)')
                                        ->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                                ]),

                            // ── Nearby context ───────────────────────────────
                            Forms\Components\Section::make('Bối cảnh xung quanh')
                                ->description('Brands & landmarks lân cận giúp agency hình dung context.')
                                ->columns(2)
                                ->columnSpan(3)
                                ->schema([
                                    Forms\Components\TagsInput::make('nearby_context.brands')
                                        ->label('Brand lân cận')
                                        ->placeholder('Starbucks, Highlands, KFC')
                                        ->helperText('Nhấn Enter sau mỗi tên'),
                                    Forms\Components\TagsInput::make('nearby_context.landmarks')
                                        ->label('Landmark / địa danh')
                                        ->placeholder('Vincom Center, Hồ Hoàn Kiếm 200m')
                                        ->helperText('Nhấn Enter sau mỗi tên'),
                                    Forms\Components\Textarea::make('nearby_context.highlights')
                                        ->label('Điểm nổi bật vị trí')
                                        ->placeholder('VD: Mặt tiền tầng trệt đối diện thang cuốn — view rõ từ lối vào chính')
                                        ->rows(3)
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    // ── TAB 5: Vị trí ────────────────────────────────────────
                    Forms\Components\Tabs\Tab::make('Vị trí')
                        ->icon('heroicon-o-map-pin')
                        ->columns(3)
                        ->schema([
                            Forms\Components\TextInput::make('_site_lat')
                                ->label('Latitude')
                                ->numeric()
                                ->step(0.0000001)
                                ->live(debounce: 800)
                                ->afterStateHydrated(fn ($component, $record) =>
                                    $component->state($record?->site?->lat))
                                ->dehydrated(false),

                            Forms\Components\TextInput::make('_site_lon')
                                ->label('Longitude')
                                ->numeric()
                                ->step(0.0000001)
                                ->live(debounce: 800)
                                ->afterStateHydrated(fn ($component, $record) =>
                                    $component->state($record?->site?->lon))
                                ->dehydrated(false),

                            Forms\Components\Select::make('inventory.timezone')
                                ->label('Múi giờ')
                                ->options(fn () => collect(timezone_identifiers_list())
                                    ->mapWithKeys(fn ($tz) => [$tz => $tz]))
                                ->searchable()
                                ->default('Asia/Ho_Chi_Minh'),

                            Forms\Components\View::make('filament.forms.components.screen-map-picker')
                                ->columnSpan(3),
                        ]),

                ])
                ->persistTabInQueryString('tab')
                ->columnSpanFull(),

            // ── AdOps nâng cao (ngoài tabs, collapsed) ───────────────────
            Forms\Components\Section::make('AdOps nâng cao')
                ->description('Cấu hình SSP/programmatic — bỏ qua nếu chỉ cần listing marketplace.')
                ->collapsed()
                ->visible($canPricing && config('oohx.show_adops_fields', false))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('unit_id')
                        ->label('Unit ID')
                        ->maxLength(100)
                        ->nullable(),

                    Forms\Components\Hidden::make('uuid'),

                    Forms\Components\Select::make('player_type')
                        ->label('Player type')
                        ->options([
                            'adtrue_android' => 'OOHX Android',
                            'adtrue_webview' => 'OOHX WebView',
                            'third_party'    => '3rd Party Player',
                            'vast_only'      => 'VAST Only',
                        ])
                        ->default('adtrue_android'),

                    Forms\Components\Select::make('spec.facing_direction')
                        ->label('Hướng mặt biển')
                        ->placeholder('Chọn hướng')
                        ->options([
                            'N' => 'North', 'NE' => 'North East',
                            'E' => 'East',  'SE' => 'South East',
                            'S' => 'South', 'SW' => 'South West',
                            'W' => 'West',  'NW' => 'North West',
                            'Rotating' => 'Rotating',
                        ])
                        ->nullable(),

                    Forms\Components\TextInput::make('inventory.share_of_voice_max_pct')
                        ->label('Max SOV (%)')
                        ->numeric()
                        ->default(100)
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('%'),

                    Forms\Components\TextInput::make('inventory.screen_count_override')
                        ->label('Screen count')
                        ->numeric()
                        ->nullable()
                        ->minValue(1)
                        ->maxValue(9999)
                        ->placeholder('1'),

                    Forms\Components\TextInput::make('inventory.max_spot_length')
                        ->label('Max duration')
                        ->numeric()
                        ->default(180)
                        ->minValue(3)
                        ->maxValue(600)
                        ->suffix('s'),

                    Forms\Components\TextInput::make('inventory.min_spot_length')
                        ->label('Min duration')
                        ->numeric()
                        ->default(3)
                        ->minValue(1)
                        ->maxValue(300)
                        ->suffix('s'),

                    Forms\Components\TextInput::make('inventory.loop_length')
                        ->label('Loop length')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(3600)
                        ->suffix('s'),

                    Forms\Components\TextInput::make('inventory.frequency_cap')
                        ->label('Freq cap')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('s')
                        ->helperText('0 = unlimited'),

                    Forms\Components\TextInput::make('inventory.category_frequency_cap')
                        ->label('Cat freq cap')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('s'),

                    Forms\Components\Toggle::make('inventory.strict_frequency_capping')
                        ->label('Strict freq cap')
                        ->default(false),

                    Forms\Components\Textarea::make('internal_notes')
                        ->label('Ghi chú nội bộ')
                        ->rows(2)
                        ->columnSpan(3),

                    Forms\Components\View::make('filament.publisher.components.selective-listing-picker')
                        ->columnSpan(3),
                    Forms\Components\Hidden::make('inventory.pmp_only')->default(false),
                    Forms\Components\Hidden::make('inventory.ad_server_enabled')->default(true),
                    Forms\Components\Hidden::make('inventory.deals_enabled')->default(true),
                ]),

        ])->columns(1);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        $canPricing = static::canPricing();

        return $table
            ->deferLoading()
            ->columns([
                ...static::additionalTableColumns(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('external_id')
                    ->label('Screen ID')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'online',
                        'danger'  => 'offline',
                        'warning' => 'maintenance',
                    ])
                    ->formatStateUsing(fn ($state) => ucfirst($state ?? 'unknown')),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label('Active'),

                SelectFilter::make('status')
                    ->label('Device status')
                    ->options([
                        'online'      => 'Online',
                        'offline'     => 'Offline',
                        'maintenance' => 'Maintenance',
                    ]),

                SelectFilter::make('site_id')
                    ->label('Site')
                    ->options(fn () => static::siteFilterOptions())
                    ->searchable(),

                SelectFilter::make('network')
                    ->label('Network')
                    ->options(fn () => static::networkFilterOptions())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $v) => $q->whereHas('site', fn ($sq) => $sq->where('network_id', $v))
                    ))
                    ->searchable(),

                SelectFilter::make('vn_category')
                    ->label('Loại biển')
                    ->options(fn () => VenueCategory::where('is_active', true)
                        ->orderBy('sort_order')
                        ->pluck('name_vi', 'id')
                        ->toArray())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $v) => $q->whereHas('inventory', fn ($iq) => $iq->where('vn_category_id', $v))
                    ))
                    ->searchable(),

                ...static::additionalFilters(),
            ])
            ->filtersFormColumns(3)
            ->recordAction('quick_detail')
            ->recordUrl(null)
            ->actions([
                Tables\Actions\Action::make('quick_detail')
                    ->label('Chi tiết')
                    ->icon('heroicon-o-information-circle')
                    ->color('gray')
                    ->modalHeading(fn (Screen $record) => $record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalWidth('4xl')
                    ->modalContent(fn (Screen $record) => view(
                        'filament.shared.components.screen-detail-modal',
                        ['screen' => $record->load(['owner', 'site.network', 'spec', 'inventory.vnCategory'])]
                    )),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),

                    // ── AI Context Enrichment (Phase 1) ──────────
                    Tables\Actions\Action::make('enrich_ai_context')
                        ->label('Enrich AI Context')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->modalHeading(fn (Screen $r) => "AI Context: {$r->name}")
                        ->modalDescription('Phân tích POI xung quanh từ OpenStreetMap + Claude Haiku inference. Cost ~$0.005 mỗi lần chạy.')
                        ->modalWidth('5xl')
                        ->modalSubmitActionLabel('Apply vào Screen')
                        ->modalCancelActionLabel('Huỷ')
                        ->visible(fn (Screen $r) => (bool) ($r->site?->lat && $r->site?->lon))
                        ->mountUsing(function (\Filament\Forms\Form $form, Screen $record) {
                            $cacheKey = 'enrich:' . (auth()->id() ?? 'anon') . ':' . $record->id . ':' . now()->timestamp;
                            try {
                                $result = app(PoiContextEnricher::class)->enrichScreen($record, 500);
                                \Cache::put($cacheKey, $result, now()->addMinutes(15));
                            } catch (\Throwable $e) {
                                // Cache the error so preview view can render it cleanly
                                \Cache::put($cacheKey, ['_error' => $e->getMessage()], now()->addMinutes(5));
                            }
                            $form->fill(['cache_key' => $cacheKey]);
                        })
                        ->form([
                            Forms\Components\Hidden::make('cache_key'),
                            Forms\Components\Placeholder::make('preview')
                                ->label('')
                                ->content(function (Forms\Get $get): \Illuminate\Support\HtmlString {
                                    $result = \Cache::get($get('cache_key'));
                                    if (! $result) {
                                        return new \Illuminate\Support\HtmlString('<p class="text-danger-600">Cache hết hạn — đóng modal và mở lại.</p>');
                                    }
                                    return new \Illuminate\Support\HtmlString(
                                        view('filament.shared.components.enrich-ai-preview', ['result' => $result])->render()
                                    );
                                })
                                ->columnSpanFull(),
                        ])
                        ->action(function (array $data, Screen $record) {
                            $result = \Cache::get($data['cache_key'] ?? '');
                            if (! $result) {
                                Notification::make()
                                    ->title('Cache hết hạn')
                                    ->body('Đóng modal và mở lại để chạy enrichment.')
                                    ->warning()
                                    ->send();
                                return;
                            }
                            if (! empty($result['_error'])) {
                                Notification::make()
                                    ->title('Enrichment lỗi — không thể apply')
                                    ->body($result['_error'])
                                    ->danger()
                                    ->send();
                                return;
                            }
                            if (empty($result['ai'])) {
                                Notification::make()
                                    ->title('AI output rỗng')
                                    ->body('Có thể ANTHROPIC_API_KEY chưa set hoặc Anthropic trả về invalid JSON.')
                                    ->danger()
                                    ->send();
                                return;
                            }
                            app(PoiContextEnricher::class)->applyToScreen($record, $result);
                            \Cache::forget($data['cache_key']);
                            Notification::make()
                                ->title('Đã apply AI context')
                                ->body('Audience profile, time performance, nearby context đã update. Tab Insights ở detail page sẽ render data mới.')
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\Action::make('toggle_rtb')
                        ->label(fn (Screen $r) => $r->inventory?->programmatic_enabled ? 'Disable RTB' : 'Enable RTB')
                        ->icon('heroicon-o-signal')
                        ->color(fn (Screen $r) => $r->inventory?->programmatic_enabled ? 'danger' : 'success')
                        ->visible($canPricing)
                        ->requiresConfirmation()
                        ->action(function (Screen $r) {
                            $inv = $r->inventory;
                            if ($inv) {
                                $inv->update(['programmatic_enabled' => ! $inv->programmatic_enabled]);
                                Notification::make()->title('Đã cập nhật RTB')->success()->send();
                            }
                        }),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('bulk_enrich_ai')
                        ->label('Enrich AI Context (batch)')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalDescription(fn ($records) => sprintf(
                            'Sẽ chạy AI enrichment cho %d screens (~%d giây + ~$%s). Chỉ áp cho screen có lat/lon.',
                            $records->count(),
                            $records->count() * 30,
                            number_format($records->count() * 0.005, 3)
                        ))
                        ->action(function ($records) {
                            $enricher = app(PoiContextEnricher::class);
                            $ok = 0; $fail = 0; $skip = 0;
                            foreach ($records as $screen) {
                                if (! $screen->site?->lat || ! $screen->site?->lon) { $skip++; continue; }
                                try {
                                    $result = $enricher->enrichScreen($screen, 500);
                                    if (! empty($result['ai'])) {
                                        $enricher->applyToScreen($screen, $result);
                                        $ok++;
                                    } else {
                                        $fail++;
                                    }
                                } catch (\Throwable $e) {
                                    $fail++;
                                }
                            }
                            Notification::make()
                                ->title("Batch enrich xong: {$ok} OK / {$fail} fail / {$skip} skip (no lat/lon)")
                                ->success()
                                ->send();
                        }),

                    // Phase 4.1 — pre-populate Campaign Planner với selected screens
                    Tables\Actions\BulkAction::make('plan_campaign')
                        ->label('Plan campaign with selected')
                        ->icon('heroicon-o-megaphone')
                        ->color('info')
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records) {
                            if ($records->isEmpty()) {
                                Notification::make()->title('Chọn ít nhất 1 screen')->warning()->send();
                                return;
                            }
                            $ids = $records->pluck('id')->take(500)->implode(',');
                            $url = \App\Filament\Resources\OohxCampaignEstimateResource::getUrl('create', [
                                'screens' => $ids,
                            ]);
                            return redirect($url);
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    // ── Infolist (View page) ────────────────────────────────────────────────

    /**
     * Admin override để thêm section AdOps nâng cao.
     */
    protected static function showAdOpsSection(): bool
    {
        return config('oohx.show_adops_fields', false);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $showAdOps = static::showAdOpsSection();

        return $infolist->schema([

            Infolists\Components\Tabs::make('screen_detail_tabs')
                ->persistTabInQueryString('tab')
                ->columnSpanFull()
                ->tabs(array_filter([

                    // ── Tab 1: Thông tin chung ──────────────────────────────
                    Infolists\Components\Tabs\Tab::make('Thông tin')
                        ->icon('heroicon-o-information-circle')
                        ->columns(3)
                        ->schema([
                            Infolists\Components\TextEntry::make('external_id')
                                ->label('Screen ID')
                                ->copyable()
                                ->fontFamily('mono'),

                            Infolists\Components\TextEntry::make('active_status')
                                ->label('Trạng thái')
                                ->badge()
                                ->getStateUsing(fn (Screen $r) => $r->active ? 'Active' : 'Inactive')
                                ->color(fn ($state) => $state === 'Active' ? 'success' : 'danger'),

                            Infolists\Components\TextEntry::make('status')
                                ->label('Device')
                                ->badge()
                                ->formatStateUsing(fn ($state) => ucfirst($state ?? 'unknown'))
                                ->color(fn ($state) => match ($state) {
                                    'online'      => 'success',
                                    'maintenance' => 'warning',
                                    default       => 'danger',
                                }),

                            Infolists\Components\TextEntry::make('owner.name')
                                ->label('Owner')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('site.name')
                                ->label('Site')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('site.network.name')
                                ->label('Network')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('inventory.vnCategory.name_vi')
                                ->label('Loại biển')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('site.city')
                                ->label('Thành phố')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('updated_at')
                                ->label('Cập nhật')
                                ->since(),
                        ]),

                    // ── Tab 2: Kỹ thuật & Giá ──────────────────────────────
                    Infolists\Components\Tabs\Tab::make('Kỹ thuật & Giá')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->columns(4)
                        ->schema([
                            Infolists\Components\TextEntry::make('resolution')
                                ->label('Resolution')
                                ->getStateUsing(fn (Screen $r) => $r->spec
                                    ? "{$r->spec->width_px}×{$r->spec->height_px} px"
                                    : '—'),

                            Infolists\Components\TextEntry::make('physical_size')
                                ->label('Kích thước')
                                ->getStateUsing(fn (Screen $r) => $r->spec?->width_cm
                                    ? "{$r->spec->width_cm}×{$r->spec->height_cm} cm"
                                    : '—'),

                            Infolists\Components\TextEntry::make('content_types')
                                ->label('Content')
                                ->getStateUsing(fn (Screen $r) => implode(', ', array_filter([
                                    $r->spec?->allow_image ? 'Image' : null,
                                    $r->spec?->allow_video ? 'Video' : null,
                                ])) ?: '—'),

                            Infolists\Components\TextEntry::make('inventory.spot_length')
                                ->label('Spot')
                                ->formatStateUsing(fn ($state) => ($state ?? 15) . 's'),

                            Infolists\Components\TextEntry::make('floor_cpm_display')
                                ->label('Floor CPM')
                                ->getStateUsing(fn (Screen $r) => $r->inventory?->floor_cpm
                                    ? number_format((float) $r->inventory->floor_cpm, 0, '.', ',') . ' ' . ($r->inventory->floor_cpm_currency ?? 'VND')
                                    : '—'),

                            Infolists\Components\TextEntry::make('inventory.weekly_impressions')
                                ->label('Impressions/tuần')
                                ->formatStateUsing(fn ($state) => $state ? number_format((int) $state) : '—'),

                            Infolists\Components\TextEntry::make('programmatic_display')
                                ->label('Programmatic')
                                ->badge()
                                ->getStateUsing(fn (Screen $r) => $r->inventory?->programmatic_enabled ? 'Enabled' : 'Disabled')
                                ->color(fn ($state) => $state === 'Enabled' ? 'success' : 'gray'),

                            Infolists\Components\TextEntry::make('inventory.timezone')
                                ->label('Timezone')
                                ->placeholder('—'),
                        ]),

                    // ── Tab 3: Hình ảnh ─────────────────────────────────────
                    Infolists\Components\Tabs\Tab::make('Hình ảnh')
                        ->icon('heroicon-o-photo')
                        ->schema([
                            Infolists\Components\ImageEntry::make('spec.photos')
                                ->label('')
                                ->disk('public')
                                ->height(200)
                                ->stacked(false)
                                ->visible(fn (Screen $r) => ! empty($r->spec?->photos)),

                            Infolists\Components\TextEntry::make('no_photos')
                                ->label('')
                                ->getStateUsing(fn () => 'Chưa có hình ảnh.')
                                ->visible(fn (Screen $r) => empty($r->spec?->photos)),
                        ]),

                    // ── Tab 4: Lịch hoạt động ───────────────────────────────
                    Infolists\Components\Tabs\Tab::make('Lịch hoạt động')
                        ->icon('heroicon-o-clock')
                        ->schema([
                            Infolists\Components\ViewEntry::make('operating_hours_grid')
                                ->label('')
                                ->view('filament.publisher.components.operating-hours-view')
                                ->getStateUsing(fn (Screen $r) => $r->inventory?->operating_hours ?? []),
                        ]),

                    // ── Tab 5: Insights (Phase 1) ───────────────────────────
                    Infolists\Components\Tabs\Tab::make('Insights')
                        ->icon('heroicon-o-chart-bar')
                        ->schema([
                            Infolists\Components\ViewEntry::make('insights_view')
                                ->label('')
                                ->view('filament.shared.components.screen-insights-infolist')
                                ->columnSpanFull(),
                        ]),

                    // ── Tab 6: AdOps (admin only) ───────────────────────────
                    $showAdOps ? Infolists\Components\Tabs\Tab::make('AdOps')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->columns(3)
                        ->schema([
                            Infolists\Components\TextEntry::make('uuid')
                                ->label('Ad request UUID')
                                ->copyable()
                                ->fontFamily('mono')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('unit_id')
                                ->label('Unit ID')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('player_type')
                                ->label('Player type')
                                ->formatStateUsing(fn ($state) => match ($state) {
                                    'adtrue_android' => 'OOHX Android',
                                    'adtrue_webview' => 'OOHX WebView',
                                    'third_party'    => '3rd Party',
                                    'vast_only'      => 'VAST Only',
                                    default          => $state ?? '—',
                                }),

                            Infolists\Components\TextEntry::make('share_of_voice')
                                ->label('Max SOV')
                                ->getStateUsing(fn (Screen $r) => ($r->inventory?->share_of_voice_max_pct ?? 100) . '%'),

                            Infolists\Components\TextEntry::make('frequency_cap_display')
                                ->label('Frequency cap')
                                ->getStateUsing(fn (Screen $r) => $r->inventory?->frequency_cap
                                    ? $r->inventory->frequency_cap . 's'
                                    : 'Unlimited'),

                            Infolists\Components\TextEntry::make('internal_notes')
                                ->label('Ghi chú nội bộ')
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ]) : null,

                ])),

            // ── Section: Vị trí & Map (ngoài tabs) ──────────────────────────
            Infolists\Components\Section::make('Vị trí')
                ->icon('heroicon-o-map-pin')
                ->collapsible()
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('site.address')
                        ->label('Địa chỉ')
                        ->placeholder('—')
                        ->columnSpan(2),

                    Infolists\Components\TextEntry::make('coordinates')
                        ->label('Tọa độ')
                        ->getStateUsing(fn (Screen $r) => $r->site?->lat
                            ? number_format((float) $r->site->lat, 7) . ', ' . number_format((float) $r->site->lon, 7)
                            : '—'),

                    Infolists\Components\ViewEntry::make('screen_map')
                        ->label('')
                        ->view('filament.components.screen-map')
                        ->columnSpanFull()
                        ->visible(fn (Screen $r) => $r->site?->lat !== null),
                ]),

            // ── Section: Data Engine estimate (read-only, từ VPS riêng) ─────
            // 1 query per render (vs N cho nhiều TextEntry) — lookup trong ViewEntry Blade.
            Infolists\Components\Section::make('Data Engine — traffic estimate')
                ->icon('heroicon-o-cpu-chip')
                ->description('Rule-based estimate từ OOHX Data Engine (POI + road network). Update mỗi 30 phút.')
                ->collapsible()
                ->schema([
                    Infolists\Components\ViewEntry::make('data_engine_estimate')
                        ->view('filament.shared.components.data-engine-estimate'),
                ]),
        ]);
    }
}
