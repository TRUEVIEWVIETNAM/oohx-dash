<?php

namespace App\Filament\Publisher\Resources;

use App\Filament\Publisher\Resources\BookingInboxResource\Pages;
use App\Models\Campaign;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BookingInboxResource extends Resource
{
    /**
     * Trạng thái chiến dịch **với tới được** ở hộp thư này.
     *
     * Một định nghĩa, dùng ở hai chỗ: `getEloquentQuery()` lọc theo nó, và bộ
     * lọc của bảng lấy nhãn theo nó. Trước đây hai danh sách được giữ riêng và
     * **đã lệch nhau**: truy vấn gồm `paused`, bộ lọc thì không — nên một
     * chiến dịch tạm dừng hiện trong bảng mà không lọc ra được.
     *
     * `draft` không có ở đây vì chiến dịch chưa gửi thì chưa đến tay media
     * owner; `cancelled` không có vì đã hủy thì không còn là việc của hộp thư.
     */
    public const VISIBLE_STATUSES = [
        'pending_approval', 'approved', 'rejected',
        'active', 'paused', 'completed',
    ];

    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Đặt chỗ';

    protected static ?string $navigationLabel = 'Hộp thư đặt chỗ';

    protected static ?string $modelLabel = 'Đặt chỗ';

    protected static ?string $pluralModelLabel = 'Đặt chỗ';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $ownerId = auth()->user()?->current_owner_id;
        if (! $ownerId) return null;

        $count = Campaign::where('status', 'pending_approval')
            ->whereHas('bookingLines', fn ($q) => $q->where('owner_id', $ownerId)->where('status', 'pending'))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->size('sm'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Chiến dịch')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Đại lý / Khách hàng')
                    ->limit(30),

                Tables\Columns\TextColumn::make('owner_lines_count')
                    ->label('Màn hình')
                    ->getStateUsing(function (Campaign $record) {
                        $ownerId = auth()->user()?->current_owner_id;
                        return $record->bookingLines()->where('owner_id', $ownerId)->count();
                    })
                    ->suffix(' screens')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('owner_estimated_cost')
                    ->label('Giá trị')
                    ->getStateUsing(function (Campaign $record) {
                        $ownerId = auth()->user()?->current_owner_id;
                        return $record->bookingLines()->where('owner_id', $ownerId)->sum('estimated_cost');
                    })
                    ->money('VND')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Bắt đầu')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending_approval' => 'warning',
                        'approved' => 'info',
                        'active' => 'success',
                        'rejected' => 'danger',
                        'completed' => 'gray',
                        default => 'gray',
                    })
                    // Bảng chữ ở `Campaign::STATUS_LABELS`. Bản `match` cũ ở
                    // đây **thiếu `paused`**, trong khi truy vấn của hộp thư
                    // gồm nó — nên một chiến dịch tạm dừng hiện ra với chữ
                    // `paused` nguyên văn tiếng Anh.
                    ->formatStateUsing(fn (string $state): string => Campaign::STATUS_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Gửi lúc')
                    ->dateTime('d/m H:i')
                    ->sortable(),
            ])
            ->filters([
                // Đúng các trạng thái truy vấn cho qua, không nhiều hơn: một
                // lựa chọn luôn trả về rỗng là một bộ lọc nói dối. `paused`
                // nay có mặt — nó vốn thiếu.
                Tables\Filters\SelectFilter::make('status')
                    ->options(Campaign::statusLabels(self::VISIBLE_STATUSES)),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->poll('30s');
    }

    /**
     * Hộp thư đặt chỗ dùng quyền RIÊNG của phía media owner.
     *
     * Không dùng chung `view` với khu người mua: quyền đó cho đọc trang thanh
     * toán chứa công nợ của mọi owner trong chiến dịch (Codex R01).
     */
    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('viewAsOwner', $record) ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole(['publisher', 'super_admin']) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ownerId = auth()->user()?->current_owner_id;

        return parent::getEloquentQuery()
            ->with(['organization', 'createdBy'])
            ->whereHas('bookingLines', fn ($q) => $q->where('owner_id', $ownerId))
            ->whereIn('status', self::VISIBLE_STATUSES);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingInbox::route('/'),
            'view'  => Pages\ViewBookingInbox::route('/{record}'),
        ];
    }
}
