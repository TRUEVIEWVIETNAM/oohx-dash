<?php

namespace App\Policies;

use App\Models\OwnerUser;
use App\Models\User;
use App\Services\TenantPermission;

/**
 * Quyền trên **thành viên** của một media owner: xem, mời, đổi vai trò, gỡ.
 *
 * ══ Gom về `manage_users`, 13/10/2026 ══
 *
 * Bản trước so `actorRole($user, …) === 'owner'`: một phép so **tên vai trò**,
 * trong khi `OwnerUser::PERMISSIONS` khai `'manage_users' => ['owner']` và
 * trang `PermissionMatrix` in bảng đó ra cho người dùng đọc. Hai bộ luật cho
 * một câu hỏi — hôm nay chúng đồng ý nhau **chỉ vì** danh sách đúng có một
 * phần tử.
 *
 * Hệ quả nếu cho `manager` quyền `manage_users` ngày nào đó: bảng quyền nói nó
 * quản lý được thành viên, `OwnerUserResource::canViewAny()` (vốn kiểm
 * `TenantPermission::check('manage_users')`) cho nó thấy menu, rồi policy này
 * từ chối từng hành động. Đúng hình dạng lỗi đã xảy ra bên tổ chức người mua.
 *
 * ══ Không dựng helper mới: bên này đã có `TenantPermission` ══
 *
 * Khác bên tổ chức người mua — nơi hai policy mỗi bên một hàm tìm thành viên —
 * bên owner đã có đúng một chỗ tập trung, và nó làm đủ ba việc cần:
 *
 *  1. `super_admin` đi trước mọi luật;
 *  2. **owner phải còn `active`** (Codex T1/F2: trước đó owner bị tạm ngưng mà
 *     thành viên vẫn ghi được inventory qua API);
 *  3. đọc quyền qua `OwnerUser::can()`, tức qua `PERMISSIONS`.
 *
 * Lớp này chỉ chưa dùng nó. Nên lần gom này là bỏ bản chép, không thêm tầng.
 *
 * Việc 2 là **thay đổi hành vi**: một media owner tạm ngưng nay không mời và
 * gỡ thành viên được nữa. Qua giao diện thì không ai thấy khác — panel
 * `publisher` đã chặn bằng `User::hasActiveTenant()` và menu đã ẩn bằng
 * `TenantPermission::check()` — nhưng policy là lớp cuối, và nó nên trả lời
 * giống hai lớp trước nó.
 *
 * ══ Ba luật riêng của việc đổi/gỡ, KHÔNG phải quyền ══
 *
 * Chúng nói về **quan hệ giữa người làm và bản ghi đích**, không về vai trò
 * người làm, nên không thuộc `PERMISSIONS`:
 *
 *  - không tự sửa/gỡ chính mình — tránh tự khóa mình ra khỏi owner;
 *  - không chạm bản ghi có vai trò `owner` — chỉ `super_admin` làm được, cùng
 *    lý do với bên tổ chức: một chủ sở hữu không được hạ bệ đồng cấp;
 *  - `super_admin` đi trước mọi luật trên.
 */
class OwnerUserPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return TenantPermission::for($user)->can('manage_users');
    }

    public function view(User $user, OwnerUser $target): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Xem một bản ghi thành viên là quyền của **mọi** thành viên của đúng
        // owner đó: không đòi `manage_users`. `PERMISSIONS` không có quyền nào
        // cho việc "xem danh sách người", nên câu hỏi ở đây là tư cách thành
        // viên — và `role()` trả về `null` khi owner đã tạm ngưng.
        return TenantPermission::for($user, $target->owner_id)->role() !== null;
    }

    /** Tạo OwnerUser mới (mời người). */
    public function create(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return TenantPermission::for($user)->can('manage_users');
    }

    /** Đổi vai trò của một thành viên. */
    public function update(User $user, OwnerUser $target): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->duocChamVao($user, $target);
    }

    /** Gỡ thành viên khỏi owner. */
    public function delete(User $user, OwnerUser $target): bool
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
     * bản chép là một chỗ để hai phép trôi khỏi nhau — mà "gỡ khỏi owner" và
     * "hạ vai trò" là hai việc khác nhau, chỉ tình cờ cùng điều kiện.
     *
     * Quyền kiểm theo owner của **bản ghi đích**, không theo `current_owner_id`:
     * cột đó là đầu vào client đổi được, không phải một sự thật.
     */
    private function duocChamVao(User $user, OwnerUser $target): bool
    {
        if (! TenantPermission::for($user, $target->owner_id)->can('manage_users')) {
            return false;
        }

        if ($target->user_id === $user->id) {
            return false;
        }

        return $target->role !== OwnerUser::ROLE_OWNER;
    }
}
