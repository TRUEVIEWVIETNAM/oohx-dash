<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PublicReflectionResource\Pages;
use App\Models\PublicReflection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Xử lý phản ánh của tổ chức xã hội (yêu cầu review đăng ký sàn TMĐT, mục 3).
 *
 * Việc đăng công khai là một hành động có chủ ý của admin, không phải hệ quả của
 * trạng thái: `published_at` là công tắc riêng, tách khỏi `status`. "Đã xử lý"
 * không đồng nghĩa với "được phép đăng nguyên văn" — nội dung phản ánh có thể
 * nhắc tên bên thứ ba.
 */
class PublicReflectionResource extends Resource
{
    protected static ?string $model = PublicReflection::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Sàn';

    protected static ?string $navigationLabel = 'Phản ánh của TCXH';

    protected static ?int $navigationSort = 6;

    /** Số phản ánh chưa xử lý — để admin không bỏ sót. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('status', PublicReflection::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Nội dung phản ánh')
                ->description('Do tổ chức xã hội gửi lên. Không sửa nội dung gốc.')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Mã tra cứu')
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('received_at')
                            ->label('Thời điểm tiếp nhận')
                            ->disabled(),
                    ]),
                    Forms\Components\TextInput::make('organization_name')
                        ->label('Tên tổ chức')
                        ->disabled(),
                    Forms\Components\TextInput::make('subject')
                        ->label('Tiêu đề')
                        ->disabled(),
                    Forms\Components\Textarea::make('content')
                        ->label('Nội dung')
                        ->rows(8)
                        ->disabled(),
                ]),

            Forms\Components\Section::make('Thông tin liên hệ')
                ->description('Dữ liệu cá nhân theo Nghị định 13/2023/NĐ-CP — chỉ dùng để phản hồi, không công khai.')
                ->collapsed()
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\TextInput::make('contact_name')->label('Người liên hệ')->disabled(),
                        Forms\Components\TextInput::make('contact_email')->label('Email')->disabled(),
                        Forms\Components\TextInput::make('contact_phone')->label('Điện thoại')->disabled(),
                    ]),
                ]),

            Forms\Components\Section::make('Xử lý')->schema([
                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options(PublicReflection::STATUS_LABELS)
                    ->required()
                    ->live(),
                Forms\Components\Textarea::make('resolution')
                    ->label('Kết quả xử lý')
                    ->helperText('Sẽ hiển thị công khai cùng phản ánh nếu bật "Đăng công khai".')
                    ->rows(5)
                    ->required(fn (Forms\Get $get) => $get('status') === PublicReflection::STATUS_RESOLVED),
                Forms\Components\DateTimePicker::make('resolved_at')
                    ->label('Thời điểm xử lý xong'),
                Forms\Components\Textarea::make('internal_notes')
                    ->label('Ghi chú nội bộ')
                    ->helperText('Không bao giờ hiển thị ra ngoài.')
                    ->rows(3),
            ]),

            Forms\Components\Section::make('Công bố')->schema([
                Forms\Components\Toggle::make('is_published')
                    ->label('Đăng công khai')
                    ->helperText('Hiện phản ánh này trên trang "Danh sách phản ánh của TCXH". Chỉ tổ chức, tiêu đề, nội dung, ngày tiếp nhận, trạng thái và kết quả xử lý được hiện — thông tin liên hệ thì không.')
                    ->formatStateUsing(fn (?PublicReflection $record) => (bool) $record?->published_at)
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (bool $state, Forms\Set $set) {
                        $set('published_at', $state ? now() : null);
                    }),
                Forms\Components\DateTimePicker::make('published_at')
                    ->label('Thời điểm đăng')
                    ->disabled()
                    ->dehydrated(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('organization_name')
                    ->label('Tổ chức')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('subject')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Tiếp nhận')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PublicReflection::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PublicReflection::STATUS_PENDING   => 'warning',
                        PublicReflection::STATUS_IN_REVIEW => 'info',
                        PublicReflection::STATUS_RESOLVED  => 'success',
                        PublicReflection::STATUS_REJECTED  => 'gray',
                        default                            => 'gray',
                    }),
                Tables\Columns\IconColumn::make('published_at')
                    ->label('Công khai')
                    ->boolean()
                    ->getStateUsing(fn (PublicReflection $record) => (bool) $record->published_at),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(PublicReflection::STATUS_LABELS),
                Tables\Filters\TernaryFilter::make('published_at')
                    ->label('Đã công khai')
                    ->nullable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    /** Phản ánh do khách gửi qua form công khai — admin không tạo tay. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPublicReflections::route('/'),
            'edit'  => Pages\EditPublicReflection::route('/{record}/edit'),
        ];
    }
}
