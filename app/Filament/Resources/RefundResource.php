<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RefundResource\Pages;
use App\Filament\Shared\Resources\BaseRefundResource;
use App\Models\Refund;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hoàn tiền ở panel quản trị sàn.
 *
 * Quản trị sàn thấy mọi khoản hoàn tiền của mọi media owner, vì khi người mua
 * khiếu nại "tôi hủy đúng hạn mà không được hoàn" thì phải có một chỗ trả lời
 * được. Đây cũng là chỗ trưng ra khi cơ quan quản lý hỏi về chính sách hủy.
 */
class RefundResource extends BaseRefundResource
{
    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 3;

    // Luật nằm ở `RefundPolicy` — một chỗ cho cả hai panel và cho API sau này.
    // Quản trị sàn khai hộ được, vì có ca owner không chịu xử lý và sàn phải
    // đóng hồ sơ khiếu nại; mỗi lần khai đều ghi tên người thực hiện vào lịch
    // sử chiến dịch, nên không có đường khai ẩn danh.

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Refund::class) ?? false;
    }

    protected static function canSettleRecord(Refund $refund): bool
    {
        return auth()->user()?->can('settle', $refund) ?? false;
    }

    protected static function additionalTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('owner.name')
                ->label('Media owner')
                ->searchable()
                ->default('—'),

            Tables\Columns\TextColumn::make('campaign.organization.name')
                ->label('Người mua')
                ->searchable()
                ->default('—'),
        ];
    }

    protected static function additionalFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('owner_id')
                ->label('Media owner')
                ->relationship('owner', 'name')
                ->searchable(),
        ];
    }

    /**
     * Quản trị sàn thấy mọi khoản; ai khác vào được panel này thì vẫn chỉ thấy
     * khoản của media owner mình thuộc.
     *
     * Cổng vào panel `/admin` đã chặn người ngoài, nhưng phạm vi dữ liệu không
     * nên phụ thuộc vào một lớp duy nhất: đây là bảng có tên người mua và số
     * tiền của từng owner.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->unless(
                $user?->hasRole('super_admin'),
                fn (Builder $q) => $q->whereIn(
                    'owner_id',
                    $user ? $user->owners()->pluck('owners.id') : [],
                ),
            )
            ->with(['campaign:id,code,name,organization_id', 'campaign.organization:id,name', 'owner:id,name', 'bookingLine:id,screen_id', 'bookingLine.screen:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRefunds::route('/'),
        ];
    }
}
