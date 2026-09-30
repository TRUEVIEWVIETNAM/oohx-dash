<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Màn hình trong danh sách công khai — DTO **danh sách trắng**.
 *
 * Chỉ những trường liệt kê ở đây ra ngoài. Không bao giờ trả thẳng model:
 * `Screen` và các quan hệ của nó mang `device_token`, `internal_notes`, còn
 * `Owner` mang `revenue_share_pct`, `billing_info`, `bank_*`, `tax_code`,
 * `business_license_path`. Một lần `toArray()` trên model là lộ hết.
 *
 * Về giá: trường tên đúng nghĩa. `/api/v1` có `price_per_slot_vnd` trả giá CPM
 * dưới cái tên "giá một slot" và không sửa được nữa vì đối tác đang đọc (F09);
 * v2 không lặp lại — `floor_cpm_vnd` là giá cho 1.000 lượt hiển thị, `io_rate`
 * là giá thuê theo kỳ, và kỳ được nói rõ ở `io_rate_unit`.
 */
class ScreenSummaryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $inventory = $this->inventory;
        $spec      = $this->spec;

        return [
            'slug'        => $this->slug,
            'name'        => $this->name,
            'screen_type' => $this->resolveScreenType(),

            'owner' => [
                'slug' => $this->owner?->slug,
                'name' => $this->owner?->name,
            ],

            'location' => [
                'site'     => $this->site?->name,
                'city'     => $this->site?->city,
                'district' => $this->location_district,
            ],

            'network' => $this->site?->network ? [
                'code' => $this->site->network->code,
                'name' => $this->site->network->name,
            ] : null,

            'size' => [
                'width_m'  => $spec?->width_cm ? round($spec->width_cm / 100, 2) : null,
                'height_m' => $spec?->height_cm ? round($spec->height_cm / 100, 2) : null,
            ],

            // Giá công khai, tên nói đúng nó là gì.
            'pricing' => [
                'model'         => $inventory?->pricing_model,
                'floor_cpm_vnd' => $inventory?->floor_cpm !== null ? (int) round((float) $inventory->floor_cpm) : null,
                'io_rate_vnd'   => $inventory?->io_rate !== null ? (int) round((float) $inventory->io_rate) : null,
                'io_rate_unit'  => $inventory?->io_rate_unit,
            ],

            'photo_url' => $this->display_photo,
        ];
    }

    private function resolveScreenType(): string
    {
        $widthCm  = $this->spec?->width_cm ?? 0;
        $heightCm = $this->spec?->height_cm ?? 0;

        if ($widthCm >= 300 || $heightCm >= 300) {
            return 'billboard';
        }

        return ($this->inventory?->venue_type === 'outdoor') ? 'led' : 'lcd';
    }
}
