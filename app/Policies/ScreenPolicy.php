<?php

namespace App\Policies;

use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Services\GioiHanNetwork;
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
 * Từ 13/10/2026 thêm **hàng rào theo mạng lưới** — xem `GioiHanNetwork`.
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

        if (! TenantPermission::for($user, $screen->owner_id)->can($permission)) {
            return false;
        }

        // Hàng rào mạng lưới — đi qua địa điểm, một đường duy nhất, cùng với
        // `Screen::network()` và `Screen::locTheoNetwork()`.
        $duocPhep = app(GioiHanNetwork::class)->cho($user, (string) $screen->owner_id);

        if ($duocPhep === null) {
            return true;
        }

        // Đọc `sites.network_id` trực tiếp, **không** qua quan hệ `site`: quan
        // hệ đó mang global scope của `Site`, tức mang chính hàng rào này. Một
        // phép kiểm tự dựa vào kết quả của chính nó thì đúng trong cả hai
        // trường hợp hôm nay, nhưng là thứ không ai muốn phải đọc lại khi đi
        // tìm lỗi. Một lượt tra theo khoá chính thì rẻ hơn sự mơ hồ đó.
        $networkId = Site::withoutGlobalScopes()
            ->whereKey($screen->site_id)
            ->value('network_id');

        return $networkId !== null && in_array((int) $networkId, $duocPhep, true);
    }
}
