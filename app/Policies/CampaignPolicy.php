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
    /**
     * Xem chiến dịch **ở khu người mua**: chỉ người của tổ chức đã đặt.
     *
     * Media owner KHÔNG được vào đây. Ở giai đoạn 3 tôi gộp nhánh owner vào
     * chính quyền này để hộp thư đặt chỗ của Filament hết 403, và hậu quả là
     * publisher của owner A mở được `/booking/{campaign}/payment` — trang đó
     * nạp toàn bộ payments của chiến dịch, tức số tiền, số hóa đơn và trạng
     * thái thanh toán của owner B (Codex R01).
     *
     * Bài học nằm ở chỗ: một quyền đọc dùng chung cho hai màn hình có phạm vi
     * dữ liệu khác nhau thì sớm muộn màn hình rộng hơn sẽ rò. Nay tách hẳn —
     * xem `viewAsOwner()`.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'view_campaigns');
    }

    /**
     * Xem chiến dịch **ở khu media owner** (hộp thư đặt chỗ).
     *
     * Chỉ thấy phần liên quan tới mình: dữ liệu hiển thị đã được
     * `BookingInboxResource::getEloquentQuery()` lọc theo owner đang chọn.
     */
    public function viewAsOwner(User $user, Campaign $campaign): bool
    {
        return $this->ownsLinesIn($user, $campaign);
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

    /**
     * Xem báo cáo phát sóng của chiến dịch.
     *
     * ══ Lỗ hổng quyền này đóng ══
     *
     * `BuyerReportController` trước đây tự so:
     *
     *     $campaign->organization_id === $request->user()->current_organization_id
     *
     * Cột đó client đổi được (có bộ chuyển tổ chức) và **không ai dọn** khi một
     * người bị gỡ khỏi tổ chức — nên người đã bị cho ra khỏi nhóm vẫn đọc được
     * báo cáo phát sóng của tổ chức ấy, và một tổ chức đã bị tạm ngưng cũng
     * vậy. Đúng khe mà `listForUser()` đóng cho `/my/campaigns` ở PR #40 và
     * `tuCachXemChienDich()` đóng cho trang đầu ở #46.
     *
     * Dùng `view_reports` chứ không `view_campaigns`: cả ba vai trò đều có cả
     * hai, nên không vai trò nào đổi quyền hôm nay — nhưng đọc đúng quyền thì
     * một vai trò thứ tư chỉ-xem-chiến-dịch sẽ không lặng lẽ có luôn báo cáo.
     */
    public function viewReports(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'view_reports');
    }

    /**
     * Đánh giá media owner sau chiến dịch.
     *
     * ══ Cùng khe, cùng cách đóng ══
     *
     * `OwnerReviewController::store` cũng so `organization_id ===
     * current_organization_id`, và đây là đường **GHI** — nó phát ra một nhận
     * xét công khai về đối tác, đứng tên tổ chức người mua.
     *
     * ══ Vì sao `view_campaigns`, không phải một quyền chặt hơn ══
     *
     * Bộ quyền hiện có không có mục nào cho việc đánh giá. Chọn
     * `submit_booking` thì vai trò `viewer` mất quyền đang có — tức PR này
     * lặng lẽ phân phối lại quyền, trong khi việc của nó là đóng một lỗ hổng.
     *
     * Nên quyền này chọn mức làm **đúng một việc**: đòi tư cách thành viên còn
     * hiệu lực, không đổi gì cho ba vai trò đang có. Câu hỏi "vai trò chỉ xem
     * có nên đánh giá đối tác không" là một quyết định nghiệp vụ, và nó cần
     * được hỏi riêng chứ không trả lời kèm.
     */
    public function review(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, $campaign, 'view_campaigns');
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
    /**
     * Người này có thuộc media owner nào đang có màn hình trong chiến dịch không.
     *
     * Chỉ tính owner **còn hoạt động**: tạm ngưng một media owner phải có hiệu
     * lực ở mọi cửa, kể cả cửa đọc.
     */
    private function ownsLinesIn(User $user, Campaign $campaign): bool
    {
        $ownerIds = $user->owners()->where('owners.status', 'active')->pluck('owners.id');

        if ($ownerIds->isEmpty()) {
            return false;
        }

        return $campaign->bookingLines()->whereIn('owner_id', $ownerIds)->exists();
    }

    private function membership(User $user, Campaign $campaign): ?OrganizationUser
    {
        return OrganizationUser::where('organization_id', $campaign->organization_id)
            ->where('user_id', $user->id)
            ->whereHas('organization', fn ($q) => $q->where('status', 'active'))
            ->first();
    }
}
