<?php

namespace App\Services;

use App\Models\OwnerUser;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Hàng rào theo mạng lưới: thành viên này được chạm vào những mạng lưới nào.
 *
 * ══ Chuyện trước 13/10/2026 ══
 *
 * `owner_users.allowed_network_ids` được **bốn** biểu mẫu Filament thu thập,
 * bảng thành viên hiện ra *"2 restricted"*, `UserInvitationService` mang nó
 * theo trong thư mời — và **không một chỗ nào thực thi**. Phương thức duy nhất
 * đọc cột đó, `OwnerUser::canAccessNetwork()`, không có người gọi.
 *
 * Nghĩa là: một chủ sở hữu giới hạn nhân viên vào một mạng lưới, nhìn thấy
 * dòng chữ "1 restricted" xác nhận việc đó, và nhân viên vẫn thấy toàn bộ kho.
 * Một ô cấu hình least-privilege không làm gì còn tệ hơn không có ô nào, vì nó
 * làm người ta tin rằng đã khoá.
 *
 * ══ Thực thi ở hai tầng, cùng đọc một nguồn ══
 *
 *  1. `HasOwnerScope` — global scope trên 5 model có `owner_id`. Lo phần
 *     **danh sách** và phần **tìm ra một hàng**: bảng Filament, widget,
 *     `findOrFail` của route binding, mọi endpoint liệt kê kho.
 *  2. `NetworkPolicy` · `SitePolicy` · `ScreenPolicy` — lo câu trả lời
 *     *được / không được* khi một hàng đã nằm trong tay, kể cả khi nó được
 *     tìm bằng `withoutGlobalScope('owner_scope')`. Một tầng không thay được
 *     tầng kia: scope mà thiếu policy thì một hàng lọt qua đường khác là mất
 *     hàng rào; policy mà thiếu scope thì danh sách vẫn liệt kê đủ.
 *
 * Thêm chốt `Site::saving` cho đường **ghi**: `sites.network_id` là chỗ duy
 * nhất quyết định một địa điểm thuộc mạng lưới nào, nên đó là chỗ duy nhất
 * cần chặn để không ai đặt được kho sang bên kia hàng rào.
 *
 * ══ Vì sao phải ghi nhớ ══
 *
 * Global scope chạy ở **mọi** truy vấn tới 5 model đó — một trang kho có thể
 * là vài chục truy vấn. Hỏi `owner_users` mỗi lần là vài chục lượt đi lại cho
 * một câu trả lời không đổi trong một request.
 *
 * Service này bind bằng `scoped()`, nên mỗi request (và trong test: mỗi ca) có
 * một thực thể riêng — không có trạng thái rò từ request này sang request
 * khác. `OwnerUser::booted()` xoá bộ nhớ khi hàng pivot được lưu hoặc xoá, nên
 * một lần đổi giới hạn có hiệu lực ngay trong cùng request.
 */
class GioiHanNetwork
{
    /** @var array<string, list<int>|null> */
    private array $boNho = [];

    /**
     * Giới hạn của người đang đăng nhập, trong tenant họ đang chọn.
     *
     * @return list<int>|null `null` = không bị giới hạn
     */
    public static function hienTai(): ?array
    {
        $nguoi = auth()->user();

        return app(static::class)->cho(
            $nguoi,
            $nguoi instanceof User ? (string) $nguoi->current_owner_id : null,
        );
    }

    /** Người đang đăng nhập có đang bị giới hạn mạng lưới không */
    public static function dangBiGioiHan(): bool
    {
        return static::hienTai() !== null;
    }

    /**
     * @return list<int>|null `null` = không bị giới hạn
     */
    public function cho(?Authenticatable $nguoi, ?string $ownerId): ?array
    {
        // Lọt qua đây: khách chưa đăng nhập, và token đối tác `/api/v1`
        // (`ApiClient` là một model riêng, không phải `User`) — token đó đọc
        // toàn bộ kho theo hợp đồng, và CLAUDE.md §1 nói không đổi hành vi v1.
        if (! $nguoi instanceof User) {
            return null;
        }

        if ($nguoi->hasRole('super_admin')) {
            return null;
        }

        if (empty($ownerId)) {
            return null;
        }

        $khoa = $nguoi->getKey() . '|' . $ownerId;

        if (array_key_exists($khoa, $this->boNho)) {
            return $this->boNho[$khoa];
        }

        // Cố ý **không** kiểm media owner còn hoạt động ở đây. Một owner bị tạm
        // ngưng phải mất *tất cả*, không phải bị thu hẹp — và việc đó là của
        // `TenantPermission`, đã làm ở mọi cửa ghi. Nếu hàng pivot không tồn
        // tại (membership bị gỡ mà `current_owner_id` còn sót) thì ở đây trả
        // `null`, đúng như `HasOwnerScope` vẫn làm từ trước: chặn người đó là
        // việc của policy, không phải của hàng rào mạng lưới.
        $thanhVien = OwnerUser::query()
            ->where('owner_id', $ownerId)
            ->where('user_id', $nguoi->getKey())
            ->first();

        return $this->boNho[$khoa] = $thanhVien?->networkDuocPhep();
    }

    public function xoaBoNho(): void
    {
        $this->boNho = [];
    }
}
