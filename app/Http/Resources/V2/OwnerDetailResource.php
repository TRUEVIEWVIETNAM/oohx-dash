<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chi tiết media owner — DTO danh sách trắng.
 *
 * Bộ trường ở đây lấy **đúng bằng** những gì trang `frontpage/owner-detail`
 * đang hiển thị công khai, không thêm. Lý do: một API mới là dịp rất dễ để
 * nới mặt tiếp xúc mà không ai để ý — thêm `verified_by_user_id` vì "trông vô
 * hại", thêm `headquarters_lat` vì "có sẵn". Mở rộng thì phải là một quyết
 * định có người ký, không phải hệ quả phụ của việc viết DTO.
 *
 * `owners` mang `revenue_share_pct`, `billing_info`, `bank_*`, `tax_code`,
 * `business_license_path`, `legal_*`. Không cái nào trong số đó có mặt ở đây,
 * và `getOwnerBySlug()` nạp **cả model** vào cache, nên danh sách trắng là
 * thứ duy nhất chặn chúng.
 */
class OwnerDetailResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'slug'    => $this->slug,
            'name'    => $this->name,
            'type'    => $this->type,
            'tagline' => $this->tagline,
            'about'   => $this->about,

            'logo_url'  => $this->logo_url,
            'cover_url' => $this->cover_url,

            // Thông tin liên hệ doanh nghiệp — đã công khai trên trang owner.
            'contact' => [
                'website' => $this->website,
                'email'   => $this->email,
                'phone'   => $this->phone,
                'address' => $this->address,
            ],

            'location' => [
                'province' => $this->province?->full_name,
                'commune'  => $this->commune?->full_name,
            ],

            'founded' => $this->founded,

            'stats' => [
                'screen_count' => (int) ($this->screen_count ?? 0),
                'city_count'   => (int) ($this->city_count ?? 0),
                'venue_types'  => array_values($this->venue_types_list ?? []),
            ],
        ];
    }
}
