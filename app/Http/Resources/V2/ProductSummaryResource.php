<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sản phẩm trong danh sách công khai — DTO danh sách trắng.
 *
 * **Về tên trường giá.** Cột trong CSDL tên `floor_price`, nhưng với sản phẩm
 * dạng gói thì đó chính là **giá người mua trả cho cả gói** — không phải giá
 * sàn nội bộ. `BundleExpander::productTotal()` lấy đúng cột này làm giá gói, và
 * trang `/products/{slug}` đã hiển thị nó công khai từ trước. Ở đây đặt tên nói
 * đúng nó là gì (`package`, `per_screen`) thay vì chép lại một cái tên gây hiểu
 * sai — cùng cách đã làm với `floor_cpm_vnd` (Codex R39).
 *
 * **Về đơn vị tiền.** Bảng `products` có cột `currency`, nên mỗi số tiền đi kèm
 * đơn vị. Không giả định VND: đó đúng là lỗi R39 đã bắt ở màn hình, và accessor
 * `price_display` của model hiện vẫn in "₫" bất kể cột `currency` — một lỗi
 * hiển thị còn lại ở phía Blade.
 *
 * **Không trả `id`.** Trang Blade dùng `$product->id` cho form thêm giỏ hàng;
 * API dùng `slug`. Khóa nội bộ không ra ngoài.
 */
class ProductSummaryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'slug'     => $this->slug,
            'name'     => $this->name,
            'type'     => $this->type,
            'category' => [
                'code'  => $this->category,
                'label' => $this->category_label,
            ],

            // Cách bán: chỉ gói, chỉ lẻ, hay cả hai.
            'listing_mode' => [
                'code'             => $this->listing_mode,
                'label'            => $this->listing_mode_label,
                'allows_package'   => $this->allowsPackage(),
                'allows_individual' => $this->allowsIndividual(),
            ],

            'owner' => [
                'slug' => $this->owner?->slug,
                'name' => $this->owner?->name,
            ],

            'location' => [
                'city'   => $this->city,
                'region' => $this->region,
                'site'   => $this->site?->name,
            ],

            'network' => $this->network ? ['name' => $this->network->name] : null,

            'quantity' => [
                'total_units'  => $this->total_units !== null ? (int) $this->total_units : null,
                'min'          => $this->min_quantity !== null ? (int) $this->min_quantity : null,
                'max'          => $this->max_quantity !== null ? (int) $this->max_quantity : null,
                'screen_count' => $this->screens_count !== null ? (int) $this->screens_count : null,
            ],

            'pricing' => [
                'currency' => $this->currency ?: 'VND',
                // Kỳ tính giá: tháng, tuần, ngày, hay cả chiến dịch.
                'unit'     => $this->price_unit,
                // Giá cả gói. Cột `floor_price`.
                'package'    => $this->amountOrNull($this->floor_price),
                // Giá một màn hình khi mua lẻ. Rỗng nghĩa là không bán lẻ.
                'per_screen' => $this->amountOrNull($this->individual_price),
                'package_discount_pct' => $this->package_discount_pct !== null ? (int) $this->package_discount_pct : null,
            ],

            'short_description' => $this->short_description,
            'cover_url'         => $this->cover_url,
            'featured'          => (bool) $this->featured,
        ];
    }

    /**
     * Giữ đúng độ chính xác của dữ liệu.
     *
     * Không làm tròn về số nguyên: `currency` có thể không phải VND, và làm
     * tròn 2,50 thành 3 là làm sai dữ liệu chứ không phải làm gọn (Codex R39).
     */
    protected function amountOrNull($value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $number = round((float) $value, 4);

        return $number === floor($number) ? (int) $number : $number;
    }
}
