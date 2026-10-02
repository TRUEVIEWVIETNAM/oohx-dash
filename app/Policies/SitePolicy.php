<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Services\TenantPermission;

/**
 * Quyền trên địa điểm. Cùng nguyên tắc với ScreenPolicy:
 * đọc dùng `view_inventory`, ghi dùng `manage_inventory`, và mọi quyền đi qua
 * TenantPermission nên membership bị gỡ hoặc owner tạm ngưng là mất quyền ngay.
 */
class SitePolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->current_owner_id !== null
            && TenantPermission::for($user)->can('view_inventory');
    }

    public function view(User $user, Site $site): bool
    {
        return $this->allows($user, $site, 'view_inventory');
    }

    public function create(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->current_owner_id !== null
            && TenantPermission::for($user)->can('manage_inventory');
    }

    public function update(User $user, Site $site): bool
    {
        return $this->allows($user, $site, 'manage_inventory');
    }

    public function delete(User $user, Site $site): bool
    {
        return $this->allows($user, $site, 'manage_inventory');
    }

    public function deleteAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return TenantPermission::for($user)->can('manage_inventory');
    }

    private function allows(User $user, Site $site, string $permission): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($site->owner_id !== $user->current_owner_id) {
            return false;
        }

        return TenantPermission::for($user, $site->owner_id)->can($permission);
    }
}
