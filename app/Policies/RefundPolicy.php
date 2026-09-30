<?php

namespace App\Policies;

use App\Models\OrganizationUser;
use App\Models\Refund;
use App\Models\User;
use App\Services\TenantPermission;

/**
 * Quyền trên nghĩa vụ hoàn tiền — **một bộ luật cho cả Filament và API**.
 *
 * CLAUDE.md mục 4: "Quyền phải được định nghĩa một chỗ và dùng chung cho cả
 * Filament lẫn API — không viết hai bộ luật." Bản đầu tôi để phép kiểm ngay
 * trong hai `RefundResource`, tức đã có hai bộ: sửa một panel là lệch panel
 * kia, và khi endpoint v2 cho hoàn tiền xuất hiện thì thành bộ thứ ba.
 *
 * Ba vai, ba phạm vi khác nhau:
 *
 * - **Quản trị sàn** thấy mọi khoản và khai hộ được, vì có ca owner không chịu
 *   xử lý và sàn phải đóng hồ sơ khiếu nại.
 * - **Media owner** chỉ thấy khoản của chính mình, và khai "đã hoàn" là việc
 *   của tiền nên hẹp hơn quyền chốt đơn.
 * - **Người mua** chỉ xem khoản của tổ chức mình, và **không bao giờ** được
 *   khai đã hoàn: người nhận tiền tự xác nhận mình đã nhận thì con số không
 *   còn nghĩa gì với bên phải trả.
 */
class RefundPolicy
{
    /**
     * Kiểm theo `$user` được truyền vào, **không** qua `TenantPermission::check()`.
     *
     * `check()` đọc `auth()->user()`, nên một policy dùng nó sẽ trả lời về
     * người đang đăng nhập chứ không về người được hỏi — sai ngay khi có lệnh
     * artisan, job, hay một endpoint kiểm quyền hộ người khác.
     */
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return TenantPermission::for($user)->can('manage_bookings');
    }

    public function view(User $user, Refund $refund): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($this->ownerSideCan($user, $refund, 'manage_bookings')) {
            return true;
        }

        // Người mua xem được khoản hoàn tiền của tổ chức mình.
        return $this->buyerMembership($user, $refund)?->can('view_campaigns') ?? false;
    }

    /**
     * Khai "đã hoàn" — một lời khai về lần chuyển khoản thật ở ngoài hệ thống.
     *
     * Kiểm theo **owner của chính khoản hoàn tiền**, không theo owner đang chọn
     * trong phiên: người thuộc hai owner chỉ cần đổi owner đang chọn là phép
     * kiểm theo phiên cho qua sai. Đúng lỗi `CampaignPolicy::membership()` đã
     * ghi lại.
     */
    public function settle(User $user, Refund $refund): bool
    {
        if ($refund->status !== Refund::STATUS_PENDING) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->ownerSideCan($user, $refund, 'settle_refunds');
    }

    /** Thành viên của media owner đứng sau khoản hoàn tiền này. */
    private function ownerSideCan(User $user, Refund $refund, string $permission): bool
    {
        if (empty($refund->owner_id)) {
            return false;
        }

        return TenantPermission::for($user, (string) $refund->owner_id)->can($permission);
    }

    private function buyerMembership(User $user, Refund $refund): ?OrganizationUser
    {
        if (empty($refund->organization_id)) {
            return null;
        }

        return OrganizationUser::where('organization_id', $refund->organization_id)
            ->where('user_id', $user->id)
            ->whereHas('organization', fn ($q) => $q->where('status', 'active'))
            ->first();
    }
}
