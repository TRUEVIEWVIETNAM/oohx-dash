<?php

namespace App\Filament\Publisher\Pages;

use App\Models\Owner;
use App\Models\VietnamCommune;
use App\Models\VietnamProvince;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class CompanyProfile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-building-office';
    protected static ?string $navigationGroup = 'Cài đặt';
    protected static ?string $navigationLabel = 'Hồ sơ công ty';
    protected static ?int    $navigationSort  = 0;
    protected static string  $view            = 'filament.publisher.pages.company-profile';

    public ?array $data = [];

    public function mount(): void
    {
        $owner = $this->getOwner();
        abort_unless($owner, 403);

        $this->form->fill($owner->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('profile_tabs')
                    ->tabs([
                        // ── Tab 1: Thông tin công ty ──
                        Forms\Components\Tabs\Tab::make('Thông tin')
                            ->icon('heroicon-o-building-office')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Tên công ty')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('tagline')
                                    ->label('Khẩu hiệu')
                                    ->maxLength(255)
                                    ->placeholder('VD: Hệ thống DOOH hàng đầu Việt Nam')
                                    ->columnSpan(2),

                                Forms\Components\RichEditor::make('about')
                                    ->label('Giới thiệu công ty')
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('founded')
                                    ->label('Năm thành lập')
                                    ->numeric()
                                    ->minValue(1900)
                                    ->maxValue(date('Y'))
                                    ->placeholder(date('Y')),

                                Forms\Components\Select::make('type')
                                    ->label('Loại hình')
                                    ->options([
                                        'media_owner' => 'Media Owner',
                                        'retailer'    => 'Retailer',
                                        'self'        => 'Self-serve',
                                    ])
                                    ->disabled()
                                    ->helperText('Liên hệ admin để thay đổi'),
                            ]),

                        // ── Tab 2: Hình ảnh ──
                        Forms\Components\Tabs\Tab::make('Hình ảnh')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\FileUpload::make('logo_url')
                                    ->label('Logo công ty')
                                    ->image()
                                    ->disk('public')
                                    ->directory('owners/logos')
                                    ->maxSize(5120)
                                    ->imagePreviewHeight('120')
                                    ->helperText('Khuyến nghị: vuông, tối thiểu 200x200px'),

                                Forms\Components\FileUpload::make('cover_url')
                                    ->label('Ảnh bìa')
                                    ->image()
                                    ->disk('public')
                                    ->directory('owners/covers')
                                    ->maxSize(10240)
                                    ->imagePreviewHeight('160')
                                    ->helperText('Khuyến nghị: 1200x400px'),
                            ]),

                        // ── Tab 3: Liên hệ ──
                        Forms\Components\Tabs\Tab::make('Liên hệ')
                            ->icon('heroicon-o-phone')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('website')
                                    ->label('Website')
                                    ->url()
                                    ->prefix('https://')
                                    ->placeholder('www.company.com'),

                                Forms\Components\TextInput::make('email')
                                    ->label('Email liên hệ')
                                    ->email()
                                    ->placeholder('contact@company.com'),

                                Forms\Components\TextInput::make('phone')
                                    ->label('Số điện thoại')
                                    ->tel()
                                    ->mask('9999 999 9999')
                                    ->maxLength(20)
                                    ->placeholder('0912 345 6789'),

                                Forms\Components\Toggle::make('featured')
                                    ->label('Hiển thị nổi bật trên frontpage')
                                    ->helperText('Owner sẽ xuất hiện ở mục Featured trên trang chủ'),
                            ]),

                        // ── Tab 4: Trụ sở ──
                        Forms\Components\Tabs\Tab::make('Trụ sở')
                            ->icon('heroicon-o-map-pin')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('province_id')
                                    ->label('Tỉnh / Thành phố')
                                    ->options(fn () => VietnamProvince::orderByRaw("type = 'thanh_pho' DESC, name ASC")->pluck('full_name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(fn (callable $set) => $set('commune_id', null)),

                                Forms\Components\Select::make('commune_id')
                                    ->label('Phường / Xã')
                                    ->options(fn (callable $get) => $get('province_id')
                                        ? VietnamCommune::where('province_id', $get('province_id'))
                                            ->orderByRaw("FIELD(type, 'phuong', 'thi_tran', 'xa'), name ASC")
                                            ->pluck('full_name', 'id')
                                        : [])
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Chọn tỉnh/thành trước'),

                                Forms\Components\TextInput::make('address')
                                    ->label('Địa chỉ chi tiết')
                                    ->maxLength(255)
                                    ->placeholder('Số 123, Đường ABC')
                                    ->columnSpan(2),

                                Forms\Components\Section::make('Tọa độ trụ sở')
                                    ->description('Nhập tọa độ hoặc kéo pin trên bản đồ')
                                    ->columnSpan(2)
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('headquarters_lat')
                                            ->label('Vĩ độ')
                                            ->numeric()
                                            ->step(0.0000001)
                                            ->live(debounce: 800)
                                            ->placeholder('21.0285'),

                                        Forms\Components\TextInput::make('headquarters_lng')
                                            ->label('Kinh độ')
                                            ->numeric()
                                            ->step(0.0000001)
                                            ->live(debounce: 800)
                                            ->placeholder('105.8542'),

                                        Forms\Components\View::make('filament.publisher.components.headquarters-map')
                                            ->columnSpan(2),
                                    ]),
                            ]),
                    ])
                    ->persistTabInQueryString('tab')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $owner = $this->getOwner();
        abort_unless($owner, 403);

        $state = $this->form->getState();

        // Only update profile fields (not admin-only fields like status, slug, revenue_share_pct)
        $owner->update([
            'name'             => $state['name'],
            'tagline'          => $state['tagline'] ?? null,
            'about'            => $state['about'] ?? null,
            'logo_url'         => $state['logo_url'] ?? null,
            'cover_url'        => $state['cover_url'] ?? null,
            'website'          => $state['website'] ?? null,
            'email'            => $state['email'] ?? null,
            'phone'            => $state['phone'] ?? null,
            'address'          => $state['address'] ?? null,
            'province_id'      => $state['province_id'] ?? null,
            'commune_id'       => $state['commune_id'] ?? null,
            'district'         => $state['district'] ?? null,
            'founded'          => $state['founded'] ?? null,
            'featured'         => $state['featured'] ?? false,
            'headquarters_lat' => $state['headquarters_lat'] ?? null,
            'headquarters_lng' => $state['headquarters_lng'] ?? null,
        ]);

        // Clear frontpage cache
        Cache::forget("fp:owner:{$owner->slug}");
        Cache::forget('fp:featured_owners');

        Notification::make()
            ->title('Đã cập nhật hồ sơ công ty')
            ->success()
            ->send();
    }

    private function getOwner(): ?Owner
    {
        $ownerId = auth()->user()?->current_owner_id;
        return $ownerId ? Owner::find($ownerId) : null;
    }
}
