<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\OrganizationUser;
use App\Models\User;

/**
 * Quyền của người mua trên chiến dịch.
 *
 * Trước đây mỗi controller tự so `organization_id === current_organization_id`
 * bằng tay. Ba vấn đề với cách đó:
 *
 * - Luật nằm ở nhiều chỗ nên sửa một chỗ là lệch chỗ còn lại — đúng kiểu lỗi
 *   `InventoryController` và `FrontpageService` đã mắc.
 * - **Không phân biệt việc gì với việc gì.** `OrganizationUser::PERMISSIONS` đã
 *   ghi rõ `viewer` chỉ được xem, nhưng phép so bằng tay cho viewer gửi booking
 *   và xác nhận thanh toán như admin.
 * - Không kiểm tổ chức còn hoạt động hay không.
 *
 * Policy này **không tự đặt luật mới**: nó đọc đúng ma trận đã có trong
 * `OrganizationUser::PERMISSIONS`, cùng bảng mà giao diện đang dùng.
 */
class CampaignPolicy
{
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'view_campaigns');
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'create_campaign');
    }

    /** Gửi booking cho media owner — có hệ quả tiền. */
    public function submit(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'submit_booking');
    }

    /** Xác nhận đã chuyển khoản. */
    public function pay(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'manage_payments');
    }

    /** Hủy đặt chỗ đã gửi — kéo theo nghĩa vụ hoàn tiền, nên xếp cùng thanh toán. */
    public function cancel(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'manage_payments');
    }

    public function uploadCreative(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'upload_creative');
    }

    private function allows(User $user, Campaign $campaign, string $permission): bool
    {
        // Quản trị sàn xem được mọi chiến dịch, nhưng **không** tiêu tiền thay
        // người mua: gửi booking và xác nhận thanh toán vẫn phải là người của tổ
        // chức đó.
        if ($user->hasRole('super_admin') && $permission === 'view_campaigns') {
            return true;
        }

        return $this->membership($user, $campaign)?->can($permission) ?? false;
    }

    /**
     * Thành viên của tổ chức sở hữu chiến dịch, và tổ chức còn hoạt động.
     *
     * Kiểm theo **tổ chức của chiến dịch**, không theo `current_organization_id`:
     * người thuộc hai tổ chức chỉ cần đổi tổ chức đang chọn là cách kiểm cũ cho
     * qua hoặc chặn sai.
     */
    private function membership(User $user, Campaign $campaign): ?OrganizationUser
    {
        return OrganizationUser::where('organization_id', $campaign->organization_id)
            ->where('user_id', $user->id)
            ->whereHas('organization', fn ($q) => $q->where('status', 'active'))
            ->first();
    }
}
