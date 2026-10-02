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
            'cover_url'    => $this->cover_url,
            'screen_count' => $this->screen_count !== null ? (int) $this->screen_count : null,
        ];
    }
}
