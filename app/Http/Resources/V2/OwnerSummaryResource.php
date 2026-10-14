<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Media owner trong danh sách công khai — DTO danh sách trắng.
 *
 * Bảng `owners` mang `revenue_share_pct`, `billing_info`, `bank_*`, `tax_code`,
 * `business_license_path`. Không trường nào trong số đó xuất hiện ở đây, và
 * cách duy nhất để giữ được điều đó là **liệt kê trường muốn trả** thay vì loại
 * trường muốn ẩn: thêm một cột mới vào bảng thì danh sách loại trừ im lặng để
 * nó ra ngoài, còn danh sách trắng thì không.
 *
 * ══ `*_url` phải là URL, không phải đường dẫn trong disk (sửa 14/10/2026) ══
 *
 * `cover_url` trước đây trả thẳng giá trị cột — `owners/cover/abc.jpg`. Trình
 * duyệt giải nó **tương đối với trang đang mở**, nên `<img src>` trên `/owners`
 * trỏ vào `/owners/owners/cover/abc.jpg` và mọi ảnh bìa là ảnh hỏng.
 *
 * Quy ước của repo là trả URL đầy đủ: `AgencySummaryResource` và
 * `Product::getPhotoUrlsAttribute()` đều `asset('storage/' . …)`. Hai resource
 * của owner là ngoại lệ duy nhất, và tên trường đã hứa sẵn là một URL.
 *
 * ══ Năm trường thêm 14/10/2026, và vì sao chúng không phải "tính năng mới" ══
 *
 * Thẻ owner của bản Blade (`partials/owner-card.blade.php`) in logo, số tỉnh
 * thành, nhãn loại hình và dấu "Verified". `FrontpageService::loadOwnerExtras()`
 * **đã tính** `city_count` và `venue_types_list` cho cả danh sách và nhóm nổi
 * bật — DTO chỉ không mang chúng ra. Nên bản Next vẽ một thẻ nghèo hơn bản nó
 * thay, dù dữ liệu đã nằm trong cùng một truy vấn.
 *
 * `verified` và `featured` là cột thật trên `owners`, kiểu boolean. Trang
 * `/owners` cố ý **không** in "N đối tác verified" (audit F-15 đã dọn câu đó vì
 * danh sách chỉ lọc `status = active`); cờ ở đây là trạng thái của **từng**
 * owner, do quản trị đặt, nên nó nói đúng thứ nó nói.
 */
class OwnerSummaryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'slug'         => $this->slug,
            'name'         => $this->name,
            'type'         => $this->type,
            'logo_url'     => $this->logo_url ? asset('storage/' . $this->logo_url) : null,
            'cover_url'    => $this->cover_url ? asset('storage/' . $this->cover_url) : null,
            'screen_count' => $this->screen_count !== null ? (int) $this->screen_count : null,

            // `city_count` và `venue_types` do `loadOwnerExtras()` gắn vào.
            // Thiếu nó thì `city_count` là null, và **null khác 0**: null nghĩa
            // là không đếm, 0 nghĩa là đếm rồi và không có tỉnh nào. Trả 0 cho
            // trường hợp đầu là bịa một con số.
            'city_count'   => isset($this->city_count) ? (int) $this->city_count : null,
            'venue_types'  => isset($this->venue_types_list)
                ? array_values((array) $this->venue_types_list)
                : null,

            'verified'     => (bool) $this->verified,
            'featured'     => (bool) $this->featured,
        ];
    }
}
