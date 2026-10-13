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
 * ══ `manage_team` là quyền SAI cho việc này — sửa 13/10/2026 ══
 *
 * Bản đầu của policy đòi `manage_team`, quyền duy nhất lúc đó chỉ `admin` có.
 * Nó bịt được lỗ, nhưng kéo theo một tác dụng phụ: `planner` **mất** quyền sửa
 * thông tin tổ chức mà nó đang có. Lúc đó tôi lý giải bằng mô tả vai trò ("…
 * Không quản lý team") và coi đó là làm đúng điều đã viết.
 *
 * Lý giải ấy sai ở một chỗ: `manage_team` trả lời câu "ai quản lý thành viên",
 * không phải câu "ai sửa thông tin tổ chức". Gộp hai câu vào một quyền là lý
 * do `planner` mất quyền — không phải vì ai đó quyết định nó không nên có.
 *
 * Chủ dự án đã quyết trả lại (13/10/2026), nên nay policy đòi
 * `manage_org_profile`: `admin` và `planner` có, `viewer` **không**. Vì sao
 * tách quyền mới thay vì thêm `manage_team` cho `planner`: thêm `manage_team`
 * sẽ mở luôn việc mời và gỡ thành viên, đúng điều mô tả vai trò nói là không.
 *
 * `viewer` vẫn bị chặn, và đó là phần đáng giá nhất của #55: mô tả của nó là
 * "Chỉ xem campaigns và reports, **không chỉnh sửa**", vậy mà trước #55 nó ghi
 * lại được mã số thuế của tổ chức.
 *
 * Phạm vi quyền này gồm cả `tax_id` và `billing_*` — xem
 * `SettingsController::updateOrganization()` về danh sách trường. Chia nhỏ
 * thành "sửa tên/website" và "sửa thông tin pháp lý" là một quyết định khác,
 * chưa ai yêu cầu.
 */
class OrganizationPolicy
{
    public function view(User $user, Organization $org): bool
    {
        return $this->membership($user, $org) !== null;
    }

    public function update(User $user, Organization $org): bool
    {
        return $this->membership($user, $org)?->can('manage_org_profile') ?? false;
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
