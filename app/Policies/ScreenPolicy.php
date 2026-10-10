<?php

namespace App\Policies;

use App\Models\Screen;
use App\Models\User;
use App\Services\TenantPermission;

/**
 * Quyền trên màn hình.
 *
 * Sửa sau review T1 của Codex:
 *  - Đọc dùng `view_inventory` (loại reporting_only), không chỉ so current_owner_id.
 *  - Sửa giá dùng `manage_pricing` (chỉ owner/manager), tách khỏi `manage_inventory`
 *    vốn cho cả `operator`.
 *  - Mọi quyền đi qua TenantPermission nên membership bị gỡ hoặc owner bị tạm ngưng
 *    đều mất quyền ngay.
 *
 * Vai trò `operator` ở đây tên cũ là `scheduler` — các văn bản review trong
 * `docs/audit-5-vung-2026-09-23/` vẫn gọi nó bằng tên cũ, và đó là bản ghi lịch
 * sử nên không sửa. Đổi tên ngày 10/10/2026, migration `2026_10_10_000001`.
 */
class ScreenPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->current_owner_id !== null
            && TenantPermission::for($user)->can('view_inventory');
    }

    public function view(User $user, Screen $screen): bool
    {
        return $this->allows($user, $screen, 'view_inventory');
    }

    public function create(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->current_owner_id !== null
            && TenantPermission::for($user)->can('manage_inventory');
    }

    public function update(User $user, Screen $screen): bool
    {
        return $this->allows($user, $screen, 'manage_inventory');
    }

    public function delete(User $user, Screen $screen): bool
    {
        return $this->allows($user, $screen, 'manage_inventory');
    }

    public function deleteAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return TenantPermission::for($user)->can('manage_inventory');
    }

    /**
     * Sửa giá sàn, CPM, multiplier, bật/tắt programmatic.
     * Vai trò `operator` quản được kho nhưng KHÔNG được đụng tới giá.
     */
    public function managePricing(User $user, Screen $screen): bool
    {
        return $this->allows($user, $screen, 'manage_pricing');
    }

    /**
     * Màn hình phải thuộc tenant đang chọn, và user phải còn quyền tương ứng
     * trong tenant đó (membership còn hiệu lực, owner còn hoạt động).
     */
    private function allows(User $user, Screen $screen, string $permission): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($screen->owner_id !== $user->current_owner_id) {
            return false;
        }

        return TenantPermission::for($user, $screen->owner_id)->can($permission);
    }
}
