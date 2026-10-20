<?php

namespace App\Filament\Pages;

use App\Services\SoatViTriService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Báo cáo chất lượng dữ liệu vị trí — `/admin/soat-vi-tri`.
 *
 * `docs/PLAN-EXPLORE-QUYET-DINH-MUA.md` chốt: nhãn cảnh báo dữ liệu **không**
 * in ra trang bán hàng. Người mua đọc "tên nói Aeon Mall Tân Phú nhưng toạ độ
 * ở Bắc Kạn" thì chỉ biết thêm rằng dữ liệu của sàn không đáng tin, và họ
 * không sửa được gì. Người sửa được là quản trị sàn, nên bảng đó ở đây.
 *
 * Trang chỉ **đọc** và chỉ **hiển thị**. Mọi phép đo nằm ở
 * `SoatViTriService`, dùng chung với `php artisan oohx:soat-vi-tri`
 * (CLAUDE.md mục 1).
 */
class SoatViTri extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Kho điểm phát';
    protected static ?string $navigationLabel = 'Soát dữ liệu vị trí';
    protected static ?int    $navigationSort  = 9;
    protected static ?string $title           = 'Soát dữ liệu vị trí';
    protected static ?string $slug            = 'soat-vi-tri';

    protected static string $view = 'filament.pages.soat-vi-tri';

    /**
     * Badge đếm **phát hiện mức cao**, không đếm `xem_tay`.
     *
     * Một con số trên menu phải là số việc phải làm. Gộp cả `xem_tay` vào thì
     * badge không bao giờ về 0 — kho hôm nay có 21 ô tỉnh hai cấp mà không
     * cái nào là lỗi — và một badge không bao giờ tắt thì không ai nhìn nữa.
     */
    public static function getNavigationBadge(): ?string
    {
        // Nhớ 10 phút: hàm này chạy ở **mọi** lần dựng menu, tức mọi trang
        // trong `/admin`. Lượt soát đọc cả kho công khai rồi gom nhóm bốn
        // lần; trả giá đó cho một con số trên thanh bên là trả sai chỗ. Trang
        // báo cáo vẫn tự soát lại, nên người đang xem luôn thấy số mới.
        $so = Cache::remember(
            'soat-vi-tri:so-cao',
            now()->addMinutes(10),
            fn () => collect(app(SoatViTriService::class)->soat()['loi'])
                ->where('muc', SoatViTriService::MUC_CAO)
                ->count(),
        );

        return $so > 0 ? (string) $so : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function getViewData(): array
    {
        $kq = app(SoatViTriService::class)->soat();

        return [
            'kq'      => $kq,
            'theoLoai' => $kq['theo_loai'],
            'nhomLoi' => collect($kq['loi'])->groupBy('loai'),
            'soCao'   => collect($kq['loi'])->where('muc', SoatViTriService::MUC_CAO)->count(),
            'soXemTay' => collect($kq['loi'])->where('muc', SoatViTriService::MUC_XEM_TAY)->count(),
        ];
    }
}
