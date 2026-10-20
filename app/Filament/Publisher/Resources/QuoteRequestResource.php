<?php

namespace App\Filament\Publisher\Resources;

use App\Filament\Publisher\Resources\QuoteRequestResource\Pages;
use App\Filament\Shared\Resources\BaseQuoteRequestResource;
use App\Models\QuoteRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Yêu cầu báo giá ở panel media owner.
 *
 * Đây là **danh sách việc phải làm**: người mua đã khoanh vị trí của owner và
 * đang chờ một con số. 85/104 vị trí trên sàn chưa có giá, nên đường này là
 * cách chính để một vị trí có giá lần đầu.
 *
 * ══ Hai ranh giới dữ liệu ══
 *
 * 1. **Phạm vi**: chỉ yêu cầu có vị trí của owner đang chọn, và `with` chỉ
 *    nạp dòng vị trí của owner đó. Owner A không bao giờ biết yêu cầu này
 *    còn hỏi owner B — biết được là biết đối thủ đang được hỏi gì.
 * 2. **Liên hệ người mua**: chỉ sau khi quản trị duyệt. Trước đó owner thấy
 *    đủ thứ cần để báo giá — vị trí nào, cửa sổ thời gian, ngân sách — nhưng
 *    không có đường liên hệ thẳng.
 */
class QuoteRequestResource extends BaseQuoteRequestResource
{
    protected static ?string $navigationGroup = 'Đặt chỗ';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', QuoteRequest::class) ?? false;
    }

    protected static function xemDuocLienHe(QuoteRequest $yeuCau): bool
    {
        return auth()->user()?->can('xemDuocLienHe', $yeuCau) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $ownerId = (string) (auth()->user()?->current_owner_id ?? '');

        if ($ownerId === '') {
            return null;
        }

        $so = QuoteRequest::where('status', QuoteRequest::STATUS_PENDING)
            ->whereHas('items', fn ($q) => $q->where('owner_id', $ownerId))
            ->count();

        return $so > 0 ? (string) $so : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Scope theo owner đang chọn.
     *
     * Owner trống thì trả về **tập rỗng**, không phải tập đầy đủ: cùng nguyên
     * tắc "chặn mặc định" của `HasOwnerScope`. Một phiên chưa chọn owner mà
     * thấy mọi yêu cầu của sàn là rò rỉ, không phải tiện lợi.
     */
    public static function getEloquentQuery(): Builder
    {
        $ownerId = (string) (auth()->user()?->current_owner_id ?? '');

        if ($ownerId === '') {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->whereHas('items', fn ($q) => $q->where('owner_id', $ownerId))
            ->with(['items' => fn ($q) => $q->where('owner_id', $ownerId)]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuoteRequests::route('/'),
            'view'  => Pages\ViewQuoteRequest::route('/{record}'),
        ];
    }
}
