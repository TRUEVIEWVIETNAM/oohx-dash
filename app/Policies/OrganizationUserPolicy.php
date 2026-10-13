<?php

namespace App\Policies;

use App\Models\OrganizationUser;
use App\Models\User;

/**
 * Quyền trên **thành viên** của một tổ chức người mua: xem, mời, đổi vai trò, gỡ.
 *
 * ══ Gom về `manage_team`, 13/10/2026 ══
 *
 * Bản trước so `actorRole($user, …) === 'admin'`: một phép so **tên vai trò**,
 * trong khi `OrganizationUser::PERMISSIONS` là chỗ khai vai trò nào làm được
 * gì. Hai bộ luật cho một câu hỏi, đúng thứ CLAUDE.md mục 4 cảnh báo — và nó
 * có hệ quả thật: thêm một vai trò mới có `manage_team` sẽ **không** cho nó
 * mời ai cả, vì phép gác thật không đọc `PERMISSIONS`.
 *
 * Lỗ này lộ ra khi PR #76 tách `manage_org_profile` khỏi `manage_team`: sau
 * lần tách đó `manage_team` không còn được kiểm ở **đâu cả**, dù nó là tên của
 * đúng việc lớp này gác.
 *
 * Nay cả hai policy của tổ chức hỏi cùng một hàm,
 * `OrganizationUser::thanhVien()`, rồi hỏi tiếp `->can('<quyền>')`.
 *
 * ══ Hành vi đổi một chỗ, có chủ ý ══
 *
 * `thanhVien()` đòi tổ chức còn `active`; `actorRole()` cũ thì không. Nên một
 * tổ chức **tạm ngưng** nay không mời và gỡ thành viên được nữa. Đi qua giao
 * diện thì không ai thấy khác: panel `buyer` đã chặn bằng
 * `User::hasActiveTenant()`. Nhưng `OrganizationPolicy` đã đòi điều này từ #55
 * cho thông tin tổ chức, và để hai policy của cùng một tổ chức trả lời khác
 * nhau về cùng một tổ chức là cách sinh ra lỗ tiếp theo.
 *
 * ══ Ba luật riêng của việc đổi/gỡ, KHÔNG phải quyền ══
 *
 * `update()` và `delete()` còn ba điều kiện nữa, và chúng không thuộc
 * `PERMISSIONS` vì chúng nói về **quan hệ giữa người làm và bản ghi đích**,
 * không về vai trò người làm:
 *
 *  - không tự sửa/gỡ chính mình — tránh tự khóa mình ra khỏi tổ chức;
 *  - không chạm bản ghi có vai trò `admin` — chỉ `super_admin` làm được, cùng
 *    lý do với `assignableRolesFor()`: một org admin không được tự nhân bản
 *    hoặc hạ bệ đồng cấp;
 *  - `super_admin` đi trước mọi luật trên.
 */
class OrganizationUserPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return OrganizationUser::thanhVien($user, $user->current_organization_id)
            ?->can('manage_team') ?? false;
    }

    public function view(User $user, OrganizationUser $target): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Xem danh sách thành viên là quyền của mọi thành viên: không đòi
        // `manage_team`, chỉ đòi cùng tổ chức. `OrganizationPolicy::view()`
        // cũng vạch ranh giới ở đúng chỗ này.
        return OrganizationUser::thanhVien($user, $target->organization_id) !== null;
    }

    /** Tạo OrganizationUser mới (mời người). */
    public function create(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return OrganizationUser::thanhVien($user, $user->current_organization_id)
            ?->can('manage_team') ?? false;
    }

    /** Đổi vai trò của một thành viên. */
    public function update(User $user, OrganizationUser $target): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->duocChamVao($user, $target);
    }

    public function delete(User $user, OrganizationUser $target): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->duocChamVao($user, $target);
    }

    /**
     * Phần dùng chung của `update()` và `delete()`.
     *
     * Hai phép này có **cùng** bộ điều kiện, và bản trước chép nó hai lần. Một
     * bản chép là một chỗ để hai phép trôi khỏi nhau — và "gỡ" với "hạ vai
     * trò" là hai việc không được phép khác nhau ở đây.
     */
    private function duocChamVao(User $user, OrganizationUser $target): bool
    {
        if (! OrganizationUser::thanhVien($user, $target->organization_id)?->can('manage_team')) {
            return false;
        }

        if ($target->user_id === $user->id) {
            return false;
        }

        return $target->role !== OrganizationUser::ROLE_ADMIN;
    }
}
