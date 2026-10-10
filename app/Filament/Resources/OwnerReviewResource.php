<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OwnerReviewResource\Pages;
use App\Models\OwnerReview;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Kiểm duyệt đánh giá media owner (review mục 6).
 *
 * Nội dung do người mua viết nên phải qua kiểm duyệt trước khi hiện — một nhận
 * xét có thể nhắc tên bên thứ ba hoặc dùng lời lẽ không phù hợp, và nó sẽ nằm
 * trên trang công khai của đối tác.
 */
class OwnerReviewResource extends Resource
{
    protected static ?string $model = OwnerReview::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Sàn';

    protected static ?string $navigationLabel = 'Đánh giá media owner';

    protected static ?int $navigationSort = 7;

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('status', OwnerReview::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Nội dung đánh giá')
                ->description('Do người mua gửi. Không sửa nội dung gốc.')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\TextInput::make('owner.name')->label('Media owner')->disabled(),
                        Forms\Components\TextInput::make('organization.name')->label('Người đánh giá')->disabled(),
                        Forms\Components\TextInput::make('rating')->label('Số sao')->disabled(),
                    ]),
                    Forms\Components\Textarea::make('comment')->label('Nhận xét')->rows(5)->disabled(),
                ]),

            Forms\Components\Section::make('Kiểm duyệt')->schema([
                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options(OwnerReview::STATUS_LABELS)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (string $state, Forms\Set $set) {
                        $set('published_at', $state === OwnerReview::STATUS_PUBLISHED ? now() : null);
                    }),
                Forms\Components\Textarea::make('moderation_note')
                    ->label('Lý do / ghi chú kiểm duyệt')
                    ->helperText('Không hiển thị công khai.')
                    ->rows(3)
                    ->required(fn (Forms\Get $get) => $get('status') === OwnerReview::STATUS_REJECTED),
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
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Media owner')->searchable()->limit(28),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Người đánh giá')->searchable()->limit(28),
                Tables\Columns\TextColumn::make('rating')
                    ->label('Sao')
                    ->formatStateUsing(fn (int $state) => str_repeat('★', $state) . str_repeat('☆', 5 - $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('comment')
                    ->label('Nhận xét')->limit(50)->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => OwnerReview::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        OwnerReview::STATUS_PENDING   => 'warning',
                        OwnerReview::STATUS_PUBLISHED => 'success',
                        OwnerReview::STATUS_REJECTED  => 'danger',
                        default                       => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Gửi lúc')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(OwnerReview::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('rating')
                    ->label('Số sao')
                    ->options([5 => '5 sao', 4 => '4 sao', 3 => '3 sao', 2 => '2 sao', 1 => '1 sao']),
            ])
            ->actions([
                Tables\Actions\Action::make('publish')
                    ->label('Duyệt đăng')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (OwnerReview $record) => $record->status !== OwnerReview::STATUS_PUBLISHED)
                    ->requiresConfirmation()
                    ->action(fn (OwnerReview $record) => $record->update([
                        'status'       => OwnerReview::STATUS_PUBLISHED,
                        'published_at' => now(),
                    ])),
                Tables\Actions\EditAction::make(),
            ]);
    }

    /** Đánh giá do người mua gửi — admin không tạo tay. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOwnerReviews::route('/'),
            'edit'  => Pages\EditOwnerReview::route('/{record}/edit'),
        ];
    }
}
