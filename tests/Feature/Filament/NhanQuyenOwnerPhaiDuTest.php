<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\PermissionMatrix;
use App\Models\OwnerUser;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Trang `PermissionMatrix` phải có nhãn cho **mọi** quyền của media owner.
 *
 * ══ Lỗ này bắt được khi đi tìm một thứ khác ══
 *
 * `OwnerUser::PERMISSIONS` có **11** quyền; `PermissionMatrix` khai nhãn cho
 * **9**. View rơi về `['label' => $action, 'desc' => '']`, nên hai quyền thiếu
 * nhãn hiện ra bằng **mã tiếng Anh** và **không có dòng mô tả** — trên đúng
 * trang có việc giải thích quyền cho người gán vai trò.
 *
 * Hai quyền rơi vào đó không phải hai quyền phụ: `manage_bookings` (duyệt / từ
 * chối yêu cầu đặt chỗ) và `settle_refunds` (khai đã hoàn tiền cho người mua).
 * Cả hai đều có hệ quả tiền, và docblock của chúng trong `PERMISSIONS` đã viết
 * sẵn lời giải thích cẩn thận — chỉ là trang không đọc tới.
 *
 * ══ Chốt tự tìm phạm vi ══
 *
 * Ca này không ghim 11 cái tên. Nó lấy danh sách từ `PERMISSIONS` và đòi bảng
 * nhãn phủ hết, nên thêm một quyền thứ 12 mà quên nhãn là nó đỏ — không cần ai
 * nhớ ra mà sửa ca test. Cùng họ với tám cái chốt ở
 * `docs/LO-TRINH-SAU-GIAI-DOAN-0.md`.
 *
 * Nó cũng canh **chiều ngược**: một nhãn cho quyền không tồn tại là một dòng
 * vẽ ra trên trang mà không vai trò nào có — thường là dấu vết của một quyền
 * đã bị gỡ khỏi `PERMISSIONS` mà nhãn còn ở lại.
 */
class NhanQuyenOwnerPhaiDuTest extends TestCase
{
    /**
     * `getViewData()` là `public`, nhưng nó đọc CSDL (thống kê theo vai trò).
     * Lấy riêng mảng nhãn qua reflection thì ca này không cần CSDL và không
     * phụ thuộc dữ liệu mẫu.
     *
     * @return array<string, array{label: string, icon: string, desc: string}>
     */
    private function bangNhan(): array
    {
        $ma = file_get_contents(
            (new ReflectionMethod(PermissionMatrix::class, 'getViewData'))
                ->getDeclaringClass()
                ->getFileName(),
        );

        // Lấy đúng khối `$permissionLabels = [ … ];`
        $this->assertSame(
            1,
            preg_match('/\$permissionLabels\s*=\s*\[(.*?)\n\s*\];/s', $ma, $khop),
            'Không tìm thấy khối $permissionLabels — tên biến đã đổi?',
        );

        // Mỗi dòng dạng `'khoa' => [...]`.
        preg_match_all("/'([a-z_]+)'\s*=>\s*\[/", $khop[1], $khoa);

        $nhan = array_fill_keys($khoa[1], true);

        $this->assertNotEmpty($nhan, 'Không đọc được khóa nào trong $permissionLabels');

        return $nhan;
    }

    public function test_moi_quyen_trong_permissions_deu_co_nhan(): void
    {
        $nhan   = $this->bangNhan();
        $thieu  = array_diff(array_keys(OwnerUser::PERMISSIONS), array_keys($nhan));

        $this->assertSame(
            [],
            array_values($thieu),
            'Quyền thiếu nhãn sẽ hiện ra bằng mã tiếng Anh và không có mô tả: '
            . implode(', ', $thieu),
        );
    }

    public function test_khong_co_nhan_nao_tro_vao_quyen_khong_ton_tai(): void
    {
        $nhan = $this->bangNhan();
        $du   = array_diff(array_keys($nhan), array_keys(OwnerUser::PERMISSIONS));

        $this->assertSame(
            [],
            array_values($du),
            'Nhãn cho quyền không có trong PERMISSIONS là một dòng không vai trò nào có: '
            . implode(', ', $du),
        );
    }
}
