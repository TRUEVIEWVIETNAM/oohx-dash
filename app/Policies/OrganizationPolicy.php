<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;

/**
 * Quyền trên chính tổ chức người mua.
 *
 * ══ Lỗ hổng policy này đóng ══
 *
 * `BuyerSettingsController::updateOrganization()` trước đây chạy:
 *
 *     $request->user()->currentOrganization->update($data);
 *
 * Không policy, không kiểm tư cách thành viên, không kiểm vai trò. Ba hệ quả,
 * và `$data` gồm `name`, `billing_email`, `billing_phone`, `tax_id`, `website`:
 *
 *  1. **Vai trò `viewer` ghi lại được mã số thuế và thông tin thanh toán của
 *     tổ chức.** Chính mô tả vai trò trong mã nói ngược lại: "Chỉ xem
 *     campaigns và reports, **không chỉnh sửa**".
 *  2. Người đã bị **gỡ khỏi tổ chức** mà `current_organization_id` vẫn trỏ ở
 *     đó ghi lại được thông tin của tổ chức ấy — `currentOrganization` là một
 *     `belongsTo` thuần, không kiểm tư cách.
 *  3. Tổ chức bị **tạm ngưng** vẫn sửa được.
 *
 * Đường ĐỌC của cùng trang cũng đi qua cột đó, nên người đã bị gỡ còn **xem**
 * được email thanh toán, số điện thoại và mã số thuế của tổ chức cũ.
 *
 * ══ `manage_team`, và nó hẹp hơn hiện trạng ══
 *
 * Chọn quyền này làm hẹp quyền của `planner` và `viewer` — đó là một thay đổi
 * hành vi nhìn thấy được, không phải một lần sửa trung tính. Lý do chọn vậy:
 * mô tả vai trò **đã có trong mã** nói đúng điều đó.
 *
 *   admin   — "Toàn quyền: quản lý team, tạo campaign, duyệt booking, payments."
 *   planner — "… Không quản lý team."
 *   viewer  — "Chỉ xem campaigns và reports, không chỉnh sửa."
 *
 * Thông tin pháp lý và thanh toán của tổ chức thuộc việc quản trị, không thuộc
 * việc lập kế hoạch. Nên đây là làm đúng điều đã viết, không phải đặt luật mới
 * — nhưng vẫn là thứ phải nói ra, vì `planner` mất một quyền đang có.
 */
class OrganizationPolicy
{
    public function view(User $user, Organization $org): bool
    {
        return $this->membership($user, $org) !== null;
    }

    public function update(User $user, Organization $org): bool
    {
        return $this->membership($user, $org)?->can('manage_team') ?? false;
    }

    /**
     * Thành viên của chính tổ chức đó, và tổ chức còn hoạt động.
     *
     * Kiểm theo tổ chức **được hỏi**, không theo `current_organization_id`:
     * cột đó là đầu vào do client đổi được, không phải một sự thật.
     */
    private function membership(User $user, Organization $org): ?OrganizationUser
    {
        return OrganizationUser::where('organization_id', $org->id)
            ->where('user_id', $user->id)
            ->whereHas('organization', fn ($q) => $q->where('status', Organization::STATUS_ACTIVE))
            ->first();
    }
}
