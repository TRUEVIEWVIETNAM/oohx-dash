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

            // Giá công khai, **kèm đơn vị tiền**.
            //
            // Bản trước trả `floor_cpm_vnd` bằng cách làm tròn `floor_cpm` và
            // bỏ qua `floor_cpm_currency` — cột đó nhận VND hoặc USD (xem
            // `ScreenImport\FieldCatalog`, và Filament có
            // `default_floor_cpm_currency`). Một hàng USD 2,50 ra ngoài thành
            // "3 VND": sai đơn vị, lệch nhiều bậc, và client không còn cách
            // nào tự sửa vì đã mất currency gốc (Codex R39).
            //
            // Không tự quy đổi ở đây: quy đổi cần một chính sách tỷ giá có
            // người chốt, không phải một hằng số lẻn vào DTO.
            'pricing' => [
                'model'     => $inventory?->pricing_model,
                'floor_cpm' => [
                    'amount'   => $this->decimalOrNull($inventory?->floor_cpm),
                    'currency' => $inventory?->floor_cpm_currency ?: 'VND',
                ],
                'io_rate' => [
                    'amount' => $inventory?->io_rate !== null ? (int) round((float) $inventory->io_rate) : null,
                    // `io_rate` không có cột currency trong CSDL, nên nó là
                    // VND theo định nghĩa. Nói ra ở đây để giả định đó nhìn
                    // thấy được trong phản hồi, chứ không nằm im trong đầu ai.
                    'currency' => 'VND',
                    'unit'     => $inventory?->io_rate_unit,
                ],
            ],

            'photo_url' => $this->display_photo,
        ];
    }

    /**
     * Số tiền giữ đúng độ chính xác của dữ liệu.
     *
     * Không làm tròn về số nguyên: `floor_cpm` có thể là 2,50 USD, và làm
     * tròn thành 3 là làm sai dữ liệu chứ không phải làm gọn. Với VND thì
     * giá trị vốn đã nguyên nên trả về số nguyên.
     */
    private function decimalOrNull($value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $number = round((float) $value, 4);

        return $number === floor($number) ? (int) $number : $number;
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
