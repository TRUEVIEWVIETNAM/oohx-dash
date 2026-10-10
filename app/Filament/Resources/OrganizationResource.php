<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Support\RawJs;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Sàn';

    protected static ?string $navigationLabel = 'Tổ chức';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin cơ bản')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Tên tổ chức')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (callable $set, ?string $state) => $set('slug', \Illuminate\Support\Str::slug($state, '-', 'vi'))),
                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->unique(ignoreRecord: true)
                        ->helperText('Tự động từ tên'),
                ]),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('type')
                        ->label('Loại hình')
                        // Chữ từ `Organization::TYPE_LABELS` — một bảng cho cả
                        // form, bộ lọc, cột, khối thông tin và khu người mua.
                        ->options(Organization::TYPE_LABELS)
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label('Trạng thái')
                        ->options(Organization::STATUS_LABELS)
                        ->required(),
                    Forms\Components\TextInput::make('tax_id')
                        ->label('MST'),
                ]),
            ]),
            Forms\Components\Section::make('Thanh toán')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('billing_email')
                        ->label('Email thanh toán')
                        ->email(),
                    Forms\Components\TextInput::make('billing_phone')
                        ->label('Điện thoại')
                        ->tel()
                        ->mask('9999 999 9999')
                        ->placeholder('0912 345 6789')
                        ->maxLength(20),
                ]),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('credit_limit')
                        ->label('Hạn mức tín dụng (₫)')
                        ->prefix('₫')
                        ->formatStateUsing(fn ($state) => $state !== null ? (int) round((float) $state) : null)
                        ->mask(RawJs::make("\$money(\$input, '.', ',', 0)"))
                        ->stripCharacters(',')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('payment_terms_days')
                        ->label('Kỳ thanh toán (ngày)')
                        ->numeric()
                        ->default(30)
                        ->minValue(1)
                        ->maxValue(365)
                        ->suffix('ngày'),
                ]),
                Forms\Components\Textarea::make('billing_address')
                    ->label('Địa chỉ')
                    ->rows(2),
            ]),
            Forms\Components\Section::make('Khác')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('website')
                        ->label('Website')
                        ->url(),
                    Forms\Components\TextInput::make('logo_url')
                        ->label('Đường dẫn logo'),
                ]),
            ])->collapsible(),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin cơ bản')->schema([
                Infolists\Components\Grid::make(3)->schema([
                    Infolists\Components\TextEntry::make('name')->label('Tên'),
                    Infolists\Components\TextEntry::make('type')->label('Loại')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            Organization::TYPE_AGENCY => 'info',
                            Organization::TYPE_BRAND => 'success',
                            default => 'gray',
                        })
                        // Thiếu dòng này thì thẻ hiện thẳng mã `agency`: có màu,
                        // không có chữ. Đúng lỗi cột trạng thái chiến dịch đã mắc
                        // ở bảng quản trị sàn trước PR #41.
                        ->formatStateUsing(fn (string $state): string => Organization::TYPE_LABELS[$state] ?? $state),
                    Infolists\Components\TextEntry::make('status')->label('Trạng thái')
                        ->badge()
                        ->color(fn (string $state): string => $state === Organization::STATUS_ACTIVE ? 'success' : 'danger')
                        ->formatStateUsing(fn (string $state): string => Organization::STATUS_LABELS[$state] ?? $state),
                ]),
                Infolists\Components\Grid::make(3)->schema([
                    Infolists\Components\TextEntry::make('tax_id')->label('MST')->default('—'),
                    Infolists\Components\TextEntry::make('billing_email')->label('Email thanh toán')->default('—'),
                    Infolists\Components\TextEntry::make('billing_phone')->label('SĐT')->default('—'),
                ]),
            ]),
            Infolists\Components\Section::make('Thống kê')->schema([
                Infolists\Components\Grid::make(4)->schema([
                    Infolists\Components\TextEntry::make('campaigns_count')
                        ->label('Chiến dịch')
                        ->getStateUsing(fn (Organization $r) => $r->campaigns()->count()),
                    Infolists\Components\TextEntry::make('active_campaigns')
                        ->label('Đang chạy')
                        // `Campaign::`, KHÔNG `Organization::` — đây là
                        // `campaigns.status`, một enum khác tình cờ cũng có mã
                        // `active`. Hằng của `Organization` ở đây vẫn ra đúng
                        // chuỗi nhưng dẫn người đọc sau đi sai bảng.
                        ->getStateUsing(fn (Organization $r) => $r->campaigns()
                            ->where('status', Campaign::STATUS_ACTIVE)->count())
                        ->color('success'),
                    Infolists\Components\TextEntry::make('total_paid')
                        ->label('Đã thanh toán')
                        ->getStateUsing(fn (Organization $r) => $r->payments()->where('status', 'completed')->sum('amount'))
                        ->money('VND'),
                    Infolists\Components\TextEntry::make('users_count')
                        ->label('Thành viên')
                        ->getStateUsing(fn (Organization $r) => $r->organizationUsers()->count()),
                ]),
            ]),
            Infolists\Components\Section::make('Thành viên')->schema([
                Infolists\Components\RepeatableEntry::make('organizationUsers')
                    ->label('')
                    ->schema([
                        Infolists\Components\Grid::make(3)->schema([
                            Infolists\Components\TextEntry::make('user.name')->label('Tên'),
                            Infolists\Components\TextEntry::make('user.email')->label('Email'),
                            Infolists\Components\TextEntry::make('role')->label('Vai trò')->badge()
                                ->formatStateUsing(fn (string $state): string => OrganizationUser::ROLE_LABELS[$state] ?? $state),
                        ]),
                    ]),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Organization::TYPE_AGENCY => 'info',
                        Organization::TYPE_BRAND => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => Organization::TYPE_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('organization_users_count')
                    ->label('Người dùng')
                    ->counts('organizationUsers')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('campaigns_count')
                    ->label('Chiến dịch')
                    ->counts('campaigns')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (string $state): string => $state === Organization::STATUS_ACTIVE ? 'success' : 'danger')
                    ->formatStateUsing(fn (string $state): string => Organization::STATUS_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('credit_limit')
                    ->label('Hạn mức')
                    ->money('VND')
                    ->default('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(Organization::TYPE_LABELS),
                Tables\Filters\SelectFilter::make('status')
                    ->options(Organization::STATUS_LABELS),
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
            'index'  => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'view'   => Pages\ViewOrganization::route('/{record}'),
            'edit'   => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\OrganizationResource\RelationManagers\OrganizationUsersRelationManager::class,
        ];
    }
}
