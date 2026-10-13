<?php

namespace App\Policies;

use App\Models\Network;
use App\Models\User;
use App\Services\GioiHanNetwork;

/**
 * Quyền trên mạng lưới.
 *
 * Từ 13/10/2026 policy này cũng gác **hàng rào theo mạng lưới**
 * (`owner_users.allowed_network_ids`). Global scope lo phần danh sách; policy
 * lo phần "một hàng đã nằm trong tay" — kể cả hàng được tìm bằng
 * `withoutGlobalScope('owner_scope')`, thứ mã này dùng ở hơn ba mươi chỗ. Hai
 * tầng, một nguồn: `GioiHanNetwork`.
 */
class NetworkPolicy
{
    /**
     * Admin: full access.
     * Publisher: chỉ thấy networks thuộc current_owner_id.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin')
            || $user->current_owner_id !== null;
    }

    public function view(User $user, Network $network): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $network->owner_id === $user->current_owner_id
            && $this->trongHangRao($user, $network);
    }

    /**
     * Người bị giới hạn mạng lưới **không** tạo được mạng lưới mới.
     *
     * Không phải vì nó mở được hàng rào — một mạng lưới mới thì rỗng. Mà vì
     * kết quả là vô nghĩa: mạng lưới vừa tạo không nằm trong tập được giao,
     * nên ngay sau khi lưu, chính người tạo không còn thấy nó. Để nguyên thì
     * giao diện mời người ta làm một việc tự biến mất, và kho của tổ chức có
     * thêm những mạng lưới rỗng không ai nhận.
     */
    public function create(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->current_owner_id !== null
            && $this->hasManagePermission($user)
            && ! GioiHanNetwork::dangBiGioiHan();
    }

    public function update(User $user, Network $network): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $network->owner_id === $user->current_owner_id
            && $this->hasManagePermission($user)
            && $this->trongHangRao($user, $network);
    }

    public function delete(User $user, Network $network): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $network->owner_id === $user->current_owner_id
            && $this->hasManagePermission($user)
            && $this->trongHangRao($user, $network);
    }

    /** Mạng lưới này có nằm trong tập đã giao cho `$user` không */
    private function trongHangRao(User $user, Network $network): bool
    {
        $duocPhep = app(GioiHanNetwork::class)->cho($user, (string) $user->current_owner_id);

        return $duocPhep === null || in_array((int) $network->getKey(), $duocPhep, true);
    }

    public function deleteAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->hasManagePermission($user);
    }

    private function hasManagePermission(User $user): bool
    {
        return \App\Services\TenantPermission::for($user)->can('manage_inventory');
    }
}
