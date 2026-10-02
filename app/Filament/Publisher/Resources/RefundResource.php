<?php

namespace App\Filament\Publisher\Resources;

use App\Filament\Publisher\Resources\RefundResource\Pages;
use App\Filament\Shared\Resources\BaseRefundResource;
use App\Models\Refund;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hoàn tiền ở panel media owner.
 *
 * Đây là **danh sách việc phải làm** của owner, không phải báo cáo: sàn không
 * giữ tiền nên chính owner là người chuyển tiền lại cho người mua. Trước khi có
 * trang này, một dòng đặt chỗ bị hủy không tạo ra thông báo nào cho owner —
 * nghĩa vụ hoàn tiền nằm trong bảng và không ai thấy.
 */
class RefundResource extends BaseRefundResource
{
    protected static ?string $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 2;

    // Luật nằm ở `RefundPolicy`, một chỗ duy nhất dùng chung cho cả hai panel
    // và cho API sau này (CLAUDE.md mục 4). Ở đây chỉ hỏi lại.

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
            Tables\Columns\TextColumn::make('campaign.organization.name')
                ->label('Người mua')
                ->searchable()
                ->default('—'),
        ];
    }

    /**
     * Scope theo owner đang chọn.
     *
     * `Refund` không mang `HasOwnerScope`, nên nếu quên dòng này thì owner A
     * thấy nghĩa vụ hoàn tiền của owner B — tức thấy người mua của nhau và số
     * tiền của nhau. Chặn mặc định khi không xác định được tenant.
     */
    public static function getEloquentQuery(): Builder
    {
        $ownerId = auth()->user()?->current_owner_id;

        return parent::getEloquentQuery()
            ->when(
                $ownerId,
                fn (Builder $q) => $q->where('owner_id', $ownerId),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            )
            ->with(['campaign:id,code,name,organization_id', 'campaign.organization:id,name', 'bookingLine:id,screen_id', 'bookingLine.screen:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRefunds::route('/'),
        ];
    }
}
