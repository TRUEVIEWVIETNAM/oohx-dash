<?php

namespace App\Observers;

use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenRateVersion;
use App\Models\User;
use App\Services\TenantPermission;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Mỗi lần giá niêm yết đổi, ghi một phiên bản mới.
 *
 * Đặt ở observer chứ không ở service: giá bị sửa từ nhiều đường — form Filament
 * của hai panel, API v1, lệnh artisan, job nhập kho, scheduler đồng bộ. Nhét
 * việc ghi lịch sử vào từng đường là cách chắc chắn để **sót một đường**, và
 * đường bị sót chính là đường sửa giá không để lại vết.
 */
class ScreenInventoryObserver
{
    /** Chỉ những cột này đổi mới coi là đổi giá. */
    private const PRICE_FIELDS = ['pricing_model', 'floor_cpm', 'io_rate', 'io_rate_unit', 'duration_discounts'];

    /**
     * Không có quyền giá thì không **sửa** được giá — kể cả khi form đã ẩn trường.
     *
     * Trước đây quyền giá chỉ thể hiện bằng `->visible($canPricing)` trong form
     * Filament. Ẩn trường là phép lịch sự với người dùng, không phải cơ chế bảo
     * vệ: mọi đường ghi khác (API, lệnh, job nhập kho) không đi qua form.
     *
     * **Chỉ chặn lúc sửa, không chặn lúc tạo.** Bản đầu đặt ở `saving` nên chặn
     * cả lúc tạo kho lần đầu, và full suite trên CI đổ 63 ca: tạo màn hình mới
     * bao giờ cũng kèm giá ban đầu, còn quyền cho bước tạo đã được kiểm ở tầng
     * API từ T1a (`authorizePricingPayload`). Chặn ở đây nữa là chặn nhầm.
     *
     * Hai trường hợp cố ý bỏ qua:
     * - Không có ai đăng nhập: lệnh artisan và job. Chặn chúng thì hỏng việc
     *   vận hành chứ không tăng an toàn.
     * - Người đăng nhập không phải `User` mà là `ApiClient` (đối tác dùng
     *   token). `ApiClient` không có `hasRole()` — gọi vào là ném
     *   BadMethodCallException, đúng lỗi 5 ca còn lại trên CI. Đường API của
     *   đối tác đã có `authorizePricingForOwner` ở controller.
     */
    public function updating(ScreenInventory $inventory): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->hasRole('super_admin')) {
            return;
        }

        $dirtyPriceFields = array_keys(array_intersect_key(
            $inventory->getDirty(),
            array_flip(self::PRICE_FIELDS),
        ));

        if ($dirtyPriceFields === []) {
            return;
        }

        $ownerId = $inventory->screen?->owner_id
            ?? Screen::withoutGlobalScopes()->whereKey($inventory->screen_id)->value('owner_id');

        // Không xác định được owner thì không phán quyết ở đây — tầng khác đã
        // chặn (scope chặn mặc định). Chặn mù ở đây chỉ gây lỗi khó hiểu.
        if (! $ownerId) {
            return;
        }

        if (TenantPermission::for($user, (string) $ownerId)->cannot('manage_pricing')) {
            throw new HttpException(403, 'Bạn không có quyền sửa giá của màn hình này.');
        }
    }

    public function created(ScreenInventory $inventory): void
    {
        $this->record($inventory);
    }

    public function updated(ScreenInventory $inventory): void
    {
        if (! $inventory->wasChanged(self::PRICE_FIELDS)) {
            return;
        }

        $this->record($inventory);
    }

    private function record(ScreenInventory $inventory): void
    {
        if (! $inventory->screen_id) {
            return;
        }

        ScreenRateVersion::create([
            'screen_id'          => $inventory->screen_id,
            'effective_from'     => now(),
            'pricing_model'      => $inventory->pricing_model,
            'floor_cpm'          => $inventory->floor_cpm,
            'io_rate'            => $inventory->io_rate,
            'io_rate_unit'       => $inventory->io_rate_unit,
            'duration_discounts' => $inventory->duration_discounts,
            // Chỉ ghi id khi người gọi thực sự là một `User`.
            //
            // `auth()->id()` gọi `getAuthIdentifier()` trên đối tượng đang đăng
            // nhập, mà với đối tác dùng token thì đó là `ApiClient` — lớp này
            // không có phương thức đó, nên gọi vào là BadMethodCallException.
            // Ngoài ra `changed_by` là khóa ngoại tới `users`: nhét id của
            // ApiClient vào là ghi một liên kết sai.
            'changed_by'         => auth()->user() instanceof User ? auth()->id() : null,
        ]);
    }
}
