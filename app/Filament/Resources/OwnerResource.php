<?php
namespace App\Filament\Resources;

use App\Models\Owner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Tables\Filters\SelectFilter;

use App\Filament\Resources\OwnerResource\Pages;
use App\Filament\Resources\OwnerResource\RelationManagers;

class OwnerResource extends Resource
{
    protected static ?string $model = Owner::class;

    protected static ?string $navigationGroup = 'Organizations';

    protected static ?int $navigationSort = 1;
    protected static ?string $label = 'Media Owner';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basic Info')->columns(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->required()->maxLength(255)->columnSpan(2)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (callable $set, ?string $state) => $set('slug', \Illuminate\Support\Str::slug($state, '-', 'vi'))),
                Forms\Components\TextInput::make('slug')
                    ->unique(ignoreRecord:true)
                    ->helperText('Tự động từ tên. Có thể sửa tay.'),
                Forms\Components\Select::make('type')
                    ->label('Loại đơn vị')
                    ->options(Owner::TYPE_LABELS)
                    ->required(),
                Forms\Components\Select::make('onboard_method')
                    ->options(['cms'=>'CMS + Player','api'=>'API','vast'=>'VAST','hardware'=>'Hardware Bundle'])
                    ->required(),
                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options(Owner::STATUS_LABELS)
                    ->default(Owner::STATUS_PENDING)->required()
                    ->helperText('Chỉ owner ở trạng thái Active mới hiển thị công khai trên sàn. Duyệt sau khi đã rà xong hồ sơ pháp lý bên dưới.'),
            ]),

            Forms\Components\Section::make('Hồ sơ pháp lý')
                ->description('Thông tin bắt buộc lưu trữ theo yêu cầu đăng ký sàn TMĐT. Rà soát xong mới chuyển trạng thái sang Active.')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('legal_completeness')
                        ->label('Tình trạng hồ sơ')
                        ->columnSpan(2)
                        ->content(function (?Owner $record) {
                            if (! $record) {
                                return 'Lưu bản ghi để kiểm tra.';
                            }

                            return $record->hasCompleteLegalProfile()
                                ? '✓ Đã đủ thông tin tối thiểu để duyệt.'
                                : '⚠ Chưa đủ thông tin tối thiểu (tên pháp lý, MST + ngày/nơi cấp, người đại diện, email, điện thoại, ĐKKD).';
                        }),
                    Forms\Components\TextInput::make('legal_name')
                        ->label('Tên công ty (theo ĐKKD)')
                        ->maxLength(255)->columnSpan(2),
                    Forms\Components\TextInput::make('tax_code')
                        ->label('Mã số thuế')->maxLength(20),
                    Forms\Components\DatePicker::make('tax_code_issued_on')
                        ->label('Ngày cấp')->displayFormat('d/m/Y'),
                    Forms\Components\TextInput::make('tax_code_issued_by')
                        ->label('Nơi cấp')->maxLength(255)->columnSpan(2)
                        ->placeholder('Sở Tài chính thành phố ...'),
                    Forms\Components\TextInput::make('legal_representative')
                        ->label('Người đại diện theo pháp luật')->maxLength(255)->columnSpan(2),
                    Forms\Components\FileUpload::make('business_license_path')
                        ->label('Giấy đăng ký kinh doanh')
                        ->helperText('PDF hoặc ảnh, tối đa 10MB. Lưu ở khu vực riêng, không truy cập công khai được.')
                        ->columnSpan(2)
                        // disk 'private': đây là giấy tờ pháp lý của đối tác, không
                        // phải ảnh bìa — không được nằm cạnh logo trên disk public.
                        ->disk('private')
                        ->directory('owners/licenses')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(10240)
                        ->downloadable(),
                    Forms\Components\DateTimePicker::make('verified_at')
                        ->label('Thời điểm xác thực hồ sơ')
                        ->helperText('Điền khi đã đối chiếu xong giấy tờ.'),
                ]),

            Forms\Components\Section::make('Tài khoản nhận tiền')
                ->description('Người mua chuyển khoản trực tiếp cho media owner — OOHX không thu hộ. Thiếu thông tin này thì màn hình của owner không thanh toán được.')
                ->columns(2)
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('bank_name')
                        ->label('Ngân hàng')->maxLength(255)
                        ->placeholder('Vietcombank (VCB)'),
                    Forms\Components\TextInput::make('bank_account_number')
                        ->label('Số tài khoản')->maxLength(40),
                    Forms\Components\TextInput::make('bank_account_name')
                        ->label('Chủ tài khoản')->maxLength(255)
                        ->placeholder('CONG TY TNHH ...'),
                    Forms\Components\TextInput::make('bank_branch')
                        ->label('Chi nhánh')->maxLength(255),
                ]),

            Forms\Components\Section::make('Hồ sơ công khai')->columns(2)->collapsible()->schema([
                Forms\Components\TextInput::make('tagline')
                    ->label('Tagline')->maxLength(255)->columnSpan(2),
                Forms\Components\RichEditor::make('about')
                    ->label('Giới thiệu')->columnSpan(2),
                Forms\Components\FileUpload::make('logo_url')
                    ->label('Logo')->image()->disk('public')->directory('owners/logos')->maxSize(5120),
                Forms\Components\FileUpload::make('cover_url')
                    ->label('Ảnh bìa')->image()->disk('public')->directory('owners/covers')->maxSize(10240),
                Forms\Components\TextInput::make('website')->label('Website')->url(),
                Forms\Components\TextInput::make('email')->label('Email')->email(),
                Forms\Components\TextInput::make('phone')->label('Điện thoại')
                    ->tel()->maxLength(20)
                    ->mask('9999 999 9999')
                    ->placeholder('0912 345 6789'),
                Forms\Components\TextInput::make('founded')->label('Năm thành lập')
                    ->numeric()->minValue(1900)->maxValue(2030),
                Forms\Components\Toggle::make('featured')->label('Featured'),
                Forms\Components\Toggle::make('verified')->label('Verified')
                    ->helperText('Hiển thị badge "Verified" trên frontpage'),
            ]),

            // Không đưa revenue_share_pct lên giao diện: sàn thu phí thuê bao của
            // media owner, không chia doanh thu giao dịch (hồ sơ TMĐT mục 16).
            // Cột vẫn còn trong DB nhưng không có cơ chế nào dùng tới nó.
            Forms\Components\Section::make('Billing')->columns(2)->collapsible()->schema([
                Forms\Components\KeyValue::make('billing_info')
                    ->label('Billing Info')->columnSpan(2),
                Forms\Components\Textarea::make('notes')->columnSpan(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Loại đơn vị')
                    ->formatStateUsing(fn ($state) => Owner::TYPE_LABELS[$state] ?? $state ?? '—')
                    ->colors(['primary'=>'media_owner','success'=>'retailer','warning'=>'self']),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Trạng thái')
                    ->formatStateUsing(fn ($state) => Owner::STATUS_LABELS[$state] ?? $state ?? '—')
                    ->colors(['warning'=>'pending','success'=>'active','danger'=>'suspended']),
                Tables\Columns\TextColumn::make('screens_count')
                    ->label('Screens')->counts('screens')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(Owner::STATUS_LABELS),
                SelectFilter::make('type')->label('Loại đơn vị')->options(Owner::TYPE_LABELS),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('stats')
                        ->icon('heroicon-o-chart-bar')
                        ->url(fn(Owner $r) => static::getUrl('edit', ['record'=>$r])),
                ]),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Overview')->columns(3)->schema([
                Infolists\Components\TextEntry::make('name'),
                Infolists\Components\TextEntry::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn ($state) => Owner::STATUS_LABELS[$state] ?? $state ?? '—'),
                Infolists\Components\TextEntry::make('type')->label('Loại đơn vị')->badge()
                    ->formatStateUsing(fn ($state) => Owner::TYPE_LABELS[$state] ?? $state ?? '—'),
                Infolists\Components\TextEntry::make('onboard_method'),
                Infolists\Components\TextEntry::make('created_at')->dateTime(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OwnerUsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOwners::route('/'),
            'create' => Pages\CreateOwner::route('/create'),
            'edit'   => Pages\EditOwner::route('/{record}/edit'),
        ];
    }
}
